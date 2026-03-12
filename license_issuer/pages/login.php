<?php
/**
 * License Issuer – Login page
 */
$pageTitle = 'Logowanie';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username && $password) {
        if (liLogin($username, $password)) {
            header('Location: index.php?page=dashboard');
            exit;
        } else {
            $error = 'Nieprawidłowy login lub hasło. Wymagana rola: superadmin.';
        }
    } else {
        $error = 'Wpisz login i hasło.';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Logowanie – TachoPro License Issuer</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/issuer.css">
  <style>
    body { display: flex; flex-direction: column; min-height: 100vh; background: #0f172a; }
    .li-login-outer { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem; }
  </style>
</head>
<body style="background:#0f172a">

<header class="li-topbar d-flex align-items-center px-4 gap-3">
  <div class="li-brand d-flex align-items-center gap-2">
    <span class="li-brand-icon"><i class="bi bi-shield-lock-fill"></i></span>
    <span class="li-brand-name fw-bold">TachoPro <span class="text-warning">License Issuer</span></span>
  </div>
  <div class="flex-grow-1"></div>
  <small class="text-secondary">v<?= LI_VERSION ?> · <?= LI_PUBLISHED ?></small>
</header>

<div class="li-login-outer">
  <div class="li-login-box">
    <div class="li-card">
      <div class="li-card-body">
        <div class="text-center mb-4">
          <div class="li-brand-icon mx-auto mb-3">
            <i class="bi bi-shield-lock-fill"></i>
          </div>
          <h4 class="fw-bold mb-1">License Issuer</h4>
          <small class="text-muted">Zaloguj się jako superadmin TachoPro</small>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= liE($error) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <div class="mb-3">
            <label class="form-label fw-600">Login</label>
            <input type="text" name="username" class="form-control" required
                   autofocus autocomplete="username"
                   value="<?= liE($_POST['username'] ?? '') ?>">
          </div>
          <div class="mb-4">
            <label class="form-label fw-600">Hasło</label>
            <input type="password" name="password" class="form-control" required autocomplete="current-password">
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-1"></i>Zaloguj się
          </button>
        </form>
      </div>
    </div>
    <p class="text-center text-muted small mt-3">
      Tylko użytkownicy z rolą <strong>superadmin</strong> mogą korzystać z tej aplikacji.
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
