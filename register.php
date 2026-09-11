<?php
/**
 * TachoPro 2.0 – Company self-registration
 * Creates a new company with a 14-day demo account.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/subscription.php';
require_once __DIR__ . '/includes/audit.php';

startSession();

// Already logged in → dashboard
if (!empty($_SESSION['user_id'])) {
    redirect('/dashboard.php');
}

$error   = '';
$success = '';
$csrf    = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Nieprawidłowy token. Odśwież stronę i spróbuj ponownie.';
    } else {
        $companyName = trim($_POST['company_name'] ?? '');
        $nip         = trim($_POST['nip']          ?? '');
        $address     = trim($_POST['address']      ?? '');
        $email       = trim($_POST['email']        ?? '');
        $phone       = trim($_POST['phone']        ?? '');
        $adminLogin  = trim($_POST['admin_login']  ?? '');
        $adminEmail  = trim($_POST['admin_email']  ?? '');
        $adminPass   = $_POST['admin_pass']        ?? '';
        $adminPass2  = $_POST['admin_pass2']       ?? '';
        $agree       = !empty($_POST['agree']);

        // Validation
        if (!$companyName) {
            $error = 'Podaj nazwę firmy.';
        } elseif (!$adminLogin || strlen($adminLogin) < 3) {
            $error = 'Login administratora musi mieć co najmniej 3 znaki.';
        } elseif ($adminEmail && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Nieprawidłowy adres e-mail administratora.';
        } elseif (strlen($adminPass) < 10) {
            $error = 'Hasło musi mieć co najmniej 10 znaków.';
        } elseif ($adminPass !== $adminPass2) {
            $error = 'Podane hasła nie są zgodne.';
        } elseif (!$agree) {
            $error = 'Musisz zaakceptować regulamin.';
        } else {
            $db = getDB();

            // Check username uniqueness
            $stmt = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$adminLogin]);
            if ($stmt->fetch()) {
                $error = 'Login "' . e($adminLogin) . '" jest już zajęty. Wybierz inny.';
            } else {
                try {
                    $db->beginTransaction();

                    // 1. Create company
                    $uniqueCode  = generateCompanyCode();
                    $trialEndsAt = date('Y-m-d', strtotime('+' . DEMO_DAYS . ' days'));
                    $db->prepare(
                        'INSERT INTO companies
                         (name, address, nip, email, phone, unique_code, plan, trial_ends_at)
                         VALUES (?,?,?,?,?,?,?,?)'
                    )->execute([$companyName, $address ?: null, $nip ?: null,
                                $email ?: null, $phone ?: null, $uniqueCode,
                                'demo', $trialEndsAt]);
                    $companyId = (int)$db->lastInsertId();

                    // 2. Create admin user
                    $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $db->prepare(
                        'INSERT INTO users
                         (company_id, username, email, password_hash, role)
                         VALUES (?,?,?,?,?)'
                    )->execute([$companyId, $adminLogin, $adminEmail ?: null, $hash, 'admin']);

                    $db->commit();

                    // Audit (system-level, no session yet)
                    try {
                        $db->prepare(
                            'INSERT INTO audit_log
                             (company_id, action, entity_type, entity_id, description, ip_address)
                             VALUES (?,?,?,?,?,?)'
                        )->execute([$companyId, 'create', 'company', $companyId,
                                    'Rejestracja firmy: ' . $companyName,
                                    filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0']);
                    } catch (Throwable $_) {}

                    $success = 'Firma "' . e($companyName) . '" została zarejestrowana. '
                             . 'Masz ' . DEMO_DAYS . ' dni na bezpłatne testy (maks. '
                             . DEMO_MAX_DRIVERS . ' kierowców i ' . DEMO_MAX_VEHICLES . ' pojazdów). '
                             . 'Zaloguj się poniżej.';
                    $csrf = getCsrfToken(); // regenerate after success

                } catch (PDOException $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('Register error: ' . $e->getMessage());
                    $error = 'Błąd rejestracji. Spróbuj ponownie lub skontaktuj się z pomocą techniczną.';
                }
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
  <title>Rejestracja – TachoPro 2.0</title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%); min-height:100vh;">

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-8">

      <!-- Header card -->
      <div class="text-center mb-4 text-white">
        <div class="tp-login-logo mx-auto mb-3">
          <i class="bi bi-speedometer2"></i>
        </div>
        <h2 class="fw-bold mb-1">TachoPro 2.0</h2>
        <p class="opacity-75 mb-0">Zarejestruj firmę – bezpłatny okres próbny <?= DEMO_DAYS ?> dni</p>
      </div>

      <!-- Demo info banner -->
      <div class="alert alert-info d-flex align-items-start gap-3 mb-4">
        <i class="bi bi-gift fs-4 flex-shrink-0 text-primary mt-1"></i>
        <div>
          <strong>Bezpłatny okres próbny</strong> – <?= DEMO_DAYS ?> dni, bez karty płatniczej!<br>
          <small class="text-muted">
            Demo: maks. <?= DEMO_MAX_DRIVERS ?> kierowców i <?= DEMO_MAX_VEHICLES ?> pojazdów.
            Po przejściu na plan Pro: <strong>15 zł netto/kierowca</strong>
            i <strong>10 zł netto/pojazd</strong> miesięcznie.
          </small>
        </div>
      </div>

      <div class="tp-setup-card" style="max-width:100%">

        <?php if ($success): ?>
        <div class="alert alert-success">
          <i class="bi bi-check-circle me-2"></i><?= $success ?>
        </div>
        <div class="text-center">
          <a href="/login.php" class="btn btn-primary">
            <i class="bi bi-box-arrow-in-right me-1"></i>Zaloguj się
          </a>
        </div>
        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2">
          <i class="bi bi-exclamation-triangle me-1"></i><?= e($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

          <h5 class="fw-bold mb-3 border-bottom pb-2">
            <i class="bi bi-building me-2 text-primary"></i>Dane firmy
          </h5>
          <div class="row g-3 mb-4">
            <div class="col-md-8">
              <label class="form-label fw-600">Nazwa firmy <span class="text-danger">*</span></label>
              <input type="text" name="company_name" class="form-control" required maxlength="255"
                     value="<?= e($_POST['company_name'] ?? '') ?>" placeholder="Nazwa Sp. z o.o.">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-600">NIP</label>
              <input type="text" name="nip" class="form-control" maxlength="20"
                     value="<?= e($_POST['nip'] ?? '') ?>" placeholder="0000000000">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Adres</label>
              <input type="text" name="address" class="form-control" maxlength="500"
                     value="<?= e($_POST['address'] ?? '') ?>" placeholder="ul. Przykładowa 1, 00-000 Warszawa">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">E-mail firmy</label>
              <input type="email" name="email" class="form-control" maxlength="255"
                     value="<?= e($_POST['email'] ?? '') ?>" placeholder="biuro@firma.pl">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Telefon</label>
              <input type="text" name="phone" class="form-control" maxlength="50"
                     value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+48 123 456 789">
            </div>
          </div>

          <h5 class="fw-bold mb-3 border-bottom pb-2">
            <i class="bi bi-person-gear me-2 text-primary"></i>Konto administratora
          </h5>
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label fw-600">Login <span class="text-danger">*</span></label>
              <input type="text" name="admin_login" class="form-control" required maxlength="100"
                     value="<?= e($_POST['admin_login'] ?? '') ?>" placeholder="admin" autocomplete="off">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-600">E-mail administratora</label>
              <input type="email" name="admin_email" class="form-control" maxlength="255"
                     value="<?= e($_POST['admin_email'] ?? '') ?>" placeholder="admin@firma.pl">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Hasło <span class="text-danger">*</span></label>
              <input type="password" name="admin_pass" class="form-control" required
                     minlength="10" placeholder="Min. 10 znaków" autocomplete="new-password">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Powtórz hasło <span class="text-danger">*</span></label>
              <input type="password" name="admin_pass2" class="form-control" required
                     minlength="10" placeholder="Powtórz hasło" autocomplete="new-password">
            </div>
          </div>

          <div class="mb-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="agree" id="agreeCheck"
                     <?= !empty($_POST['agree']) ? 'checked' : '' ?> required>
              <label class="form-check-label" for="agreeCheck">
                Akceptuję <a href="#" class="text-decoration-none">regulamin usługi</a>
                i <a href="#" class="text-decoration-none">politykę prywatności</a>.
                <span class="text-danger">*</span>
              </label>
            </div>
          </div>

          <button type="submit" class="btn btn-primary w-100 py-2 fw-600">
            <i class="bi bi-person-check me-2"></i>Zarejestruj i rozpocznij demo
          </button>
        </form>

        <?php endif; ?>

        <div class="text-center mt-4">
          <small class="text-muted">
            Masz już konto?
            <a href="/login.php" class="text-decoration-none">Zaloguj się</a>
          </small>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
