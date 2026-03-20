<?php
/**
 * Standalone test for parseDriverCardVehicles().
 *
 * Run:  php tests/test_parseDriverCardVehicles.php
 *
 * Covers:
 *   1. TLV Phase-1, Gen-2 (32-byte) record – basic valid plate
 *   2. TLV Phase-1, Gen-1 (29-byte) record – compact format
 *   3. TLV Phase-1, multiple records returned in date order
 *   4. Phase-2 fallback (no TLV tag) – consecutive Gen-2 records
 *   5. False-positive: single-letter middle token ("AIA M 3") rejected
 *   6. Valid 3-token plate "KR 123AB" accepted
 *   7. Nation from NationAlpha (Gen-2) takes precedence
 *   8. Nation resolved from NationNumeric when alpha absent / Gen-1
 *   9. Future timestamp (> tsMax) rejected
 *  10. Too-old timestamp (< tsMin) rejected
 *  11. Odometer overflow (> 9 999 999) rejected
 *  12. Short / empty data returns []
 *  13. Alternative TLV tag 0x0528
 *  14. Two-record minimum in Phase-2 fallback (single record not returned)
 *  19. Epoch firstUse (VU unset) accepted, falls back to lastUse as display date
 *  20. Multi-vehicle card with epoch firstUse on most records – all found
 *  21. Phase-1 extended scan: unknown 0x05xx TLV tag found; all epoch-firstUse
 *      vehicles returned (regression for "only 1 vehicle" bug)
 *  22. Phase-2b best-group: no TLV at all + all epoch firstUse – all vehicles found
 */

require_once __DIR__ . '/../includes/functions.php';

/* ── Helpers ──────────────────────────────────────────────────────────────── */

$passed = 0;
$failed = 0;

function ok(string $label, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        echo "  PASS  $label\n";
        $passed++;
    } else {
        echo "  FAIL  $label\n";
        $failed++;
    }
}

/**
 * Build a Gen-2 (32-byte) EF_CardVehiclesUsed record.
 *
 * @param string $reg       Registration number (max 13 chars, ASCII)
 * @param string $nation    NationAlpha (1-3 chars), e.g. "PL"
 * @param int    $nationNum NationNumeric (1-50)
 * @param int    $firstUse  Unix timestamp
 * @param int    $lastUse   Unix timestamp
 * @param int    $odoB      Odometer begin (3-byte value)
 * @param int    $odoE      Odometer end   (3-byte value)
 */
function buildGen2Rec(
    string $reg,
    string $nation,
    int $nationNum,
    int $firstUse,
    int $lastUse,
    int $odoB = 100000,
    int $odoE = 150000
): string {
    $nationAlpha = str_pad(substr($nation, 0, 3), 3, "\x00");
    $codePage    = "\x00";
    $regPad      = str_pad(substr($reg, 0, 13), 13, "\x00");
    $ts1         = pack('N', $firstUse);
    $ts2         = pack('N', $lastUse);
    $ob          = chr(($odoB >> 16) & 0xFF) . chr(($odoB >> 8) & 0xFF) . chr($odoB & 0xFF);
    $oe          = chr(($odoE >> 16) & 0xFF) . chr(($odoE >> 8) & 0xFF) . chr($odoE & 0xFF);

    return chr($nationNum) . $nationAlpha . $codePage . $regPad . $ts1 . $ts2 . $ob . $oe;
}

/**
 * Build a Gen-1 (29-byte) EF_CardVehiclesUsed record.
 */
function buildGen1Rec(
    string $reg,
    int $nationNum,
    int $firstUse,
    int $lastUse,
    int $odoB = 100000,
    int $odoE = 150000
): string {
    $codePage = "\x00";
    $regPad   = str_pad(substr($reg, 0, 13), 13, "\x00");
    $ts1      = pack('N', $firstUse);
    $ts2      = pack('N', $lastUse);
    $ob       = chr(($odoB >> 16) & 0xFF) . chr(($odoB >> 8) & 0xFF) . chr($odoB & 0xFF);
    $oe       = chr(($odoE >> 16) & 0xFF) . chr(($odoE >> 8) & 0xFF) . chr($odoE & 0xFF);

    return chr($nationNum) . $codePage . $regPad . $ts1 . $ts2 . $ob . $oe;
}

/**
 * Wrap records in a TLV EF_CardVehiclesUsed envelope.
 *
 * @param string $records      Concatenated raw records
 * @param int    $recCount     noOfVehicleUsed value
 * @param int    $tagByte2     Second tag byte (0x04 or 0x28)
 * @param string $prefix       Random prefix bytes to prepend
 */
function buildTlvBlob(
    string $records,
    int $recCount,
    int $tagByte2 = 0x04,
    string $prefix = ''
): string {
    // TLV content: 2-byte noOfVeh + 2-byte noOfVehForUse + records
    $tlvContent = pack('n', $recCount) . pack('n', $recCount) . $records;
    $bl         = strlen($tlvContent);

    $header = "\x05" . chr($tagByte2) . pack('n', $bl);

    return $prefix . $header . $tlvContent . str_repeat("\x00", 64);
}

/* ── Fixed timestamps within the valid window (last 12 months from now) ── */
/* Use rolling dates relative to today so tests stay valid regardless of when run */
$now          = time();
$firstUse2023 = $now - 300 * 86400;               // ~10 months ago
$lastUse2023  = $now - 270 * 86400;               // ~9 months ago
$firstUse2024 = $now - 180 * 86400;               // ~6 months ago
$lastUse2024  = $now -  60 * 86400;               // ~2 months ago
$firstUse2023Fmt = gmdate('Y-m-d', $firstUse2023);
$lastUse2023Fmt  = gmdate('Y-m-d', $lastUse2023);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 1: TLV Phase-1, Gen-2 single record – basic valid plate
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 1: TLV Gen-2 single record\n";

$rec  = buildGen2Rec('WX12345', 'PL', 40, $firstUse2023, $lastUse2023);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('returns exactly 1 vehicle', count($out) === 1);
ok('registration = WX12345',    ($out[0]['reg'] ?? '') === 'WX12345');
ok('nation = PL (alpha)',        ($out[0]['nation'] ?? '') === 'PL');
ok('first_use matches',         ($out[0]['first_use'] ?? '') === $firstUse2023Fmt);
ok('last_use matches',          ($out[0]['last_use']  ?? '') === $lastUse2023Fmt);
ok('distance  = 50000',         ($out[0]['distance']  ?? -1) === 50000);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 2: TLV Phase-1, Gen-1 (29-byte) single record
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 2: TLV Gen-1 single record\n";

$rec  = buildGen1Rec('BI1234C', 40, $firstUse2023, $lastUse2023, 50000, 75000);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('returns exactly 1 vehicle', count($out) === 1);
ok('registration = BI1234C',    ($out[0]['reg'] ?? '') === 'BI1234C');
ok('nation from numeric = PL',  ($out[0]['nation'] ?? '') === 'PL');
ok('distance = 25000',          ($out[0]['distance'] ?? -1) === 25000);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 3: TLV Phase-1, multiple records sorted by first_use
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 3: TLV Gen-2 multiple records (sorted)\n";

$rec1 = buildGen2Rec('DW123AB', 'PL', 40, $firstUse2024, $lastUse2024);
$rec2 = buildGen2Rec('KR456CD', 'PL', 40, $firstUse2023, $lastUse2023);
$blob = buildTlvBlob($rec1 . $rec2, 2);
$out  = parseDriverCardVehicles($blob);

ok('returns 2 vehicles',                 count($out) === 2);
ok('first record is older first_use',    ($out[0]['reg'] ?? '') === 'KR456CD');
ok('second record is newer first_use',   ($out[1]['reg'] ?? '') === 'DW123AB');

/* ══════════════════════════════════════════════════════════════════════════
 * Test 4: Phase-2 fallback – no TLV, raw consecutive Gen-2 records
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 4: Phase-2 fallback (no TLV)\n";

$rec1 = buildGen2Rec('GD789EF', 'PL', 40, $firstUse2023, $lastUse2023);
$rec2 = buildGen2Rec('PO321GH', 'PL', 40, $firstUse2024, $lastUse2024);
// Raw bytes with no TLV header; preceded by random-ish bytes that won't parse as records
$blob = str_repeat("\x00", 100) . $rec1 . $rec2 . str_repeat("\x00", 100);
$out  = parseDriverCardVehicles($blob);

ok('returns 2 vehicles',           count($out) === 2);
ok('plate GD789EF present',        in_array('GD789EF', array_column($out, 'reg')));
ok('plate PO321GH present',        in_array('PO321GH', array_column($out, 'reg')));

/* ══════════════════════════════════════════════════════════════════════════
 * Test 5: False-positive rejection – "AIA M 3" (middle token = lone letter)
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 5: False-positive 'AIA M 3' rejected\n";

$rec  = buildGen2Rec('AIA M 3', 'PL', 40, $firstUse2023, $lastUse2023);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('false-positive plate rejected', count($out) === 0);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 6: Valid 3-token plate "KR 123AB" is accepted
 *         (only middle tokens that are a single letter get rejected)
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 6: Valid 3-token plate 'KR 123AB' accepted\n";

$rec  = buildGen2Rec('KR 123AB', 'PL', 40, $firstUse2023, $lastUse2023);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('3-token plate accepted',           count($out) === 1);
ok('plate = KR 123AB',                 ($out[0]['reg'] ?? '') === 'KR 123AB');

/* ══════════════════════════════════════════════════════════════════════════
 * Test 7: German-style plate "B AB 1234" accepted (middle token "AB" len > 1)
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 7: German-style 'B AB 1234' accepted\n";

$rec  = buildGen2Rec('B AB 1234', 'D', 13, $firstUse2023, $lastUse2023);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('plate accepted',      count($out) === 1);
ok('plate = B AB 1234',   ($out[0]['reg'] ?? '') === 'B AB 1234');
ok('nation = D',          ($out[0]['nation'] ?? '') === 'D');

/* ══════════════════════════════════════════════════════════════════════════
 * Test 8: Nation from NationAlpha takes precedence over NationNumeric
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 8: NationAlpha precedence\n";

// nationNum = 17 ('F' = France), but nationAlpha = 'EST' (Estonia)
$rec  = buildGen2Rec('ABC123', 'EST', 17, $firstUse2023, $lastUse2023);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('alpha nation wins', ($out[0]['nation'] ?? '') === 'EST');

/* ══════════════════════════════════════════════════════════════════════════
 * Test 9: Future timestamp (beyond tsMax) rejected
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 9: Future timestamp rejected\n";

$farFuture = time() + 200 * 86400;  // +200 days, beyond 90-day tsMax
$rec  = buildGen2Rec('LU1111X', 'PL', 40, $farFuture, $farFuture + 86400);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('future-ts record rejected', count($out) === 0);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 10: lastUse too old (before tsMin) → record rejected.
 *          The parser rejects any record whose timestamps fall outside the
 *          plausible 20-year window.  Use a date older than 20 years to test.
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 10: Too-old lastUse rejected\n";

$ancient = gmmktime(0, 0, 0, 1, 1, 1990); // well beyond 20-year window
$rec  = buildGen2Rec('OLD0001', 'PL', 40, $ancient, $ancient + 86400);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('ancient lastUse record rejected', count($out) === 0);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 11: Odometer value > 9 999 999 rejected
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 11: Odometer overflow rejected\n";

$rec  = buildGen2Rec('OD12345', 'PL', 40, $firstUse2023, $lastUse2023, 10_000_000, 10_100_000);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('odometer overflow rejected', count($out) === 0);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 12: Short / empty data returns empty array
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 12: Short/empty data\n";

ok('empty string → []',      parseDriverCardVehicles('') === []);
ok('39-byte string → []',    parseDriverCardVehicles(str_repeat("\x00", 39)) === []);
ok('all-null 100 bytes → []', parseDriverCardVehicles(str_repeat("\x00", 100)) === []);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 13: Alternative TLV tag 0x0528 is also recognised
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 13: Alternative TLV tag 0x0528\n";

$rec  = buildGen2Rec('ZK99887', 'PL', 40, $firstUse2023, $lastUse2023);
$blob = buildTlvBlob($rec, 1, 0x28); // second tag byte = 0x28
$out  = parseDriverCardVehicles($blob);

ok('0x0528-tagged record found', count($out) === 1);
ok('plate = ZK99887',            ($out[0]['reg'] ?? '') === 'ZK99887');

/* ══════════════════════════════════════════════════════════════════════════
 * Test 14: Phase-2 requires ≥ 2 consecutive records; single record not returned
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 14: Phase-2 minimum 2 records\n";

// Single isolated Gen-2 record with no TLV envelope → should NOT be returned
$rec1 = buildGen2Rec('SO55555', 'PL', 40, $firstUse2023, $lastUse2023);
// Fill surroundings with bytes that cannot form a valid record
$blob = str_repeat("\xFF", 100) . $rec1 . str_repeat("\xFF", 100);
$out  = parseDriverCardVehicles($blob);

ok('single isolated record not returned by Phase-2', count($out) === 0);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 15: Null-byte padded registration ("WA\x00\x00\x0012345") is normalised
 *           to "WA 12345" (no multiple consecutive spaces).
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 15: Null-padded registration normalised to single spaces\n";

// Build a Gen-2 record manually to inject "\x00\x00" padding in the middle of the reg
function buildGen2RecRaw(
    string $regRaw13,   // exactly 13 bytes of raw regNumber field
    string $nation,
    int    $nationNum,
    int    $firstUse,
    int    $lastUse,
    int    $odoB = 100000,
    int    $odoE = 150000
): string {
    $nationAlpha = str_pad(substr($nation, 0, 3), 3, "\x00");
    $codePage    = "\x00";
    $ts1         = pack('N', $firstUse);
    $ts2         = pack('N', $lastUse);
    $ob          = chr(($odoB >> 16) & 0xFF) . chr(($odoB >> 8) & 0xFF) . chr($odoB & 0xFF);
    $oe          = chr(($odoE >> 16) & 0xFF) . chr(($odoE >> 8) & 0xFF) . chr($odoE & 0xFF);
    return chr($nationNum) . $nationAlpha . $codePage . $regRaw13 . $ts1 . $ts2 . $ob . $oe;
}

// "WA" + 1 null byte + "12345" + 5 null bytes = 13 bytes total
// (The null byte in the middle becomes a space after normalisation → "WA 12345")
$regRaw13 = "WA\x0012345\x00\x00\x00\x00\x00";
$rec  = buildGen2RecRaw($regRaw13, 'PL', 40, $firstUse2023, $lastUse2023);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('padded reg: 1 result',            count($out) === 1);
ok('padded reg: no consecutive spaces', ($out[0]['reg'] ?? '') === 'WA 12345');

/* ══════════════════════════════════════════════════════════════════════════
 * Test 16: dddParseVehicleReg – classic plate with space
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 16: dddParseVehicleReg – various EU plate formats\n";

/**
 * Build a 14-byte blob as stored in a vehicle DDD file:
 *   byte 0    = codePage (0x00)
 *   bytes 1-13 = reg, padded with 0x00
 * The function prepends random-ish bytes and appends padding so the scanner
 * actually finds the plate somewhere in the middle.
 */
function makeVehicleDddBlob(string $reg): string {
    $field = "\x00" . str_pad(substr($reg, 0, 13), 13, "\x00");
    return str_repeat("\x01\x02\x03\x04", 50) . $field . str_repeat("\x00", 50);
}

// 16a: classic "WA 12345" (2-letter prefix + space + 5 digits)
ok('16a: WA 12345',   dddParseVehicleReg(makeVehicleDddBlob('WA 12345')) === 'WA 12345');

// 16b: plate without space "WA12345"
ok('16b: WA12345',    dddParseVehicleReg(makeVehicleDddBlob('WA12345'))  === 'WA12345');

// 16c: null-padded "WA\x0012345" → should normalise to "WA 12345"
ok('16c: null-padded "WA12345"', dddParseVehicleReg(makeVehicleDddBlob("WA\x0012345")) === 'WA 12345');

// 16d: German 3-token "B AB1234"
ok('16d: B AB1234',   dddParseVehicleReg(makeVehicleDddBlob('B AB1234')) === 'B AB1234');

// 16e: purely alphabetic "ABCDE" should NOT match (no digit)
ok('16e: no-digit rejected', dddParseVehicleReg(makeVehicleDddBlob('ABCDE')) === null);

// 16f: empty blob returns null
ok('16f: empty → null', dddParseVehicleReg('') === null);

/* ── Test 17: Proprietary 31-byte record format ──────────────────────────── */
/* Layout: odoBegin(3)+firstUse(4)+lastUse(4)+nation(1)+codepage(1)+reg(13)+ctr(2)+odoEnd(3) */
echo "\nTest 17: Proprietary 31-byte record format (odo-first, no nationAlpha)\n";

function make31byteRec(string $reg, int $odoBegin, int $firstUse, int $lastUse, int $nation, int $odoEnd): string {
    $r  = chr(($odoBegin >> 16) & 0xFF) . chr(($odoBegin >> 8) & 0xFF) . chr($odoBegin & 0xFF);  // odoBegin
    $r .= pack('N', $firstUse);                                                                      // firstUse
    $r .= pack('N', $lastUse);                                                                       // lastUse
    $r .= chr($nation);                                                                              // nationNumeric
    $r .= chr(0x02);                                                                                 // codePage
    $r .= str_pad(substr($reg, 0, 13), 13, "\x20");                                                  // registration
    $r .= "\x08\x06";                                                                                // counter (ignored)
    $r .= chr(($odoEnd >> 16) & 0xFF) . chr(($odoEnd >> 8) & 0xFF) . chr($odoEnd & 0xFF);           // odoEnd
    return $r;
}

$ts1 = mktime(0, 0, 0, 8, 22, 2025);
$ts2 = mktime(23, 59, 59, 8, 22, 2025);
$ts3 = mktime(0, 0, 0, 8, 23, 2025);
$ts4 = mktime(23, 59, 59, 8, 23, 2025);

// Build two consecutive 31-byte records for "PY 90501" (nationNumeric=40=PL)
$rec1 = make31byteRec('PY 90501', 264270, $ts1, $ts2, 40, 264270);
$rec2 = make31byteRec('PY 90501', 264358, $ts3, $ts4, 40, 264620);
assert(strlen($rec1) === 31 && strlen($rec2) === 31, '31-byte records built');

// Wrap in a TLV 0x0504 block without a standard noOfVeh/ptr header
$records = $rec1 . $rec2;
$bl      = strlen($records);
$blob    = "\x05\x04" . chr(($bl >> 8) & 0xFF) . chr($bl & 0xFF) . $records;
// Pad to at least 40 bytes total
$blob   .= str_repeat("\x00", max(0, 40 - strlen($blob)));

$result17 = parseDriverCardVehicles($blob);
ok('17a: finds records in 31-byte format', count($result17) >= 2);
ok('17b: registration = PY 90501', ($result17[0]['reg'] ?? '') === 'PY 90501');
ok('17c: nation = PL',              ($result17[0]['nation'] ?? '') === 'PL');
ok('17d: first_use = 2025-08-22',   ($result17[0]['first_use'] ?? '') === '2025-08-22');
ok('17e: odo_begin correct',        ($result17[0]['odo_begin'] ?? -1) === 264270);
ok('17f: odo_end stored correctly', ($result17[1]['odo_end'] ?? -1) === 264620);
ok('17g: distance for rec2',        ($result17[1]['distance'] ?? -1) === 262);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 18: firstUse older than 12 months but lastUse recent → ACCEPTED.
 *          Root-cause regression test: vehicles on the card for years but
 *          still driven within the 12-month window must not be filtered out.
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 18: Old firstUse + recent lastUse accepted\n";

$oldFirstUse   = time() - 800 * 86400;  // ~2.2 years ago (well outside 12 months)
$recentLastUse = time() -  30 * 86400;  // 30 days ago (clearly inside window)
$rec  = buildGen2Rec('TR9988AB', 'PL', 40, $oldFirstUse, $recentLastUse);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('18a: old-firstUse/recent-lastUse accepted', count($out) === 1);
ok('18b: plate = TR9988AB',                     ($out[0]['reg'] ?? '') === 'TR9988AB');
ok('18c: last_use is recent',                   ($out[0]['last_use'] ?? '') === gmdate('Y-m-d', $recentLastUse));

/* ══════════════════════════════════════════════════════════════════════════
 * Test 19: firstUse = 0 (epoch / not recorded by VU) → ACCEPTED with
 *          first_use falling back to lastUse (no spurious 1970-01-01).
 *
 * Some vehicle units leave vehicleFirstUse at zero.  The record is still
 * valid – the driver DID use the vehicle – so we accept it and display the
 * last-use date as both first_use and last_use rather than discarding it.
 *
 * 19b: A non-zero firstUse that is older than 20 years → still REJECTED
 *      (distinguishes genuine corruption from the unset-epoch case).
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 19: Epoch firstUse (VU unset) – accepted, falls back to lastUse\n";

$epochFirstUse = 0;                      // Unix epoch – VU didn't record firstUse
$recentLastUse = time() - 30 * 86400;    // 30 days ago
$rec19  = buildGen2Rec('AB12345', 'PL', 40, $epochFirstUse, $recentLastUse);
$blob19 = buildTlvBlob($rec19, 1);
$out19  = parseDriverCardVehicles($blob19);
ok('19a: epoch firstUse accepted (VU unset field)',  count($out19) === 1);
ok('19a: first_use falls back to last_use',          ($out19[0]['first_use'] ?? '') === gmdate('Y-m-d', $recentLastUse));
ok('19a: no spurious 1970-01-01 date',               ($out19[0]['first_use'] ?? '') !== '1970-01-01');
ok('19a: plate is AB12345',                          ($out19[0]['reg'] ?? '') === 'AB12345');

// 19b: A non-zero firstUse older than 20 years → rejected (genuine corruption)
echo "\nTest 19b: Ancient non-epoch firstUse rejected\n";
$ancientFirstUse = strtotime('-25 years');
$rec19b  = buildGen2Rec('CD67890', 'PL', 40, $ancientFirstUse, $recentLastUse);
$blob19b = buildTlvBlob($rec19b, 1);
$out19b  = parseDriverCardVehicles($blob19b);
ok('19b: >20-year-old non-epoch firstUse rejected',  count($out19b) === 0);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 20: Realistic multi-vehicle card where most records have firstUse=0.
 *          Root-cause regression: the epoch-firstUse guard must NOT silently
 *          drop all records that the VU left with an unset vehicleFirstUse.
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 20: Multi-vehicle card with epoch firstUse on most records\n";

// Build 10 Gen-2 records; only PY 90501 has a proper firstUse,
// all others have firstUse = 0 (VU didn't record it)
$pyFirst = mktime(0, 0, 0, 11, 19, 2025);
$t20recs = '';
$t20veh = [
    ['PO 12345', 0,       mktime(23,59,59,  1, 15, 2024)],
    ['WA 67890', 0,       mktime(23,59,59,  3, 21, 2024)],
    ['GD 11111', 0,       mktime(23,59,59,  6,  5, 2024)],
    ['KR 22222', 0,       mktime(23,59,59,  9, 10, 2024)],
    ['WR 33333', 0,       mktime(23,59,59, 11, 25, 2024)],
    ['LU 44444', 0,       mktime(23,59,59,  2, 14, 2025)],
    ['RZ 55555', 0,       mktime(23,59,59,  5, 30, 2025)],
    ['BY 66666', 0,       mktime(23,59,59,  8, 20, 2025)],
    ['PY 90501', $pyFirst, mktime(23,59,59, 11, 19, 2025)],
    ['SZ 77777', 0,       mktime(23,59,59,  2, 26, 2026)],
];
foreach ($t20veh as [$treg, $tfu, $tlu]) {
    $t20recs .= buildGen2Rec($treg, 'PL', 40, $tfu, $tlu);
}
$t20blob = buildTlvBlob($t20recs, count($t20veh));
$out20   = parseDriverCardVehicles($t20blob);

ok('20a: all 10 vehicles found',         count($out20) === 10);
ok('20b: PY 90501 present',              in_array('PY 90501', array_column($out20, 'reg')));
ok('20c: SZ 77777 present (Feb 2026)',   in_array('SZ 77777', array_column($out20, 'reg')));
ok('20d: no 1970-01-01 first_use dates', !in_array('1970-01-01', array_column($out20, 'first_use')));
// Records with epoch firstUse should have first_use == last_use
$szRec = current(array_filter($out20, fn($r) => $r['reg'] === 'SZ 77777'));
ok('20e: SZ 77777 first_use = last_use (epoch fallback)', $szRec && $szRec['first_use'] === $szRec['last_use']);

/* ══════════════════════════════════════════════════════════════════════════
 * Test 21: Phase-1 extended scan – unknown TLV tag with epoch firstUse.
 *
 * This is the primary regression test for the "only one vehicle (PY 90501)
 * with date November 19 2025 returned" bug.
 *
 * When a DDD file uses a non-standard TLV tag (not 0x0504/0x0528/0x050b),
 * the old hard-coded tag list caused Phase 1 to miss the block, leaving
 * Phase 2 (blind scan) to run with allowEpochFirstUse=false.  Phase 2 then
 * rejected all 9 epoch-firstUse records and returned only PY 90501.
 *
 * After the fix, Phase 1 scans for ANY 0x05xx tag (first byte = 0x05), so
 * it finds the block regardless of the second tag byte.  Because Phase 1
 * passes allowEpochFirstUse=true, all 10 vehicles are correctly returned.
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 21: Phase-1 extended scan – unknown TLV tag, epoch firstUse records\n";

$t21pyFirst = mktime(0, 0, 0, 11, 19, 2025);
$t21veh = [
    ['PO 12345', 0,            mktime(23, 59, 59,  1, 15, 2024)],
    ['WA 67890', 0,            mktime(23, 59, 59,  3, 21, 2024)],
    ['GD 11111', 0,            mktime(23, 59, 59,  6,  5, 2024)],
    ['KR 22222', 0,            mktime(23, 59, 59,  9, 10, 2024)],
    ['WR 33333', 0,            mktime(23, 59, 59, 11, 25, 2024)],
    ['LU 44444', 0,            mktime(23, 59, 59,  2, 14, 2025)],
    ['RZ 55555', 0,            mktime(23, 59, 59,  5, 30, 2025)],
    ['BY 66666', 0,            mktime(23, 59, 59,  8, 20, 2025)],
    ['PY 90501', $t21pyFirst,  mktime(23, 59, 59, 11, 19, 2025)],
    ['SZ 77777', 0,            mktime(23, 59, 59,  2, 26, 2026)],
];
$t21recs = '';
foreach ($t21veh as [$treg, $tfu, $tlu]) {
    $t21recs .= buildGen2Rec($treg, 'PL', 40, $tfu, $tlu);
}
// Wrap in an UNKNOWN TLV tag (0x050F) so Phase 1 cannot find the block
$t21hdr  = pack('n', 9) . pack('n', 10);       // vPtr=9, noOfVeh=10
$t21val  = $t21hdr . $t21recs;
$t21blob = "\x05\x0F" . pack('n', strlen($t21val)) . $t21val . str_repeat("\x00", 64);

$out21 = parseDriverCardVehicles($t21blob);

ok('21a: all 10 vehicles found via Phase-1 extended scan',  count($out21) === 10);
ok('21b: PY 90501 present',                   in_array('PY 90501', array_column($out21, 'reg')));
ok('21c: SZ 77777 (Feb 2026) present',        in_array('SZ 77777', array_column($out21, 'reg')));
ok('21d: no 1970-01-01 dates',                !in_array('1970-01-01', array_column($out21, 'first_use')));
$sz21 = current(array_filter($out21, fn($r) => $r['reg'] === 'SZ 77777'));
ok('21e: SZ 77777 first_use = last_use',      $sz21 && $sz21['first_use'] === $sz21['last_use']);
ok('21f: PY 90501 first_use = 2025-11-19',    current(array_filter($out21, fn($r) => $r['reg'] === 'PY 90501'))['first_use'] ?? '' === '2025-11-19');

/* ══════════════════════════════════════════════════════════════════════════
 * Test 22: Phase-2b best-group – no TLV structure at all, all epoch firstUse.
 *
 * When there is no TLV structure (Phase 1 finds nothing) AND all records have
 * epoch firstUse (Phase 2a finds nothing because allowEpochFirstUse=false),
 * Phase 2b runs with allowEpochFirstUse=true and picks the group with the
 * MOST consecutive valid records.
 *
 * In this test, the real records (10 vehicles) are preceded by 100 zero bytes.
 * Misaligned reads of the zero prefix produce no valid garbage (zeros fail
 * the registration validation), so Phase 2b cleanly finds the real group.
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 22: Phase-2b best-group – no TLV, all epoch firstUse\n";

$t22pyFirst = mktime(0, 0, 0, 11, 19, 2025);
$t22veh = [
    ['PO 12345', 0,            mktime(23, 59, 59,  1, 15, 2024)],
    ['WA 67890', 0,            mktime(23, 59, 59,  3, 21, 2024)],
    ['GD 11111', 0,            mktime(23, 59, 59,  6,  5, 2024)],
    ['KR 22222', 0,            mktime(23, 59, 59,  9, 10, 2024)],
    ['WR 33333', 0,            mktime(23, 59, 59, 11, 25, 2024)],
    ['LU 44444', 0,            mktime(23, 59, 59,  2, 14, 2025)],
    ['RZ 55555', 0,            mktime(23, 59, 59,  5, 30, 2025)],
    ['BY 66666', 0,            mktime(23, 59, 59,  8, 20, 2025)],
    ['PY 90501', $t22pyFirst,  mktime(23, 59, 59, 11, 19, 2025)],
    ['SZ 77777', 0,            mktime(23, 59, 59,  2, 26, 2026)],
];
$t22recs = '';
foreach ($t22veh as [$treg, $tfu, $tlu]) {
    $t22recs .= buildGen2Rec($treg, 'PL', 40, $tfu, $tlu);
}
// Precede with 100 zero bytes and NO TLV tag so Phase 1 finds nothing
$t22blob = str_repeat("\x00", 100) . $t22recs . str_repeat("\x00", 100);

$out22 = parseDriverCardVehicles($t22blob);

ok('22a: all 10 vehicles found via Phase-2b',  count($out22) === 10);
ok('22b: PY 90501 present',                    in_array('PY 90501', array_column($out22, 'reg')));
ok('22c: SZ 77777 (Feb 2026) present',         in_array('SZ 77777', array_column($out22, 'reg')));
ok('22d: no 1970-01-01 dates',                 !in_array('1970-01-01', array_column($out22, 'first_use')));
$sz22 = current(array_filter($out22, fn($r) => $r['reg'] === 'SZ 77777'));
ok('22e: SZ 77777 first_use = last_use',       $sz22 && $sz22['first_use'] === $sz22['last_use']);

/* ── Summary ──────────────────────────────────────────────────────────────── */
echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Passed: $passed  |  Failed: $failed\n";
echo str_repeat('─', 50) . "\n";

exit($failed > 0 ? 1 : 0);
