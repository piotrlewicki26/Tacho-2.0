<?php
/**
 * TachoPro 2.0 – Login page
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSession();

// If already logged in, redirect to dashboard
if (!empty($_SESSION['user_id'])) {
    redirect('/dashboard.php');
}

$error    = '';
$expired  = !empty($_GET['expired']);
$csrf     = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token. Odśwież stronę i spróbuj ponownie.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$username || !$password) {
            $error = 'Podaj login i hasło.';
        } elseif (isLockedOut(getClientIp(), $username)) {
            $error = 'Zbyt wiele nieudanych prób logowania. Konto tymczasowo zablokowane na ' . LOCKOUT_MINUTES . ' minut.';
        } else {
            $user = attemptLogin($username, $password);
            if ($user) {
                redirect('/dashboard.php');
            } else {
                $error = 'Nieprawidłowy login lub hasło.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Logowanie – TachoPro 2.0</title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="tp-login-bg">

<div class="tp-login-card">
  <!-- Logo -->
  <div class="text-center mb-4">
    <div class="tp-login-logo">
      <i class="bi bi-speedometer2"></i>
    </div>
    <h2 class="fw-bold mb-0">TachoPro 2.0</h2>
    <p class="text-muted small">System zarządzania czasem pracy kierowców</p>
  </div>

  <?php if ($expired): ?>
  <div class="alert alert-warning py-2 small">
    <i class="bi bi-clock-history me-1"></i>Sesja wygasła. Zaloguj się ponownie.
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-danger py-2 small">
    <i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?>
  </div>
  <?php endif; ?>

  <form method="POST" autocomplete="off" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

    <div class="mb-3">
      <label class="form-label fw-600">Login</label>
      <div class="input-group">
        <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
        <input type="text" name="username" class="form-control" required
               placeholder="Wpisz login"
               value="<?= e(htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES)) ?>"
               maxlength="100" autofocus autocomplete="username">
      </div>
    </div>

    <div class="mb-4">
      <label class="form-label fw-600">Hasło</label>
      <div class="input-group">
        <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
        <input type="password" name="password" id="passwordInput" class="form-control" required
               placeholder="Wpisz hasło" maxlength="255" autocomplete="current-password">
        <button type="button" class="btn btn-outline-secondary" id="togglePassword" tabindex="-1">
          <i class="bi bi-eye" id="toggleIcon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
      <i class="bi bi-box-arrow-in-right me-2"></i>Zaloguj się
    </button>
  </form>

  <div class="text-center mt-4">
    <small class="text-muted">
      &copy; <?= date('Y') ?> TachoPro 2.0 &mdash; Wszelkie prawa zastrzeżone
    </small>
    <div class="mt-2">
      <small>
        Nowa firma?
        <a href="/register.php" class="text-decoration-none fw-600">
          Zarejestruj się – 14 dni bezpłatnie
        </a>
      </small>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.getElementById('togglePassword')?.addEventListener('click', function () {
    const inp  = document.getElementById('passwordInput');
    const icon = document.getElementById('toggleIcon');
    if (inp.type === 'password') {
      inp.type = 'text';
      icon.className = 'bi bi-eye-slash';
    } else {
      inp.type = 'password';
      icon.className = 'bi bi-eye';
    }
  });
</script>
</body>
</html>
