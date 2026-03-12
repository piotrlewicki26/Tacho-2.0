<?php
/**
 * TachoPro 2.0 – Setup wizard
 * Creates the DB schema and the first superadmin user.
 * Should be removed / protected after first run.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token CSRF. Odśwież stronę.';
    } else {
        $companyName = trim($_POST['company_name'] ?? '');
        $adminUser   = trim($_POST['admin_user']   ?? '');
        $adminPass   = $_POST['admin_pass']   ?? '';
        $adminPass2  = $_POST['admin_pass2']  ?? '';
        $adminEmail  = trim($_POST['admin_email']  ?? '');

        if (!$companyName || !$adminUser || !$adminPass || !$adminEmail) {
            $error = 'Wszystkie pola są wymagane.';
        } elseif (strlen($adminPass) < 10) {
            $error = 'Hasło musi mieć co najmniej 10 znaków.';
        } elseif ($adminPass !== $adminPass2) {
            $error = 'Hasła nie są zgodne.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Nieprawidłowy adres e-mail.';
        } else {
            try {
                $db = getDB();

                // Run schema
                $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
                $db->exec($sql);

                // Check if already set up
                $count = (int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn();
                if ($count > 0) {
                    $error = 'System jest już skonfigurowany. Usuń plik setup.php ze serwera.';
                } else {
                    // Create company
                    $uniqueCode = generateCompanyCode();
                    $stmt = $db->prepare(
                        'INSERT INTO companies (name, unique_code) VALUES (?, ?)'
                    );
                    $stmt->execute([$companyName, $uniqueCode]);
                    $companyId = (int)$db->lastInsertId();

                    // Create admin user
                    $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $db->prepare(
                        'INSERT INTO users (company_id, username, email, password_hash, role) VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$companyId, $adminUser, $adminEmail, $hash, 'superadmin']);

                    // Create default license (core only, 1 year)
                    $licKey = generateLicenseKey($uniqueCode, ['core'], date('Y-m-d', strtotime('+1 year')));
                    $stmt = $db->prepare(
                        'INSERT INTO licenses (company_id, license_key, mod_core, valid_from, valid_until) VALUES (?, ?, 1, CURDATE(), ?)'
                    );
                    $stmt->execute([$companyId, $licKey, date('Y-m-d', strtotime('+1 year'))]);

                    $success = 'System został skonfigurowany pomyślnie. Zaloguj się używając utworzonych danych.';
                }
            } catch (Exception $e) {
                error_log('Setup error: ' . $e->getMessage());
                $error = 'Błąd instalacji: ' . e($e->getMessage());
            }
        }
    }
}

$csrf = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Instalacja – TachoPro 2.0</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="tp-setup-bg">
<div class="tp-setup-card">
  <div class="text-center mb-4">
    <div class="tp-login-logo">
      <i class="bi bi-speedometer2"></i>
    </div>
    <h2 class="fw-700 mb-0">TachoPro 2.0</h2>
    <p class="text-muted">Kreator instalacji</p>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success">
    <i class="bi bi-check-circle me-2"></i><?= e($success) ?>
    <hr>
    <a href="/login.php" class="btn btn-success btn-sm">Przejdź do logowania</a>
  </div>
  <?php else: ?>

  <?php if ($error): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <h5 class="mb-3"><i class="bi bi-building me-2"></i>Dane firmy</h5>
    <div class="mb-3">
      <label class="form-label fw-600">Nazwa firmy <span class="text-danger">*</span></label>
      <input type="text" name="company_name" class="form-control" required
             value="<?= e($_POST['company_name'] ?? '') ?>" maxlength="255">
    </div>

    <h5 class="mb-3 mt-4"><i class="bi bi-person-badge me-2"></i>Konto administratora</h5>
    <div class="mb-3">
      <label class="form-label fw-600">Login <span class="text-danger">*</span></label>
      <input type="text" name="admin_user" class="form-control" required
             value="<?= e($_POST['admin_user'] ?? '') ?>" maxlength="100" autocomplete="off">
    </div>
    <div class="mb-3">
      <label class="form-label fw-600">E-mail <span class="text-danger">*</span></label>
      <input type="email" name="admin_email" class="form-control" required
             value="<?= e($_POST['admin_email'] ?? '') ?>" maxlength="255">
    </div>
    <div class="row g-3 mb-3">
      <div class="col">
        <label class="form-label fw-600">Hasło <span class="text-danger">*</span></label>
        <input type="password" name="admin_pass" class="form-control" required minlength="10" autocomplete="new-password">
        <div class="form-text">Min. 10 znaków</div>
      </div>
      <div class="col">
        <label class="form-label fw-600">Powtórz hasło <span class="text-danger">*</span></label>
        <input type="password" name="admin_pass2" class="form-control" required autocomplete="new-password">
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-100 mt-2">
      <i class="bi bi-check2-circle me-2"></i>Zainstaluj TachoPro 2.0
    </button>
  </form>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
