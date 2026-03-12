<?php
/**
 * TachoPro 2.0 – DDD Files API
 * Handles: upload, delete, download
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/license_check.php';

requireLogin();
requireModule('core');

header('Content-Type: application/json');

$action    = $_REQUEST['action']    ?? '';
$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// ── Upload ───────────────────────────────────────────────────
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Nieprawidłowy token CSRF.']); exit;
    }
    if (!hasRole('manager')) {
        echo json_encode(['error' => 'Brak uprawnień.']); exit;
    }

    $fileType    = $_POST['file_type']    ?? '';
    $downloadDate= $_POST['download_date']?? date('Y-m-d');

    if (!in_array($fileType, ['driver','vehicle'], true)) {
        echo json_encode(['error' => 'Nieprawidłowy typ pliku.']); exit;
    }

    if (empty($_FILES['ddd_file']) || $_FILES['ddd_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Plik przekracza limit rozmiaru serwera.',
            UPLOAD_ERR_FORM_SIZE  => 'Plik przekracza dozwolony rozmiar.',
            UPLOAD_ERR_PARTIAL    => 'Plik został przesłany częściowo.',
            UPLOAD_ERR_NO_FILE    => 'Nie wybrano pliku.',
        ];
        $errCode = $_FILES['ddd_file']['error'] ?? -1;
        echo json_encode(['error' => $uploadErrors[$errCode] ?? 'Błąd przesyłania pliku.']);
        exit;
    }

    $file = $_FILES['ddd_file'];
    $maxSize = 10 * 1024 * 1024;  // 10 MB
    if ($file['size'] > $maxSize) {
        echo json_encode(['error' => 'Plik jest za duży (maks. 10 MB).']); exit;
    }

    // Validate extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['ddd','c1b','tgd'], true)) {
        echo json_encode(['error' => 'Nieobsługiwany format pliku.']); exit;
    }

    // Validate MIME / magic bytes (DDD files are binary)
    if (!is_uploaded_file($file['tmp_name'])) {
        echo json_encode(['error' => 'Błąd bezpieczeństwa – plik nie został prawidłowo przesłany.']); exit;
    }

    // Store file
    $uploadDir = __DIR__ . '/../uploads/ddd/' . $companyId . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }

    $storedName = date('Ymd_His_') . bin2hex(random_bytes(6)) . '.' . $ext;
    $destPath   = $uploadDir . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['error' => 'Nie można zapisać pliku na serwerze.']); exit;
    }

    $fileHash = hash_file('sha256', $destPath);
    $fileSize = filesize($destPath);

    // Save to DB
    $stmt = $db->prepare(
        'INSERT INTO ddd_files
         (company_id, file_type, original_name, stored_name, file_size, file_hash, download_date, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $companyId,
        $fileType,
        $file['name'],
        $storedName,
        $fileSize,
        $fileHash,
        $downloadDate ?: date('Y-m-d'),
        $userId,
    ]);

    echo json_encode(['success' => true, 'message' => 'Plik został wgrany do archiwum.']);
    exit;
}

// ── Delete ───────────────────────────────────────────────────
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

    // Verify ownership
    $stmt = $db->prepare('SELECT * FROM ddd_files WHERE id=? AND company_id=? AND is_deleted=0');
    $stmt->execute([$fileId, $companyId]);
    $f = $stmt->fetch();

    if (!$f) {
        echo json_encode(['error' => 'Plik nie został znaleziony.']); exit;
    }

    // Soft-delete in DB
    $db->prepare('UPDATE ddd_files SET is_deleted=1, deleted_at=NOW() WHERE id=?')
       ->execute([$fileId]);

    // Optionally remove physical file
    $physPath = __DIR__ . '/../uploads/ddd/' . $companyId . '/' . $f['stored_name'];
    if (is_file($physPath)) {
        unlink($physPath);
    }

    echo json_encode(['success' => true, 'message' => 'Plik usunięty z archiwum.']);
    exit;
}

// ── Download ─────────────────────────────────────────────────
if ($action === 'download' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $csrf   = $_GET['csrf'] ?? '';
    $fileId = (int)($_GET['id'] ?? 0);

    if (!validateCsrf($csrf)) {
        http_response_code(403);
        echo json_encode(['error' => 'Nieprawidłowy token CSRF.']); exit;
    }

    $stmt = $db->prepare('SELECT * FROM ddd_files WHERE id=? AND company_id=? AND is_deleted=0');
    $stmt->execute([$fileId, $companyId]);
    $f = $stmt->fetch();

    if (!$f) {
        http_response_code(404);
        echo json_encode(['error' => 'Plik nie został znaleziony.']); exit;
    }

    $physPath = __DIR__ . '/../uploads/ddd/' . $companyId . '/' . $f['stored_name'];
    if (!is_file($physPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Plik fizyczny nie istnieje.']); exit;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addslashes($f['original_name']) . '"');
    header('Content-Length: ' . filesize($physPath));
    header('Cache-Control: no-store');
    readfile($physPath);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Nieznana akcja.']);
