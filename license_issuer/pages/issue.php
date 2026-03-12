<?php
/**
 * License Issuer – Issue new license
 */
liRequireLogin();
$db = liGetDB();

$preselectedCompanyId = (int)($_GET['company_id'] ?? 0);

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!liValidateCsrf($_POST['csrf_token'] ?? '')) {
        liFlash('danger', 'Nieprawidłowy token CSRF.');
        header('Location: index.php?page=issue');
        exit;
    }

    $companyId  = (int)($_POST['company_id']  ?? 0);
    $validFrom  = $_POST['valid_from']  ?? date('Y-m-d');
    $validUntil = $_POST['valid_until'] ?? '';
    $version    = trim($_POST['version'] ?? LI_VERSION);
    $publishedAt= $_POST['published_at'] ?? date('Y-m-d');
    $maxUsers   = max(0, (int)($_POST['max_users']    ?? 0));
    $maxVehicles= max(0, (int)($_POST['max_vehicles'] ?? 0));
    $maxDrivers = max(0, (int)($_POST['max_drivers']  ?? 0));
    $mods       = (array)($_POST['modules'] ?? ['core']);
    if (!in_array('core', $mods, true)) $mods[] = 'core';

    // Validate
    if (!$companyId || !$validUntil) {
        liFlash('danger', 'Firma i data ważności są wymagane.');
        header('Location: index.php?page=issue&company_id=' . $companyId);
        exit;
    }
    if ($validFrom > $validUntil) {
        liFlash('danger', 'Data od nie może być późniejsza niż data do.');
        header('Location: index.php?page=issue&company_id=' . $companyId);
        exit;
    }
    if (!preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
        liFlash('danger', 'Nieprawidłowy format wersji (np. 2.0 lub 2.0.1).');
        header('Location: index.php?page=issue&company_id=' . $companyId);
        exit;
    }

    // Fetch company unique_code (must exist and belong to the target company)
    $stmt = $db->prepare('SELECT id, name, unique_code FROM companies WHERE id=? LIMIT 1');
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    if (!$company) {
        liFlash('danger', 'Firma nie istnieje.');
        header('Location: index.php?page=issue');
        exit;
    }

    $licKey = liGenerateLicenseKey(
        $company['unique_code'], $mods, $validUntil,
        $maxUsers, $maxVehicles, $maxDrivers
    );

    $db->prepare(
        'INSERT INTO licenses
         (company_id, license_key, mod_core, mod_delegation, mod_driver_analysis, mod_vehicle_analysis,
          version, published_at, max_users, max_vehicles, max_drivers, valid_from, valid_until)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $companyId,
        $licKey,
        in_array('core',             $mods) ? 1 : 0,
        in_array('delegation',       $mods) ? 1 : 0,
        in_array('driver_analysis',  $mods) ? 1 : 0,
        in_array('vehicle_analysis', $mods) ? 1 : 0,
        $version,
        $publishedAt,
        $maxUsers,
        $maxVehicles,
        $maxDrivers,
        $validFrom,
        $validUntil,
    ]);

    liFlash('success', 'Licencja dla firmy "' . $company['name'] . '" została wystawiona pomyślnie.');
    header('Location: index.php?page=view&company_id=' . $companyId);
    exit;
}

// ── Load companies ───────────────────────────────────────────
$companies = $db->query('SELECT id, name, nip FROM companies ORDER BY name')->fetchAll();

$selectedCompany = null;
if ($preselectedCompanyId) {
    foreach ($companies as $c) {
        if ($c['id'] === $preselectedCompanyId) { $selectedCompany = $c; break; }
    }
}

$moduleMap = [
    'core'             => ['label' => 'Core (Dashboard, Kierowcy, Pojazdy)', 'icon' => 'speedometer2',    'required' => true],
    'delegation'       => ['label' => 'Delegacje',                           'icon' => 'map',              'required' => false],
    'driver_analysis'  => ['label' => 'Analiza kierowców',                   'icon' => 'bar-chart-line',   'required' => false],
    'vehicle_analysis' => ['label' => 'Analiza pojazdów',                    'icon' => 'truck-front',      'required' => false],
];

$pageTitle = 'Wystaw licencję';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="index.php?page=dashboard" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-plus-circle me-2 text-primary"></i>Wystaw licencję</h1>
</div>

<div class="row">
  <div class="col-lg-7 col-xl-6">
    <div class="li-card">
      <div class="li-card-header">
        <i class="bi bi-shield-plus text-primary"></i>
        Nowa licencja TachoPro 2.0
      </div>
      <div class="li-card-body">
        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?= liE(liCsrfToken()) ?>">

          <!-- Company -->
          <div class="mb-3">
            <label class="form-label fw-600">Firma <span class="text-danger">*</span></label>
            <select name="company_id" class="form-select" required id="companySelect">
              <option value="">— wybierz firmę —</option>
              <?php foreach ($companies as $c): ?>
              <option value="<?= $c['id'] ?>"<?= $c['id'] == $preselectedCompanyId ? ' selected' : '' ?>>
                <?= liE($c['name']) ?><?= $c['nip'] ? ' (' . liE($c['nip']) . ')' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Licencja jest przypisana do jednej firmy i nie może być przeniesiona.</div>
          </div>

          <!-- Version & publish date -->
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label fw-600">Wersja TachoPro <span class="text-danger">*</span></label>
              <input type="text" name="version" class="form-control" required
                     value="<?= liE(LI_VERSION) ?>" pattern="^\d+\.\d+(\.\d+)?$"
                     placeholder="np. 2.0">
              <div class="form-text">Wersja aplikacji objęta licencją</div>
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-600">Data publikacji licencji</label>
              <input type="date" name="published_at" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
          </div>

          <!-- Validity -->
          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label fw-600">Ważna od</label>
              <input type="date" name="valid_from" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-600">Ważna do <span class="text-danger">*</span></label>
              <input type="date" name="valid_until" class="form-control" required
                     value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
            </div>
          </div>

          <!-- Limits -->
          <div class="mb-3">
            <label class="form-label fw-600">Limity (0 = bez limitu)</label>
            <div class="row g-2">
              <div class="col-4">
                <label class="form-label small text-muted">Użytkownicy</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text"><i class="bi bi-people"></i></span>
                  <input type="number" name="max_users" class="form-control" min="0" value="0">
                </div>
              </div>
              <div class="col-4">
                <label class="form-label small text-muted">Pojazdy</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text"><i class="bi bi-truck"></i></span>
                  <input type="number" name="max_vehicles" class="form-control" min="0" value="0">
                </div>
              </div>
              <div class="col-4">
                <label class="form-label small text-muted">Kierowcy</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                  <input type="number" name="max_drivers" class="form-control" min="0" value="0">
                </div>
              </div>
            </div>
          </div>

          <!-- Modules -->
          <div class="mb-4">
            <label class="form-label fw-600">Moduły</label>
            <div class="row g-2">
              <?php foreach ($moduleMap as $modKey => $info): ?>
              <div class="col-sm-6">
                <div class="form-check form-switch border rounded px-3 py-2">
                  <input class="form-check-input" type="checkbox" name="modules[]"
                         value="<?= liE($modKey) ?>"
                         id="mod_<?= liE($modKey) ?>"
                         <?= $info['required'] ? 'checked disabled' : 'checked' ?>>
                  <label class="form-check-label d-flex align-items-center gap-2" for="mod_<?= liE($modKey) ?>">
                    <i class="bi bi-<?= $info['icon'] ?> text-primary"></i>
                    <?= liE($info['label']) ?>
                    <?php if ($info['required']): ?>
                    <span class="badge bg-secondary ms-auto">wymagany</span>
                    <?php endif; ?>
                  </label>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <!-- Always send core -->
            <input type="hidden" name="modules[]" value="core">
          </div>

          <button type="submit" class="btn btn-primary">
            <i class="bi bi-shield-plus me-1"></i>Wystaw licencję
          </button>
          <a href="index.php?page=dashboard" class="btn btn-outline-secondary ms-2">Anuluj</a>
        </form>
      </div>
    </div>
  </div>

  <!-- Info panel -->
  <div class="col-lg-5 col-xl-6">
    <div class="li-card">
      <div class="li-card-header"><i class="bi bi-info-circle text-info"></i> Informacje</div>
      <div class="li-card-body">
        <ul class="list-unstyled small text-muted">
          <li class="mb-2"><i class="bi bi-building me-2 text-primary"></i>Licencja jest przypisana wyłącznie do <strong>jednej firmy</strong> za pomocą jej unikalnego kodu (SHA-256).</li>
          <li class="mb-2"><i class="bi bi-shield-check me-2 text-success"></i>Klucz licencji jest generowany kryptograficznie i nie może być sfałszowany bez znajomości kodu firmy.</li>
          <li class="mb-2"><i class="bi bi-people me-2 text-warning"></i>Limity użytkowników, pojazdów i kierowców są weryfikowane przez TachoPro przed dodaniem nowych rekordów. <strong>0 = bez limitu.</strong></li>
          <li class="mb-2"><i class="bi bi-clock me-2 text-secondary"></i>Wersja wskazuje, która wersja TachoPro jest objęta licencją.</li>
          <li class="mb-2"><i class="bi bi-puzzle me-2 text-info"></i>Moduł <strong>Core</strong> jest zawsze aktywny. Pozostałe moduły można dowolnie łączyć.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
