<?php
/**
 * TachoPro 2.0 – Superadmin Panel
 * Full visibility across all companies.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';
require_once __DIR__ . '/includes/subscription.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();

// Superadmin only
if (!hasRole('superadmin')) {
    flashSet('danger', 'Brak dostępu. Ta strona jest dostępna wyłącznie dla superadmin.');
    redirect('/dashboard.php');
}

$db = getDB();

// ── Handle actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        flashSet('danger', 'Nieprawidłowy token CSRF.');
        redirect('/admin.php');
    }

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'upgrade_company') {
        $targetCid = (int)($_POST['company_id'] ?? 0);
        if ($targetCid) {
            upgradeCompanyToPro($targetCid);
            auditLog('upgrade', 'company', $targetCid, 'Superadmin ręcznie aktywował plan Pro');
            flashSet('success', 'Firma przełączona na plan Pro.');
        }
    } elseif ($postAction === 'upgrade_company_plus') {
        $targetCid = (int)($_POST['company_id'] ?? 0);
        if ($targetCid) {
            upgradeCompanyToProPlus($targetCid);
            auditLog('upgrade', 'company', $targetCid, 'Superadmin ręcznie aktywował plan PRO Module+');
            flashSet('success', 'Firma przełączona na plan PRO Module+.');
        }
    } elseif ($postAction === 'downgrade_company') {
        $targetCid = (int)($_POST['company_id'] ?? 0);
        if ($targetCid) {
            $db->prepare("UPDATE companies SET plan='demo' WHERE id=?")->execute([$targetCid]);
            auditLog('downgrade', 'company', $targetCid, 'Superadmin przełączył firmę na plan Demo');
            flashSet('success', 'Firma przełączona na plan Demo.');
        }
    } elseif ($postAction === 'reset_trial') {
        $targetCid = (int)($_POST['company_id'] ?? 0);
        if ($targetCid) {
            $newTrialEnd = date('Y-m-d', strtotime('+' . DEMO_DAYS . ' days'));
            $db->prepare("UPDATE companies SET plan='demo', trial_ends_at=? WHERE id=?")
               ->execute([$newTrialEnd, $targetCid]);
            auditLog('reset_trial', 'company', $targetCid, 'Superadmin zresetował okres próbny do ' . $newTrialEnd);
            flashSet('success', 'Okres próbny zresetowany.');
        }
    } elseif ($postAction === 'save_config') {
        // Save system settings (Stripe + email)
        $settingsToSave = [
            'stripe_publishable_key' => trim($_POST['stripe_publishable_key'] ?? ''),
            'stripe_secret_key'      => trim($_POST['stripe_secret_key']      ?? ''),
            'stripe_webhook_secret'  => trim($_POST['stripe_webhook_secret']  ?? ''),
            'smtp_host'              => trim($_POST['smtp_host']              ?? ''),
            'smtp_port'              => trim($_POST['smtp_port']              ?? ''),
            'smtp_user'              => trim($_POST['smtp_user']              ?? ''),
            'smtp_from_email'        => trim($_POST['smtp_from_email']        ?? ''),
            'smtp_from_name'         => trim($_POST['smtp_from_name']         ?? ''),
        ];
        // Only update password if a new one was supplied
        $smtpPass = $_POST['smtp_password'] ?? '';
        if ($smtpPass !== '') {
            $settingsToSave['smtp_password'] = $smtpPass;
        }
        foreach ($settingsToSave as $key => $value) {
            setSystemSetting($key, $value);
        }
        auditLog('update', 'system_settings', 0, 'Superadmin zaktualizował konfigurację systemu');
        flashSet('success', 'Konfiguracja systemu została zapisana.');
    }

    redirect('/admin.php' . (isset($_GET['section']) ? '?section=' . e($_GET['section']) : ''));
}

$section = $_GET['section'] ?? 'companies';

// ── Load data per section ─────────────────────────────────────
$companies = [];
$allUsers  = [];
$auditRows = [];
$sysConfig = [];

if ($section === 'companies' || $section === '') {
    $companies = $db->query(
        "SELECT c.*,
                (SELECT COUNT(*) FROM users    u WHERE u.company_id=c.id AND u.is_active=1 AND u.role != 'superadmin') AS cnt_users,
                (SELECT COUNT(*) FROM vehicles v WHERE v.company_id=c.id AND v.is_active=1)  AS cnt_vehicles,
                (SELECT COUNT(*) FROM drivers  d WHERE d.company_id=c.id AND d.is_active=1)  AS cnt_drivers,
                (SELECT SUM(amount_gross) FROM invoices i WHERE i.company_id=c.id AND i.status='paid') AS total_paid
         FROM companies c
         ORDER BY c.plan ASC, c.created_at DESC"
    )->fetchAll();
}

if ($section === 'users') {
    $allUsers = $db->query(
        "SELECT u.*, c.name AS company_name, c.plan AS company_plan
         FROM users u
         JOIN companies c ON c.id = u.company_id
         ORDER BY u.created_at DESC
         LIMIT 200"
    )->fetchAll();
}

if ($section === 'audit') {
    $auditRows = $db->query(
        "SELECT a.*, c.name AS company_name
         FROM audit_log a
         LEFT JOIN companies c ON c.id = a.company_id
         ORDER BY a.created_at DESC
         LIMIT 200"
    )->fetchAll();
}

if ($section === 'config') {
    $configKeys = [
        'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password',
        'smtp_from_email', 'smtp_from_name',
    ];
    foreach ($configKeys as $k) {
        $sysConfig[$k] = getSystemSetting($k) ?? '';
    }
}

// Summary stats
$statStmt = $db->query(
    "SELECT
       (SELECT COUNT(*) FROM companies) AS total_companies,
       (SELECT COUNT(*) FROM companies WHERE plan='pro') AS pro_companies,
       (SELECT COUNT(*) FROM companies WHERE plan='pro_plus') AS pro_plus_companies,
       (SELECT COUNT(*) FROM companies WHERE plan='demo') AS demo_companies,
       (SELECT COUNT(*) FROM users WHERE is_active=1 AND role != 'superadmin') AS total_users,
       (SELECT COUNT(*) FROM drivers WHERE is_active=1) AS total_drivers,
       (SELECT COUNT(*) FROM vehicles WHERE is_active=1) AS total_vehicles,
       (SELECT COALESCE(SUM(amount_gross),0) FROM invoices WHERE status='paid') AS total_revenue"
);
$stats = $statStmt->fetch();

$pageTitle  = 'Superadmin – Panel zarządzania';
$activePage = 'admin';
include __DIR__ . '/templates/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <h1 class="tp-page-title mb-0">
    <i class="bi bi-shield-lock-fill text-danger me-2"></i>Panel Superadmin
  </h1>
  <span class="badge bg-danger">SUPERADMIN</span>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-2">
    <div class="tp-stat"><div class="tp-stat-icon primary"><i class="bi bi-building"></i></div>
      <div><div class="tp-stat-value"><?= (int)$stats['total_companies'] ?></div><div class="tp-stat-label">Firm</div></div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="tp-stat"><div class="tp-stat-icon success"><i class="bi bi-crown"></i></div>
      <div><div class="tp-stat-value"><?= (int)$stats['pro_companies'] ?></div><div class="tp-stat-label">PRO</div></div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="tp-stat"><div class="tp-stat-icon primary"><i class="bi bi-crown-fill"></i></div>
      <div><div class="tp-stat-value"><?= (int)$stats['pro_plus_companies'] ?></div><div class="tp-stat-label">PRO Module+</div></div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="tp-stat"><div class="tp-stat-icon warning"><i class="bi bi-hourglass-split"></i></div>
      <div><div class="tp-stat-value"><?= (int)$stats['demo_companies'] ?></div><div class="tp-stat-label">Demo</div></div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="tp-stat"><div class="tp-stat-icon secondary"><i class="bi bi-people"></i></div>
      <div><div class="tp-stat-value"><?= (int)$stats['total_users'] ?></div><div class="tp-stat-label">Użytkowników</div></div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="tp-stat"><div class="tp-stat-icon success"><i class="bi bi-currency-exchange"></i></div>
      <div><div class="tp-stat-value"><?= number_format((float)$stats['total_revenue'], 0, ',', ' ') ?>zł</div><div class="tp-stat-label">Przychody</div></div>
    </div>
  </div>
</div>

<!-- Section tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $section === 'companies' || $section === '' ? 'active' : '' ?>"
       href="/admin.php?section=companies">
      <i class="bi bi-building me-1"></i>Firmy
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $section === 'users' ? 'active' : '' ?>"
       href="/admin.php?section=users">
      <i class="bi bi-people me-1"></i>Użytkownicy
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $section === 'audit' ? 'active' : '' ?>"
       href="/admin.php?section=audit">
      <i class="bi bi-clock-history me-1"></i>Historia zmian
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $section === 'config' ? 'active' : '' ?>"
       href="/admin.php?section=config">
      <i class="bi bi-gear-wide-connected me-1"></i>Konfiguracja
    </a>
  </li>
</ul>

<?php if ($section === 'companies' || $section === ''): ?>
<!-- ── Companies table ────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-building text-primary"></i>
    <span class="tp-card-title">Wszystkie firmy</span>
    <span class="badge bg-secondary ms-auto"><?= count($companies) ?></span>
  </div>
  <div class="table-responsive">
    <table class="tp-table">
      <thead>
        <tr>
          <th>Firma</th>
          <th>NIP</th>
          <th>Plan</th>
          <th>Koniec demo</th>
          <th>Użytkownicy</th>
          <th>Kierowcy</th>
          <th>Pojazdy</th>
          <th>Przychody</th>
          <th>Rejestracja</th>
          <th>Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $co):
          $trialExpired = $co['plan'] === 'demo' && $co['trial_ends_at'] && strtotime($co['trial_ends_at']) < strtotime('today');
        ?>
        <tr class="<?= $trialExpired ? 'table-danger' : '' ?>">
          <td>
            <div class="fw-600"><?= e($co['name']) ?></div>
            <small class="text-muted"><?= e($co['email'] ?? '') ?></small>
          </td>
          <td><?= e($co['nip'] ?? '—') ?></td>
          <td>
            <?php if ($co['plan'] === 'pro_plus'): ?>
            <span class="badge" style="background:linear-gradient(135deg,#1a56db,#9333ea);color:#fff"><i class="bi bi-crown-fill me-1"></i>PRO Module+</span>
            <?php elseif ($co['plan'] === 'pro'): ?>
            <span class="badge bg-success"><i class="bi bi-crown me-1"></i>Pro</span>
            <?php elseif ($trialExpired): ?>
            <span class="badge bg-danger">Demo (wygasł)</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark">Demo</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= $co['trial_ends_at'] ? fmtDate($co['trial_ends_at']) : '—' ?></td>
          <td class="text-center"><?= (int)$co['cnt_users'] ?></td>
          <td class="text-center"><?= (int)$co['cnt_drivers'] ?></td>
          <td class="text-center"><?= (int)$co['cnt_vehicles'] ?></td>
          <td class="text-end"><?= $co['total_paid'] ? number_format($co['total_paid'], 2, ',', ' ') . ' zł' : '—' ?></td>
          <td class="small text-muted"><?= fmtDate(substr($co['created_at'], 0, 10)) ?></td>
          <td>
            <div class="d-flex gap-1 flex-wrap">
              <?php if ($co['plan'] === 'demo'): ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action"     value="upgrade_company">
                <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                <button type="submit" class="btn btn-xs btn-success" title="Aktywuj PRO">
                  <i class="bi bi-crown"></i>
                </button>
              </form>
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action"     value="upgrade_company_plus">
                <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                <button type="submit" class="btn btn-xs btn-primary" title="Aktywuj PRO Module+"
                        style="background:linear-gradient(135deg,#1a56db,#9333ea);border:none">
                  <i class="bi bi-crown-fill"></i>
                </button>
              </form>
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action"     value="reset_trial">
                <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-warning" title="Resetuj demo">
                  <i class="bi bi-arrow-clockwise"></i>
                </button>
              </form>
              <?php elseif ($co['plan'] === 'pro'): ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action"     value="upgrade_company_plus">
                <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                <button type="submit" class="btn btn-xs btn-primary" title="Upgrade do PRO Module+"
                        style="background:linear-gradient(135deg,#1a56db,#9333ea);border:none">
                  <i class="bi bi-crown-fill"></i> Module+
                </button>
              </form>
              <form method="POST" class="d-inline"
                    onsubmit="return confirm('Przełączyć na Demo?')">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action"     value="downgrade_company">
                <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-secondary" title="Downgrade do Demo">
                  <i class="bi bi-arrow-down-circle"></i>
                </button>
              </form>
              <?php else: ?>
              <form method="POST" class="d-inline"
                    onsubmit="return confirm('Przełączyć na Demo?')">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action"     value="downgrade_company">
                <input type="hidden" name="company_id" value="<?= $co['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-secondary" title="Downgrade do Demo">
                  <i class="bi bi-arrow-down-circle"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$companies): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">Brak firm</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($section === 'users'): ?>
<!-- ── All users ─────────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-people text-primary"></i>
    <span class="tp-card-title">Wszyscy użytkownicy</span>
    <span class="badge bg-secondary ms-auto"><?= count($allUsers) ?></span>
  </div>
  <div class="table-responsive">
    <table class="tp-table">
      <thead>
        <tr>
          <th>Login</th>
          <th>E-mail</th>
          <th>Rola</th>
          <th>Firma</th>
          <th>Plan firmy</th>
          <th>Ostatnie logowanie</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allUsers as $u): ?>
        <tr class="<?= !$u['is_active'] ? 'text-muted' : '' ?>">
          <td><?= e($u['username']) ?></td>
          <td><?= e($u['email'] ?? '—') ?></td>
          <td>
            <span class="badge bg-<?= $u['role'] === 'superadmin' ? 'danger' : ($u['role'] === 'admin' ? 'primary' : ($u['role'] === 'manager' ? 'info' : 'secondary')) ?>">
              <?= e($u['role']) ?>
            </span>
          </td>
          <td><?= e($u['company_name']) ?></td>
          <td>
            <span class="badge bg-<?= $u['company_plan'] === 'pro' ? 'success' : 'warning text-dark' ?>">
              <?= e($u['company_plan']) ?>
            </span>
          </td>
          <td class="small text-muted">
            <?= $u['last_login'] ? htmlspecialchars(substr($u['last_login'],0,16), ENT_QUOTES, 'UTF-8') : '—' ?>
          </td>
          <td>
            <span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>">
              <?= $u['is_active'] ? 'Aktywny' : 'Nieaktywny' ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($section === 'audit'): ?>
<!-- ── Audit log ─────────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-clock-history text-secondary"></i>
    <span class="tp-card-title">Historia zmian (ostatnie 200)</span>
  </div>
  <div class="table-responsive">
    <table class="tp-table">
      <thead>
        <tr>
          <th>Data</th>
          <th>Firma</th>
          <th>Użytkownik</th>
          <th>Akcja</th>
          <th>Obiekt</th>
          <th>Opis</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($auditRows as $row): ?>
        <tr>
          <td class="small text-muted"><?= htmlspecialchars(substr($row['created_at'],0,16), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="small"><?= e($row['company_name'] ?? '—') ?></td>
          <td><?= e($row['username'] ?? '—') ?></td>
          <td>
            <span class="badge bg-<?= match($row['action']) {
              'create'  => 'success',
              'update'  => 'info',
              'delete'  => 'danger',
              'login'   => 'primary',
              'logout'  => 'secondary',
              default   => 'warning text-dark',
            } ?>">
              <?= e($row['action']) ?>
            </span>
          </td>
          <td class="small">
            <?= $row['entity_type'] ? e($row['entity_type'] . ' #' . $row['entity_id']) : '—' ?>
          </td>
          <td class="small"><?= e($row['description'] ?? '') ?></td>
          <td class="small text-muted font-monospace"><?= e($row['ip_address'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$auditRows): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Brak wpisów w historii</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($section === 'config'): ?>
<!-- ── System configuration ───────────────────────────────────── -->
<div class="row g-4">

  <!-- Stripe -->
  <div class="col-lg-6">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-credit-card-2-front text-primary"></i>
        <span class="tp-card-title">Konfiguracja Stripe</span>
      </div>
      <div class="tp-card-body">
        <form method="POST" action="/admin.php?section=config" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
          <input type="hidden" name="action"     value="save_config">
          <div class="mb-3">
            <label class="form-label fw-600">Stripe Publishable Key <small class="text-muted">(pk_live_… lub pk_test_…)</small></label>
            <input type="text" name="stripe_publishable_key" class="form-control font-monospace"
                   value="<?= e($sysConfig['stripe_publishable_key'] ?? '') ?>"
                   placeholder="pk_live_..." autocomplete="off">
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Stripe Secret Key <small class="text-muted">(sk_live_… lub sk_test_…)</small></label>
            <input type="password" name="stripe_secret_key" class="form-control font-monospace"
                   value="<?= e($sysConfig['stripe_secret_key'] ?? '') ?>"
                   placeholder="sk_live_..." autocomplete="new-password">
            <div class="form-text">Klucz tajny. Nie udostępniaj nikomu.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Stripe Webhook Secret <small class="text-muted">(whsec_…)</small></label>
            <input type="password" name="stripe_webhook_secret" class="form-control font-monospace"
                   value="<?= e($sysConfig['stripe_webhook_secret'] ?? '') ?>"
                   placeholder="whsec_..." autocomplete="new-password">
            <div class="form-text">Dostępny w Stripe Dashboard → Webhooks.</div>
          </div>
          <?php
          $spk = $sysConfig['stripe_publishable_key'] ?? '';
          $ssk = $sysConfig['stripe_secret_key'] ?? '';
          $stripeOk = !empty($spk) && str_starts_with($spk, 'pk_')
                   && !empty($ssk) && str_starts_with($ssk, 'sk_');
          ?>
          <?php if ($stripeOk): ?>
          <div class="alert alert-success py-2 small">
            <i class="bi bi-check-circle-fill me-1"></i>Stripe jest skonfigurowany i aktywny.
          </div>
          <?php else: ?>
          <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle me-1"></i>Stripe nie jest jeszcze skonfigurowany.
            Płatności online będą niedostępne.
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-check2 me-1"></i>Zapisz konfigurację Stripe
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- SMTP / Email -->
  <div class="col-lg-6">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-envelope text-info"></i>
        <span class="tp-card-title">Konfiguracja e-mail (SMTP)</span>
      </div>
      <div class="tp-card-body">
        <form method="POST" action="/admin.php?section=config" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
          <input type="hidden" name="action"     value="save_config">
          <!-- Carry over Stripe values so they are not wiped -->
          <input type="hidden" name="stripe_publishable_key" value="<?= e($sysConfig['stripe_publishable_key'] ?? '') ?>">
          <input type="hidden" name="stripe_secret_key"      value="<?= e($sysConfig['stripe_secret_key']      ?? '') ?>">
          <input type="hidden" name="stripe_webhook_secret"  value="<?= e($sysConfig['stripe_webhook_secret']  ?? '') ?>">
          <div class="row g-3 mb-3">
            <div class="col-8">
              <label class="form-label fw-600">Host SMTP</label>
              <input type="text" name="smtp_host" class="form-control"
                     value="<?= e($sysConfig['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
            </div>
            <div class="col-4">
              <label class="form-label fw-600">Port</label>
              <input type="number" name="smtp_port" class="form-control"
                     value="<?= e($sysConfig['smtp_port'] ?? '587') ?>" placeholder="587">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Użytkownik SMTP</label>
            <input type="text" name="smtp_user" class="form-control"
                   value="<?= e($sysConfig['smtp_user'] ?? '') ?>" autocomplete="username">
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Hasło SMTP</label>
            <input type="password" name="smtp_password" class="form-control"
                   placeholder="Zostaw puste, aby nie zmieniać" autocomplete="new-password">
            <div class="form-text">
              <?= !empty($sysConfig['smtp_password']) ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Hasło jest zapisane.</span>' : 'Brak zapisanego hasła.' ?>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-7">
              <label class="form-label fw-600">Adres nadawcy</label>
              <input type="email" name="smtp_from_email" class="form-control"
                     value="<?= e($sysConfig['smtp_from_email'] ?? '') ?>" placeholder="noreply@example.com">
            </div>
            <div class="col-5">
              <label class="form-label fw-600">Nazwa nadawcy</label>
              <input type="text" name="smtp_from_name" class="form-control"
                     value="<?= e($sysConfig['smtp_from_name'] ?? 'TachoPro') ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-info text-white">
            <i class="bi bi-check2 me-1"></i>Zapisz konfigurację e-mail
          </button>
        </form>
      </div>
    </div>
  </div>

</div><!-- /row -->
<?php endif; ?>

<style>
.btn-xs { padding:.2rem .5rem; font-size:.8rem; }
</style>
<?php include __DIR__ . '/templates/footer.php'; ?>
