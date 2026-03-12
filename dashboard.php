<?php
/**
 * TachoPro 2.0 – Dashboard
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';

requireLogin();
requireModule('core');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// ── Driver card stats ─────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT
       SUM(CASE WHEN card_valid_until < CURDATE() THEN 1 ELSE 0 END)                        AS overdue,
       SUM(CASE WHEN card_valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS soon,
       SUM(CASE WHEN card_valid_until > DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS ok,
       COUNT(*) AS total
     FROM drivers WHERE company_id = ? AND is_active = 1"
);
$stmt->execute([$companyId]);
$cardStats = $stmt->fetch();

// ── Driver card downloads ─────────────────────────────────────
$stmt = $db->prepare(
    "SELECT d.id, d.first_name, d.last_name,
            cd.download_date, cd.next_required_date
     FROM drivers d
     LEFT JOIN card_downloads cd ON cd.id = (
         SELECT id FROM card_downloads WHERE driver_id = d.id ORDER BY download_date DESC LIMIT 1
     )
     WHERE d.company_id = ? AND d.is_active = 1
     ORDER BY cd.next_required_date ASC
     LIMIT 5"
);
$stmt->execute([$companyId]);
$cardDownloads = $stmt->fetchAll();

$dlStats = ['overdue'=>0,'soon'=>0];
foreach ($cardDownloads as $cd) {
    $s = downloadStatus($cd['next_required_date']);
    if ($s['class']==='danger')  $dlStats['overdue']++;
    elseif ($s['class']==='warning') $dlStats['soon']++;
}

// ── Vehicle downloads ─────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT v.id, v.registration, v.make, v.model,
            vd.download_date, vd.next_required_date
     FROM vehicles v
     LEFT JOIN vehicle_downloads vd ON vd.id = (
         SELECT id FROM vehicle_downloads WHERE vehicle_id = v.id ORDER BY download_date DESC LIMIT 1
     )
     WHERE v.company_id = ? AND v.is_active = 1
     ORDER BY vd.next_required_date ASC
     LIMIT 5"
);
$stmt->execute([$companyId]);
$vehDownloads = $stmt->fetchAll();

$vdStats = ['overdue'=>0,'soon'=>0];
foreach ($vehDownloads as $vd) {
    $s = downloadStatus($vd['next_required_date']);
    if ($s['class']==='danger')  $vdStats['overdue']++;
    elseif ($s['class']==='warning') $vdStats['soon']++;
}

// ── Vehicle calibrations ─────────────────────────────────────
$stmt = $db->prepare(
    "SELECT
       SUM(CASE WHEN next_calibration_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
       SUM(CASE WHEN next_calibration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS soon,
       SUM(CASE WHEN next_calibration_date > DATE_ADD(CURDATE(),INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS ok,
       COUNT(*) AS total
     FROM vehicles WHERE company_id = ? AND is_active = 1"
);
$stmt->execute([$companyId]);
$calibStats = $stmt->fetch();

// ── Quick counts ─────────────────────────────────────────────
$s = $db->prepare("SELECT COUNT(*) FROM drivers WHERE company_id=? AND is_active=1");
$s->execute([$companyId]);
$totalDrivers = (int)$s->fetchColumn();

$s = $db->prepare("SELECT COUNT(*) FROM vehicles WHERE company_id=? AND is_active=1");
$s->execute([$companyId]);
$totalVehicles = (int)$s->fetchColumn();

$s = $db->prepare("SELECT COUNT(*) FROM ddd_files WHERE company_id=? AND is_deleted=0");
$s->execute([$companyId]);
$totalFiles = (int)$s->fetchColumn();

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Przegląd systemu – ' . date('d.m.Y');
$activePage   = 'dashboard';

include __DIR__ . '/templates/header.php';
?>

<!-- ── Quick stats row ───────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <div class="tp-stat">
      <div class="tp-stat-icon primary"><i class="bi bi-person-badge"></i></div>
      <div>
        <div class="tp-stat-value"><?= $totalDrivers ?></div>
        <div class="tp-stat-label">Kierowców</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="tp-stat">
      <div class="tp-stat-icon success"><i class="bi bi-truck"></i></div>
      <div>
        <div class="tp-stat-value"><?= $totalVehicles ?></div>
        <div class="tp-stat-label">Pojazdów</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="tp-stat">
      <div class="<?= ($cardStats['overdue']??0)>0 ? 'tp-stat-icon danger' : 'tp-stat-icon success' ?>"><i class="bi bi-credit-card-2-front"></i></div>
      <div>
        <div class="tp-stat-value"><?= (int)($cardStats['overdue']??0) ?></div>
        <div class="tp-stat-label">Karty przeterminowane</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-xl-3">
    <div class="tp-stat">
      <div class="tp-stat-icon secondary"><i class="bi bi-archive"></i></div>
      <div>
        <div class="tp-stat-value"><?= $totalFiles ?></div>
        <div class="tp-stat-label">Pliki DDD</div>
      </div>
    </div>
  </div>
</div>

<!-- ── Row: Driver cards + downloads ────────────────────────── -->
<div class="row g-3 mb-4">

  <!-- Driver card validity -->
  <div class="col-md-6 col-xl-4">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-credit-card-2-front text-primary"></i>
        <span class="tp-card-title">Ważność kart kierowcy</span>
      </div>
      <div class="tp-card-body">
        <div class="d-flex flex-column gap-2">
          <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#fee2e2">
            <span class="fw-600 text-danger"><i class="bi bi-x-circle me-1"></i>Przeterminowane</span>
            <span class="badge bg-danger fs-6"><?= (int)($cardStats['overdue']??0) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#fef9c3">
            <span class="fw-600 text-warning"><i class="bi bi-exclamation-circle me-1"></i>Wkrótce wygasną</span>
            <span class="badge bg-warning text-dark fs-6"><?= (int)($cardStats['soon']??0) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#d1fae5">
            <span class="fw-600 text-success"><i class="bi bi-check-circle me-1"></i>Aktualne</span>
            <span class="badge bg-success fs-6"><?= (int)($cardStats['ok']??0) ?></span>
          </div>
        </div>
      </div>
      <div class="tp-card-footer">
        <a href="/drivers.php" class="btn btn-sm btn-outline-primary">Zarządzaj kierowcami</a>
      </div>
    </div>
  </div>

  <!-- Card downloads -->
  <div class="col-md-6 col-xl-4">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-cloud-download text-info"></i>
        <span class="tp-card-title">Pobrania kart – status</span>
        <?php if ($dlStats['overdue']>0): ?>
          <span class="badge bg-danger ms-auto"><?= $dlStats['overdue'] ?> po terminie</span>
        <?php endif; ?>
      </div>
      <div class="tp-card-body p-0">
        <div class="table-responsive">
          <table class="tp-table">
            <thead>
              <tr>
                <th>Kierowca</th>
                <th>Ostatnie pobranie</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cardDownloads as $cd): ?>
              <?php $st = downloadStatus($cd['next_required_date']); ?>
              <tr>
                <td><?= e($cd['first_name'] . ' ' . $cd['last_name']) ?></td>
                <td><?= fmtDate($cd['download_date']) ?></td>
                <td><span class="badge bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$cardDownloads): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">Brak danych</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Calibrations -->
  <div class="col-md-6 col-xl-4">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-tools text-warning"></i>
        <span class="tp-card-title">Legalizacje tachografów</span>
      </div>
      <div class="tp-card-body">
        <div class="d-flex flex-column gap-2">
          <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#fee2e2">
            <span class="fw-600 text-danger"><i class="bi bi-x-circle me-1"></i>Przeterminowane</span>
            <span class="badge bg-danger fs-6"><?= (int)($calibStats['overdue']??0) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#fef9c3">
            <span class="fw-600 text-warning"><i class="bi bi-exclamation-circle me-1"></i>Wkrótce</span>
            <span class="badge bg-warning text-dark fs-6"><?= (int)($calibStats['soon']??0) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background:#d1fae5">
            <span class="fw-600 text-success"><i class="bi bi-check-circle me-1"></i>Aktualne</span>
            <span class="badge bg-success fs-6"><?= (int)($calibStats['ok']??0) ?></span>
          </div>
        </div>
      </div>
      <div class="tp-card-footer">
        <a href="/vehicles.php" class="btn btn-sm btn-outline-primary">Zarządzaj pojazdami</a>
      </div>
    </div>
  </div>

</div>

<!-- ── Row: Vehicle downloads ────────────────────────────────── -->
<div class="row g-3">
  <div class="col-lg-8">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-cloud-download text-success"></i>
        <span class="tp-card-title">Pobrania danych pojazdów – 5 ostatnich</span>
        <?php if ($vdStats['overdue']>0): ?>
          <span class="badge bg-danger ms-auto"><?= $vdStats['overdue'] ?> po terminie</span>
        <?php endif; ?>
      </div>
      <div class="tp-card-body p-0">
        <div class="table-responsive">
          <table class="tp-table">
            <thead>
              <tr>
                <th>Pojazd</th>
                <th>Ostatnie pobranie</th>
                <th>Następne do</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($vehDownloads as $vd): ?>
              <?php $st = downloadStatus($vd['next_required_date']); ?>
              <tr>
                <td>
                  <strong><?= e($vd['registration']) ?></strong>
                  <small class="text-muted ms-1"><?= e($vd['make'] . ' ' . $vd['model']) ?></small>
                </td>
                <td><?= fmtDate($vd['download_date']) ?></td>
                <td><?= fmtDate($vd['next_required_date']) ?></td>
                <td><span class="badge bg-<?= e($st['class']) ?>"><?= e($st['label']) ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$vehDownloads): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Brak danych</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-info-circle text-primary"></i>
        <span class="tp-card-title">Szybki dostęp</span>
      </div>
      <div class="tp-card-body d-flex flex-column gap-2">
        <a href="/drivers.php?action=add" class="btn btn-outline-primary btn-sm text-start">
          <i class="bi bi-person-plus me-2"></i>Dodaj kierowcę
        </a>
        <a href="/vehicles.php?action=add" class="btn btn-outline-success btn-sm text-start">
          <i class="bi bi-plus-circle me-2"></i>Dodaj pojazd
        </a>
        <button class="btn btn-outline-info btn-sm text-start"
                data-bs-toggle="modal" data-bs-target="#dddUploadModal">
          <i class="bi bi-cloud-upload me-2"></i>Wgraj plik DDD
        </button>
        <a href="/files.php" class="btn btn-outline-secondary btn-sm text-start">
          <i class="bi bi-archive me-2"></i>Archiwum DDD
        </a>
        <a href="/reports.php" class="btn btn-outline-dark btn-sm text-start">
          <i class="bi bi-file-earmark-bar-graph me-2"></i>Raporty
        </a>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
