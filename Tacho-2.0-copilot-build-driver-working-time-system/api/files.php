<?php
/**
 * TachoPro 2.0 – DDD Files API
 * Handles: upload (with auto driver/vehicle creation), delete, download
 *
 * NOTE: This is a JSON API endpoint.  All auth/module failures must return
 * JSON (not HTML redirects), otherwise the JS fetch handler would see an
 * un-parseable response and display a false "network error".
 */

// ── Global safety net: any uncaught exception or fatal error → JSON ──────
// Registered BEFORE ob_start so the handler can safely emit headers.
set_exception_handler(function (Throwable $e): void {
    // Clean every output buffer level that may be open
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Błąd serwera. Spróbuj ponownie.']);
});

// PHP fatal errors (E_ERROR etc.) are converted to ErrorException so the
// exception handler above can catch them.
set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    if (error_reporting() === 0) {
        return false;   // silenced with @
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}, E_ERROR | E_PARSE | E_USER_ERROR);

// Catch any truly fatal PHP error (parse/compile-time, etc.) that bypasses
// both set_error_handler and set_exception_handler.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Błąd serwera. Spróbuj ponownie.']);
    }
});

// Buffer any stray PHP warnings/notices so they never corrupt the JSON body
ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/license_check.php';

$action = $_REQUEST['action'] ?? '';

// Download streams binary data – skip JSON header for that action
if ($action !== 'download') {
    header('Content-Type: application/json');
}

// ── Auth check (return JSON 401 instead of HTML redirect) ─────
startSession();
if (empty($_SESSION['user_id'])) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(401);
    if ($action !== 'download') {
        echo json_encode(['error' => 'Sesja wygasła. Zaloguj się ponownie.']);
    }
    exit;
}

// ── Module check (return JSON 403 instead of HTML redirect) ───
// All authenticated users have access – no plan gating required.

// Discard any stray output that may have accumulated
while (ob_get_level() > 0) ob_end_clean();

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// ══════════════════════════════════════════════════════════════
// DDD binary helpers
// ══════════════════════════════════════════════════════════════

/**
 * Trim a raw 35-byte EU tachograph name field to a clean UTF-8 string.
 * Handles ASCII (NameCodeType 0x00) and Latin-1 (NameCodeType 0x02), which
 * are the two most common encodings found in Polish/EU driver cards.
 */
if (!function_exists('dddNameTrim')) {
function dddNameTrim(string $raw): string
{
    $result = '';
    foreach (str_split($raw) as $ch) {
        $b = ord($ch);
        if ($b >= 0x20 && $b <= 0x7e) {
            $result .= $ch;                                         // printable ASCII
        } elseif ($b >= 0xa0) {
            $result .= mb_convert_encoding($ch, 'UTF-8', 'ISO-8859-1');  // Latin-1 supplement
        }
        // 0x00–0x1f and 0x7f–0x9f (control bytes) are silently dropped
    }
    return trim($result);
}
}

if (!function_exists('dddParseDriverInfo')) {
/**
 * Parse driver name and card number from an EU tachograph driver-card DDD file.
 *
 * Two complementary strategies are tried in order:
 *
 * Strategy 1 – JSX algorithm (truck-delegate-pro.jsx parseDDD):
 *   Scans for tag 0x05 0x20, then walks inside the block looking for a byte
 *   in A-Z range that starts a plausible surname (≥3 chars, mixed-case) followed
 *   by the first-name field 36 bytes later.  This matches the most common real
 *   driver-card binary structure.
 *
 * Strategy 2 – EF_Identification (ISO 7816 / EU Reg. 165/2014 Annex 1B/1C):
 *   Scans for tag 0x01 0x05, reads the 16-byte card number (alphanumeric) at
 *   base+1, and reads surname/first-name at base+66/base+102 as a fallback for
 *   name extraction when Strategy 1 finds nothing.
 *
 * Card number extraction always uses the EF_Identification block.
 *
 * @return array{last_name:string, first_name:string, card_number:string}|null
 */
function dddParseDriverInfo(string $data): ?array
{
    $len = strlen($data);

    // ── Strategy 1: JSX tag 0x05 0x20 – primary name detection ───────────────
    $driverName = null;
    for ($i = 0; $i < $len - 4; $i++) {
        if (ord($data[$i]) !== 0x05 || ord($data[$i + 1]) !== 0x20) {
            continue;
        }
        $bl = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
        if ($bl < 40 || $bl > 3000 || $i + 4 + $bl > $len) {
            continue;
        }
        for ($k = 0; $k < $bl - 72; $k++) {
            $b = ord($data[$i + 4 + $k]);
            if ($b < 65 || $b > 90) {
                continue;   // not A-Z
            }
            $sn = trim(str_replace("\0", '', dddReadStr($data, $i + 4 + $k, 36)));
            $fn = trim(str_replace("\0", '', dddReadStr($data, $i + 4 + $k + 36, 36)));
            if (strlen($sn) >= 3 && preg_match('/^[A-Za-z][A-Za-z \-]*$/', $sn)
                && strlen($fn) >= 2 && preg_match('/^[A-Za-z][A-Za-z \-]*$/', $fn)) {
                $driverName = ['last_name' => $sn, 'first_name' => $fn];
                break;
            }
        }
        if ($driverName) {
            break;
        }
    }

    // ── Strategy 2: EF_Identification tag 0x01 0x05 – card number + fallback name + birth date ──
    $cardNumber   = null;
    $birthDate    = null;
    $cardValidUntil = null;
    for ($i = 0; $i < $len - 144; $i++) {
        if (ord($data[$i]) !== 0x01 || ord($data[$i + 1]) !== 0x05) {
            continue;
        }
        if ($i + 6 >= $len) {
            continue;
        }
        // 6-byte block header: tag(2) + unknown(2) + data-length(2, big-endian)
        $bl = (ord($data[$i + 4]) << 8) | ord($data[$i + 5]);
        if ($bl < 130 || $bl > 500 || $i + 6 + $bl > $len) {
            continue;
        }

        $base = $i + 6;

        // ── Card number: 16 alphanumeric ASCII bytes at base+1 ─────────────
        $cardRaw = substr($data, $base + 1, 16);
        $valid   = true;
        for ($k = 0; $k < 16; $k++) {
            $b = ord($cardRaw[$k]);
            if (($b < 0x30 || $b > 0x39) &&
                ($b < 0x41 || $b > 0x5a) &&
                ($b < 0x61 || $b > 0x7a)) {
                $valid = false;
                break;
            }
        }
        if ($valid) {
            $cardNumber = rtrim($cardRaw);
        }

        // ── Fallback name: fixed offsets in EF_Identification ──────────────
        if (!$driverName) {
            $sn = dddNameTrim(substr($data, $base + 66, 35));
            $fn = dddNameTrim(substr($data, $base + 102, 35));
            if (strlen($sn) >= 2 && strlen($fn) >= 1) {
                $driverName = ['last_name' => $sn, 'first_name' => $fn];
            }
        }

        // ── Birth date: holderBirthDate (TimeReal 4 bytes) at base+137 ─────
        // EU Reg. 165/2014 Annex 1B EF_Identification layout:
        //   base+0  cardIssuingMemberState(1) + cardNumber(16) + issuingAuth(36)
        //   + cardIssueDate(4) + validityBegin(4) + expiryDate(4)
        //   + holderName: [nameCoding(1)+surname(35)] + [nameCoding(1)+firstname(35)] = 72 bytes
        //   base+137 holderBirthDate (TimeReal = Unix timestamp, 4 bytes)
        if ($bl >= 141 && $i + 6 + 141 <= $len) {
            $birthTs   = unpack('N', substr($data, $base + 137, 4))[1];
            $birthYear = (int)gmdate('Y', $birthTs);
            // Sanity check: drivers born 1930–2005
            if ($birthYear >= 1930 && $birthYear <= 2005) {
                $birthDate = gmdate('Y-m-d', $birthTs);
            }
        }

        // cardExpiryDate at base+61 (TimeReal = 4-byte big-endian Unix timestamp)
        if ($bl >= 65 && $i + 6 + 65 <= $len) {
            $expiryTs   = unpack('N', substr($data, $base + 61, 4))[1];
            $expiryYear = (int)gmdate('Y', $expiryTs);
            if ($expiryYear >= 2000 && $expiryYear <= 2050) {
                $cardValidUntil = gmdate('Y-m-d', $expiryTs);
            }
        }

        if ($cardNumber !== null || $driverName !== null) {
            break;
        }
    }

    if (!$driverName) {
        return null;
    }

    return [
        'last_name'        => $driverName['last_name'],
        'first_name'       => $driverName['first_name'],
        'card_number'      => $cardNumber ?? '',
        'birth_date'       => $birthDate,
        'card_valid_until' => $cardValidUntil,
    ];
}
} /* end if (!function_exists('dddParseDriverInfo')) */

if (!function_exists('dddParseVehicleReg')) {
/**
 * Parse vehicle registration plate from a vehicle DDD file.
 * Returns the registration string or null.
 *
 * Handles common EU plate formats:
 *  - "AB 12345"  – 2-4 letter prefix, space, alphanumeric suffix (classic)
 *  - "AB12345"   – prefix and suffix with no separator
 *  - "B AB1234"  – single-letter city code + two-part rest (e.g. German plates)
 *  - "AB 123CD"  – mixed-order alphanumeric suffix
 */
function dddParseVehicleReg(string $data): ?string {
    $len = strlen($data);
    for ($i = 0; $i < $len - 14; $i++) {
        $raw = dddReadStr($data, $i, 14);
        $s   = strtoupper(trim(preg_replace('/\s+/', ' ',
               preg_replace('/[^A-Z0-9 ]/', '', str_replace(["\x00", "\xFF"], ' ', $raw)))));
        if ($s === '') continue;
        $noSpc = str_replace(' ', '', $s);
        if (strlen($noSpc) < 4 || strlen($noSpc) > 10) continue;
        if (!preg_match('/^[A-Z]/', $s))               continue;
        if (!preg_match('/[0-9]/', $s))                continue;
        if (preg_match('/^[A-Z]{1,4}\s?[A-Z0-9]{3,9}$/', $s) ||
            preg_match('/^[A-Z]{1,4}\s[A-Z0-9]{1,6}\s[A-Z0-9]{1,6}$/', $s)) {
            return $s;
        }
    }
    return null;
}
} /* end if (!function_exists('dddParseVehicleReg')) */

/**
 * Convert a company name into a safe filesystem directory name.
 * Transliterates common Polish diacritics to ASCII, strips unsafe chars.
 */
function dddSanitizeDirName(string $name): string {
    static $map = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
        'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ];
    $name = strtr($name, $map);
    $name = preg_replace('/[^a-zA-Z0-9 _\-]/', '', $name);
    $name = preg_replace('/[\s_]+/', '_', trim($name));
    return substr($name, 0, 64) ?: 'company';
}

// ══════════════════════════════════════════════════════════════
// Upload
// ══════════════════════════════════════════════════════════════
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Nieprawidłowy token CSRF.']); exit;
    }
    if (!hasRole('manager')) {
        echo json_encode(['error' => 'Brak uprawnień.']); exit;
    }

    $fileType     = $_POST['file_type']     ?? '';
    $downloadDate = $_POST['download_date'] ?? date('Y-m-d');

    if (!in_array($fileType, ['driver', 'vehicle'], true)) {
        echo json_encode(['error' => 'Nieprawidłowy typ pliku.']); exit;
    }

    if (empty($_FILES['ddd_file']) || $_FILES['ddd_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE  => 'Plik przekracza limit rozmiaru serwera.',
            UPLOAD_ERR_FORM_SIZE => 'Plik przekracza dozwolony rozmiar.',
            UPLOAD_ERR_PARTIAL   => 'Plik został przesłany częściowo.',
            UPLOAD_ERR_NO_FILE   => 'Nie wybrano pliku.',
        ];
        $errCode = $_FILES['ddd_file']['error'] ?? -1;
        echo json_encode(['error' => $uploadErrors[$errCode] ?? 'Błąd przesyłania pliku.']);
        exit;
    }

    $file = $_FILES['ddd_file'];
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['error' => 'Plik jest za duży (maks. 10 MB).']); exit;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['ddd', 'c1b', 'tgd'], true)) {
        echo json_encode(['error' => 'Nieobsługiwany format pliku.']); exit;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        echo json_encode(['error' => 'Błąd bezpieczeństwa – plik nie został prawidłowo przesłany.']); exit;
    }

    // ── Duplicate check BEFORE storing ─────────────────────────────────────
    $uploadHash = hash_file('sha256', $file['tmp_name']);
    $dupChk     = $db->prepare(
        'SELECT id FROM ddd_files WHERE company_id=? AND file_hash=? AND is_deleted=0 LIMIT 1'
    );
    $dupChk->execute([$companyId, $uploadHash]);
    if ($dupChk->fetchColumn()) {
        echo json_encode(['error' => 'Ten plik już istnieje w archiwum (duplikat).']); exit;
    }

    try {
        // ── Resolve company-name-based upload directory ───────────
        $cStmt = $db->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
        $cStmt->execute([$companyId]);
        $companyDirName = dddSanitizeDirName($cStmt->fetchColumn() ?: (string)$companyId);
        $typeSubdir     = $fileType === 'driver' ? 'Drivers' : 'Vehicles';
        $storedSubdir   = $companyDirName . '/' . $typeSubdir;

        // ── Store physical file ───────────────────────────────────
        $dddBaseDir = __DIR__ . '/../uploads/ddd/';
        if (!is_dir($dddBaseDir) || !is_writable($dddBaseDir)) {
            echo json_encode(['error' => 'Katalog uploads/ddd/ nie istnieje lub nie ma uprawnień do zapisu. Sprawdź uprawnienia zapisu na serwerze (chmod 0755).']); exit;
        }

        $uploadDir = $dddBaseDir . $storedSubdir . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            echo json_encode(['error' => 'Nie można utworzyć katalogu na serwerze. Sprawdź uprawnienia do uploads/ddd/.']); exit;
        }

        $storedName = date('Ymd_His_') . bin2hex(random_bytes(6)) . '.' . $ext;
        $destPath   = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['error' => 'Nie można zapisać pliku na serwerze.']); exit;
        }

        $fileHash = $uploadHash;   // already computed above
        $fileSize = filesize($destPath);

        // ── Parse binary content for auto-create ─────────────────
        $binaryData      = file_get_contents($destPath);
        $linkedDriverId  = null;
        $linkedVehicleId = null;
        $parsedCardNumber = null;
        $autoCreated     = null;   // info message back to UI

        if ($fileType === 'driver') {
            $parsed = dddParseDriverInfo($binaryData);
            if ($parsed) {
                $lastName           = $parsed['last_name'];
                $firstName          = $parsed['first_name'];
                $parsedCardNumber   = $parsed['card_number'];
                $parsedBirthDate    = $parsed['birth_date'] ?? null;
                $parsedCardValid    = $parsed['card_valid_until'] ?? null;

                // Try to find existing driver by card number first, then by name
                $stmt = $db->prepare(
                    'SELECT id FROM drivers
                     WHERE company_id=? AND is_active=1
                       AND (
                         (? <> \'\' AND card_number=?)
                         OR (LOWER(last_name)=LOWER(?) AND LOWER(first_name)=LOWER(?))
                       )
                     LIMIT 1'
                );
                $stmt->execute([$companyId, $parsedCardNumber, $parsedCardNumber, $lastName, $firstName]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    $linkedDriverId = (int)$existing;
                    // Update card number on existing driver if not yet set
                    if ($parsedCardNumber) {
                        $db->prepare(
                            'UPDATE drivers SET card_number=? WHERE id=? AND (card_number IS NULL OR card_number=\'\')'
                        )->execute([$parsedCardNumber, $linkedDriverId]);
                    }
                    // Update birth date on existing driver if not yet set
                    if ($parsedBirthDate) {
                        $db->prepare(
                            'UPDATE drivers SET birth_date=? WHERE id=? AND birth_date IS NULL'
                        )->execute([$parsedBirthDate, $linkedDriverId]);
                    }
                    // Update card expiry date – keep the latest (furthest) date
                    if ($parsedCardValid) {
                        $db->prepare(
                            'UPDATE drivers SET card_valid_until=? WHERE id=? AND (card_valid_until IS NULL OR card_valid_until < ?)'
                        )->execute([$parsedCardValid, $linkedDriverId, $parsedCardValid]);
                    }
                } else {
                    // Auto-create driver with card number, birth date and card expiry
                    $db->prepare(
                        'INSERT INTO drivers (company_id, first_name, last_name, card_number, birth_date, card_valid_until) VALUES (?,?,?,?,?,?)'
                    )->execute([$companyId, $firstName, $lastName, $parsedCardNumber ?: null, $parsedBirthDate, $parsedCardValid]);
                    $linkedDriverId = (int)$db->lastInsertId();
                    $autoCreated = "Automatycznie utworzono kierowcę: {$firstName} {$lastName}";
                }
            }
        } elseif ($fileType === 'vehicle') {
            $reg = dddParseVehicleReg($binaryData);
            if ($reg) {
                $stmt = $db->prepare(
                    'SELECT id FROM vehicles
                     WHERE company_id=? AND is_active=1 AND UPPER(registration)=UPPER(?)
                     LIMIT 1'
                );
                $stmt->execute([$companyId, $reg]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    $linkedVehicleId = (int)$existing;
                } else {
                    // Auto-create vehicle
                    $db->prepare(
                        'INSERT INTO vehicles (company_id, registration) VALUES (?,?)'
                    )->execute([$companyId, strtoupper($reg)]);
                    $linkedVehicleId = (int)$db->lastInsertId();
                    $autoCreated = "Automatycznie utworzono pojazd: {$reg}";
                }
            }
        }

        // ── Save record to DB ─────────────────────────────────────
        $stmt = $db->prepare(
            'INSERT INTO ddd_files
             (company_id, driver_id, vehicle_id, file_type, original_name, stored_name,
              stored_subdir, file_size, file_hash, download_date, uploaded_by, card_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId,
            $linkedDriverId,
            $linkedVehicleId,
            $fileType,
            $file['name'],
            $storedName,
            $storedSubdir,
            $fileSize,
            $fileHash,
            $downloadDate ?: date('Y-m-d'),
            $userId,
            $parsedCardNumber ?: null,
        ]);
        $newFileId   = (int)$db->lastInsertId();
        $periodStart = null;
        $periodEnd   = null;

        // ── Parse activity data and persist to ddd_activity_days ─────
        try {
            $actResult = ($fileType === 'driver')
                ? parseDddFile($destPath)
                : parseVehicleDdd($destPath);

            if (!empty($actResult['days'])) {
                $dates       = array_column($actResult['days'], 'date');
                sort($dates);
                $periodStart = $dates[0];
                $periodEnd   = end($dates);

                // Update period_start / period_end in ddd_files
                $db->prepare('UPDATE ddd_files SET period_start=?, period_end=? WHERE id=?')
                   ->execute([$periodStart, $periodEnd, $newFileId]);

                // ── Record card download date using end of data range ─────
                // Use period_end (the last date with activity in the file) as the
                // "last download" date so it reflects the actual data range of the card.
                if ($fileType === 'driver' && $linkedDriverId) {
                    $effDate = $periodEnd;
                    $exists  = $db->prepare(
                        'SELECT id FROM card_downloads WHERE driver_id=? AND download_date=? LIMIT 1'
                    );
                    $exists->execute([$linkedDriverId, $effDate]);
                    if (!$exists->fetchColumn()) {
                        $db->prepare(
                            'INSERT INTO card_downloads (driver_id, download_date, performed_by) VALUES (?,?,?)'
                        )->execute([$linkedDriverId, $effDate, $userId]);
                    }
                }

                // Persist per-day activity data (driver files only – full slot data)
                if ($fileType === 'driver') {
                    $insDay = $db->prepare(
                        'INSERT IGNORE INTO ddd_activity_days
                         (file_id, date, drive_min, work_min, avail_min, rest_min, dist_km,
                          violations, segments, border_crossings)
                         VALUES (?,?,?,?,?,?,?,?,?,?)'
                    );
                    foreach ($actResult['days'] as $day) {
                        $insDay->execute([
                            $newFileId,
                            $day['date'],
                            $day['drive'] ?? 0,
                            $day['work']  ?? 0,
                            $day['avail'] ?? 0,
                            $day['rest']  ?? 0,
                            $day['dist']  ?? 0,
                            json_encode($day['viol']      ?? []),
                            json_encode($day['segs']      ?? []),
                            /* Store actual crossings JSON if found at upload time,
                             * or SQL NULL as a placeholder for days where the
                             * integrated parseDddFile call found nothing.  The NULL
                             * placeholder is immediately resolved by the backfill
                             * block below, so driver_analysis is not needed. */
                            !empty($day['crossings']) ? json_encode($day['crossings']) : null,
                        ]);
                    }

                    /* ── Immediate border-crossings backfill ───────────────────
                     * Call parseBorderCrossings directly on the saved file to
                     * resolve any NULL border_crossings rows right here at upload
                     * time.  This ensures every newly uploaded driver card has
                     * complete crossing data without requiring a driver_analysis
                     * page visit first.
                     *
                     * Days with crossings already got JSON from the INSERT above
                     * (parseDddFile's integrated call) and are not touched here
                     * (WHERE border_crossings IS NULL filter).  Days with no
                     * crossings receive the '0' sentinel (confirmed empty by the
                     * current parser). */
                    $rawDataForBC = file_get_contents($destPath);
                    if ($rawDataForBC !== false) {
                        $bcYrs = array_filter(
                            array_map(fn($d) => (int)substr($d['date'], 0, 4), $actResult['days']),
                            fn($y) => $y >= 1990
                        );
                        if ($bcYrs) {
                            $bcMin      = max(1990, max(min($bcYrs) - 1, max($bcYrs) - 2));
                            $bcMax      = max($bcYrs) + 1;
                            $uploadedBC = parseBorderCrossings($rawDataForBC, $bcMin, $bcMax);
                            $updBC      = $db->prepare(
                                'UPDATE ddd_activity_days SET border_crossings=?
                                  WHERE file_id=? AND date=? AND border_crossings IS NULL'
                            );
                            foreach ($actResult['days'] as $day) {
                                $crs     = $uploadedBC[$day['date']] ?? false;
                                $newJson = ($crs !== false && !empty($crs))
                                           ? json_encode($crs)
                                           : json_encode(0);
                                $updBC->execute([$newJson, $newFileId, $day['date']]);
                            }
                        }
                    }

                    /* ── Upsert into driver_activity_calendar ──────────────────
                     * Build a continuous per-driver activity calendar that
                     * accumulates data from every uploaded DDD card.
                     * When the same date already exists for this driver we keep
                     * whichever record has more total activity minutes (more
                     * complete data wins).  Border crossings, violations and
                     * segments are also updated when the new file has richer
                     * data. */
                    if ($linkedDriverId) {
                        // Reload the freshly-written ddd_activity_days rows so
                        // we include the resolved border_crossings values.
                        $calRows = $db->prepare(
                            'SELECT date, drive_min, work_min, avail_min, rest_min,
                                    dist_km, violations, segments, border_crossings
                             FROM ddd_activity_days WHERE file_id=? ORDER BY date'
                        );
                        $calRows->execute([$newFileId]);
                        $calData = $calRows->fetchAll(\PDO::FETCH_ASSOC);

                        $upsertCal = $db->prepare(
                            'INSERT INTO driver_activity_calendar
                               (company_id, driver_id, date, drive_min, work_min, avail_min,
                                rest_min, dist_km, violations, segments, border_crossings,
                                source_file_id)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE
                               drive_min        = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                                     > drive_min+work_min+avail_min+rest_min,
                                                     VALUES(drive_min), drive_min),
                               work_min         = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                                     > drive_min+work_min+avail_min+rest_min,
                                                     VALUES(work_min), work_min),
                               avail_min        = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                                     > drive_min+work_min+avail_min+rest_min,
                                                     VALUES(avail_min), avail_min),
                               rest_min         = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                                     > drive_min+work_min+avail_min+rest_min,
                                                     VALUES(rest_min), rest_min),
                               dist_km          = GREATEST(dist_km, VALUES(dist_km)),
                               violations       = IF(VALUES(violations) IS NOT NULL
                                                     AND VALUES(violations) != \'[]\',
                                                     VALUES(violations), violations),
                               segments         = IF(VALUES(segments) IS NOT NULL
                                                     AND VALUES(segments) != \'[]\',
                                                     VALUES(segments), segments),
                               border_crossings = IF(VALUES(border_crossings) IS NOT NULL
                                                     AND VALUES(border_crossings) NOT IN (\'0\',\'[]\',\'null\',\'false\'),
                                                     VALUES(border_crossings), border_crossings),
                               source_file_id   = IF(VALUES(drive_min)+VALUES(work_min)+VALUES(avail_min)+VALUES(rest_min)
                                                     > drive_min+work_min+avail_min+rest_min,
                                                     VALUES(source_file_id), source_file_id)'
                        );

                        foreach ($calData as $row) {
                            $upsertCal->execute([
                                $companyId,
                                $linkedDriverId,
                                $row['date'],
                                (int)$row['drive_min'],
                                (int)$row['work_min'],
                                (int)$row['avail_min'],
                                (int)$row['rest_min'],
                                (int)$row['dist_km'],
                                $row['violations']       ?? json_encode([]),
                                $row['segments']         ?? json_encode([]),
                                $row['border_crossings'] ?? null,
                                $newFileId,
                            ]);
                        }
                    }
                }
            }
        } catch (Throwable $actErr) {
            // Non-fatal: activity parsing failed but the file itself was saved
            error_log('DDD activity parse error (file_id=' . $newFileId . '): ' . $actErr->getMessage());
        }

        // ── Fallback card download record (when activity parse found no days) ─
        // If the activity parse succeeded with period_end, the card_download was
        // already inserted inside the try block above. Insert here only when no
        // period_end was determined (parse failed or file had no activity rows).
        if ($fileType === 'driver' && $linkedDriverId) {
            $hasDownload = $db->prepare(
                'SELECT id FROM card_downloads WHERE driver_id=? LIMIT 1'
            );
            $hasDownload->execute([$linkedDriverId]);
            if (!$hasDownload->fetchColumn()) {
                $effDate = $downloadDate ?: date('Y-m-d');
                $db->prepare(
                    'INSERT IGNORE INTO card_downloads (driver_id, download_date, performed_by) VALUES (?,?,?)'
                )->execute([$linkedDriverId, $effDate, $userId]);
            }
        }

        $msg = 'Plik został wgrany do archiwum.';
        if ($autoCreated) $msg .= ' ' . $autoCreated . '.';

        echo json_encode([
            'success'      => true,
            'message'      => $msg,
            'driver_id'    => $linkedDriverId,
            'vehicle_id'   => $linkedVehicleId,
            'card_number'  => $parsedCardNumber,
            'auto_created' => $autoCreated,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
        ]);
    } catch (Throwable $e) {
        // Clean up partially saved file if something went wrong after saving
        if (!empty($destPath) && is_file($destPath)) {
            @unlink($destPath);
        }
        http_response_code(500);
        echo json_encode(['error' => 'Błąd podczas zapisywania pliku. Spróbuj ponownie.']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// Delete
// ══════════════════════════════════════════════════════════════
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Nieprawidłowy token CSRF.']); exit;
    }
    if (!hasRole('manager')) {
        echo json_encode(['error' => 'Brak uprawnień.']); exit;
    }

    $fileId = (int)($_POST['file_id'] ?? 0);
    if (!$fileId) {
        echo json_encode(['error' => 'Nieprawidłowy identyfikator pliku.']); exit;
    }

    try {
        $stmt = $db->prepare('SELECT * FROM ddd_files WHERE id=? AND company_id=? AND is_deleted=0');
        $stmt->execute([$fileId, $companyId]);
        $f = $stmt->fetch();

        if (!$f) {
            echo json_encode(['error' => 'Plik nie został znaleziony.']); exit;
        }

        $db->prepare('UPDATE ddd_files SET is_deleted=1, deleted_at=NOW() WHERE id=?')
           ->execute([$fileId]);

        $physPath = dddPhysPath($f, $companyId);
        if (is_file($physPath)) {
            @unlink($physPath);
        }

        echo json_encode(['success' => true, 'message' => 'Plik usunięty z archiwum.']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Błąd podczas usuwania pliku. Spróbuj ponownie.']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// Download
// ══════════════════════════════════════════════════════════════
if ($action === 'download' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $csrf   = $_GET['csrf'] ?? '';
    $fileId = (int)($_GET['id'] ?? 0);

    if (!validateCsrf($csrf)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Nieprawidłowy token CSRF.']); exit;
    }

    try {
        $stmt = $db->prepare('SELECT * FROM ddd_files WHERE id=? AND company_id=? AND is_deleted=0');
        $stmt->execute([$fileId, $companyId]);
        $f = $stmt->fetch();

        if (!$f) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Plik nie został znaleziony.']); exit;
        }

        $physPath = dddPhysPath($f, $companyId);
        if (!is_file($physPath)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Plik fizyczny nie istnieje.']); exit;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($f['original_name']) . '"');
        header('Content-Length: ' . filesize($physPath));
        header('Cache-Control: no-store');
        readfile($physPath);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Błąd podczas pobierania pliku.']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════════
// Preview (parse in-memory, do NOT save – returns compliance info)
// ══════════════════════════════════════════════════════════════
if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Nieprawidłowy token CSRF.']); exit;
    }
    if (!hasRole('manager')) {
        echo json_encode(['error' => 'Brak uprawnień.']); exit;
    }

    $fileType = $_POST['file_type'] ?? '';
    if (!in_array($fileType, ['driver', 'vehicle'], true)) {
        echo json_encode(['error' => 'Nieprawidłowy typ pliku.']); exit;
    }

    if (empty($_FILES['ddd_file']) || $_FILES['ddd_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE  => 'Plik przekracza limit rozmiaru serwera.',
            UPLOAD_ERR_FORM_SIZE => 'Plik przekracza dozwolony rozmiar.',
            UPLOAD_ERR_PARTIAL   => 'Plik został przesłany częściowo.',
            UPLOAD_ERR_NO_FILE   => 'Nie wybrano pliku.',
        ];
        $errCode = $_FILES['ddd_file']['error'] ?? -1;
        echo json_encode(['error' => $uploadErrors[$errCode] ?? 'Błąd przesyłania pliku.']);
        exit;
    }

    $file = $_FILES['ddd_file'];
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['error' => 'Plik jest za duży (maks. 10 MB).']); exit;
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['ddd', 'c1b', 'tgd'], true)) {
        echo json_encode(['error' => 'Nieobsługiwany format pliku (.ddd, .c1b, .tgd).']); exit;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        echo json_encode(['error' => 'Błąd bezpieczeństwa – plik nie został prawidłowo przesłany.']); exit;
    }

    try {
        $binaryData = file_get_contents($file['tmp_name']);
        if ($binaryData === false || strlen($binaryData) < 100) {
            echo json_encode(['ok' => false, 'issues' => ['Plik jest zbyt mały lub nie można go odczytać.'], 'file_size' => $file['size']]); exit;
        }

        $issues   = [];   // compliance problems – non-empty means invalid
        $warnings = [];   // advisory notices that do not block upload
        $info     = [];   // extracted data fields for display

        $info['file_name'] = $file['name'];
        $info['file_size'] = $file['size'];
        $info['file_type'] = $fileType;

        // ── Duplicate detection ──────────────────────────────────────
        $fileHash = hash('sha256', $binaryData);
        $dupStmt  = $db->prepare(
            'SELECT id FROM ddd_files WHERE company_id=? AND file_hash=? AND is_deleted=0 LIMIT 1'
        );
        $dupStmt->execute([$companyId, $fileHash]);
        if ($dupStmt->fetchColumn()) {
            $warnings[] = 'Ten plik został już wcześniej wgrany do archiwum (duplikat).';
            $info['is_duplicate'] = true;
        }

        if ($fileType === 'driver') {
            // ── Parse driver name + card number ────────────────────
            $parsed = dddParseDriverInfo($binaryData);

            if ($parsed) {
                $info['last_name']   = $parsed['last_name'];
                $info['first_name']  = $parsed['first_name'];
                $info['card_number'] = $parsed['card_number'];

                // Validate name characters (A-Z, a-z, Polish diacritics, hyphen, space)
                $namePattern = '/^[\p{L}\s\-]+$/u';
                if (!preg_match($namePattern, $parsed['last_name'])) {
                    $warnings[] = 'Nazwisko zawiera nieoczekiwane znaki: "' . $parsed['last_name'] . '"';
                }
                if (!preg_match($namePattern, $parsed['first_name'])) {
                    $warnings[] = 'Imię zawiera nieoczekiwane znaki: "' . $parsed['first_name'] . '"';
                }
                if (!empty($parsed['card_number'])) {
                    if (!preg_match('/^[A-Z0-9]{8,16}$/i', $parsed['card_number'])) {
                        $warnings[] = 'Numer karty ma nieoczekiwany format: "' . $parsed['card_number'] . '"';
                    }
                } else {
                    $warnings[] = 'Nie znaleziono numeru karty kierowcy w pliku.';
                }

                // Check if driver already exists in this company
                $stmt = $db->prepare(
                    'SELECT id, first_name, last_name, card_number FROM drivers
                     WHERE company_id=? AND is_active=1
                       AND (
                         (? <> \'\' AND card_number=?)
                         OR (LOWER(last_name)=LOWER(?) AND LOWER(first_name)=LOWER(?))
                       )
                     LIMIT 1'
                );
                $stmt->execute([$companyId, $parsed['card_number'], $parsed['card_number'],
                                $parsed['last_name'], $parsed['first_name']]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $info['existing_driver_id']   = (int)$existing['id'];
                    $info['existing_driver_name'] = $existing['first_name'] . ' ' . $existing['last_name'];
                    $info['action_hint']          = 'linked';
                } else {
                    $info['action_hint'] = 'auto_create';
                }
            } else {
                $warnings[] = 'Nie można automatycznie odczytać danych kierowcy z pliku. Plik zostanie wgrany do archiwum, ale nie zostanie automatycznie przypisany do kierowcy.';
            }

            // ── Parse activity data ────────────────────────────────
            $actResult = parseDddFile($file['tmp_name']);
            if (empty($actResult['error']) && !empty($actResult['days'])) {
                $dates = array_column($actResult['days'], 'date');
                sort($dates);
                $info['period_start']  = $dates[0];
                $info['period_end']    = end($dates);
                $info['day_count']     = count($actResult['days']);
                $info['drive_total_h'] = round($actResult['summary']['drive'] / 60, 1);
                $info['violations']    = count($actResult['summary']['violations']);
                if ($info['violations'] > 0) {
                    $warnings[] = 'Plik zawiera ' . $info['violations'] . ' naruszeń przepisów UE dotyczących czasu jazdy.';
                }
            } else {
                $warnings[] = 'Nie znaleziono rekordów aktywności w pliku (plik może być pusty lub mieć inny format).';
            }

        } else {
            // ── Parse vehicle registration ─────────────────────────
            $reg = dddParseVehicleReg($binaryData);

            if ($reg) {
                $info['registration'] = $reg;

                // Check if vehicle already exists
                $stmt = $db->prepare(
                    'SELECT id, registration FROM vehicles
                     WHERE company_id=? AND is_active=1 AND UPPER(registration)=UPPER(?)
                     LIMIT 1'
                );
                $stmt->execute([$companyId, $reg]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $info['existing_vehicle_id']  = (int)$existing['id'];
                    $info['existing_vehicle_reg'] = $existing['registration'];
                    $info['action_hint']          = 'linked';
                } else {
                    $info['action_hint'] = 'auto_create';
                }
            } else {
                $warnings[] = 'Nie można automatycznie odczytać numeru rejestracyjnego pojazdu. Plik zostanie wgrany do archiwum, ale nie zostanie automatycznie przypisany do pojazdu.';
            }

            // ── Parse vehicle activity data ────────────────────────
            $actResult = parseVehicleDdd($file['tmp_name']);
            if (empty($actResult['error']) && !empty($actResult['days'])) {
                $dates = array_column($actResult['days'], 'date');
                sort($dates);
                $info['period_start'] = $dates[0];
                $info['period_end']   = end($dates);
                $info['day_count']    = count($actResult['days']);
                $info['total_km']     = $actResult['summary']['total_km'] ?? 0;
            } else {
                $warnings[] = 'Nie znaleziono rekordów aktywności pojazdu w pliku.';
            }
        }

        echo json_encode([
            'ok'       => empty($issues),
            'issues'   => $issues,
            'warnings' => $warnings,
            'info'     => $info,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Błąd podczas analizy pliku: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Nieznana akcja.']);