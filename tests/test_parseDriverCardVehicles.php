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

/* ── Fixed timestamps within the valid window ──────────────────────────── */
$firstUse2023 = gmmktime(0, 0, 0, 1, 15, 2023);  // 2023-01-15
$lastUse2023  = gmmktime(0, 0, 0, 12, 31, 2023); // 2023-12-31
$firstUse2024 = gmmktime(0, 0, 0, 3,  1, 2024);  // 2024-03-01
$lastUse2024  = gmmktime(0, 0, 0, 9,  30, 2024); // 2024-09-30

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
ok('first_use = 2023-01-15',    ($out[0]['first_use'] ?? '') === '2023-01-15');
ok('last_use  = 2023-12-31',    ($out[0]['last_use']  ?? '') === '2023-12-31');
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
 * Test 10: Too-old timestamp (before tsMin = current year – 6) rejected
 * ══════════════════════════════════════════════════════════════════════════ */
echo "\nTest 10: Too-old timestamp rejected\n";

$ancient = gmmktime(0, 0, 0, 1, 1, 2010); // well before any valid window
$rec  = buildGen2Rec('OLD0001', 'PL', 40, $ancient, $ancient + 86400);
$blob = buildTlvBlob($rec, 1);
$out  = parseDriverCardVehicles($blob);

ok('ancient-ts record rejected', count($out) === 0);

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

// "WA" + 3 null bytes + "12345" + 5 null bytes = 13 bytes total
$regRaw13 = "WA\x00\x00\x0012345\x00\x00\x00\x00\x00";
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

/* ── Summary ──────────────────────────────────────────────────────────────── */
echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Passed: $passed  |  Failed: $failed\n";
echo str_repeat('─', 50) . "\n";

exit($failed > 0 ? 1 : 0);
