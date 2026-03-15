<?php
/**
 * TachoPro 2.0 – Driver Activity Calendar
 *
 * Shows a continuous month-by-month activity calendar for a selected driver.
 * Data comes from driver_activity_calendar, which is populated at upload time
 * by merging every uploaded DDD card for that driver – so this view always
 * shows the full history without requiring a per-file analysis.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// Auto-create the driver_activity_calendar table if migration 018 has not been applied yet.
try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `driver_activity_calendar` (
          `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `company_id`       INT UNSIGNED NOT NULL,
          `driver_id`        INT UNSIGNED NOT NULL,
          `date`             DATE         NOT NULL,
          `drive_min`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `work_min`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `avail_min`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `rest_min`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `dist_km`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `violations`       JSON DEFAULT NULL,
          `segments`         JSON DEFAULT NULL,
          `border_crossings` JSON DEFAULT NULL,
          `source_file_id`   INT UNSIGNED DEFAULT NULL,
          `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_driver_date (`driver_id`, `date`),
          INDEX idx_driver_date (`driver_id`, `date`),
          INDEX idx_company_driver (`company_id`, `driver_id`),
          FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
          FOREIGN KEY (`driver_id`)  REFERENCES `drivers`(`id`)  ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $tableErr) {
    error_log('driver_calendar: could not ensure driver_activity_calendar table: ' . $tableErr->getMessage());
}

// ── Driver filter ─────────────────────────────────────────────
$driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$viewMode = in_array($_GET['view'] ?? '', ['calendar', 'list']) ? $_GET['view'] : 'calendar';

// Date range – default: current month + 2 months back
$today       = new DateTime();
$defaultFrom = (clone $today)->modify('first day of -2 months')->format('Y-m-d');
$defaultTo   = $today->format('Y-m-d');
$dateFrom    = $_GET['from'] ?? $defaultFrom;
$dateTo      = $_GET['to']   ?? $defaultTo;

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $defaultTo;
if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

$stmt = $db->prepare(
    'SELECT id, first_name, last_name FROM drivers WHERE company_id=? AND is_active=1 ORDER BY last_name,first_name'
);
$stmt->execute([$companyId]);
$allDrivers = $stmt->fetchAll();

// ── Load calendar data ────────────────────────────────────────
$calDays     = [];   // date => row
$summary     = ['drive' => 0, 'work' => 0, 'rest' => 0, 'avail' => 0, 'violations' => 0, 'dist' => 0];
$chartDays   = [];
$driverInfo  = null;

if ($driverId) {
    // Verify driver belongs to this company
    $dStmt = $db->prepare('SELECT id, first_name, last_name, card_number FROM drivers WHERE id=? AND company_id=? AND is_active=1');
    $dStmt->execute([$driverId, $companyId]);
    $driverInfo = $dStmt->fetch();

    if ($driverInfo) {
        try {
            $rows = $db->prepare(
                'SELECT date, drive_min, work_min, avail_min, rest_min, dist_km,
                        violations, segments, border_crossings, source_file_id
                 FROM driver_activity_calendar
                 WHERE driver_id=? AND date BETWEEN ? AND ?
                 ORDER BY date ASC'
            );
            $rows->execute([$driverId, $dateFrom, $dateTo]);
            $rawRows = $rows->fetchAll();

            /* ── Re-parse border crossings for stale/null rows ─────────
             * Group rows that need re-parsing by source_file_id, then
             * run parseBorderCrossings once per file and update both
             * ddd_activity_days and driver_activity_calendar so future
             * loads skip re-parsing. */
            $needsReparse = [];   // source_file_id => [date => true]
            foreach ($rawRows as $r) {
                $bc = $r['border_crossings'];
                if ($bc === null || $bc === '[]' || $bc === 'null' || $bc === 'false' || $bc === '0') {
                    $fid = (int)($r['source_file_id'] ?? 0);
                    if ($fid) {
                        $needsReparse[$fid][$r['date']] = true;
                    }
                }
            }

            $reparsedByFile = [];   // file_id => [date => crossings_array]
            if ($needsReparse) {
                $fileStmt = $db->prepare(
                    "SELECT * FROM ddd_files WHERE id=? AND company_id=? AND is_deleted=0"
                );
                foreach (array_keys($needsReparse) as $fid) {
                    $fileStmt->execute([$fid, $companyId]);
                    $fRow = $fileStmt->fetch();
                    if (!$fRow) continue;
                    $fp = dddPhysPath($fRow, $companyId);
                    if (!is_file($fp)) continue;
                    $rawData = file_get_contents($fp);
                    if ($rawData === false) continue;

                    /* Derive year window from dates in the re-parse set */
                    $reparseDates = array_keys($needsReparse[$fid]);
                    $reYears = array_filter(
                        array_map(fn($d) => (int)substr($d, 0, 4), $reparseDates),
                        fn($y) => $y >= 1990
                    );
                    if ($reYears) {
                        $reYrMin = max(1990, max(min($reYears) - 1, max($reYears) - 2));
                        $reYrMax = max($reYears) + 1;
                    } else {
                        $cy      = (int)gmdate('Y');
                        $reYrMin = $cy - 5;
                        $reYrMax = $cy + 1;
                    }
                    $crossingsForFile = parseBorderCrossings($rawData, $reYrMin, $reYrMax);
                    $reparsedByFile[$fid] = $crossingsForFile;

                    /* Persist back to ddd_activity_days and driver_activity_calendar */
                    $updDays = $db->prepare(
                        'UPDATE ddd_activity_days SET border_crossings=? WHERE file_id=? AND date=?'
                    );
                    $updCal  = $db->prepare(
                        'UPDATE driver_activity_calendar SET border_crossings=? WHERE driver_id=? AND date=?'
                    );
                    foreach (array_keys($needsReparse[$fid]) as $d) {
                        $crs     = $crossingsForFile[$d] ?? false;
                        $newJson = $crs !== false ? json_encode($crs) : json_encode(0);
                        $updDays->execute([$newJson, $fid, $d]);
                        $updCal->execute([
                            $crs !== false ? json_encode($crs) : null,
                            $driverId,
                            $d,
                        ]);
                    }
                }
            }

            foreach ($rawRows as $row) {
                $viols    = json_decode($row['violations']        ?? '[]', true) ?: [];
                $segs     = json_decode($row['segments']          ?? '[]', true) ?: [];
                $crossings= json_decode($row['border_crossings']  ?? '[]', true) ?: [];
                if (is_int($crossings)) $crossings = [];   // sentinel '0'
                /* Merge freshly re-parsed crossings if DB had none */
                $fid = (int)($row['source_file_id'] ?? 0);
                if (empty($crossings) && $fid && isset($reparsedByFile[$fid][$row['date']])) {
                    $crossings = $reparsedByFile[$fid][$row['date']];
                }

                $calDays[$row['date']] = [
                    'date'      => $row['date'],
                    'drive'     => (int)$row['drive_min'],
                    'work'      => (int)$row['work_min'],
                    'avail'     => (int)$row['avail_min'],
                    'rest'      => (int)$row['rest_min'],
                    'dist'      => (int)$row['dist_km'],
                    'segs'      => $segs,
                    'crossings' => $crossings,
                    'viol'      => $viols,
                    'file_id'   => $row['source_file_id'],
                ];

                $summary['drive'] += (int)$row['drive_min'];
                $summary['work']  += (int)$row['work_min'];
                $summary['rest']  += (int)$row['rest_min'];
                $summary['avail'] += (int)$row['avail_min'];
                $summary['dist']  += (int)$row['dist_km'];
                $summary['violations'] += count(array_filter($viols, fn($v) => ($v['type'] ?? '') === 'error'));

                $chartDays[] = [
                    'date'      => $row['date'],
                    'segs'      => $segs,
                    'dist'      => (int)$row['dist_km'],
                    'crossings' => $crossings,
                ];
            }
        } catch (Throwable $calErr) {
            error_log('driver_calendar: query error for driver ' . $driverId . ': ' . $calErr->getMessage());
            // $calDays remains empty – the "no data" empty state will be shown
        }
    }
}

// ── Build month grid for calendar view ──────────────────────
/**
 * Returns an array of [year, month] pairs between $from and $to (inclusive).
 */
function monthRange(string $from, string $to): array
{
    $months = [];
    $cur    = new DateTime(substr($from, 0, 7) . '-01');
    $end    = new DateTime(substr($to, 0, 7)   . '-01');
    while ($cur <= $end) {
        $months[] = [(int)$cur->format('Y'), (int)$cur->format('n')];
        $cur->modify('+1 month');
    }
    return $months;
}

$months = monthRange($dateFrom, $dateTo);

$pageTitle  = 'Kalendarz kierowcy';
$activePage = 'driver_calendar';
include __DIR__ . '/../../templates/header.php';
?>

<div class="row g-3 mb-4">
  <!-- Filters -->
  <div class="col-lg-3">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-funnel text-primary"></i>
        <span class="tp-card-title">Filtry</span>
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

          <?php if ($driverId): ?>
          <div class="mb-3">
            <label class="form-label fw-600">Od</label>
            <input type="date" name="from" class="form-control" value="<?= e($dateFrom) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Do</label>
            <input type="date" name="to" class="form-control" value="<?= e($dateTo) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Widok</label>
            <select name="view" class="form-select">
              <option value="calendar"<?= $viewMode==='calendar'?' selected':'' ?>>Kalendarz</option>
              <option value="list"<?= $viewMode==='list'?' selected':'' ?>>Lista dni</option>
            </select>
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-search me-1"></i>Pokaż
          </button>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>

  <!-- Summary stats -->
  <div class="col-lg-9">
    <?php if (!$driverId): ?>
    <div class="tp-card h-100 d-flex align-items-center justify-content-center">
      <div class="tp-empty-state">
        <i class="bi bi-calendar3"></i>
        <p>Wybierz kierowcę, aby wyświetlić kalendarz aktywności.</p>
      </div>
    </div>
    <?php elseif (!$driverInfo): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i>Kierowca nie istnieje lub brak dostępu.</div>
    <?php elseif (empty($calDays)): ?>
    <div class="tp-card h-100 d-flex align-items-center justify-content-center">
      <div class="tp-empty-state">
        <i class="bi bi-calendar-x"></i>
        <p>Brak danych dla wybranego kierowcy w tym okresie.<br>
           <a href="/files.php">Wgraj plik DDD</a>, aby wypełnić kalendarz.</p>
      </div>
    </div>
    <?php else: ?>
    <div class="row g-3">
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon primary"><i class="bi bi-speedometer2"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor($summary['drive']/60) ?>h <?= $summary['drive']%60 ?>m</div>
            <div class="tp-stat-label">Jazda łącznie</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon warning"><i class="bi bi-briefcase"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor($summary['work']/60) ?>h <?= $summary['work']%60 ?>m</div>
            <div class="tp-stat-label">Praca</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon success"><i class="bi bi-moon"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor($summary['rest']/60) ?>h <?= $summary['rest']%60 ?>m</div>
            <div class="tp-stat-label">Odpoczynek</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon <?= $summary['violations']>0?'danger':'success' ?>">
            <i class="bi bi-<?= $summary['violations']>0?'exclamation-triangle':'check-circle' ?>"></i>
          </div>
          <div>
            <div class="tp-stat-value"><?= $summary['violations'] ?></div>
            <div class="tp-stat-label">Naruszenia</div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($driverId && $driverInfo && !empty($calDays)): ?>

<?php if ($viewMode === 'calendar'): ?>
<!-- ══════════════════════════════════════════════
     CALENDAR VIEW
     ══════════════════════════════════════════════ -->
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-calendar3 text-primary"></i>
    <span class="tp-card-title">
      Kalendarz aktywności – <?= e($driverInfo['last_name'] . ' ' . $driverInfo['first_name']) ?>
    </span>
    <span class="badge bg-secondary ms-2"><?= count($calDays) ?> dni z danymi</span>
  </div>
  <div class="tp-card-body">

    <!-- Legend -->
    <div class="d-flex flex-wrap gap-3 mb-3 small">
      <span><span class="dc-badge dc-drive"></span> Jazda</span>
      <span><span class="dc-badge dc-work"></span> Praca</span>
      <span><span class="dc-badge dc-avail"></span> Dyspozycyjność</span>
      <span><span class="dc-badge dc-rest"></span> Odpoczynek</span>
      <span><span class="dc-badge dc-viol"></span> Naruszenie</span>
      <span><span class="dc-badge dc-no-data"></span> Brak danych</span>
    </div>

    <?php foreach ($months as [$year, $month]): ?>
    <?php
      $monthNames = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
                     'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
      $monthLabel = $monthNames[$month] . ' ' . $year;

      $firstDay   = new DateTime("$year-$month-01");
      $daysInMonth= (int)$firstDay->format('t');
      $startDow   = (int)$firstDay->format('N'); // 1=Mon … 7=Sun
    ?>
    <div class="dc-month mb-4">
      <div class="dc-month-title"><?= $monthLabel ?></div>
      <div class="dc-grid">
        <!-- Day-of-week headers -->
        <?php foreach (['Pn','Wt','Śr','Cz','Pt','Sb','Nd'] as $dow): ?>
        <div class="dc-dow"><?= $dow ?></div>
        <?php endforeach; ?>
        <!-- Empty cells before first day -->
        <?php for ($e = 1; $e < $startDow; $e++): ?>
        <div class="dc-cell dc-empty"></div>
        <?php endfor; ?>
        <!-- Day cells -->
        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
        <?php
          $dateStr  = sprintf('%04d-%02d-%02d', $year, $month, $d);
          $day      = $calDays[$dateStr] ?? null;
          $hasError = $day && !empty(array_filter($day['viol'], fn($v)=>($v['type']??'')==='error'));
          $hasWarn  = $day && !empty(array_filter($day['viol'], fn($v)=>($v['type']??'')==='warn'));
          $cellCls  = 'dc-cell';
          if ($day) {
              $total = $day['drive'] + $day['work'] + $day['avail'] + $day['rest'];
              $dominantCls = 'dc-rest';
              if ($total > 0) {
                  $max = max($day['drive'], $day['work'], $day['avail'], $day['rest']);
                  if      ($max === $day['drive']) $dominantCls = 'dc-drive';
                  elseif  ($max === $day['work'])  $dominantCls = 'dc-work';
                  elseif  ($max === $day['avail']) $dominantCls = 'dc-avail';
                  else                             $dominantCls = 'dc-rest';
              }
              $cellCls .= ' dc-has-data ' . $dominantCls;
              if ($hasError) $cellCls .= ' dc-viol';
              elseif ($hasWarn) $cellCls .= ' dc-warn';
          } else {
              $cellCls .= ' dc-no-data';
          }
          $tooltip = '';
          if ($day) {
              $tooltip = htmlspecialchars(
                  $dateStr . "\n" .
                  'Jazda: '    . floor($day['drive']/60) . 'h ' . ($day['drive']%60) . 'm' . "\n" .
                  'Praca: '    . floor($day['work']/60)  . 'h ' . ($day['work']%60)  . 'm' . "\n" .
                  'Dysp: '     . floor($day['avail']/60) . 'h ' . ($day['avail']%60) . 'm' . "\n" .
                  'Odpoczynek:'  . floor($day['rest']/60)  . 'h ' . ($day['rest']%60)  . 'm' .
                  ($day['dist'] ? "\nKm: " . $day['dist'] : '') .
                  ($hasError ? "\n⚠ Naruszenie!" : ($hasWarn ? "\n⚠ Ostrzeżenie" : ''))
              );
          }
        ?>
        <div class="<?= $cellCls ?>"
             <?= $tooltip ? 'title="' . $tooltip . '"' : '' ?>
             <?= $day ? 'data-date="' . $dateStr . '"' : '' ?>>
          <span class="dc-day-num"><?= $d ?></span>
          <?php if ($day && $day['drive'] > 0): ?>
          <div class="dc-bar-wrap">
            <?php
              $total = max(1, $day['drive'] + $day['work'] + $day['avail'] + $day['rest']);
              $driveW = round($day['drive'] / $total * 100);
              $workW  = round($day['work']  / $total * 100);
              $availW = round($day['avail'] / $total * 100);
              $restW  = 100 - $driveW - $workW - $availW;
            ?>
            <div class="dc-bar dc-bar-drive"  style="width:<?= $driveW ?>%"></div>
            <div class="dc-bar dc-bar-work"   style="width:<?= $workW  ?>%"></div>
            <div class="dc-bar dc-bar-avail"  style="width:<?= $availW ?>%"></div>
            <div class="dc-bar dc-bar-rest"   style="width:<?= $restW  ?>%"></div>
          </div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div><!-- .dc-grid -->
    </div><!-- .dc-month -->
    <?php endforeach; ?>

  </div>
</div>

<?php else: // LIST VIEW ?>
<!-- ══════════════════════════════════════════════
     LIST VIEW
     ══════════════════════════════════════════════ -->

<!-- SVG Timeline -->
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-activity text-primary"></i>
    <span class="tp-card-title">Oś czasu aktywności – <?= e($driverInfo['last_name'] . ' ' . $driverInfo['first_name']) ?></span>
    <span class="badge bg-secondary ms-2"><?= count($calDays) ?> dni</span>
  </div>
  <div class="tp-card-body">
    <div id="tachoTimeline" style="width:100%;overflow-x:auto;"></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var days = <?= json_encode(array_values($chartDays), JSON_UNESCAPED_UNICODE) ?>;
  if (window.TachoChart) TachoChart.render('tachoTimeline', days);
});
</script>

<!-- Daily table -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-table text-secondary"></i>
    <span class="tp-card-title">Podsumowanie dzienne</span>
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
            <th>Km</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($calDays as $day): ?>
          <?php
            $hasError = !empty(array_filter($day['viol'], fn($v)=>($v['type']??'')==='error'));
            $hasWarn  = !empty(array_filter($day['viol'], fn($v)=>($v['type']??'')==='warn'));
          ?>
          <tr class="<?= $hasError?'table-danger':($hasWarn?'table-warning':'') ?>">
            <td><?= fmtDate($day['date']) ?></td>
            <td><?= floor($day['drive']/60) ?>h <?= $day['drive']%60 ?>m</td>
            <td><?= floor($day['work']/60) ?>h <?= $day['work']%60 ?>m</td>
            <td><?= floor($day['avail']/60) ?>h <?= $day['avail']%60 ?>m</td>
            <td><?= floor($day['rest']/60) ?>h <?= $day['rest']%60 ?>m</td>
            <td><?= $day['dist'] ? $day['dist'] . ' km' : '—' ?></td>
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
<?php endif; ?>

<?php endif; ?>

<style>
/* ── Driver Calendar styles ─────────────────────────────────── */
.dc-badge {
  display: inline-block;
  width: 14px; height: 14px;
  border-radius: 3px;
  vertical-align: middle;
  margin-right: 3px;
}
.dc-badge.dc-drive  { background: var(--tp-primary, #2563eb); }
.dc-badge.dc-work   { background: #f59e0b; }
.dc-badge.dc-avail  { background: #10b981; }
.dc-badge.dc-rest   { background: #94a3b8; }
.dc-badge.dc-viol   { background: #ef4444; }
.dc-badge.dc-no-data{ background: #e5e7eb; border:1px solid #d1d5db; }

.dc-month-title {
  font-weight: 600;
  font-size: .95rem;
  margin-bottom: .5rem;
  color: var(--tp-text, #1e293b);
}
.dc-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 3px;
}
.dc-dow {
  text-align: center;
  font-size: .7rem;
  font-weight: 600;
  color: #64748b;
  padding: 3px 0;
}
.dc-cell {
  border-radius: 5px;
  min-height: 52px;
  padding: 3px 4px 2px;
  position: relative;
  cursor: default;
  overflow: hidden;
}
.dc-empty { background: transparent; }
.dc-no-data { background: #f1f5f9; }
.dc-has-data { border: 1px solid rgba(0,0,0,.07); }
.dc-drive  { background: #dbeafe; }
.dc-work   { background: #fef3c7; }
.dc-avail  { background: #d1fae5; }
.dc-rest   { background: #f1f5f9; }
.dc-viol   { border: 2px solid #ef4444 !important; }
.dc-warn   { border: 2px solid #f59e0b !important; }
.dc-has-data:hover { filter: brightness(.95); }
.dc-day-num {
  font-size: .65rem;
  font-weight: 700;
  color: #374151;
  line-height: 1;
  display: block;
}
.dc-bar-wrap {
  display: flex;
  height: 5px;
  border-radius: 2px;
  overflow: hidden;
  margin-top: 3px;
}
.dc-bar { height: 100%; }
.dc-bar-drive { background: var(--tp-primary, #2563eb); }
.dc-bar-work  { background: #f59e0b; }
.dc-bar-avail { background: #10b981; }
.dc-bar-rest  { background: #94a3b8; }
</style>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
