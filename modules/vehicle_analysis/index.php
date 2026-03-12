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

$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
$selectedFile = null;
if ($fileId) {
    $stmt = $db->prepare("SELECT * FROM ddd_files WHERE id=? AND company_id=? AND is_deleted=0");
    $stmt->execute([$fileId, $companyId]);
    $selectedFile = $stmt->fetch();
}

// ── Parse vehicle DDD file ────────────────────────────────────
function parseVehicleDdd(string $path): array {
    $data = file_get_contents($path);
    if ($data === false) return ['error' => 'Nie można odczytać pliku.'];
    $len = strlen($data);
    if ($len < 200) return ['error' => 'Plik jest zbyt mały.'];

    $days = [];
    $summary = ['total_km' => 0, 'days_active' => 0, 'drivers' => []];

    for ($i = 0; $i < $len - 8; $i += 2) {
        if ($i + 8 > $len) break;
        $ts  = unpack('N', substr($data, $i, 4))[1];
        $yr  = (int)gmdate('Y', $ts);
        if ($yr < 2015 || $yr > 2030) continue;

        $pres = unpack('n', substr($data, $i+4, 2))[1];
        $dist = unpack('n', substr($data, $i+6, 2))[1];
        if ($pres < 100 || $pres > 10000 || $dist > 1500) continue;

        $dateKey = gmdate('Y-m-d', $ts);
        if (isset($days[$dateKey])) continue;

        $days[$dateKey] = [
            'date' => $dateKey,
            'km'   => $dist,
        ];
        $summary['total_km']    += $dist;
        $summary['days_active'] += ($dist > 0 ? 1 : 0);
    }

    ksort($days);
    return ['days' => array_values($days), 'summary' => $summary];
}

$vehDays    = [];
$vehSummary = [];
$parseError = null;

if ($selectedFile) {
    $filePath = __DIR__ . '/../../uploads/ddd/' . $companyId . '/' . $selectedFile['stored_name'];
    if (is_file($filePath)) {
        $result     = parseVehicleDdd($filePath);
        $vehDays    = $result['days']    ?? [];
        $vehSummary = $result['summary'] ?? [];
        if (isset($result['error'])) $parseError = $result['error'];
    } else {
        $parseError = 'Plik fizyczny nie istnieje.';
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
        <span class="tp-card-title">Wybierz pojazd i plik</span>
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
          <?php if ($vehicleId && $vehicleFiles): ?>
          <div class="mb-3">
            <label class="form-label fw-600">Plik DDD pojazdu</label>
            <select name="file_id" class="form-select">
              <option value="">— Wybierz plik —</option>
              <?php foreach ($vehicleFiles as $f): ?>
              <option value="<?= $f['id'] ?>"<?= $f['id']==$fileId?' selected':'' ?>>
                <?= e($f['original_name']) ?> (<?= fmtDate($f['download_date']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-success w-100">
            <i class="bi bi-truck-front me-1"></i>Analizuj
          </button>
          <?php elseif ($vehicleId): ?>
          <div class="alert alert-info py-2 small">Brak plików DDD dla tego pojazdu.</div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <?php if (!$selectedFile): ?>
    <div class="tp-card h-100 d-flex align-items-center justify-content-center">
      <div class="tp-empty-state">
        <i class="bi bi-truck-front"></i>
        <p>Wybierz pojazd i plik DDD, aby zobaczyć analizę.</p>
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
            <div class="tp-stat-label">Łącznie km (z danych)</div>
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
    <?php endif; ?>
  </div>
</div>

<?php if ($vehDays): ?>
<!-- ── Daily KM chart ─────────────────────────────────────────── -->
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-bar-chart text-success"></i>
    <span class="tp-card-title">Przebieg dzienny (km z pliku DDD)</span>
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
    <span class="tp-card-title">Dane dzienne</span>
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
