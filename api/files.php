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
if (!hasModule('core')) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Brak dostępu. Wymagany aktywny abonament PRO lub PRO Module+.']);
    exit;
}

// Discard any stray output that may have accumulated
while (ob_get_level() > 0) ob_end_clean();

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// ══════════════════════════════════════════════════════════════
// DDD binary helpers (ported from truck-delegate-pro.jsx)
// ══════════════════════════════════════════════════════════════

/**
 * Read a printable-ASCII string of up to $n bytes from binary $data at $offset.
 * Non-printable bytes become null characters (later trimmed).
 */
function dddReadStr(string $data, int $offset, int $n): string {
    $len = strlen($data);
    $out = '';
    for ($k = 0; $k < $n && $offset + $k < $len; $k++) {
        $b = ord($data[$offset + $k]);
        $out .= ($b >= 32 && $b < 127) ? chr($b) : "\0";
    }
    return $out;
}

/**
 * Parse driver surname + first name from a driver DDD file.
 * Returns ['last_name'=>…,'first_name'=>…] or null on failure.
 */
function dddParseDriverName(string $data): ?array {
    $len = strlen($data);
    for ($i = 0; $i < $len - 4; $i++) {
        // Look for tag 0x05 0x20 (EF_Identification-like marker)
        if (ord($data[$i]) !== 0x05 || ord($data[$i + 1]) !== 0x20) continue;
        // Block length (big-endian uint16)
        $bl = (ord($data[$i + 2]) << 8) | ord($data[$i + 3]);
        if ($bl < 40 || $bl > 3000 || $i + 4 + $bl > $len) continue;
        for ($k = 0; $k < $bl - 72; $k++) {
            $b = ord($data[$i + 4 + $k]);
            if ($b < 65 || $b > 90) continue;         // must start with A-Z
            $sn = str_replace("\0", '', dddReadStr($data, $i + 4 + $k,      36));
            $fn = str_replace("\0", '', dddReadStr($data, $i + 4 + $k + 36, 36));
            $sn = trim($sn);
            $fn = trim($fn);
            // Surname: ≥3 chars, starts with uppercase then lowercase
            if (strlen($sn) >= 3 && preg_match('/^[A-Z][a-z]{2}/', $sn) && strlen($fn) >= 2) {
                return ['last_name' => $sn, 'first_name' => $fn];
            }
        }
        if (isset($sn) && $sn) break;  // found block, no point continuing
    }
    return null;
}

/**
 * Parse vehicle registration plate from a vehicle DDD file.
 * Returns the registration string or null.
 */
function dddParseVehicleReg(string $data): ?string {
    $len = strlen($data);
    for ($i = 0; $i < $len - 14; $i++) {
        $s = trim(str_replace("\0", '', dddReadStr($data, $i, 14)));
        if (preg_match('/^[A-Z]{2,4}\s[A-Z0-9]{4,6}$/', $s)) {
            return $s;
        }
    }
    return null;
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

    try {
        // ── Store physical file ───────────────────────────────────
        $uploadDir = __DIR__ . '/../uploads/ddd/' . $companyId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            echo json_encode(['error' => 'Nie można utworzyć katalogu na serwerze.']); exit;
        }

        $storedName = date('Ymd_His_') . bin2hex(random_bytes(6)) . '.' . $ext;
        $destPath   = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['error' => 'Nie można zapisać pliku na serwerze.']); exit;
        }

        $fileHash = hash_file('sha256', $destPath);
        $fileSize = filesize($destPath);

        // ── Parse binary content for auto-create ─────────────────
        $binaryData      = file_get_contents($destPath);
        $linkedDriverId  = null;
        $linkedVehicleId = null;
        $autoCreated     = null;   // info message back to UI

        if ($fileType === 'driver') {
            $parsed = dddParseDriverName($binaryData);
            if ($parsed) {
                $lastName  = $parsed['last_name'];
                $firstName = $parsed['first_name'];

                // Try to find existing driver by name (exact, case-insensitive)
                $stmt = $db->prepare(
                    'SELECT id FROM drivers
                     WHERE company_id=? AND is_active=1
                       AND LOWER(last_name)=LOWER(?) AND LOWER(first_name)=LOWER(?)
                     LIMIT 1'
                );
                $stmt->execute([$companyId, $lastName, $firstName]);
                $existing = $stmt->fetchColumn();

                if ($existing) {
                    $linkedDriverId = (int)$existing;
                } else {
                    // Auto-create driver
                    $db->prepare(
                        'INSERT INTO drivers (company_id, first_name, last_name) VALUES (?,?,?)'
                    )->execute([$companyId, $firstName, $lastName]);
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
              file_size, file_hash, download_date, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId,
            $linkedDriverId,
            $linkedVehicleId,
            $fileType,
            $file['name'],
            $storedName,
            $fileSize,
            $fileHash,
            $downloadDate ?: date('Y-m-d'),
            $userId,
        ]);

        $msg = 'Plik został wgrany do archiwum.';
        if ($autoCreated) $msg .= ' ' . $autoCreated . '.';

        echo json_encode([
            'success'      => true,
            'message'      => $msg,
            'driver_id'    => $linkedDriverId,
            'vehicle_id'   => $linkedVehicleId,
            'auto_created' => $autoCreated,
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

        $physPath = __DIR__ . '/../uploads/ddd/' . $companyId . '/' . $f['stored_name'];
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

        $physPath = __DIR__ . '/../uploads/ddd/' . $companyId . '/' . $f['stored_name'];
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

http_response_code(400);
echo json_encode(['error' => 'Nieznana akcja.']);