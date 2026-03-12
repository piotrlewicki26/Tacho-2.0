<?php
/**
 * TachoPro 2.0 – Driver Time Analysis Module
 * Parses uploaded DDD files and shows charts + violations.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();
requireModule('driver_analysis');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// ── Load driver filter ────────────────────────────────────────
$driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;

// All drivers
$stmt = $db->prepare('SELECT id, first_name, last_name FROM drivers WHERE company_id=? AND is_active=1 ORDER BY last_name,first_name');
$stmt->execute([$companyId]);
$allDrivers = $stmt->fetchAll();

// Latest DDD files for selected driver
$driverFiles = [];
if ($driverId) {
    $stmt = $db->prepare(
        "SELECT * FROM ddd_files
         WHERE company_id=? AND driver_id=? AND file_type='driver' AND is_deleted=0
         ORDER BY download_date DESC LIMIT 10"
    );
    $stmt->execute([$companyId, $driverId]);
    $driverFiles = $stmt->fetchAll();
}

// Selected file for analysis
$fileId = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
$selectedFile = null;
if ($fileId) {
    $stmt = $db->prepare("SELECT * FROM ddd_files WHERE id=? AND company_id=? AND is_deleted=0");
    $stmt->execute([$fileId, $companyId]);
    $selectedFile = $stmt->fetch();
}

$pageTitle  = 'Analiza czasu pracy kierowcy';
$activePage = 'driver_analysis';

include __DIR__ . '/../../templates/header.php';
?>

<div class="row g-3 mb-4">
  <!-- Filters -->
  <div class="col-md-4">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-funnel text-primary"></i>
        <span class="tp-card-title">Wybierz kierowcę i plik</span>
      </div>
      <div class="tp-card-body">
        <form method="GET" novalidate>
          <div class="mb-3">
            <label class="form-label fw-600">Kierowca</label>
            <select name="driver_id" class="form-select" onchange="this.form.submit()">
              <option value="">— Wybierz kierowcę —</option>
              <?php foreach ($allDrivers as $d): ?>
              <option value="<?= $d['id'] ?>"<?= $d['id']==$driverId?' selected':'' ?>>
                <?= e($d['last_name'] . ' ' . $d['first_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($driverId && $driverFiles): ?>
          <div class="mb-3">
            <label class="form-label fw-600">Plik DDD</label>
            <select name="file_id" class="form-select">
              <option value="">— Wybierz plik —</option>
              <?php foreach ($driverFiles as $f): ?>
              <option value="<?= $f['id'] ?>"<?= $f['id']==$fileId?' selected':'' ?>>
                <?= e($f['original_name']) ?> (<?= fmtDate($f['download_date']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-bar-chart-line me-1"></i>Analizuj
          </button>
          <?php elseif ($driverId): ?>
          <div class="alert alert-info py-2 small">
            Brak plików DDD dla tego kierowcy.
            <a href="/files.php">Wgraj plik</a>.
          </div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <!-- Info / legend -->
  <div class="col-md-8">
    <?php if (!$selectedFile): ?>
    <div class="tp-card h-100 d-flex align-items-center justify-content-center">
      <div class="tp-empty-state">
        <i class="bi bi-bar-chart-line"></i>
        <p>Wybierz kierowcę i plik DDD, aby zobaczyć analizę czasu pracy.</p>
      </div>
    </div>
    <?php else: ?>

    <?php
    // Parse the DDD file using PHP (basic binary analysis)
    $filePath = dddPhysPath($selectedFile, $companyId);
    $parseResult = null;
    $parseError  = null;

    if (is_file($filePath)) {
        $parseResult = parseDddFile($filePath);
    } else {
        $parseError = 'Plik fizyczny nie istnieje w archiwum.';
    }

    $days       = $parseResult['days']       ?? [];
    $summary    = $parseResult['summary']    ?? [];
    $violations = $summary['violations']     ?? [];
    ?>

    <!-- Summary cards -->
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon primary"><i class="bi bi-speedometer2"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor(($summary['drive']??0)/60) ?>h</div>
            <div class="tp-stat-label">Łącznie jazda</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon warning"><i class="bi bi-briefcase"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor(($summary['work']??0)/60) ?>h</div>
            <div class="tp-stat-label">Praca</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon success"><i class="bi bi-moon"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor(($summary['rest']??0)/60) ?>h</div>
            <div class="tp-stat-label">Odpoczynek</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon <?= count($violations)>0?'danger':'success' ?>">
            <i class="bi bi-<?= count($violations)>0?'exclamation-triangle':'check-circle' ?>"></i>
          </div>
          <div>
            <div class="tp-stat-value"><?= count($violations) ?></div>
            <div class="tp-stat-label">Naruszenia</div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($selectedFile && isset($days) && $days): ?>

<!-- ── Activity chart ─────────────────────────────────────────── -->
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-bar-chart-line text-primary"></i>
    <span class="tp-card-title">Czas aktywności wg dni</span>
  </div>
  <div class="tp-card-body">
    <div class="tp-chart-wrap" style="height:320px">
      <canvas id="activityChart"></canvas>
    </div>
  </div>
</div>

<!-- ── Violations ────────────────────────────────────────────── -->
<?php if ($violations): ?>
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-exclamation-triangle text-danger"></i>
    <span class="tp-card-title">Naruszenia</span>
    <span class="badge bg-danger ms-2"><?= count($violations) ?></span>
  </div>
  <div class="tp-card-body p-0">
    <table class="tp-table">
      <thead><tr><th>Data</th><th>Opis</th><th>Poziom</th></tr></thead>
      <tbody>
        <?php foreach ($violations as $v): ?>
        <tr>
          <td><?= fmtDate($v['date']) ?></td>
          <td><?= e($v['msg']) ?></td>
          <td>
            <span class="violation-<?= $v['type']==='error'?'error':'warn' ?>">
              <?= $v['type']==='error' ? 'Poważne' : 'Ostrzeżenie' ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── Daily breakdown ───────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-calendar-week text-secondary"></i>
    <span class="tp-card-title">Szczegółowe dane dzienne</span>
    <span class="badge bg-secondary ms-2"><?= count($days) ?> dni</span>
  </div>
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead>
          <tr>
            <th>Data</th>
            <th>Jazda</th>
            <th>Praca</th>
            <th>Dysp.</th>
            <th>Odp.</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($days as $day): ?>
          <?php $hasError = array_filter($day['viol'], fn($v)=>$v['type']==='error');
                $hasWarn  = array_filter($day['viol'], fn($v)=>$v['type']==='warn'); ?>
          <tr class="<?= $hasError?'table-danger':($hasWarn?'table-warning':'') ?>">
            <td><?= fmtDate($day['date']) ?></td>
            <td><?= floor($day['drive']/60) ?>h <?= $day['drive']%60 ?>m</td>
            <td><?= floor($day['work']/60) ?>h <?= $day['work']%60 ?>m</td>
            <td><?= floor($day['avail']/60) ?>h <?= $day['avail']%60 ?>m</td>
            <td><?= floor($day['rest']/60) ?>h <?= $day['rest']%60 ?>m</td>
            <td>
              <?php if ($hasError): ?>
                <span class="violation-error">Naruszenie</span>
              <?php elseif ($hasWarn): ?>
                <span class="violation-warn">Ostrzeżenie</span>
              <?php else: ?>
                <span class="violation-ok">OK</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
const actLabels = <?= json_encode(array_column($days, 'date')) ?>;
const driveMins = <?= json_encode(array_map(fn($d)=>round($d['drive']/60,1), $days)) ?>;
const workMins  = <?= json_encode(array_map(fn($d)=>round($d['work']/60,1), $days)) ?>;
const restMins  = <?= json_encode(array_map(fn($d)=>round($d['rest']/60,1), $days)) ?>;

new Chart(document.getElementById('activityChart'), {
  type: 'bar',
  data: {
    labels: actLabels,
    datasets: [
      { label: 'Jazda (h)',     data: driveMins, backgroundColor: 'rgba(13,110,253,0.8)',  borderRadius: 3 },
      { label: 'Praca (h)',     data: workMins,  backgroundColor: 'rgba(239,108,0,0.8)',   borderRadius: 3 },
      { label: 'Odpoczynek (h)',data: restMins,  backgroundColor: 'rgba(5,150,105,0.8)',   borderRadius: 3 },
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'top' } },
    scales: { x: { stacked: false }, y: { beginAtZero: true, title: { display: true, text: 'Godziny' } } }
  }
});
</script>

<?php elseif ($selectedFile && $parseError): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?= e($parseError) ?></div>
<?php elseif ($selectedFile): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Plik nie zawiera rozpoznawalnych danych aktywności.</div>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
