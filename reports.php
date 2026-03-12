<?php
/**
 * TachoPro 2.0 – Reports
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';
require_once __DIR__ . '/includes/subscription.php';

requireLogin();
requireModule('core');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$_isDemoReport = isDemo($companyId);

// ── Summary stats ─────────────────────────────────────────────
$drivers = (function() use ($db, $companyId) {
    $s = $db->prepare('SELECT COUNT(*) FROM drivers WHERE company_id=? AND is_active=1');
    $s->execute([$companyId]);
    return (int)$s->fetchColumn();
})();

$vehicles = (function() use ($db, $companyId) {
    $s = $db->prepare('SELECT COUNT(*) FROM vehicles WHERE company_id=? AND is_active=1');
    $s->execute([$companyId]);
    return (int)$s->fetchColumn();
})();

$filesTotal = (function() use ($db, $companyId) {
    $s = $db->prepare('SELECT COUNT(*) FROM ddd_files WHERE company_id=? AND is_deleted=0');
    $s->execute([$companyId]);
    return (int)$s->fetchColumn();
})();

// Monthly uploads (last 12 months)
$stmt = $db->prepare(
    "SELECT DATE_FORMAT(uploaded_at,'%Y-%m') AS month, COUNT(*) AS cnt
     FROM ddd_files
     WHERE company_id=? AND is_deleted=0
       AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     GROUP BY month ORDER BY month ASC"
);
$stmt->execute([$companyId]);
$monthlyUploads = $stmt->fetchAll();

// Card expiry overview
$stmt = $db->prepare(
    "SELECT
       SUM(CASE WHEN card_valid_until < CURDATE() THEN 1 ELSE 0 END) AS overdue,
       SUM(CASE WHEN card_valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS soon,
       SUM(CASE WHEN card_valid_until > DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS ok
     FROM drivers WHERE company_id=? AND is_active=1"
);
$stmt->execute([$companyId]);
$cardExpiry = $stmt->fetch();

// Calibration overview
$stmt = $db->prepare(
    "SELECT
       SUM(CASE WHEN next_calibration_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
       SUM(CASE WHEN next_calibration_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS soon,
       SUM(CASE WHEN next_calibration_date > DATE_ADD(CURDATE(),INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS ok
     FROM vehicles WHERE company_id=? AND is_active=1"
);
$stmt->execute([$companyId]);
$calibExpiry = $stmt->fetch();

// Files by type
$stmt = $db->prepare(
    "SELECT file_type, COUNT(*) AS cnt
     FROM ddd_files WHERE company_id=? AND is_deleted=0 GROUP BY file_type"
);
$stmt->execute([$companyId]);
$filesByType = [];
foreach ($stmt->fetchAll() as $r) { $filesByType[$r['file_type']] = $r['cnt']; }

// Prepare chart data
$chartLabels = array_column($monthlyUploads, 'month');
$chartData   = array_column($monthlyUploads, 'cnt');

$pageTitle    = 'Raporty';
$pageSubtitle = 'Przegląd statystyk systemu' . ($_isDemoReport ? ' – WERSJA DEMONSTRACYJNA' : '');
$activePage   = 'reports';
include __DIR__ . '/templates/header.php';

if ($_isDemoReport): ?>
<div class="alert alert-warning d-print-none mb-4">
  <i class="bi bi-exclamation-triangle me-2"></i>
  <strong>Wersja demonstracyjna</strong> – wydruki zawierają znak wodny "DEMO".
  <a href="/billing.php#upgrade-section" class="alert-link ms-2">Upgrade do Pro &rarr;</a>
</div>
<script>document.body.classList.add("tp-demo");</script>
<?php endif; ?>

<!-- ── Stats row ─────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="tp-stat">
      <div class="tp-stat-icon primary"><i class="bi bi-person-badge"></i></div>
      <div><div class="tp-stat-value"><?= $drivers ?></div><div class="tp-stat-label">Kierowcy</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="tp-stat">
      <div class="tp-stat-icon success"><i class="bi bi-truck"></i></div>
      <div><div class="tp-stat-value"><?= $vehicles ?></div><div class="tp-stat-label">Pojazdy</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="tp-stat">
      <div class="tp-stat-icon secondary"><i class="bi bi-archive"></i></div>
      <div><div class="tp-stat-value"><?= $filesTotal ?></div><div class="tp-stat-label">Pliki DDD</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="tp-stat">
      <div class="tp-stat-icon <?= ($cardExpiry['overdue']??0)>0?'danger':'success' ?>">
        <i class="bi bi-credit-card-2-front"></i>
      </div>
      <div><div class="tp-stat-value"><?= (int)($cardExpiry['overdue']??0) ?></div><div class="tp-stat-label">Karty po terminie</div></div>
    </div>
  </div>
</div>

<!-- ── Charts ────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
  <!-- Monthly uploads chart -->
  <div class="col-lg-8">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-bar-chart-line text-primary"></i>
        <span class="tp-card-title">Wgrania plików DDD (ostatnie 12 miesięcy)</span>
      </div>
      <div class="tp-card-body">
        <div class="tp-chart-wrap">
          <canvas id="uploadsChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Card expiry pie -->
  <div class="col-lg-4">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-pie-chart text-info"></i>
        <span class="tp-card-title">Ważność kart kierowcy</span>
      </div>
      <div class="tp-card-body">
        <div class="tp-chart-wrap">
          <canvas id="cardExpiryChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Calibrations pie -->
  <div class="col-md-4">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-tools text-warning"></i>
        <span class="tp-card-title">Legalizacje tachografów</span>
      </div>
      <div class="tp-card-body">
        <div class="tp-chart-wrap">
          <canvas id="calibChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Files by type -->
  <div class="col-md-4">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-pie-chart text-success"></i>
        <span class="tp-card-title">Pliki DDD wg typu</span>
      </div>
      <div class="tp-card-body">
        <div class="tp-chart-wrap">
          <canvas id="fileTypeChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick summary table -->
  <div class="col-md-4">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-list-check text-secondary"></i>
        <span class="tp-card-title">Podsumowanie</span>
      </div>
      <div class="tp-card-body">
        <table class="tp-table">
          <tbody>
            <tr><td>Karty przeterminowane</td>
                <td class="text-end"><span class="badge bg-danger"><?= (int)($cardExpiry['overdue']??0) ?></span></td></tr>
            <tr><td>Karty wkrótce wygasające</td>
                <td class="text-end"><span class="badge bg-warning text-dark"><?= (int)($cardExpiry['soon']??0) ?></span></td></tr>
            <tr><td>Karty aktualne</td>
                <td class="text-end"><span class="badge bg-success"><?= (int)($cardExpiry['ok']??0) ?></span></td></tr>
            <tr><td>Legalizacje po terminie</td>
                <td class="text-end"><span class="badge bg-danger"><?= (int)($calibExpiry['overdue']??0) ?></span></td></tr>
            <tr><td>Legalizacje wkrótce</td>
                <td class="text-end"><span class="badge bg-warning text-dark"><?= (int)($calibExpiry['soon']??0) ?></span></td></tr>
            <tr><td>Legalizacje OK</td>
                <td class="text-end"><span class="badge bg-success"><?= (int)($calibExpiry['ok']??0) ?></span></td></tr>
            <tr><td>Pliki DDD – karty</td>
                <td class="text-end"><span class="badge bg-primary"><?= (int)($filesByType['driver']??0) ?></span></td></tr>
            <tr><td>Pliki DDD – pojazdy</td>
                <td class="text-end"><span class="badge bg-success"><?= (int)($filesByType['vehicle']??0) ?></span></td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
const uploadsCtx = document.getElementById('uploadsChart');
new Chart(uploadsCtx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [{
      label: 'Wgrania DDD',
      data: <?= json_encode($chartData) ?>,
      backgroundColor: 'rgba(13,110,253,0.7)',
      borderColor: '#0d6efd',
      borderWidth: 1,
      borderRadius: 4,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
  }
});

new Chart(document.getElementById('cardExpiryChart'), {
  type: 'doughnut',
  data: {
    labels: ['Przeterminowane','Wkrótce','Aktualne'],
    datasets: [{ data: [<?= (int)($cardExpiry['overdue']??0) ?>,<?= (int)($cardExpiry['soon']??0) ?>,<?= (int)($cardExpiry['ok']??0) ?>],
      backgroundColor: ['#dc2626','#d97706','#059669'] }]
  },
  options: { responsive: true, maintainAspectRatio: false, cutout: '65%',
    plugins: { legend: { position:'bottom', labels:{ boxWidth:12 } } } }
});

new Chart(document.getElementById('calibChart'), {
  type: 'doughnut',
  data: {
    labels: ['Przeterminowane','Wkrótce','Aktualne'],
    datasets: [{ data: [<?= (int)($calibExpiry['overdue']??0) ?>,<?= (int)($calibExpiry['soon']??0) ?>,<?= (int)($calibExpiry['ok']??0) ?>],
      backgroundColor: ['#dc2626','#d97706','#059669'] }]
  },
  options: { responsive: true, maintainAspectRatio: false, cutout: '65%',
    plugins: { legend: { position:'bottom', labels:{ boxWidth:12 } } } }
});

new Chart(document.getElementById('fileTypeChart'), {
  type: 'pie',
  data: {
    labels: ['Karty kierowców','Pojazdy'],
    datasets: [{ data: [<?= (int)($filesByType['driver']??0) ?>,<?= (int)($filesByType['vehicle']??0) ?>],
      backgroundColor: ['#0d6efd','#059669'] }]
  },
  options: { responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position:'bottom', labels:{ boxWidth:12 } } } }
});
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
