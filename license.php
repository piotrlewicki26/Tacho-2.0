<?php
/**
 * TachoPro 2.0 – License management
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';

requireLogin();

if (!hasRole('admin')) {
    flashSet('warning', 'Brak uprawnień.');
    redirect('/dashboard.php');
}

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// ── Handle POST (add/edit license – superadmin only) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        flashSet('danger', 'Nieprawidłowy token CSRF.');
        redirect('/license.php');
    }
    if (!hasRole('superadmin')) {
        flashSet('danger', 'Tylko superadmin może zarządzać licencjami.');
        redirect('/license.php');
    }

    $postAction = $_POST['action'] ?? '';
    $targetCid  = (int)($_POST['company_id'] ?? $companyId);

    // Verify company code match (optional extra check)
    $stmt = $db->prepare('SELECT unique_code FROM companies WHERE id=?');
    $stmt->execute([$targetCid]);
    $co = $stmt->fetch();
    if (!$co) { flashSet('danger', 'Firma nie istnieje.'); redirect('/license.php'); }

    if ($postAction === 'add_license') {
        $mods = $_POST['modules'] ?? [];
        $validFrom   = $_POST['valid_from']   ?? date('Y-m-d');
        $validUntil  = $_POST['valid_until']  ?? '';
        $version     = trim($_POST['version'] ?? '2.0');
        $publishedAt = $_POST['published_at'] ?? date('Y-m-d');
        $maxUsers    = max(0, (int)($_POST['max_users']    ?? 0));
        $maxVehicles = max(0, (int)($_POST['max_vehicles'] ?? 0));
        $maxDrivers  = max(0, (int)($_POST['max_drivers']  ?? 0));
        if (!$validUntil) { flashSet('danger', 'Podaj datę ważności licencji.'); redirect('/license.php'); }

        $licKey = generateLicenseKey($co['unique_code'], (array)$mods, $validUntil);
        $db->prepare(
            'INSERT INTO licenses
             (company_id, license_key, mod_core, mod_delegation, mod_driver_analysis, mod_vehicle_analysis,
              version, published_at, max_users, max_vehicles, max_drivers, valid_from, valid_until)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $targetCid,
            $licKey,
            in_array('core',             (array)$mods) ? 1 : 0,
            in_array('delegation',       (array)$mods) ? 1 : 0,
            in_array('driver_analysis',  (array)$mods) ? 1 : 0,
            in_array('vehicle_analysis', (array)$mods) ? 1 : 0,
            $version,
            $publishedAt,
            $maxUsers,
            $maxVehicles,
            $maxDrivers,
            $validFrom,
            $validUntil,
        ]);
        flashSet('success', 'Licencja została wystawiona.');
        redirect('/license.php');
    }
}

// ── Load licenses ─────────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT l.*, c.name AS company_name
     FROM licenses l
     JOIN companies c ON c.id = l.company_id
     WHERE l.company_id = ?
     ORDER BY l.valid_until DESC'
);
$stmt->execute([$companyId]);
$licenses = $stmt->fetchAll();

$activeLic = getActiveLicense();

// All companies for superadmin
$allCompanies = [];
if (hasRole('superadmin')) {
    $allCompanies = $db->query('SELECT id, name FROM companies ORDER BY name')->fetchAll();
}

$pageTitle  = 'Licencja';
$activePage = 'license';
include __DIR__ . '/templates/header.php';
?>

<div class="row g-4">
  <!-- Active license info -->
  <div class="col-lg-6">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-shield-check text-success"></i>
        <span class="tp-card-title">Aktywna licencja</span>
      </div>
      <div class="tp-card-body">
        <?php if ($activeLic): ?>
        <?php $licSt = dateStatus($activeLic['valid_until'], 30); ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1">
            <span class="fw-600">Status licencji</span>
            <span class="badge bg-<?= e($licSt['class']) ?>"><?= e($licSt['label']) ?></span>
          </div>
          <div class="d-flex justify-content-between text-muted small">
            <span>Wersja TachoPro</span>
            <strong><?= e($activeLic['version'] ?? '—') ?></strong>
          </div>
          <div class="d-flex justify-content-between text-muted small">
            <span>Data publikacji</span>
            <strong><?= fmtDate($activeLic['published_at'] ?? null) ?></strong>
          </div>
          <div class="d-flex justify-content-between text-muted small">
            <span>Ważna od</span>
            <strong><?= fmtDate($activeLic['valid_from']) ?></strong>
          </div>
          <div class="d-flex justify-content-between text-muted small">
            <span>Ważna do</span>
            <strong><?= fmtDate($activeLic['valid_until']) ?></strong>
          </div>
          <?php if ($licSt['days'] !== null && $licSt['days'] >= 0): ?>
          <div class="d-flex justify-content-between text-muted small">
            <span>Pozostało dni</span>
            <strong><?= $licSt['days'] ?></strong>
          </div>
          <?php endif; ?>
        </div>
        <hr>
        <p class="fw-600 mb-2">Limity licencji:</p>
        <?php
        $limitMap = [
            'max_users'    => ['label' => 'Użytkownicy', 'icon' => 'people'],
            'max_vehicles' => ['label' => 'Pojazdy',     'icon' => 'truck'],
            'max_drivers'  => ['label' => 'Kierowcy',    'icon' => 'person-badge'],
        ];
        $countMap  = [
            'max_users'    => 'SELECT COUNT(*) FROM users    WHERE company_id=' . $companyId . ' AND is_active=1 AND role!="superadmin"',
            'max_vehicles' => 'SELECT COUNT(*) FROM vehicles WHERE company_id=' . $companyId . ' AND is_active=1',
            'max_drivers'  => 'SELECT COUNT(*) FROM drivers  WHERE company_id=' . $companyId . ' AND is_active=1',
        ];
        foreach ($limitMap as $col => $info):
            $limit   = (int)($activeLic[$col] ?? 0);
            $current = (int)$db->query($countMap[$col])->fetchColumn();
            $over    = $limit > 0 && $current > $limit;
        ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-<?= $info['icon'] ?> text-secondary"></i>
          <span><?= e($info['label']) ?></span>
          <span class="ms-auto badge bg-<?= $over ? 'danger' : 'secondary' ?>">
            <?= $current ?><?= $limit > 0 ? '/' . $limit : ' / ∞' ?>
          </span>
        </div>
        <?php endforeach; ?>
        <hr>
        <p class="fw-600 mb-2">Aktywne moduły:</p>
        <?php
        $moduleMap = [
            'mod_core'             => ['label'=>'Core (Dashboard, Kierowcy, Pojazdy)', 'icon'=>'speedometer2'],
            'mod_delegation'       => ['label'=>'Delegacje',                           'icon'=>'map'],
            'mod_driver_analysis'  => ['label'=>'Analiza kierowców',                   'icon'=>'bar-chart-line'],
            'mod_vehicle_analysis' => ['label'=>'Analiza pojazdów',                    'icon'=>'truck-front'],
        ];
        foreach ($moduleMap as $col => $info):
            $on = (bool)($activeLic[$col] ?? 0);
        ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-<?= $info['icon'] ?> text-<?= $on?'success':'secondary' ?>"></i>
          <span class="<?= $on?'':'text-muted' ?>"><?= e($info['label']) ?></span>
          <?php if ($on): ?>
            <i class="bi bi-check-circle-fill text-success ms-auto"></i>
          <?php else: ?>
            <i class="bi bi-x-circle text-danger ms-auto"></i>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div class="mt-3">
          <small class="text-muted">Klucz licencji:</small>
          <div class="font-monospace small text-muted text-truncate">
            <?= e($activeLic['license_key']) ?>
          </div>
        </div>

        <?php else: ?>
        <div class="alert alert-danger">
          <i class="bi bi-exclamation-triangle me-2"></i>
          Brak aktywnej licencji. Skontaktuj się z administratorem systemu.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- License history -->
  <div class="col-lg-6">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-clock-history text-secondary"></i>
        <span class="tp-card-title">Historia licencji</span>
      </div>
      <div class="tp-card-body p-0">
        <div class="table-responsive">
          <table class="tp-table">
            <thead>
              <tr><th>Wersja</th><th>Ważna od</th><th>Ważna do</th><th>Limity (U/P/K)</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($licenses as $lic): ?>
              <?php $st = dateStatus($lic['valid_until'], 30); ?>
              <tr>
                <td><span class="badge bg-primary"><?= e($lic['version'] ?? '1.0') ?></span></td>
                <td><?= fmtDate($lic['valid_from']) ?></td>
                <td><?= fmtDate($lic['valid_until']) ?></td>
                <td class="font-monospace small">
                  <?= (int)($lic['max_users']    ?? 0) ?: '∞' ?> /
                  <?= (int)($lic['max_vehicles'] ?? 0) ?: '∞' ?> /
                  <?= (int)($lic['max_drivers']  ?? 0) ?: '∞' ?>
                </td>
                <td><span class="badge bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$licenses): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Brak historii licencji</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (hasRole('superadmin')): ?>
<!-- ── Superadmin: issue new license ─────────────────────────── -->
<div class="tp-card mt-4">
  <div class="tp-card-header">
    <i class="bi bi-plus-circle text-primary"></i>
    <span class="tp-card-title">Wystaw nową licencję</span>
  </div>
  <div class="tp-card-body">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
      <input type="hidden" name="action"     value="add_license">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-600">Firma <span class="text-danger">*</span></label>
          <select name="company_id" class="form-select" required>
            <?php foreach ($allCompanies as $c): ?>
            <option value="<?= $c['id'] ?>"<?= $c['id']==$companyId?' selected':'' ?>>
              <?= e($c['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Wersja TachoPro</label>
          <input type="text" name="version" class="form-control" value="2.0"
                 pattern="^\d+\.\d+(\.\d+)?$" placeholder="np. 2.0">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Data publikacji licencji</label>
          <input type="date" name="published_at" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-600">Ważna od</label>
          <input type="date" name="valid_from" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-600">Ważna do <span class="text-danger">*</span></label>
          <input type="date" name="valid_until" class="form-control" required
                 value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
        </div>
        <div class="col-12">
          <label class="form-label fw-600">Limity (0 = bez limitu)</label>
          <div class="row g-2">
            <div class="col-md-4">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-people"></i></span>
                <input type="number" name="max_users"    class="form-control" min="0" value="0" placeholder="Użytkownicy">
              </div>
            </div>
            <div class="col-md-4">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-truck"></i></span>
                <input type="number" name="max_vehicles" class="form-control" min="0" value="0" placeholder="Pojazdy">
              </div>
            </div>
            <div class="col-md-4">
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                <input type="number" name="max_drivers"  class="form-control" min="0" value="0" placeholder="Kierowcy">
              </div>
            </div>
          </div>
        </div>
        <div class="col-12">
          <label class="form-label fw-600">Moduły</label>
          <div class="d-flex flex-wrap gap-3">
            <?php foreach ($moduleMap as $col => $info):
              $modKey = str_replace('mod_', '', $col);
            ?>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="modules[]"
                     value="<?= e($modKey) ?>" id="mod_<?= e($modKey) ?>"
                     <?= $modKey==='core' ? 'checked disabled' : 'checked' ?>>
              <label class="form-check-label" for="mod_<?= e($modKey) ?>">
                <?= e($info['label']) ?>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <!-- Always send core -->
          <input type="hidden" name="modules[]" value="core">
        </div>
      </div>
      <button type="submit" class="btn btn-primary mt-3">
        <i class="bi bi-shield-plus me-1"></i>Wystaw licencję
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/templates/footer.php'; ?>
