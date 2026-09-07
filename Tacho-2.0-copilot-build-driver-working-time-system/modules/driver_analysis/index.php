<?php
/**
 * TachoPro 2.0 – Driver Analysis (redirect shim)
 * This module has been merged into the unified Driver Activity Calendar.
 * Any direct links to driver_analysis are transparently forwarded there.
 */
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

// ── Redirect to the unified Driver Activity Calendar ─────────
$driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$qs       = 'tab=timeline' . ($driverId ? '&driver_id=' . $driverId : '');
header('Location: /modules/driver_calendar/?' . $qs, true, 301);
exit;

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// ── Driver filter ─────────────────────────────────────────────
$driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;

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

// ── Load analysis data ────────────────────────────────────────
$days       = [];
$summary    = ['drive' => 0, 'work' => 0, 'rest' => 0, 'avail' => 0, 'violations' => []];
$violations = [];
$parseError = null;
$chartDays  = [];   // [{date, segs, dist}] for JS timeline chart

if ($selectedFile) {
    // Try pre-parsed data from ddd_activity_days first (fast path)
    $dbDays = $db->prepare(
        'SELECT date, drive_min, work_min, avail_min, rest_min, dist_km, violations, segments, border_crossings
         FROM ddd_activity_days WHERE file_id=? ORDER BY date'
    );
    $dbDays->execute([$fileId]);
    $dbRows = $dbDays->fetchAll();

    if ($dbRows) {
        /* Trigger re-parse when:
         *  - SQL NULL  : row was never parsed (new upload or DB had no value)
         *  - 'null'    : JSON null written by older code – re-try in case parser was fixed
         *  - '[]'      : stale empty written by an even older parser version
         *  - 'false'   : "confirmed empty" sentinel written by an older parser that may
         *                have missed crossings; re-try with the current improved parser
         *                (parseBorderCrossings now accepts single crossings and derives the
         *                year window from actual activity dates).  Once the new parse runs,
         *                result is stored back so subsequent loads skip re-parsing.
         *  - '0'       : upload-time parser found nothing; re-try because the parser may
         *                have been improved since the file was uploaded.  After the re-parse
         *                the row is updated to either the actual crossings JSON or '0'
         *                (confirmed empty by the current parser). */
        $needsCrossings = array_reduce($dbRows, function (bool $carry, array $row): bool {
            $bc = $row['border_crossings'];
            return $carry || $bc === null || $bc === '[]' || $bc === 'null' || $bc === 'false' || $bc === '0';
        }, false);

        /* Re-parse binary file to backfill border_crossings for rows that need it */
        $reparsedCrossings = [];
        if ($needsCrossings) {
            $fp = dddPhysPath($selectedFile, $companyId);
            if (is_file($fp)) {
                $rawData = file_get_contents($fp);
                if ($rawData !== false) {
                    /* Derive the year window from the actual activity dates stored in the DB.
                     * Using a fixed "$curYear - 3" floor would silently drop crossings from
                     * files uploaded more than 3 years ago.
                     * Guard against empty/malformed dates with a fallback. */
                    $dbYears = array_filter(
                        array_map(fn($r) => (int)substr($r['date'] ?? '', 0, 4), $dbRows),
                        fn($y) => $y >= 1990
                    );
                    if ($dbYears) {
                        /* Cap the year floor at most 2 years before the latest date.
                         * Using min($dbYears)-1 can over-extend the window when outlier
                         * spurious activity records are present, causing parseBorderCrossings
                         * to find stale timestamps in non-place blocks and return early
                         * with false-positive crossings (same fix as in parseDddFile). */
                        $dbYearMin = max(1990, max(min($dbYears) - 1, max($dbYears) - 2));
                        $dbYearMax = max($dbYears) + 1;
                    } else {
                        $curYear   = (int)gmdate('Y');
                        $dbYearMin = $curYear - 5;
                        $dbYearMax = $curYear + 1;
                    }
                    $reparsedCrossings = parseBorderCrossings($rawData, $dbYearMin, $dbYearMax);

                    /* Persist the result so future page loads skip re-parsing.
                     * Store actual JSON array if crossings were found.
                     * Store JSON 0 ('0') as the "confirmed empty after active re-parse"
                     * sentinel – json_decode('0', true) returns 0 which ?: [] gives [] for
                     * the UI.  '0' is NOT in the re-parse trigger list so it prevents
                     * infinite re-parsing for cards that genuinely have no crossings.
                     * Also propagate the refreshed border_crossings to the continuous
                     * driver_activity_calendar so the calendar view stays in sync. */
                    $updCross = $db->prepare(
                        'UPDATE ddd_activity_days SET border_crossings=? WHERE file_id=? AND date=?'
                    );
                    $linkedDriver = isset($selectedFile['driver_id']) ? (int)$selectedFile['driver_id'] : 0;
                    $updCal = $linkedDriver ? $db->prepare(
                        'UPDATE driver_activity_calendar SET border_crossings=? WHERE driver_id=? AND date=?'
                    ) : null;
                    foreach ($dbRows as $r) {
                        $bc = $r['border_crossings'];
                        if ($bc !== null && $bc !== '[]' && $bc !== 'null' && $bc !== 'false' && $bc !== '0') continue;
                        $crs     = $reparsedCrossings[$r['date']] ?? false;
                        $newJson = $crs !== false ? json_encode($crs) : json_encode(0);
                        $updCross->execute([$newJson, $fileId, $r['date']]);
                        /* Mirror to driver_activity_calendar: store the crossings JSON when
                         * found, or NULL to keep the row in a re-parseable state for future
                         * parser improvements (never store the '0' sentinel there). */
                        if ($updCal) {
                            $updCal->execute([
                                $crs !== false ? json_encode($crs) : null,
                                $linkedDriver,
                                $r['date'],
                            ]);
                        }
                    }
                }
            }
        }

        foreach ($dbRows as $row) {
            $viols     = json_decode($row['violations']      ?? '[]', true) ?: [];
            $segs      = json_decode($row['segments']        ?? '[]', true) ?: [];
            $crossings = json_decode($row['border_crossings'] ?? '[]', true) ?: [];
            /* Use freshly re-parsed crossings if DB had none */
            if (empty($crossings) && isset($reparsedCrossings[$row['date']])) {
                $crossings = $reparsedCrossings[$row['date']];
            }
            $days[] = [
                'date'      => $row['date'],
                'drive'     => (int)$row['drive_min'],
                'work'      => (int)$row['work_min'],
                'avail'     => (int)$row['avail_min'],
                'rest'      => (int)$row['rest_min'],
                'dist'      => (int)$row['dist_km'],
                'segs'      => $segs,
                'crossings' => $crossings,
                'viol'      => $viols,
            ];
            // Chart data
            $chartDays[] = ['date' => $row['date'], 'segs' => $segs, 'dist' => (int)$row['dist_km'], 'crossings' => $crossings];
            // Summary
            $summary['drive'] += (int)$row['drive_min'];
            $summary['work']  += (int)$row['work_min'];
            $summary['rest']  += (int)$row['rest_min'];
            $summary['avail'] += (int)$row['avail_min'];
            foreach ($viols as $v) {
                $summary['violations'][] = array_merge($v, ['date' => $row['date']]);
            }
        }
        $violations = $summary['violations'];
    } else {
        // Fall back to binary parsing
        $filePath = dddPhysPath($selectedFile, $companyId);
        if (is_file($filePath)) {
            $parseResult = parseDddFile($filePath);
            $days        = $parseResult['days']    ?? [];
            $summary     = $parseResult['summary'] ?? $summary;
            $violations  = $summary['violations']  ?? [];
            foreach ($days as $day) {
                $chartDays[] = ['date' => $day['date'], 'segs' => $day['segs'] ?? [], 'dist' => $day['dist'] ?? 0, 'crossings' => $day['crossings'] ?? []];
            }

            // Persist fresh data to ddd_activity_days so future loads use the
            // fast DB path instead of re-parsing the binary every time.
            if (!empty($days) && $fileId) {
                try {
                    $insDay = $db->prepare(
                        'INSERT IGNORE INTO ddd_activity_days
                         (file_id, date, drive_min, work_min, avail_min, rest_min, dist_km,
                          violations, segments, border_crossings)
                         VALUES (?,?,?,?,?,?,?,?,?,?)'
                    );
                    foreach ($days as $day) {
                        $insDay->execute([
                            $fileId,
                            $day['date'],
                            $day['drive']  ?? 0,
                            $day['work']   ?? 0,
                            $day['avail']  ?? 0,
                            $day['rest']   ?? 0,
                            $day['dist']   ?? 0,
                            json_encode($day['viol']      ?? []),
                            json_encode($day['segs']      ?? []),
                            !empty($day['crossings']) ? json_encode($day['crossings']) : json_encode(0),
                        ]);
                    }
                    // Update period_start / period_end in ddd_files
                    $freshDates = array_column($days, 'date');
                    sort($freshDates);
                    $db->prepare('UPDATE ddd_files SET period_start=?, period_end=? WHERE id=?')
                       ->execute([$freshDates[0], end($freshDates), $fileId]);
                } catch (\Throwable $e) {
                    // Non-fatal: data is already displayed in-memory; DB write failure
                    // just means future loads will re-parse again.
                }
            }
        } else {
            $parseError = 'Plik fizyczny nie istnieje w archiwum. Prześlij plik ponownie.';
        }
    }
}

// ── Timeline date range filter ────────────────────────────────
$tlFrom = isset($_GET['tl_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tl_from']) ? $_GET['tl_from'] : '';
$tlTo   = isset($_GET['tl_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['tl_to'])   ? $_GET['tl_to']   : '';
if ($tlFrom && $tlTo && $tlFrom > $tlTo) [$tlFrom, $tlTo] = [$tlTo, $tlFrom];

$filteredChartDays = $chartDays;
if ($tlFrom || $tlTo) {
    $filteredChartDays = array_values(array_filter($chartDays, function ($d) use ($tlFrom, $tlTo) {
        return (!$tlFrom || $d['date'] >= $tlFrom) && (!$tlTo || $d['date'] <= $tlTo);
    }));
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

  <!-- Summary stats -->
  <div class="col-md-8">
    <?php if (!$selectedFile): ?>
    <div class="tp-card h-100 d-flex align-items-center justify-content-center">
      <div class="tp-empty-state">
        <i class="bi bi-bar-chart-line"></i>
        <p>Wybierz kierowcę i plik DDD, aby zobaczyć analizę czasu pracy.</p>
      </div>
    </div>
    <?php else: ?>
    <div class="row g-3">
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon primary"><i class="bi bi-speedometer2"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor(($summary['drive']??0)/60) ?>h <?= ($summary['drive']??0)%60 ?>m</div>
            <div class="tp-stat-label">Łącznie jazda</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon warning"><i class="bi bi-briefcase"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor(($summary['work']??0)/60) ?>h <?= ($summary['work']??0)%60 ?>m</div>
            <div class="tp-stat-label">Praca</div>
          </div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="tp-stat">
          <div class="tp-stat-icon success"><i class="bi bi-moon"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor(($summary['rest']??0)/60) ?>h <?= ($summary['rest']??0)%60 ?>m</div>
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

    <?php if ($parseError): ?>
    <div class="alert alert-warning mt-3"><i class="bi bi-exclamation-triangle me-2"></i><?= e($parseError) ?></div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($selectedFile && $chartDays): ?>

<!-- ── SVG Timeline Chart ─────────────────────────────────────── -->
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-activity text-primary"></i>
    <span class="tp-card-title">Oś czasu aktywności tachografu</span>
    <span class="badge bg-secondary ms-2"><?= count($chartDays) ?> dni</span>
  </div>
  <div class="tp-card-body">
    <div id="tachoTimeline" style="width:100%;overflow-x:auto;"></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var days = <?= json_encode($chartDays, JSON_UNESCAPED_UNICODE) ?>;
  if (window.TachoChart) TachoChart.render('tachoTimeline', days);
});
</script>

<!-- ── Violations ────────────────────────────────────────────── -->
<?php if ($violations): ?>
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-exclamation-triangle text-danger"></i>
    <span class="tp-card-title">Naruszenia przepisów UE</span>
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

<!-- ── Daily summary table ───────────────────────────────────── -->
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
          <?php foreach ($days as $day): ?>
          <?php
            $hasError = !empty(array_filter($day['viol'], fn($v)=>$v['type']==='error'));
            $hasWarn  = !empty(array_filter($day['viol'], fn($v)=>$v['type']==='warn'));
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

<?php elseif ($selectedFile && !$parseError): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Plik nie zawiera rozpoznawalnych danych aktywności.</div>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
