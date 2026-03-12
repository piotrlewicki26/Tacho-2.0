<?php
/**
 * License Issuer – View license history for a company
 */
liRequireLogin();
$db = liGetDB();

$companyId = (int)($_GET['company_id'] ?? 0);
if (!$companyId) {
    header('Location: index.php?page=dashboard');
    exit;
}

$stmt = $db->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');
$stmt->execute([$companyId]);
$company = $stmt->fetch();
if (!$company) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Usage counts
$stmtU = $db->prepare('SELECT COUNT(*) FROM users    WHERE company_id=? AND is_active=1 AND role != "superadmin"');
$stmtU->execute([$companyId]);
$cntUsers    = (int)$stmtU->fetchColumn();

$stmtV = $db->prepare('SELECT COUNT(*) FROM vehicles WHERE company_id=? AND is_active=1');
$stmtV->execute([$companyId]);
$cntVehicles = (int)$stmtV->fetchColumn();

$stmtD = $db->prepare('SELECT COUNT(*) FROM drivers  WHERE company_id=? AND is_active=1');
$stmtD->execute([$companyId]);
$cntDrivers  = (int)$stmtD->fetchColumn();

// Licenses
$stmt = $db->prepare(
    'SELECT * FROM licenses WHERE company_id=? ORDER BY valid_until DESC'
);
$stmt->execute([$companyId]);
$licenses = $stmt->fetchAll();

$moduleMap = [
    'mod_core'             => ['label' => 'Core',             'icon' => 'speedometer2'],
    'mod_delegation'       => ['label' => 'Delegacje',        'icon' => 'map'],
    'mod_driver_analysis'  => ['label' => 'Analiza kierowców','icon' => 'bar-chart-line'],
    'mod_vehicle_analysis' => ['label' => 'Analiza pojazdów', 'icon' => 'truck-front'],
];

$pageTitle = 'Licencje: ' . $company['name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="index.php?page=dashboard" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h1 class="h4 fw-bold mb-0">
    <i class="bi bi-building me-2 text-primary"></i><?= liE($company['name']) ?>
  </h1>
  <a href="index.php?page=issue&company_id=<?= $companyId ?>" class="btn btn-primary btn-sm ms-auto">
    <i class="bi bi-plus-circle me-1"></i>Nowa licencja
  </a>
</div>

<div class="row g-4 mb-4">
  <!-- Company info -->
  <div class="col-md-5">
    <div class="li-card h-100 mb-0">
      <div class="li-card-header"><i class="bi bi-building text-primary"></i> Dane firmy</div>
      <div class="li-card-body">
        <dl class="row mb-0 small">
          <dt class="col-5 text-muted">NIP</dt>
          <dd class="col-7"><?= liE($company['nip'] ?? '—') ?></dd>
          <dt class="col-5 text-muted">E-mail</dt>
          <dd class="col-7"><?= liE($company['email'] ?? '—') ?></dd>
          <dt class="col-5 text-muted">Dodano</dt>
          <dd class="col-7"><?= fmtDate(substr($company['created_at'], 0, 10)) ?></dd>
          <dt class="col-12 text-muted">Unikalny kod</dt>
          <dd class="col-12">
            <code class="small text-break"><?= liE($company['unique_code']) ?></code>
          </dd>
        </dl>
      </div>
    </div>
  </div>

  <!-- Usage counts -->
  <div class="col-md-7">
    <div class="li-card h-100 mb-0">
      <div class="li-card-header"><i class="bi bi-bar-chart text-success"></i> Aktualne zużycie</div>
      <div class="li-card-body">
        <div class="row text-center g-3">
          <div class="col-4">
            <div class="h3 fw-bold text-primary"><?= $cntUsers ?></div>
            <div class="text-muted small"><i class="bi bi-people me-1"></i>Użytkownicy</div>
          </div>
          <div class="col-4">
            <div class="h3 fw-bold text-primary"><?= $cntVehicles ?></div>
            <div class="text-muted small"><i class="bi bi-truck me-1"></i>Pojazdy</div>
          </div>
          <div class="col-4">
            <div class="h3 fw-bold text-primary"><?= $cntDrivers ?></div>
            <div class="text-muted small"><i class="bi bi-person-badge me-1"></i>Kierowcy</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- License history -->
<div class="li-card">
  <div class="li-card-header">
    <i class="bi bi-clock-history text-secondary"></i>
    Historia licencji
    <span class="badge bg-secondary ms-auto"><?= count($licenses) ?></span>
  </div>
  <div class="table-responsive">
    <table class="li-table">
      <thead>
        <tr>
          <th>Wersja</th>
          <th>Data publikacji</th>
          <th>Ważna od</th>
          <th>Ważna do</th>
          <th>Limity (U/V/K)</th>
          <th>Moduły</th>
          <th>Status</th>
          <th>Klucz</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($licenses as $lic):
            $today   = new DateTime('today');
            $until   = new DateTime($lic['valid_until']);
            $expired = $until < $today;
            $soon    = !$expired && $until->diff($today)->days <= 30;
        ?>
        <tr>
          <td><span class="badge bg-primary"><?= liE($lic['version'] ?? '1.0') ?></span></td>
          <td><?= fmtDate($lic['published_at'] ?? null) ?></td>
          <td><?= fmtDate($lic['valid_from']) ?></td>
          <td><?= fmtDate($lic['valid_until']) ?></td>
          <td>
            <span title="Użytkownicy"><?= (int)($lic['max_users']    ?? 0) ?: '∞' ?></span> /
            <span title="Pojazdy"><?=     (int)($lic['max_vehicles'] ?? 0) ?: '∞' ?></span> /
            <span title="Kierowcy"><?=    (int)($lic['max_drivers']  ?? 0) ?: '∞' ?></span>
          </td>
          <td>
            <?php foreach ($moduleMap as $col => $info):
                if ($lic[$col] ?? 0): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle me-1" title="<?= liE($info['label']) ?>">
              <i class="bi bi-<?= $info['icon'] ?>"></i>
            </span>
            <?php endif; endforeach; ?>
          </td>
          <td>
            <?php if ($expired): ?>
              <span class="badge bg-danger">Wygasła</span>
            <?php elseif ($soon): ?>
              <span class="badge bg-warning text-dark">Wkrótce</span>
            <?php else: ?>
              <span class="badge bg-success">Aktywna</span>
            <?php endif; ?>
          </td>
          <td>
            <code class="text-muted" style="font-size:.7rem"
                  title="<?= liE($lic['license_key']) ?>"><?= liE(substr($lic['license_key'], 0, 12)) ?>…</code>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$licenses): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">Brak historii licencji dla tej firmy</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
