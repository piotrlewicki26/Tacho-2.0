<?php
/**
 * TachoPro 2.0 – Vehicle File Analysis Module
 * Analyses DDD files from tachograph mass memory.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();
requireModule('vehicle_analysis');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

$vehicleId = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$rawFrom   = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
$rawTo     = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

$stmt = $db->prepare('SELECT id, registration, make, model FROM vehicles WHERE company_id=? AND is_active=1 ORDER BY registration');
$stmt->execute([$companyId]);
$allVehicles = $stmt->fetchAll();

$vehicleFiles = [];
if ($vehicleId) {
    $stmt = $db->prepare(
        "SELECT * FROM ddd_files
         WHERE company_id=? AND vehicle_id=? AND file_type='vehicle' AND is_deleted=0
         ORDER BY download_date DESC LIMIT 10"
    );
    $stmt->execute([$companyId, $vehicleId]);
    $vehicleFiles = $stmt->fetchAll();
}

$vehDays    = [];
$vehSummary = [];
$parseError = null;
$dataDateMin = null;
$dataDateMax = null;
$dateFrom = date('Y-m-d', strtotime('-27 days'));
$dateTo   = date('Y-m-d');

if ($vehicleId) {
    try {
        backfillVehicleActivityCalendar($db, $companyId, $vehicleId);

        $rangeStmt = $db->prepare(
            'SELECT MIN(`date`) AS dmin, MAX(`date`) AS dmax
             FROM vehicle_activity_calendar
             WHERE company_id=? AND vehicle_id=?'
        );
        $rangeStmt->execute([$companyId, $vehicleId]);
        $range = $rangeStmt->fetch();
        if ($range && $range['dmin']) {
            $dataDateMin = $range['dmin'];
            $dataDateMax = $range['dmax'];
        }

        if ($rawFrom !== '' || $rawTo !== '') {
            $fallbackFrom = $dataDateMin ?? $dateFrom;
            $fallbackTo   = $dataDateMax ?? $dateTo;
            $dateFrom = $rawFrom !== '' ? $rawFrom : $fallbackFrom;
            $dateTo   = $rawTo   !== '' ? $rawTo   : $fallbackTo;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $dataDateMin ?? date('Y-m-d', strtotime('-27 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $dataDateMax ?? date('Y-m-d');
        if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

        $rows = $db->prepare(
            'SELECT `date`, dist_km AS km
             FROM vehicle_activity_calendar
             WHERE company_id=? AND vehicle_id=? AND `date` BETWEEN ? AND ?
             ORDER BY `date` ASC'
        );
        $rows->execute([$companyId, $vehicleId, $dateFrom, $dateTo]);
        $vehDays = $rows->fetchAll();

        $totalKm = 0;
        $daysActive = 0;
        foreach ($vehDays as $d) {
            $km = (int)($d['km'] ?? 0);
            $totalKm += $km;
            if ($km > 0) $daysActive++;
        }
        $vehSummary = ['total_km' => $totalKm, 'days_active' => $daysActive];
    } catch (Throwable $e) {
        $parseError = 'Nie udało się załadować aktywności pojazdu.';
        error_log('vehicle_analysis: load error for vehicle ' . $vehicleId . ': ' . $e->getMessage());
    }
}

$pageTitle  = 'Analiza danych pojazdu';
$activePage = 'vehicle_analysis';
include __DIR__ . '/../../templates/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-funnel text-success"></i>
        <span class="tp-card-title">Wybierz pojazd</span>
      </div>
      <div class="tp-card-body">
        <form method="GET" novalidate>
          <div class="mb-3">
            <label class="form-label fw-600">Pojazd</label>
            <select name="vehicle_id" class="form-select" onchange="this.form.submit()">
              <option value="">— Wybierz pojazd —</option>
              <?php foreach ($allVehicles as $v): ?>
              <option value="<?= $v['id'] ?>"<?= $v['id']==$vehicleId?' selected':'' ?>>
                <?= e($v['registration']) ?><?= $v['make']?' – '.$v['make'].' '.$v['model']:'' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($vehicleId): ?>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label mb-1 small text-muted">Od</label>
              <input type="date" name="from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-6">
              <label class="form-label mb-1 small text-muted">Do</label>
              <input type="date" name="to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
            </div>
          </div>
          <?php
            $q28From = date('Y-m-d', strtotime('-27 days'));
            $q28To   = date('Y-m-d');
            $q3mFrom = date('Y-m-d', strtotime('-3 months'));
            $q3mTo   = date('Y-m-d');
            $is28    = ($dateFrom === $q28From && $dateTo === $q28To);
            $is3m    = ($dateFrom === $q3mFrom && $dateTo === $q3mTo);
            $isAll   = ($dataDateMin && $dataDateMax && $dateFrom === $dataDateMin && $dateTo === $dataDateMax);
          ?>
          <div class="d-flex gap-1 mb-3">
            <a href="?vehicle_id=<?= $vehicleId ?>&from=<?= $q28From ?>&to=<?= $q28To ?>"
               class="btn btn-xs <?= $is28 ? 'btn-primary' : 'btn-outline-primary' ?> flex-fill">28 dni</a>
            <a href="?vehicle_id=<?= $vehicleId ?>&from=<?= $q3mFrom ?>&to=<?= $q3mTo ?>"
               class="btn btn-xs <?= $is3m ? 'btn-success' : 'btn-outline-success' ?> flex-fill">3 mies.</a>
            <?php if ($dataDateMin && $dataDateMax): ?>
            <a href="?vehicle_id=<?= $vehicleId ?>&from=<?= $dataDateMin ?>&to=<?= $dataDateMax ?>"
               class="btn btn-xs <?= $isAll ? 'btn-secondary' : 'btn-outline-secondary' ?> flex-fill">Całość</a>
            <?php endif; ?>
          </div>

          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-search me-1"></i>Filtruj
          </button>
          <?php if ($dataDateMin): ?>
          <div class="text-muted small mt-2">
            Dane: <?= fmtDate($dataDateMin) ?> – <?= fmtDate($dataDateMax) ?><br>
            Pliki DDD: <?= count($vehicleFiles) ?>
          </div>
          <?php endif; ?>
          <?php endif; ?>
          <?php if ($vehicleId && !$vehicleFiles): ?>
          <div class="alert alert-info py-2 small">Brak plików DDD dla tego pojazdu.</div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <?php if (!$vehicleId): ?>
    <div class="tp-card h-100 d-flex align-items-center justify-content-center">
      <div class="tp-empty-state">
        <i class="bi bi-truck-front"></i>
        <p>Wybierz pojazd, aby zobaczyć kalendarz i oś czasu aktywności.</p>
      </div>
    </div>
    <?php elseif ($parseError): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= e($parseError) ?></div>
    <?php else: ?>
    <div class="row g-3">
      <div class="col-6">
        <div class="tp-stat">
          <div class="tp-stat-icon success"><i class="bi bi-speedometer"></i></div>
          <div>
            <div class="tp-stat-value"><?= number_format($vehSummary['total_km'] ?? 0) ?></div>
            <div class="tp-stat-label">Łącznie km (zakres)</div>
          </div>
        </div>
      </div>
      <div class="col-6">
        <div class="tp-stat">
          <div class="tp-stat-icon primary"><i class="bi bi-calendar-check"></i></div>
          <div>
            <div class="tp-stat-value"><?= $vehSummary['days_active'] ?? 0 ?></div>
            <div class="tp-stat-label">Dni aktywnych</div>
          </div>
        </div>
      </div>
    </div>
    <?php if (!$vehDays): ?>
    <div class="tp-empty-state py-4">
      <i class="bi bi-calendar-x"></i>
      <p class="mt-2 mb-1 fw-600">Brak aktywności w wybranym zakresie</p>
      <p class="text-muted small mb-0">
        <?php if ($dataDateMin): ?>
        Spróbuj zakresu <a href="?vehicle_id=<?= $vehicleId ?>&from=<?= $dataDateMin ?>&to=<?= $dataDateMax ?>">Całość</a>.
        <?php else: ?>
        Wgraj plik DDD pojazdu, aby zapełnić kalendarz.
        <?php endif; ?>
      </p>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($vehDays): ?>
<!-- ── Daily KM chart ─────────────────────────────────────────── -->
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-bar-chart text-success"></i>
    <span class="tp-card-title">Oś czasu aktywności pojazdu (km dziennie)</span>
  </div>
  <div class="tp-card-body">
    <div class="tp-chart-wrap" style="height:300px">
      <canvas id="kmChart"></canvas>
    </div>
  </div>
</div>

<!-- ── Daily table ────────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-table text-secondary"></i>
    <span class="tp-card-title">Kalendarz aktywności pojazdu</span>
    <span class="badge bg-secondary ms-2"><?= count($vehDays) ?> rekordów</span>
  </div>
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead><tr><th>Data</th><th class="text-end">Przebieg (km)</th></tr></thead>
        <tbody>
          <?php foreach ($vehDays as $day): ?>
          <tr>
            <td><?= fmtDate($day['date']) ?></td>
            <td class="text-end"><?= number_format($day['km']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
new Chart(document.getElementById('kmChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($vehDays, 'date')) ?>,
    datasets: [{
      label: 'Przebieg (km)',
      data: <?= json_encode(array_column($vehDays, 'km')) ?>,
      borderColor: '#059669',
      backgroundColor: 'rgba(5,150,105,0.15)',
      fill: true,
      tension: 0.3,
      pointRadius: 3,
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, title: { display: true, text: 'km' } } }
  }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
