<?php
/**
 * TachoPro 2.0 – Company management
 * Superadmin: add new companies. Admin: edit own company.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';

requireLogin();
requireModule('core');

if (!hasRole('admin')) {
    flashSet('warning', 'Brak uprawnień do zarządzania firmą.');
    redirect('/dashboard.php');
}

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$action    = $_GET['action'] ?? 'view';

// Determine whether this user can add additional companies:
// superadmin always can; admin only if on PRO Module+ plan.
$canAddCompany = hasRole('superadmin') || hasModule('multi_company');

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        flashSet('danger', 'Nieprawidłowy token CSRF.');
        redirect('/company.php');
    }

    $postAction = $_POST['action'] ?? '';

    // Add new company (superadmin or PRO Module+ admin)
    if ($postAction === 'add' && $canAddCompany) {
        $name  = trim($_POST['name']    ?? '');
        $addr  = trim($_POST['address'] ?? '');
        $nip   = trim($_POST['nip']     ?? '');
        $email = trim($_POST['email']   ?? '');
        $phone = trim($_POST['phone']   ?? '');

        $adminUser  = trim($_POST['admin_user']  ?? '');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass  = $_POST['admin_pass']       ?? '';

        if (!$name || !$adminUser || !$adminPass || !$adminEmail) {
            flashSet('danger', 'Wypełnij wszystkie wymagane pola.');
            redirect('/company.php?action=add');
        }
        if (strlen($adminPass) < 10) {
            flashSet('danger', 'Hasło administratora musi mieć co najmniej 10 znaków.');
            redirect('/company.php?action=add');
        }

        try {
            $db->beginTransaction();

            $uniqueCode = generateCompanyCode();
            $stmt = $db->prepare(
                'INSERT INTO companies (name, address, nip, email, phone, unique_code) VALUES (?,?,?,?,?,?)'
            );
            $stmt->execute([$name, $addr, $nip, $email, $phone, $uniqueCode]);
            $newCompanyId = (int)$db->lastInsertId();

            $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare(
                'INSERT INTO users (company_id, username, email, password_hash, role) VALUES (?,?,?,?,?)'
            )->execute([$newCompanyId, $adminUser, $adminEmail, $hash, 'admin']);

            // Default core license (1 year)
            $licKey = generateLicenseKey($uniqueCode, ['core'], date('Y-m-d', strtotime('+1 year')));
            $db->prepare(
                'INSERT INTO licenses (company_id, license_key, mod_core, valid_from, valid_until) VALUES (?,?,1,CURDATE(),?)'
            )->execute([$newCompanyId, $licKey, date('Y-m-d', strtotime('+1 year'))]);

            $db->commit();
            flashSet('success', 'Firma "' . $name . '" została dodana. Unikalny kod: ' . substr($uniqueCode, 0, 16) . '…');
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Add company error: ' . $e->getMessage());
            flashSet('danger', 'Błąd dodawania firmy.');
        }
        redirect('/company.php');
    }

    // Edit company info
    if ($postAction === 'edit') {
        $cid = hasRole('superadmin') ? (int)($_POST['company_id'] ?? $companyId) : $companyId;
        $name  = trim($_POST['name']    ?? '');
        $addr  = trim($_POST['address'] ?? '');
        $nip   = trim($_POST['nip']     ?? '');
        $email = trim($_POST['email']   ?? '');
        $phone = trim($_POST['phone']   ?? '');

        if (!$name) { flashSet('danger', 'Nazwa firmy jest wymagana.'); redirect('/company.php'); }

        $db->prepare(
            'UPDATE companies SET name=?, address=?, nip=?, email=?, phone=? WHERE id=?'
        )->execute([$name, $addr, $nip, $email, $phone, $cid]);
        flashSet('success', 'Dane firmy zostały zaktualizowane.');
        redirect('/company.php');
    }
}

// ── Load data ─────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM companies WHERE id=?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// All companies for superadmin
$allCompanies = [];
if ($canAddCompany) {
    $allCompanies = $db->query('SELECT * FROM companies ORDER BY name')->fetchAll();
}

$pageTitle  = 'Firma';
$activePage = 'company';
include __DIR__ . '/templates/header.php';
?>

<?php if ($canAddCompany): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div></div>
  <a href="/company.php?action=add" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Dodaj nową firmę
  </a>
</div>
<?php elseif (hasRole('admin')): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-4">
  <i class="bi bi-lock-fill"></i>
  <span>
    Możliwość dodawania kolejnych firm jest dostępna w pakiecie
    <a href="/billing.php#upgrade-section" class="alert-link fw-bold">PRO Module+</a>.
  </span>
</div>
<?php endif; ?>

<?php if ($action === 'add' && $canAddCompany): ?>
<!-- ── Add company form ───────────────────────────────────────── -->
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-building text-primary"></i>
    <span class="tp-card-title">Nowa firma</span>
    <a href="/company.php" class="btn btn-sm btn-outline-secondary ms-auto">Anuluj</a>
  </div>
  <div class="tp-card-body">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
      <input type="hidden" name="action"     value="add">
      <h6 class="mb-3 text-muted"><i class="bi bi-building me-1"></i>Dane firmy</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label fw-600">Nazwa firmy <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required maxlength="255">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-600">NIP</label>
          <input type="text" name="nip" class="form-control" maxlength="20">
        </div>
        <div class="col-12">
          <label class="form-label fw-600">Adres</label>
          <input type="text" name="address" class="form-control" maxlength="500">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-600">E-mail firmy</label>
          <input type="email" name="email" class="form-control" maxlength="255">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-600">Telefon</label>
          <input type="text" name="phone" class="form-control" maxlength="50">
        </div>
      </div>
      <h6 class="mb-3 text-muted"><i class="bi bi-person-badge me-1"></i>Administrator firmy</h6>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-600">Login <span class="text-danger">*</span></label>
          <input type="text" name="admin_user" class="form-control" required maxlength="100">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">E-mail <span class="text-danger">*</span></label>
          <input type="email" name="admin_email" class="form-control" required maxlength="255">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Hasło (min. 10 zn.) <span class="text-danger">*</span></label>
          <input type="password" name="admin_pass" class="form-control" required minlength="10" autocomplete="new-password">
        </div>
      </div>
      <div class="mt-4">
        <div class="alert alert-info py-2 small">
          <i class="bi bi-info-circle me-1"></i>
          Unikalny kod firmy zostanie wygenerowany automatycznie i nie może być zmieniony.
          Licencja bazowa (moduł Core, 1 rok) zostanie przypisana automatycznie.
        </div>
      </div>
      <button type="submit" class="btn btn-primary mt-2">
        <i class="bi bi-check2 me-1"></i>Utwórz firmę
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Current company ───────────────────────────────────────── -->
<div class="row g-4">
  <div class="col-lg-7">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-building text-primary"></i>
        <span class="tp-card-title">Dane firmy</span>
      </div>
      <div class="tp-card-body">
        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
          <input type="hidden" name="action"     value="edit">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-600">Nazwa firmy <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required maxlength="255"
                     value="<?= e($company['name'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-600">NIP</label>
              <input type="text" name="nip" class="form-control" maxlength="20"
                     value="<?= e($company['nip'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label fw-600">Adres</label>
              <input type="text" name="address" class="form-control" maxlength="500"
                     value="<?= e($company['address'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">E-mail</label>
              <input type="email" name="email" class="form-control" maxlength="255"
                     value="<?= e($company['email'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-600">Telefon</label>
              <input type="text" name="phone" class="form-control" maxlength="50"
                     value="<?= e($company['phone'] ?? '') ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-primary mt-3">
            <i class="bi bi-check2 me-1"></i>Zapisz zmiany
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-key text-warning"></i>
        <span class="tp-card-title">Unikalny kod firmy</span>
      </div>
      <div class="tp-card-body">
        <div class="alert alert-warning py-2 small mb-3">
          <i class="bi bi-lock-fill me-1"></i>
          Ten kod jest generowany jednorazowo i nie może być zmieniony.
          Służy jako podstawa do generowania licencji.
        </div>
        <div class="input-group">
          <input type="text" class="form-control form-control-sm font-monospace"
                 value="<?= e($company['unique_code'] ?? '') ?>"
                 id="uniqueCodeInput" readonly>
          <button class="btn btn-outline-secondary btn-sm" type="button"
                  onclick="navigator.clipboard.writeText(document.getElementById('uniqueCodeInput').value);this.innerHTML='<i class=\'bi bi-check\'></i>';setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i>',2000)">
            <i class="bi bi-clipboard"></i>
          </button>
        </div>
        <small class="text-muted mt-2 d-block">
          Zarejestrowano: <?= fmtDate($company['created_at'] ? substr($company['created_at'],0,10) : null) ?>
        </small>
      </div>
    </div>
  </div>
</div>

<?php if ($allCompanies && $canAddCompany): ?>
<!-- ── All companies (superadmin) ────────────────────────────── -->
<div class="tp-card mt-4">
  <div class="tp-card-header">
    <i class="bi bi-buildings text-primary"></i>
    <span class="tp-card-title">Wszystkie firmy</span>
    <span class="badge bg-secondary ms-2"><?= count($allCompanies) ?></span>
  </div>
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead>
          <tr><th>Nazwa</th><th>NIP</th><th>E-mail</th><th>Rejestracja</th></tr>
        </thead>
        <tbody>
          <?php foreach ($allCompanies as $c): ?>
          <tr>
            <td><?= e($c['name']) ?></td>
            <td><?= e($c['nip'] ?? '—') ?></td>
            <td><?= e($c['email'] ?? '—') ?></td>
            <td><?= fmtDate(substr($c['created_at'],0,10)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/templates/footer.php'; ?>
