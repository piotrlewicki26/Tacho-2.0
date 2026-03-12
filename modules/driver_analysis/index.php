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

/**
 * PHP DDD parser – mirrors the JSX parseDDD() algorithm from truck-delegate-pro.jsx.
 *
 * Record header layout (EU Reg. 165/2014 Annex 1B/1C):
 *   TimeReal(4) + presenceCounter(2) + distanceKm(2) + activity entries(2 each)
 * Activity entry bit layout:
 *   bit15 = slot (0=driver, 1=co-driver), bits14-11 = activity (0=REST,1=AVAIL,2=WORK,3=DRIVE),
 *   bits10-0 = time in minutes from midnight
 */
function parseDddFile(string $path): array {
    $data = file_get_contents($path);
    if ($data === false) return ['error' => 'Nie można odczytać pliku.'];
    $len  = strlen($data);
    if ($len < 100) return ['error' => 'Plik jest zbyt mały.'];

    $EU_MAX_DAY   = 540;   // 9 h
    $EU_MAX_DAY_X = 600;   // 10 h extended
    $EU_MAX_CONT  = 270;   // 4 h 30 m continuous drive
    $EU_MIN_REST  = 660;   // 11 h daily rest

    $empty = ['days' => [], 'summary' => ['drive'=>0,'work'=>0,'rest'=>0,'avail'=>0,'violations'=>[]]];

    // ── Step 1: Collect candidate record headers ───────────────────────────────
    // Use a dynamic 5-year window (JSX uses 2023-2027; we slide with the current year
    // so the parser keeps working for future card downloads without code changes).
    $curYear = (int)gmdate('Y');
    $yrMin   = $curYear - 3;
    $yrMax   = $curYear + 1;
    $cands = [];
    for ($i = 0; $i < $len - 8; $i += 2) {
        $ts   = unpack('N', substr($data, $i, 4))[1];
        $yr   = (int)gmdate('Y', $ts);
        if ($yr < $yrMin || $yr > $yrMax) continue;

        $pres = unpack('n', substr($data, $i+4, 2))[1];
        $dist = unpack('n', substr($data, $i+6, 2))[1];
        if ($pres < 500 || $pres > 8000 || $dist > 1100) continue;

        $cands[] = ['off' => $i, 'ts' => $ts, 'pres' => $pres, 'dist' => $dist];
    }
    if (!$cands) return $empty;

    // ── Step 2: Deduplicate by date – keep median presenceCounter per date ─────
    $byDate = [];
    foreach ($cands as $c) {
        $byDate[gmdate('Y-m-d', $c['ts'])][] = $c;
    }
    $deduped = [];
    foreach ($byDate as $arr) {
        usort($arr, fn($a,$b) => $a['pres'] - $b['pres']);
        $deduped[] = $arr[(int)(count($arr) / 2)];
    }

    // ── Step 3: Sort by presenceCounter (chronological order) ─────────────────
    usort($deduped, fn($a,$b) => $a['pres'] - $b['pres']);

    // ── Step 4: IQR outlier filtering ─────────────────────────────────────────
    $presVals = array_column($deduped, 'pres');
    sort($presVals);
    $n = count($presVals);
    if ($n >= 4) {
        $p25 = $presVals[(int)($n * 0.25)];
        $p75 = $presVals[(int)($n * 0.75)];
        $iqr = $p75 - $p25;
        $pMin = $p25 - 3 * $iqr;
        $pMax = $p75 + 3 * $iqr;
        $filtered = array_values(array_filter($deduped, fn($c) => $c['pres'] >= $pMin && $c['pres'] <= $pMax));
    } else {
        $filtered = $deduped;
    }
    if (!$filtered) return $empty;

    // ── Step 5: Build next-record-offset lookup ────────────────────────────────
    $offsets = array_column($filtered, 'off');
    sort($offsets);
    $offMap  = array_flip($offsets);   // offset → index in sorted array

    // ── Step 6: Parse activity entries per record ──────────────────────────────
    $days = [];
    foreach ($filtered as $r) {
        // Bound the scan to the next record's start (≤ 600 B)
        $myIdx     = $offMap[$r['off']] ?? -1;
        $nextRec   = ($myIdx >= 0 && $myIdx < count($offsets) - 1)
                     ? $offsets[$myIdx + 1]
                     : $r['off'] + 400;
        $bound     = min($nextRec, $r['off'] + 600, $len - 1);

        // Collect raw slot/activity/time triples
        $pts = [];
        for ($j = $r['off'] + 8; $j < $bound - 1; $j += 2) {
            $raw  = unpack('n', substr($data, $j, 2))[1];
            $slot = ($raw >> 15) & 1;
            $act  = ($raw >> 11) & 7;
            $tmin = $raw & 0x7FF;
            if ($slot === 0 && $act <= 3 && $tmin >= 0 && $tmin <= 1440) {
                $pts[] = ['act' => $act, 'tmin' => $tmin];
            }
        }

        // Strictly-monotonic time filter (JSX: only strictly-increasing tmin)
        $mono = []; $lt = -1;
        foreach ($pts as $p) {
            if ($p['tmin'] > $lt) { $mono[] = $p; $lt = $p['tmin']; }
        }

        // Build duration slots
        $slots = [];
        $mCnt  = count($mono);
        for ($k = 0; $k < $mCnt; $k++) {
            $end = ($k < $mCnt - 1) ? $mono[$k + 1]['tmin'] : 1440;
            $dur = $end - $mono[$k]['tmin'];
            if ($dur > 0) {
                $slots[] = ['act' => $mono[$k]['act'], 'start' => $mono[$k]['tmin'], 'end' => $end, 'dur' => $dur];
            }
        }

        // Validate total minutes: 1350 ≤ total ≤ 1460 (JSX requirement)
        $total = array_sum(array_column($slots, 'dur'));
        if ($total < 1350 || $total > 1460) continue;

        $dateKey    = gmdate('Y-m-d', $r['ts']);
        $driveTotal = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 3), 'dur'));
        $restTotal  = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 0), 'dur'));
        $workTotal  = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 2), 'dur'));
        $availTotal = array_sum(array_column(array_filter($slots, fn($s) => $s['act'] === 1), 'dur'));

        // EU regulation violations
        $viol = [];
        if ($driveTotal > $EU_MAX_DAY_X) {
            $viol[] = ['type'=>'error','msg'=>'Przekroczenie czasu jazdy: '.floor($driveTotal/60).'h '.($driveTotal%60).'m (max '.floor($EU_MAX_DAY_X/60).'h)'];
        } elseif ($driveTotal > $EU_MAX_DAY) {
            $viol[] = ['type'=>'warn','msg'=>'Wydłużony czas jazdy: '.floor($driveTotal/60).'h '.($driveTotal%60).'m'];
        }
        if ($restTotal < $EU_MIN_REST && $driveTotal > 60) {
            $viol[] = ['type'=>'warn','msg'=>'Niewystarczający odpoczynek: '.floor($restTotal/60).'h '.($restTotal%60).'m (min 11h)'];
        }
        $cont = 0; $maxCont = 0;
        foreach ($slots as $seg) {
            if ($seg['act'] === 3) { $cont += $seg['dur']; $maxCont = max($maxCont, $cont); }
            elseif ($seg['act'] === 0 && $seg['dur'] >= 15) { $cont = 0; }
        }
        if ($maxCont > $EU_MAX_CONT) {
            $viol[] = ['type'=>'warn','msg'=>'Przekroczenie ciągłego czasu jazdy: '.floor($maxCont/60).'h '.($maxCont%60).'m (max 4h30m)'];
        }

        $days[$dateKey] = [
            'date'  => $dateKey,
            'drive' => $driveTotal,
            'work'  => $workTotal,
            'avail' => $availTotal,
            'rest'  => $restTotal,
            'dist'  => $r['dist'],
            'segs'  => $slots,
            'viol'  => $viol,
        ];
    }

    ksort($days);
    $days = array_values($days);

    // Rebuild summary from final day list
    $summary = ['drive' => 0, 'work' => 0, 'rest' => 0, 'avail' => 0, 'violations' => []];
    foreach ($days as $day) {
        $summary['drive'] += $day['drive'];
        $summary['work']  += $day['work'];
        $summary['rest']  += $day['rest'];
        $summary['avail'] += $day['avail'];
        foreach ($day['viol'] as $v) {
            $summary['violations'][] = array_merge($v, ['date' => $day['date']]);
        }
    }

    return ['days' => $days, 'summary' => $summary];
}

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
