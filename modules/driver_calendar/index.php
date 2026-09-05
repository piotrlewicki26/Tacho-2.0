<?php
/**
 * TachoPro 2.0 – Driver Activity Calendar (unified)
 *
 * Combines the calendar view, SVG activity timeline, violations list,
 * border crossings and per-file analysis into a single professional module.
 * Data is always kept in sync from every uploaded DDD file automatically.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// Auto-create the driver_activity_calendar table if not yet applied.
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
$crossingQuality = in_array($_GET['crossing_quality'] ?? 'all', ['all', 'validated', 'raw', 'inferred'], true)
    ? (string)($_GET['crossing_quality'] ?? 'all') : 'all';
$activeTab = in_array($_GET['tab'] ?? '', ['calendar','timeline','violations','files','pojazdy','granice'])
    ? $_GET['tab'] : 'calendar';

$stmt = $db->prepare(
    'SELECT id, first_name, last_name FROM drivers WHERE company_id=? AND is_active=1 ORDER BY last_name,first_name'
);
$stmt->execute([$companyId]);
$allDrivers = $stmt->fetchAll();

// ── Load & sync calendar data ─────────────────────────────────
$calDays    = [];
$chartDays  = [];
$violations = [];
$summary    = ['drive' => 0, 'work' => 0, 'rest' => 0, 'avail' => 0, 'dist' => 0, 'violations' => 0];
$driverInfo = null;
$driverFiles = [];
$dataDateMin = null;
$dataDateMax = null;
$crossingsDetected = 0;
$crossingsShown    = 0;
$selectedCrossingsByDate = [];
$timelineCrossingsByDate = [];
$timelineDateFrom = null;
$timelineDateTo   = null;
$borderStays = [];
$borderCompareAvailable = false;
$dateFrom    = date('Y-m-01');
$dateTo      = date('Y-m-t');

if ($driverId) {
    $dStmt = $db->prepare('SELECT id, first_name, last_name, card_number FROM drivers WHERE id=? AND company_id=? AND is_active=1');
    $dStmt->execute([$driverId, $companyId]);
    $driverInfo = $dStmt->fetch();

    if ($driverInfo) {
        // Always sync latest DDD data into the calendar
        try {
            backfillDriverActivityCalendar($db, $companyId, $driverId);
        } catch (Throwable $bfErr) {
            error_log('driver_calendar: backfill error for driver ' . $driverId . ': ' . $bfErr->getMessage());
        }
        try {
            rebuildDriverBorderCrossings($db, $companyId, $driverId, false);
        } catch (Throwable $bcErr) {
            error_log('driver_calendar: crossings rebuild error for driver ' . $driverId . ': ' . $bcErr->getMessage());
        }

        // Detect actual data range for this driver
        try {
            $rangeStmt = $db->prepare(
                'SELECT MIN(date) AS dmin, MAX(date) AS dmax
                 FROM driver_activity_calendar WHERE driver_id=?'
            );
            $rangeStmt->execute([$driverId]);
            $rangeRow = $rangeStmt->fetch();
            if ($rangeRow && $rangeRow['dmin']) {
                $dataDateMin = $rangeRow['dmin'];
                $dataDateMax = $rangeRow['dmax'];
            }
        } catch (Throwable $e) {
            error_log('driver_calendar: range query error: ' . $e->getMessage());
        }
        if (!$dataDateMin) {
            try {
                $rangeRawStmt = $db->prepare(
                    'SELECT MIN(d.date) AS dmin, MAX(d.date) AS dmax
                     FROM ddd_activity_days d
                     JOIN ddd_files f ON f.id=d.file_id
                     WHERE f.company_id=? AND f.driver_id=? AND f.file_type=\'driver\' AND f.is_deleted=0'
                );
                $rangeRawStmt->execute([$companyId, $driverId]);
                $rangeRaw = $rangeRawStmt->fetch();
                if ($rangeRaw && $rangeRaw['dmin']) {
                    $dataDateMin = $rangeRaw['dmin'];
                    $dataDateMax = $rangeRaw['dmax'];
                }
            } catch (Throwable $e) {
                error_log('driver_calendar: raw range query error: ' . $e->getMessage());
            }
        }

        // Date range – default: last 28 days (today − 27 days → today).
        $today        = new DateTime();
        $curMonthFrom = $today->format('Y-m-01');
        $curMonthTo   = $today->format('Y-m-t');
        $defaultFrom  = date('Y-m-d', strtotime('-27 days'));
        $defaultTo    = date('Y-m-d');

        $rawFrom = isset($_GET['from']) ? trim($_GET['from']) : '';
        $rawTo   = isset($_GET['to'])   ? trim($_GET['to'])   : '';

        if ($rawFrom !== '' || $rawTo !== '') {
            // User explicitly submitted the filter form – honour their choice.
            $fallbackFrom = $dataDateMin ?? $defaultFrom;
            $fallbackTo   = $dataDateMax ?? $defaultTo;
            $dateFrom = $rawFrom !== '' ? $rawFrom : $fallbackFrom;
            $dateTo   = $rawTo   !== '' ? $rawTo   : $fallbackTo;
        } else {
            // First driver selection (no dates in URL).
            // Default to the last 28 days (today − 27 days → today).
            $dateFrom = $defaultFrom;
            $dateTo   = $defaultTo;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $dataDateMin ?? $defaultFrom;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $dataDateMax ?? $defaultTo;
        if ($dateFrom > $dateTo) [$dateFrom, $dateTo] = [$dateTo, $dateFrom];

        // Load selected activity range for calendar/summary.
        try {
            $rows = $db->prepare(
                'SELECT date, drive_min, work_min, avail_min, rest_min, dist_km,
                        violations, segments, source_file_id
                 FROM driver_activity_calendar
                 WHERE driver_id=? AND date BETWEEN ? AND ?
                 ORDER BY date ASC'
            );
            $rows->execute([$driverId, $dateFrom, $dateTo]);
            $rawRows = $rows->fetchAll();

            foreach ($rawRows as $row) {
                $viols    = json_decode($row['violations']       ?? '[]', true) ?: [];
                $segs     = json_decode($row['segments']         ?? '[]', true) ?: [];
                $calDays[$row['date']] = [
                    'date'      => $row['date'],
                    'drive'     => (int)$row['drive_min'],
                    'work'      => (int)$row['work_min'],
                    'avail'     => (int)$row['avail_min'],
                    'rest'      => (int)$row['rest_min'],
                    'dist'      => (int)$row['dist_km'],
                    'segs'      => $segs,
                    'crossings' => [],
                    'viol'      => $viols,
                    'file_id'   => $row['source_file_id'],
                ];
                $summary['drive'] += (int)$row['drive_min'];
                $summary['work']  += (int)$row['work_min'];
                $summary['rest']  += (int)$row['rest_min'];
                $summary['avail'] += (int)$row['avail_min'];
                $summary['dist']  += (int)$row['dist_km'];
                $summary['violations'] += count($viols);
                $chartDays[] = ['date' => $row['date'], 'segs' => $segs, 'dist' => (int)$row['dist_km'], 'crossings' => []];

                // Collect all violations with date for violations tab
                foreach ($viols as $v) {
                    $violations[] = array_merge($v, ['date' => $row['date']]);
                }
            }

            // If no data found in the requested range but data exists elsewhere,
            // automatically fall back to the full data range and re-query.
            if (empty($calDays) && $dataDateMin && ($dateFrom > $dataDateMax || $dateTo < $dataDateMin)) {
                $dateFrom = $dataDateMin;
                $dateTo   = $dataDateMax;
                $rows->execute([$driverId, $dateFrom, $dateTo]);
                $rawRows2 = $rows->fetchAll();
                foreach ($rawRows2 as $row) {
                    $viols    = json_decode($row['violations']       ?? '[]', true) ?: [];
                    $segs     = json_decode($row['segments']         ?? '[]', true) ?: [];
                    $calDays[$row['date']] = [
                        'date'      => $row['date'],
                        'drive'     => (int)$row['drive_min'],
                        'work'      => (int)$row['work_min'],
                        'avail'     => (int)$row['avail_min'],
                        'rest'      => (int)$row['rest_min'],
                        'dist'      => (int)$row['dist_km'],
                        'segs'      => $segs,
                        'crossings' => [],
                        'viol'      => $viols,
                        'file_id'   => $row['source_file_id'],
                    ];
                    $summary['drive'] += (int)$row['drive_min'];
                    $summary['work']  += (int)$row['work_min'];
                    $summary['rest']  += (int)$row['rest_min'];
                    $summary['avail'] += (int)$row['avail_min'];
                    $summary['dist']  += (int)$row['dist_km'];
                    $summary['violations'] += count($viols);
                    $chartDays[] = ['date' => $row['date'], 'segs' => $segs, 'dist' => (int)$row['dist_km'], 'crossings' => []];
                    foreach ($viols as $v) {
                        $violations[] = array_merge($v, ['date' => $row['date']]);
                    }
                }
            }
        } catch (Throwable $calErr) {
            error_log('driver_calendar: query error for driver ' . $driverId . ': ' . $calErr->getMessage());
        }

        // Fallback: when driver_activity_calendar is empty/broken, render directly
        // from ddd_activity_days joined with driver files for the selected range.
        if (empty($calDays)) {
            try {
                $rawStmt = $db->prepare(
                    'SELECT d.date, d.drive_min, d.work_min, d.avail_min, d.rest_min,
                            d.dist_km, d.violations, d.segments, d.file_id AS source_file_id
                     FROM ddd_activity_days d
                     JOIN ddd_files f ON f.id=d.file_id
                     WHERE f.company_id=? AND f.driver_id=? AND f.file_type=\'driver\' AND f.is_deleted=0
                       AND d.date BETWEEN ? AND ?
                     ORDER BY d.date ASC'
                );
                $rawStmt->execute([$companyId, $driverId, $dateFrom, $dateTo]);
                foreach ($rawStmt->fetchAll() as $row) {
                    $viols     = json_decode($row['violations']       ?? '[]', true) ?: [];
                    $segs      = json_decode($row['segments']         ?? '[]', true) ?: [];
                    $calDays[$row['date']] = [
                        'date'      => $row['date'],
                        'drive'     => (int)$row['drive_min'],
                        'work'      => (int)$row['work_min'],
                        'avail'     => (int)$row['avail_min'],
                        'rest'      => (int)$row['rest_min'],
                        'dist'      => (int)$row['dist_km'],
                        'segs'      => $segs,
                        'crossings' => [],
                        'viol'      => $viols,
                        'file_id'   => $row['source_file_id'],
                    ];
                    $summary['drive'] += (int)$row['drive_min'];
                    $summary['work']  += (int)$row['work_min'];
                    $summary['rest']  += (int)$row['rest_min'];
                    $summary['avail'] += (int)$row['avail_min'];
                    $summary['dist']  += (int)$row['dist_km'];
                    $summary['violations'] += count($viols);
                    $chartDays[] = ['date' => $row['date'], 'segs' => $segs, 'dist' => (int)$row['dist_km'], 'crossings' => []];
                    foreach ($viols as $v) {
                        $violations[] = array_merge($v, ['date' => $row['date']]);
                    }
                }
            } catch (Throwable $rawErr) {
                error_log('driver_calendar: fallback ddd_activity_days query error for driver ' . $driverId . ': ' . $rawErr->getMessage());
            }
        }

        // Load DDD files for this driver
        try {
            $fStmt = $db->prepare(
                "SELECT id, original_name, stored_name, stored_subdir, download_date, period_start, period_end, file_size
                 FROM ddd_files
                 WHERE company_id=? AND driver_id=? AND file_type='driver' AND is_deleted=0
                 ORDER BY download_date DESC"
            );
            $fStmt->execute([$companyId, $driverId]);
            $driverFiles = $fStmt->fetchAll();
        } catch (Throwable $e) {
            error_log('driver_calendar: files query error: ' . $e->getMessage());
        }
    }
}

// ── Parse vehicle usage records from driver DDD files (for Pojazdy tab) ──
$vehicleRecords = [];
if ($driverId && $driverInfo && $activeTab === 'pojazdy' && $driverFiles) {
    foreach ($driverFiles as $fRow) {
        $fp = dddPhysPath($fRow, $companyId);
        if (!is_file($fp)) continue;
        $rawData = file_get_contents($fp);
        if ($rawData === false) continue;
        $recs = parseDriverCardVehicles($rawData);
        foreach ($recs as $r) {
            $vehicleRecords[] = array_merge($r, ['source_file' => $fRow['original_name']]);
        }
    }
    // Merge records from multiple DDD files: prefer most recent date_to;
    // 'source_file' is carried through from the winning (most recent) record.
    $vehicleRecords = mergeVehicleRecords($vehicleRecords);
    $vehicleRecords = groupVehicleTrips($vehicleRecords);
}

// ── Timeline range ───────────────────────────────────────────────
$timelineChartDays = $chartDays; // fallback: same as calendar range
$timelineDateFrom = $dateFrom;
$timelineDateTo   = $dateTo;
if ($driverId && $driverInfo && $activeTab === 'timeline' && $dataDateMin) {
    try {
        $timelineDateFrom = $dataDateMin;
        $timelineDateTo   = $dataDateMax ?? date('Y-m-d');
        // Only load separate timeline data if it differs from current calendar range
        if ($timelineDateFrom !== $dateFrom || $timelineDateTo !== $dateTo) {
            $tlStmt = $db->prepare(
                'SELECT date, segments, dist_km
                 FROM driver_activity_calendar
                 WHERE company_id=? AND driver_id=? AND date BETWEEN ? AND ?
                 ORDER BY date ASC'
            );
            $tlStmt->execute([$companyId, $driverId, $timelineDateFrom, $timelineDateTo]);
            $timelineChartDays = [];
            foreach ($tlStmt->fetchAll() as $tlRow) {
                $tlSegs = json_decode($tlRow['segments'] ?? '[]', true) ?: [];
                $timelineChartDays[] = [
                    'date'      => $tlRow['date'],
                    'segs'      => $tlSegs,
                    'dist'      => (int)$tlRow['dist_km'],
                    'crossings' => [],
                ];
            }
        }
    } catch (Throwable $tlErr) {
        error_log('driver_calendar: timeline data load error: ' . $tlErr->getMessage());
        $timelineChartDays = $chartDays; // fallback
    }
}
$filteredChartDays = $chartDays; // used for violations/summary tabs (respects date filter)

if ($driverId && $driverInfo) {
    try {
        $selectedCrossingsByDate = getDriverBorderCrossingsByDateRange($db, $companyId, $driverId, $dateFrom, $dateTo, $crossingQuality);
        if ($activeTab === 'timeline') {
            if ($timelineDateFrom === $dateFrom && $timelineDateTo === $dateTo) {
                $timelineCrossingsByDate = $selectedCrossingsByDate;
            } else {
                $timelineCrossingsByDate = getDriverBorderCrossingsByDateRange($db, $companyId, $driverId, $timelineDateFrom, $timelineDateTo, $crossingQuality);
            }
        } elseif ($activeTab === 'granice') {
            // Border list must respect the currently selected date filter.
            $timelineCrossingsByDate = $selectedCrossingsByDate;
        } else {
            $timelineCrossingsByDate = [];
        }
    } catch (Throwable $bcLoadErr) {
        error_log('driver_calendar: crossings load error for driver ' . $driverId . ': ' . $bcLoadErr->getMessage());
        $selectedCrossingsByDate = [];
        $timelineCrossingsByDate = [];
    }

    foreach ($calDays as $dKey => $day) {
        $calDays[$dKey]['crossings'] = $selectedCrossingsByDate[$dKey] ?? [];
    }
    foreach ($chartDays as $i => $day) {
        $dKey = (string)($day['date'] ?? '');
        $chartDays[$i]['crossings'] = $selectedCrossingsByDate[$dKey] ?? [];
    }
    $filteredChartDays = $chartDays;

    foreach ($timelineChartDays as $i => $day) {
        $dKey = (string)($day['date'] ?? '');
        $timelineChartDays[$i]['crossings'] = $timelineCrossingsByDate[$dKey] ?? [];
    }
    $timelineDates = [];
    foreach ($timelineChartDays as $day) {
        if (!empty($day['date'])) $timelineDates[(string)$day['date']] = true;
    }
    foreach ($timelineCrossingsByDate as $dKey => $crosses) {
        if (isset($timelineDates[$dKey])) continue;
        $timelineChartDays[] = [
            'date'      => $dKey,
            'segs'      => [],
            'dist'      => 0,
            'crossings' => $crosses,
        ];
    }
    usort($timelineChartDays, static fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));

    $crossingsShown = 0;
    foreach ($calDays as $d) {
        $crossings = $d['crossings'] ?? [];
        if (is_array($crossings)) {
            $crossingsShown += count($crossings);
        }
    }

    try {
        $detStmt = $db->prepare(
            'SELECT COUNT(*)
             FROM driver_border_crossings
             WHERE company_id=? AND driver_id=?
               AND crossing_date BETWEEN ? AND ?'
        );
        $detStmt->execute([$companyId, $driverId, $dateFrom, $dateTo]);
        $crossingsDetected = (int)$detStmt->fetchColumn();
    } catch (Throwable $detErr) {
        error_log('driver_calendar: crossings debug query error: ' . $detErr->getMessage());
    }

    // ── Build border stay timeline rows only for dedicated border tab ──
    if ($activeTab === 'granice') {
    try {
        $flat = [];
        foreach ($timelineCrossingsByDate as $d => $rows) {
            if (!is_array($rows)) continue;
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $tmin = isset($r['tmin']) ? (int)$r['tmin'] : -1;
                if ($tmin < 0 || $tmin > 1439) continue;
                $ts = (isset($r['ts']) && is_numeric($r['ts']) && (int)$r['ts'] > 0)
                    ? (int)$r['ts']
                    : (strtotime($d . ' 00:00:00 UTC') + $tmin * 60);
                if ($ts <= 0) continue;
                $flat[] = [
                    'date' => (string)$d,
                    'ts' => $ts,
                    'tmin' => $tmin,
                    'type' => isset($r['type']) ? (int)$r['type'] : 2,
                    'country' => strtoupper(trim((string)($r['country'] ?? ''))),
                    'quality' => (string)($r['quality'] ?? 'raw'),
                ];
            }
        }
        usort($flat, static fn(array $a, array $b): int => ($a['ts'] <=> $b['ts']));
        $ded = [];
        $seen = [];
        foreach ($flat as $e) {
            $k = $e['ts'] . '|' . $e['type'] . '|' . $e['country'];
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $ded[] = $e;
        }
        $flat = $ded;

        $driverKmByDate = [];
        $kmStmt = $db->prepare(
            'SELECT date, dist_km FROM driver_activity_calendar
             WHERE company_id=? AND driver_id=? AND date BETWEEN ? AND ?'
        );
        $kmStmt->execute([$companyId, $driverId, $timelineDateFrom, $timelineDateTo]);
        foreach ($kmStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $driverKmByDate[(string)$r['date']] = (int)$r['dist_km'];
        }

        $vuKmByDate = [];
        if (!empty($vehicleRecords)) {
            try {
                $regToVehicle = [];
                $vStmt = $db->prepare('SELECT id, registration FROM vehicles WHERE company_id=? AND is_active=1');
                $vStmt->execute([$companyId]);
                foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $vr) {
                    $regKey = strtoupper(preg_replace('/\s+/', '', (string)($vr['registration'] ?? '')));
                    if ($regKey !== '') $regToVehicle[$regKey][] = (int)$vr['id'];
                }

                $candidateIds = [];
                foreach (($vehicleRecords ?? []) as $vr) {
                    $rk = strtoupper(preg_replace('/\s+/', '', (string)($vr['reg'] ?? '')));
                    if ($rk !== '' && !empty($regToVehicle[$rk])) {
                        foreach ($regToVehicle[$rk] as $vid) $candidateIds[$vid] = true;
                    }
                }
                if (!empty($candidateIds)) {
                    $vids = array_keys($candidateIds);
                    $in = implode(',', array_fill(0, count($vids), '?'));
                    $vuSql = "SELECT date, SUM(dist_km) AS km
                              FROM vehicle_activity_calendar
                              WHERE company_id=? AND vehicle_id IN ($in) AND date BETWEEN ? AND ?
                              GROUP BY date";
                    $vuParams = array_merge([$companyId], $vids, [$timelineDateFrom, $timelineDateTo]);
                    $vuStmt = $db->prepare($vuSql);
                    $vuStmt->execute($vuParams);
                    foreach ($vuStmt->fetchAll(PDO::FETCH_ASSOC) as $vr) {
                        $vuKmByDate[(string)$vr['date']] = (int)$vr['km'];
                    }
                    $borderCompareAvailable = !empty($vuKmByDate);
                }
            } catch (Throwable $vuErr) {
                $borderCompareAvailable = false;
            }
        }

        $sumKm = static function (array $map, int $fromTs, int $toTs): int {
            if ($toTs < $fromTs) return 0;
            $km = 0;
            $cur = gmdate('Y-m-d', $fromTs);
            $end = gmdate('Y-m-d', $toTs);
            while ($cur <= $end) {
                $km += (int)($map[$cur] ?? 0);
                $cur = gmdate('Y-m-d', strtotime($cur . ' +1 day'));
            }
            return $km;
        };

        for ($i = 0, $n = count($flat); $i < $n; $i++) {
            $cur = $flat[$i];
            if ($cur['country'] === '') continue;
            $exit = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if (($flat[$j]['country'] ?? '') === '') continue;
                if ($flat[$j]['country'] !== $cur['country'] || (int)$flat[$j]['type'] === 1) {
                    $exit = $flat[$j];
                    break;
                }
            }
            $enterTs = (int)$cur['ts'];
            $exitTs = $exit ? (int)$exit['ts'] : null;
            $durMin = $exitTs ? max(0, (int)floor(($exitTs - $enterTs) / 60)) : null;
            $driverKm = $exitTs ? $sumKm($driverKmByDate, $enterTs, $exitTs) : null;
            $vuKm = ($exitTs && $borderCompareAvailable) ? $sumKm($vuKmByDate, $enterTs, $exitTs) : null;
            $borderStays[] = [
                'country' => $cur['country'],
                'enter_ts' => $enterTs,
                'enter_type' => (int)$cur['type'],
                'enter_quality' => (string)$cur['quality'],
                'exit_ts' => $exitTs,
                'exit_country' => $exit['country'] ?? null,
                'exit_type' => $exit['type'] ?? null,
                'duration_min' => $durMin,
                'driver_km' => $driverKm,
                'vu_km' => $vuKm,
            ];
        }
        } catch (Throwable $stErr) {
            error_log('driver_calendar: border stay build error: ' . $stErr->getMessage());
            $borderStays = [];
            $borderCompareAvailable = false;
        }
    }
}

// ── Build month grid ──────────────────────────────────────────
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
$months = ($driverId && $driverInfo && !empty($calDays)) ? monthRange($dateFrom, $dateTo) : [];

// ── Days driving/working count ────────────────────────────────
$driveDays = count(array_filter($calDays, fn($d) => $d['drive'] > 0));
$workDays  = count(array_filter($calDays, fn($d) => ($d['drive'] + $d['work']) > 0));

$pageTitle  = 'Kalendarz kierowcy';
$activePage = 'driver_calendar';
include __DIR__ . '/../../templates/header.php';
?>

<!-- ── Summary row: driver filter + stats ─────────────────────── -->
<?php if (!$driverId): ?>
<div class="row g-3 mb-3">
  <div class="col-xl-3 col-lg-4">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-person-badge text-primary"></i>
        <span class="tp-card-title">Kierowca</span>
      </div>
      <div class="tp-card-body">
        <form method="GET" novalidate id="filterForm">
          <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
          <div class="mb-3">
            <select name="driver_id" class="form-select" onchange="var f=this.form;var ef=f.elements['from'],et=f.elements['to'];if(ef)ef.value='';if(et)et.value='';f.submit();" title="Wybierz kierowcę">
              <option value="">— Wybierz kierowcę —</option>
              <?php foreach ($allDrivers as $d): ?>
              <option value="<?= $d['id'] ?>"<?= $d['id']==$driverId?' selected':'' ?>>
                <?= e($d['last_name'] . ' ' . $d['first_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="tp-card d-flex align-items-center justify-content-center" style="min-height:320px">
  <div class="tp-empty-state">
    <i class="bi bi-person-lines-fill" style="font-size:3rem;color:#94a3b8"></i>
    <p class="mt-3 mb-1 fw-600">Wybierz kierowcę</p>
    <p class="text-muted small">Wybierz kierowcę z listy powyżej, aby wyświetlić jego kalendarz i analizę aktywności.</p>
  </div>
</div>

<?php elseif (!$driverInfo): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i>Kierowca nie istnieje lub brak dostępu.</div>

<?php else: ?>
<!-- Summary: driver filter panel + stats in one row -->
<div class="row g-3 mb-3">
  <!-- ── Left panel (now inside summary) ─── -->
  <div class="col-xl-3 col-lg-4">
    <div class="tp-card mb-3">
      <div class="tp-card-header">
        <i class="bi bi-person-badge text-primary"></i>
        <span class="tp-card-title">Kierowca</span>
      </div>
      <div class="tp-card-body">
        <form method="GET" novalidate id="filterForm">
          <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
          <div class="mb-3">
            <select name="driver_id" class="form-select" onchange="var f=this.form;var ef=f.elements['from'],et=f.elements['to'];if(ef)ef.value='';if(et)et.value='';f.submit();" title="Wybierz kierowcę">
              <option value="">— Wybierz kierowcę —</option>
              <?php foreach ($allDrivers as $d): ?>
              <option value="<?= $d['id'] ?>"<?= $d['id']==$driverId?' selected':'' ?>>
                <?= e($d['last_name'] . ' ' . $d['first_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Driver info card -->
          <div class="dc-driver-card mb-3">
            <div class="dc-driver-avatar">
              <?= strtoupper(mb_substr($driverInfo['first_name'], 0, 1) . mb_substr($driverInfo['last_name'], 0, 1)) ?>
            </div>
            <div>
              <div class="fw-700"><?= e($driverInfo['last_name'] . ' ' . $driverInfo['first_name']) ?></div>
              <?php if ($driverInfo['card_number']): ?>
              <div class="text-muted small"><?= e($driverInfo['card_number']) ?></div>
              <?php endif; ?>
              <?php if ($dataDateMin): ?>
              <div class="text-muted small mt-1">
                <i class="bi bi-calendar-range me-1"></i>
                <?= fmtDate($dataDateMin) ?> – <?= fmtDate($dataDateMax) ?>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <hr class="my-2">
          <label class="form-label fw-600 small">Zakres dat</label>
          <!-- Quick-select buttons -->
          <?php if ($dataDateMin): ?>
          <div class="d-flex gap-1 mb-2 flex-wrap">
            <?php
              $qCurFrom  = date('Y-m-01');
              $qCurTo    = date('Y-m-t');
              $q12mFrom  = date('Y-m-d', strtotime('-12 months'));
              $q12mTo    = date('Y-m-d');
              $q28From   = date('Y-m-d', strtotime('-27 days'));
              $q28To     = date('Y-m-d');
              $q3mFrom   = date('Y-m-d', strtotime('-3 months'));
              $q3mTo     = date('Y-m-d');
              $isCur  = ($dateFrom === $qCurFrom  && $dateTo === $qCurTo);
              $is12m  = ($dateFrom === $q12mFrom  && $dateTo === $q12mTo);
              $is28   = ($dateFrom === $q28From   && $dateTo === $q28To);
              $is3m   = ($dateFrom === $q3mFrom   && $dateTo === $q3mTo);
            ?>
            <a href="?driver_id=<?= $driverId ?>&from=<?= $q28From ?>&to=<?= $q28To ?>&tab=<?= e($activeTab) ?>"
               class="btn btn-xs <?= $is28 ? 'btn-primary' : 'btn-outline-primary' ?> flex-fill">28 dni</a>
            <a href="?driver_id=<?= $driverId ?>&from=<?= $qCurFrom ?>&to=<?= $qCurTo ?>&tab=<?= e($activeTab) ?>"
               class="btn btn-xs <?= $isCur ? 'btn-info' : 'btn-outline-info' ?> flex-fill">Bież. mies.</a>
            <a href="?driver_id=<?= $driverId ?>&from=<?= $q3mFrom ?>&to=<?= $q3mTo ?>&tab=<?= e($activeTab) ?>"
               class="btn btn-xs <?= $is3m ? 'btn-success' : 'btn-outline-success' ?> flex-fill">3 mies.</a>
            <a href="?driver_id=<?= $driverId ?>&from=<?= $q12mFrom ?>&to=<?= $q12mTo ?>&tab=<?= e($activeTab) ?>"
               class="btn btn-xs <?= $is12m ? 'btn-secondary' : 'btn-outline-secondary' ?> flex-fill">12 mies.</a>
          </div>
          <?php endif; ?>
          <div class="mb-2">
            <label class="form-label mb-1 small text-muted">Od</label>
            <input type="date" name="from" class="form-control form-control-sm"
                   value="<?= e($dateFrom ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label mb-1 small text-muted">Do</label>
            <input type="date" name="to" class="form-control form-control-sm"
                   value="<?= e($dateTo ?? '') ?>">
          </div>
          <button type="submit" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-search me-1"></i>Filtruj
          </button>
        </form>
      </div>
    </div>

    <!-- Files quick list -->
    <?php if ($driverFiles): ?>
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-file-earmark-text text-secondary"></i>
        <span class="tp-card-title">Pliki DDD</span>
        <span class="badge bg-secondary ms-auto"><?= count($driverFiles) ?></span>
      </div>
      <div class="tp-card-body p-0">
        <ul class="list-group list-group-flush dc-file-list">
          <?php foreach (array_slice($driverFiles, 0, 5) as $f): ?>
          <li class="list-group-item list-group-item-action py-2 px-3 small">
            <div class="fw-600 text-truncate" title="<?= e($f['original_name']) ?>">
              <i class="bi bi-file-earmark-binary text-primary me-1"></i>
              <?= e($f['original_name']) ?>
            </div>
            <div class="text-muted">
              <?= fmtDate($f['download_date']) ?>
              <?php if ($f['period_start'] && $f['period_end']): ?>
              <span class="ms-1">· <?= fmtDate($f['period_start']) ?>–<?= fmtDate($f['period_end']) ?></span>
              <?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
          <?php if (count($driverFiles) > 5): ?>
          <li class="list-group-item text-center py-2">
            <a href="?driver_id=<?= $driverId ?>&tab=files" class="small text-primary text-decoration-none">
              + <?= count($driverFiles) - 5 ?> więcej plików
            </a>
          </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Stats ───────────────────────────────────────────────── -->
  <div class="col-xl-9 col-lg-8">
    <div class="row g-3">
      <div class="col-12 col-sm-6">
        <div class="tp-stat">
          <div class="tp-stat-icon primary"><i class="bi bi-speedometer2"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor($summary['drive']/60) ?>h <?= $summary['drive']%60 ?>m</div>
            <div class="tp-stat-label">Jazda łącznie</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6">
        <div class="tp-stat">
          <div class="tp-stat-icon warning"><i class="bi bi-briefcase"></i></div>
          <div>
            <div class="tp-stat-value"><?= floor($summary['work']/60) ?>h <?= $summary['work']%60 ?>m</div>
            <div class="tp-stat-label">Praca</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6">
        <div class="tp-stat">
          <div class="tp-stat-icon success"><i class="bi bi-signpost-split"></i></div>
          <div>
            <div class="tp-stat-value"><?= number_format($summary['dist']) ?> km</div>
            <div class="tp-stat-label">Dystans</div>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6">
        <div class="tp-stat">
          <div class="tp-stat-icon <?= $summary['violations'] > 0 ? 'danger' : 'success' ?>">
            <i class="bi bi-<?= $summary['violations'] > 0 ? 'exclamation-triangle' : 'shield-check' ?>"></i>
          </div>
          <div>
            <div class="tp-stat-value"><?= $summary['violations'] ?></div>
            <div class="tp-stat-label">Naruszenia</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div><!-- /.row summary -->

<!-- ── Tabs (full-width) ───────────────────────────────────────── -->
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="tp-card mb-0">
      <div class="tp-card-header p-0 border-bottom-0">
        <ul class="nav nav-tabs dc-tabs w-100 px-3 pt-2" role="tablist">
          <li class="nav-item" role="presentation">
            <a class="nav-link<?= $activeTab==='calendar'?' active':'' ?>"
               href="?driver_id=<?= $driverId ?>&from=<?= e($dateFrom??'') ?>&to=<?= e($dateTo??'') ?>&tab=calendar"
               role="tab"><i class="bi bi-calendar3 me-1"></i>Kalendarz</a>
          </li>
          <li class="nav-item" role="presentation">
            <a class="nav-link<?= $activeTab==='violations'?' active':'' ?>"
               href="?driver_id=<?= $driverId ?>&from=<?= e($dateFrom??'') ?>&to=<?= e($dateTo??'') ?>&tab=violations"
               role="tab">
              <i class="bi bi-exclamation-triangle me-1"></i>Naruszenia
              <?php if ($summary['violations'] > 0): ?>
              <span class="badge bg-danger ms-1"><?= $summary['violations'] ?></span>
              <?php endif; ?>
            </a>
          </li>
          <li class="nav-item" role="presentation">
            <a class="nav-link<?= $activeTab==='files'?' active':'' ?>"
               href="?driver_id=<?= $driverId ?>&from=<?= e($dateFrom??'') ?>&to=<?= e($dateTo??'') ?>&tab=files"
               role="tab">
              <i class="bi bi-folder2-open me-1"></i>Pliki DDD
              <?php if ($driverFiles): ?>
              <span class="badge bg-secondary ms-1"><?= count($driverFiles) ?></span>
              <?php endif; ?>
            </a>
          </li>
          <li class="nav-item" role="presentation">
            <a class="nav-link<?= $activeTab==='pojazdy'?' active':'' ?>"
               href="?driver_id=<?= $driverId ?>&from=<?= e($dateFrom??'') ?>&to=<?= e($dateTo??'') ?>&tab=pojazdy"
               role="tab">
              <i class="bi bi-truck me-1"></i>Pojazdy
            </a>
          </li>
          <li class="nav-item" role="presentation">
            <a class="nav-link<?= $activeTab==='timeline'?' active':'' ?>"
               href="?driver_id=<?= $driverId ?>&from=<?= e($dateFrom??'') ?>&to=<?= e($dateTo??'') ?>&tab=timeline"
               role="tab">
              <i class="bi bi-activity me-1"></i>Oś czasu
            </a>
          </li>
          <li class="nav-item" role="presentation">
            <a class="nav-link<?= $activeTab==='granice'?' active':'' ?>"
               href="?driver_id=<?= $driverId ?>&from=<?= e($dateFrom??'') ?>&to=<?= e($dateTo??'') ?>&tab=granice"
               role="tab">
              <i class="bi bi-signpost-split me-1"></i>Przekroczenia granic
            </a>
          </li>
        </ul>
      </div>

      <div class="tp-card-body">
        <?php if ($driverInfo): ?>
        <div class="d-flex justify-content-end align-items-center gap-2 mb-2">
          <span class="badge text-bg-light border">
            crossings detected / shown: <?= (int)$crossingsDetected ?> / <?= (int)$crossingsShown ?>
          </span>
          <a class="btn btn-sm btn-outline-secondary"
             href="?driver_id=<?= $driverId ?>&from=<?= e($dateFrom) ?>&to=<?= e($dateTo) ?>&tab=<?= e($activeTab) ?>&rebuild_crossings=1">
            Rebuild crossings
          </a>
        </div>
        <?php endif; ?>

        <?php if (empty($calDays) && !in_array($activeTab, ['files', 'pojazdy', 'timeline'], true)): ?>
        <!-- No data state -->
        <div class="tp-empty-state py-5">
          <i class="bi bi-calendar-x" style="font-size:2.5rem;color:#94a3b8"></i>
          <p class="mt-3 mb-1 fw-600">Brak danych aktywności</p>
          <p class="text-muted small">
            Brak danych dla wybranego kierowcy w wybranym zakresie dat.
            <?php if (!$driverFiles): ?>
            <a href="/files.php">Wgraj plik DDD</a>, aby wypełnić kalendarz.
            <?php else: ?>
            <?php if ($dataDateMin): ?>
            <a href="?driver_id=<?= $driverId ?>&from=<?= $dataDateMin ?>&to=<?= $dataDateMax ?>&tab=<?= e($activeTab) ?>">Pokaż pełny zakres danych</a>
            (<?= fmtDate($dataDateMin) ?> – <?= fmtDate($dataDateMax) ?>).
            <?php else: ?>
            Spróbuj rozszerzyć zakres dat lub wgraj nowy plik DDD.
            <?php endif; ?>
            <?php endif; ?>
          </p>
        </div>

        <?php elseif ($activeTab === 'calendar'): ?>
        <!-- ════════════════════════════════════════════════════
             TAB: CALENDAR
             ════════════════════════════════════════════════════ -->

        <!-- Legend -->
        <div class="d-flex flex-wrap gap-3 mb-3 small align-items-center">
          <span><span class="dc-badge dc-drive"></span> 🛞 Jazda</span>
          <span><span class="dc-badge dc-work"></span> ⚒ Praca</span>
          <span><span class="dc-badge dc-avail"></span> □ Dyspozycyjność</span>
          <span><span class="dc-badge dc-rest"></span> 🛏 Odpoczynek</span>
          <span><span class="dc-badge dc-viol"></span> Naruszenie</span>
          <span><span class="dc-badge dc-no-data"></span> Brak danych</span>
          <span class="ms-auto text-muted"><?= $workDays ?> dni aktywności · <?= count($calDays) ?> dni z danymi</span>
        </div>

        <?php foreach ($months as [$year, $month]): ?>
        <?php
          $monthNames = ['','Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
                         'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
          $monthLabel  = $monthNames[$month] . ' ' . $year;
          $firstDay    = new DateTime("$year-$month-01");
          $daysInMonth = (int)$firstDay->format('t');
          $startDow    = (int)$firstDay->format('N');

          // Count days with data in this month
          $monthDataDays = 0;
          for ($di = 1; $di <= $daysInMonth; $di++) {
              $ds = sprintf('%04d-%02d-%02d', $year, $month, $di);
              if (isset($calDays[$ds]) && ($calDays[$ds]['drive'] + $calDays[$ds]['work'] + $calDays[$ds]['avail']) > 0) $monthDataDays++;
          }
        ?>
        <div class="dc-month mb-4">
          <div class="dc-month-header">
            <span class="dc-month-title"><?= $monthLabel ?></span>
            <?php if ($monthDataDays > 0): ?>
            <span class="badge bg-primary-subtle text-primary-emphasis ms-2"><?= $monthDataDays ?> dni</span>
            <?php endif; ?>
          </div>
          <div class="dc-grid">
            <?php foreach (['Pn','Wt','Śr','Cz','Pt','Sb','Nd'] as $dow): ?>
            <div class="dc-dow"><?= $dow ?></div>
            <?php endforeach; ?>
            <?php for ($e = 1; $e < $startDow; $e++): ?>
            <div class="dc-cell dc-empty"></div>
            <?php endfor; ?>
            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
            <?php
              $dateStr  = sprintf('%04d-%02d-%02d', $year, $month, $d);
              $day      = $calDays[$dateStr] ?? null;
              $hasError = $day && !empty(array_filter($day['viol'], fn($v)=>($v['type']??'')==='error'));
              $hasWarn  = $day && !empty(array_filter($day['viol'], fn($v)=>($v['type']??'')==='warn'));
              $isWeekend= in_array(date('N', strtotime($dateStr)), ['6','7']);
              $dowNames = ['','Pn','Wt','Śr','Cz','Pt','Sb','Nd'];
              $dowLabel = $dowNames[(int)date('N', strtotime($dateStr))];
              $cellCls  = 'dc-cell' . ($isWeekend ? ' dc-weekend' : '');
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
              if ($day) {
                  $tooltipText = $dateStr . "\n" .
                      'Jazda: '    . floor($day['drive']/60) . 'h ' . ($day['drive']%60) . 'm' . "\n" .
                      'Praca: '    . floor($day['work']/60)  . 'h ' . ($day['work']%60)  . 'm' . "\n" .
                      'Dysp: '     . floor($day['avail']/60) . 'h ' . ($day['avail']%60) . 'm' . "\n" .
                      'Odpoczynek: '  . floor($day['rest']/60)  . 'h ' . ($day['rest']%60)  . 'm' .
                      ($day['dist'] ? "\nKm: " . $day['dist'] : '') .
                      ($hasError ? "\n⚠ Naruszenie!" : ($hasWarn ? "\n⚠ Ostrzeżenie" : ''));
                  $tooltip = 'title="' . htmlspecialchars($tooltipText) . '"';
              } else {
                  $tooltip = '';
              }
            ?>
            <div class="<?= $cellCls ?>" <?= $tooltip ?> <?= $day ? 'data-date="'.$dateStr.'"' : '' ?>>
              <div class="dc-day-header">
                <span class="dc-day-num"><?= $d ?></span>
                <span class="dc-day-dow"><?= $dowLabel ?></span>
              </div>
              <?php if ($day): ?>
              <div class="dc-sum">
                <?php if ($day['drive'] > 0): $h=floor($day['drive']/60);$m=$day['drive']%60; ?>
                <div class="dc-si"><span class="dc-si-ico" title="Jazda">🛞</span><span class="dc-si-dot dc-si-drive"></span><?= $h ?>h<?= $m>0?' '.$m.'m':'' ?></div>
                <?php endif; ?>
                <?php if ($day['work'] > 0): $h=floor($day['work']/60);$m=$day['work']%60; ?>
                <div class="dc-si"><span class="dc-si-ico" title="Praca">⚒</span><span class="dc-si-dot dc-si-work"></span><?= $h ?>h<?= $m>0?' '.$m.'m':'' ?></div>
                <?php endif; ?>
                <?php if ($day['rest'] > 0): $h=floor($day['rest']/60);$m=$day['rest']%60; ?>
                <div class="dc-si"><span class="dc-si-ico" title="Odpoczynek">🛏</span><span class="dc-si-dot dc-si-rest"></span><?= $h ?>h<?= $m>0?' '.$m.'m':'' ?></div>
                <?php endif; ?>
                <?php if ($day['avail'] > 0): $h=floor($day['avail']/60);$m=$day['avail']%60; ?>
                <div class="dc-si"><span class="dc-si-ico" title="Dyspozycyjność">□</span><span class="dc-si-dot dc-si-avail"></span><?= $h ?>h<?= $m>0?' '.$m.'m':'' ?></div>
                <?php endif; ?>
                <?php if ($day['dist'] > 0): ?>
                <div class="dc-si dc-si-km"><?= $day['dist'] ?>&nbsp;km</div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <?php if ($hasError): ?><span class="dc-viol-dot"></span><?php endif; ?>
            </div>
            <?php endfor; ?>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($calDays)): ?>
        <!-- Day-view click handler for calendar cells -->
        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var calDaysData = <?= json_encode(array_values($calDays), JSON_UNESCAPED_UNICODE) ?>;
          document.querySelectorAll('.dc-cell[data-date]').forEach(function(cell) {
            cell.style.cursor = 'pointer';
            cell.addEventListener('click', function() {
              if (window.TachoChart && TachoChart.showDayView) {
                TachoChart.showDayView(cell.getAttribute('data-date'), calDaysData);
              }
            });
          });
        });
        </script>
        <?php endif; ?>

        <?php elseif ($activeTab === 'timeline'): ?>
        <!-- ════════════════════════════════════════════════════
             TAB: TIMELINE / ANALIZATOR
             ════════════════════════════════════════════════════ -->

        <div id="tachoTimelineMain" style="width:100%;overflow-x:auto;min-height:200px;"></div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var days = <?= json_encode(array_values($timelineChartDays), JSON_UNESCAPED_UNICODE) ?>;
          var vehicles = <?= json_encode(array_values($vehicleRecords), JSON_UNESCAPED_UNICODE) ?>;
          if (days.length && window.TachoChart) {
            TachoChart.render('tachoTimelineMain', days, vehicles);
          }
        });
        </script>

        <?php if (empty($timelineChartDays)): ?>
        <div class="tp-empty-state py-4">
          <i class="bi bi-activity" style="font-size:2rem;color:#94a3b8"></i>
          <p class="mt-2 text-muted small">Brak danych do wyświetlenia na osi czasu dla wybranego zakresu.</p>
        </div>
        <?php endif; ?>

        <!-- Violations in timeline view -->
        <?php
          $tlViolations = [];
          foreach ($filteredChartDays as $fd) {
              $fDate = $fd['date'];
              if (isset($calDays[$fDate])) {
                  foreach ($calDays[$fDate]['viol'] as $v) {
                      $tlViolations[] = array_merge($v, ['date' => $fDate]);
                  }
              }
          }
        ?>
        <?php /* Infringements in selected scope – hidden per UX request */ ?>
        <?php /* Daily summary table – hidden per UX request */ ?>

        <?php elseif ($activeTab === 'granice'): ?>
        <!-- ════════════════════════════════════════════════════
             TAB: BORDER CROSSINGS
             ════════════════════════════════════════════════════ -->
        <?php if (!empty($borderStays)): ?>
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
          <i class="bi bi-info-circle text-muted"></i>
          <small class="text-muted">Źródło: karta kierowcy (crossingi). Dystans: suma dni z kalendarza kierowcy; porównanie VU gdy dostępne dla pojazdów powiązanych rejestracją.</small>
          <span class="badge bg-primary ms-auto"><?= count($borderStays) ?> wpis<?= count($borderStays) === 1 ? '' : 'ów' ?></span>
        </div>
        <div class="table-responsive">
          <table class="tp-table">
            <thead>
              <tr>
                <th>Kraj</th>
                <th>Wjazd / zdarzenie</th>
                <th>Wyjazd / następne zdarzenie</th>
                <th>Czas w kraju</th>
                <th class="text-end">KM (karta kierowcy)</th>
                <?php if ($borderCompareAvailable): ?>
                <th class="text-end">KM (tacho/VU)</th>
                <th class="text-end">Różnica</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($borderStays as $st): ?>
              <?php
                $enterTs = (int)($st['enter_ts'] ?? 0);
                $exitTs  = isset($st['exit_ts']) && $st['exit_ts'] ? (int)$st['exit_ts'] : null;
                $enterLbl = ((int)($st['enter_type'] ?? 2) === 0 ? '+ Włożenie karty' : ((int)($st['enter_type'] ?? 2) === 1 ? '− Wycofanie karty' : '↔ Przekroczenie granicy'));
                $exitLbl = $exitTs
                    ? (((int)($st['exit_type'] ?? 2) === 0 ? '+ Włożenie karty' : ((int)($st['exit_type'] ?? 2) === 1 ? '− Wycofanie karty' : '↔ Przekroczenie granicy')))
                    : '—';
                $durMin = isset($st['duration_min']) ? (int)$st['duration_min'] : null;
                $durTxt = $durMin !== null ? sprintf('%02dh %02dm', intdiv($durMin, 60), $durMin % 60) : '—';
                $dkm = isset($st['driver_km']) ? (int)$st['driver_km'] : null;
                $vkm = isset($st['vu_km']) && $st['vu_km'] !== null ? (int)$st['vu_km'] : null;
              ?>
              <tr>
                <td><strong><?= e($st['country'] ?? '—') ?></strong></td>
                <td class="text-nowrap">
                  <?php if ($enterTs > 0): ?>
                  <?= gmdate('d.m.Y H:i:s', $enterTs) ?><br>
                  <span class="text-muted small"><?= e($enterLbl) ?><?= ($st['enter_quality'] ?? '') === 'inferred' ? ' · inferred' : '' ?></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td class="text-nowrap">
                  <?php if ($exitTs): ?>
                  <?= gmdate('d.m.Y H:i:s', $exitTs) ?><br>
                  <span class="text-muted small"><?= e($exitLbl) ?><?= !empty($st['exit_country']) ? ' · '.e($st['exit_country']) : '' ?></span>
                  <?php else: ?>
                  <span class="text-muted">brak wyjazdu w zakresie</span>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap"><?= $durTxt ?></td>
                <td class="text-end text-nowrap"><?= $dkm !== null ? number_format($dkm) . ' km' : '—' ?></td>
                <?php if ($borderCompareAvailable): ?>
                <td class="text-end text-nowrap"><?= $vkm !== null ? number_format($vkm) . ' km' : '—' ?></td>
                <td class="text-end text-nowrap">
                  <?php if ($vkm !== null && $dkm !== null): ?>
                  <?= number_format($dkm - $vkm) ?> km
                  <?php else: ?>—<?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="tp-empty-state py-5">
          <i class="bi bi-signpost-2" style="font-size:2.5rem;color:#94a3b8"></i>
          <p class="mt-3 mb-1 fw-600">Brak danych przekroczeń granic</p>
          <p class="text-muted small">Brak zdarzeń granicznych dla wybranego zakresu.</p>
        </div>
        <?php endif; ?>

        <?php elseif ($activeTab === 'violations'): ?>
        <!-- ════════════════════════════════════════════════════
             TAB: VIOLATIONS
             ════════════════════════════════════════════════════ -->
        <?php if ($violations): ?>
        <div class="table-responsive">
          <table class="tp-table">
            <thead>
              <tr>
                <th>Data</th>
                <th>Opis naruszenia</th>
                <th>Poziom</th>
                <th class="text-nowrap">Kara – kierowca</th>
                <th class="text-nowrap">Kara – firma</th>
                <th>Akt prawny</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($violations as $v):
                $vp = (isset($v['penalty_driver']) || isset($v['penalty_company']))
                    ? $v
                    : array_merge($v, violPenalty($v['type'] ?? '', $v['msg'] ?? ''));
              ?>
              <tr class="<?= ($v['type']??'')==='error'?'table-danger':'table-warning' ?>">
                <td class="text-nowrap"><?= fmtDate($v['date'] ?? '') ?></td>
                <td><?= e($v['msg'] ?? '') ?></td>
                <td>
                  <?php if (($v['type']??'')==='error'): ?>
                  <span class="violation-error"><i class="bi bi-exclamation-triangle-fill me-1"></i>Poważne</span>
                  <?php else: ?>
                  <span class="violation-warn"><i class="bi bi-exclamation-circle me-1"></i>Ostrzeżenie</span>
                  <?php endif; ?>
                </td>
                <td class="text-nowrap"><?= $vp['penalty_driver'] > 0 ? 'do ' . number_format($vp['penalty_driver'], 0, ',', ' ') . ' PLN' : '–' ?></td>
                <td class="text-nowrap"><?= $vp['penalty_company'] > 0 ? 'do ' . number_format($vp['penalty_company'], 0, ',', ' ') . ' PLN' : '–' ?></td>
                <td class="small text-muted"><?= e($vp['article'] ?? '') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="tp-empty-state py-5">
          <i class="bi bi-shield-check" style="font-size:2.5rem;color:#10b981"></i>
          <p class="mt-3 mb-1 fw-600 text-success">Brak naruszeń</p>
          <p class="text-muted small">Nie wykryto żadnych naruszeń przepisów UE w wybranym okresie.</p>
        </div>
        <?php endif; ?>

        <?php elseif ($activeTab === 'files'): ?>
        <!-- ════════════════════════════════════════════════════
             TAB: FILES
             ════════════════════════════════════════════════════ -->
        <?php if ($driverFiles): ?>
        <div class="table-responsive">
          <table class="tp-table">
            <thead>
              <tr>
                <th>Plik</th>
                <th>Data wgrania</th>
                <th>Okres danych</th>
                <th>Rozmiar</th>
                <th class="text-end">Akcja</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($driverFiles as $f): ?>
              <tr>
                <td>
                  <i class="bi bi-file-earmark-binary text-primary me-1"></i>
                  <?= e($f['original_name']) ?>
                </td>
                <td><?= fmtDate($f['download_date']) ?></td>
                <td>
                  <?php if ($f['period_start'] && $f['period_end']): ?>
                  <?= fmtDate($f['period_start']) ?> – <?= fmtDate($f['period_end']) ?>
                  <?php else: ?>
                  —
                  <?php endif; ?>
                </td>
                <td><?= $f['file_size'] ? number_format((int)$f['file_size'] / 1024, 1) . ' KB' : '—' ?></td>
                <td class="text-end">
                  <?php
                    $fFrom = $f['period_start'] ?: $f['download_date'];
                    $fTo   = $f['period_end']   ?: $f['download_date'];
                  ?>
                  <a href="?driver_id=<?= $driverId ?>&from=<?= e($fFrom) ?>&to=<?= e($fTo) ?>&tab=calendar"
                     class="btn btn-xs btn-outline-primary">
                    <i class="bi bi-calendar3 me-1"></i>Kalendarz
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="tp-empty-state py-5">
          <i class="bi bi-folder-x" style="font-size:2.5rem;color:#94a3b8"></i>
          <p class="mt-3 mb-1 fw-600">Brak plików DDD</p>
          <p class="text-muted small">
            Nie wgrano jeszcze żadnego pliku DDD dla tego kierowcy.
            <a href="/files.php">Wgraj plik DDD</a>.
          </p>
        </div>
        <?php endif; ?>

        <?php elseif ($activeTab === 'pojazdy'): ?>
        <!-- ════════════════════════════════════════════════════
             TAB: POJAZDY (vehicles used – parsed from DDD binary)
             ════════════════════════════════════════════════════ -->
        <?php if (!$driverFiles): ?>
        <div class="tp-empty-state py-5">
          <i class="bi bi-truck" style="font-size:2.5rem;color:#94a3b8"></i>
          <p class="mt-3 mb-1 fw-600">Brak plików DDD</p>
          <p class="text-muted small">
            Nie wgrano jeszcze żadnego pliku DDD dla tego kierowcy.
            <a href="/files.php">Wgraj plik DDD</a>, aby pobrać listę używanych pojazdów.
          </p>
        </div>
        <?php elseif (empty($vehicleRecords)): ?>
        <div class="tp-empty-state py-5">
          <i class="bi bi-truck" style="font-size:2.5rem;color:#94a3b8"></i>
          <p class="mt-3 mb-1 fw-600">Brak danych o pojazdach</p>
          <p class="text-muted small">
            Nie znaleziono rekordów używanych pojazdów w plikach DDD kierowcy dla wybranego zakresu dat.
            <?php if ($dataDateMin): ?>
            <a href="?driver_id=<?= $driverId ?>&from=<?= $dataDateMin ?>&to=<?= $dataDateMax ?>&tab=pojazdy">Pokaż pełny zakres danych</a>
            (<?= fmtDate($dataDateMin) ?> – <?= fmtDate($dataDateMax) ?>).
            <?php endif; ?>
          </p>
        </div>
        <?php else: ?>
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
          <i class="bi bi-info-circle text-muted"></i>
          <small class="text-muted">Dane odczytane z plików DDD karty kierowcy (EF_CardVehiclesUsed). Kolejne dni w tym samym pojeździe są scalane w okresy.</small>
          <span class="badge bg-primary ms-auto"><?= count($vehicleRecords) ?> okres<?= count($vehicleRecords) === 1 ? '' : (count($vehicleRecords) < 5 ? 'y' : 'ów') ?></span>
        </div>
        <div class="table-responsive">
          <table class="tp-table">
            <thead>
              <tr>
                <th>Rejestracja</th>
                <th>Kraj</th>
                <th>Wyjazd</th>
                <th>Powrót</th>
                <th class="text-center">Dni</th>
                <th class="text-end">Licznik (pocz.)</th>
                <th class="text-end">Licznik (końc.)</th>
                <th class="text-end">Dystans</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($vehicleRecords as $vr):
                  $dFrom = new DateTime($vr['date_from']);
                  $dTo   = new DateTime($vr['date_to']);
                  $days  = (int)$dFrom->diff($dTo)->days + 1;
              ?>
              <tr>
                <td>
                  <i class="bi bi-truck text-primary me-1"></i>
                  <strong><?= e($vr['reg']) ?></strong>
                </td>
                <td><?= e($vr['nation'] ?: '—') ?></td>
                <td class="text-nowrap"><?= fmtDate($vr['date_from']) ?></td>
                <td class="text-nowrap"><?= fmtDate($vr['date_to']) ?></td>
                <td class="text-center">
                  <span class="badge bg-secondary"><?= $days ?></span>
                </td>
                <td class="text-end text-nowrap"><?= $vr['odo_begin'] > 0 ? number_format($vr['odo_begin']) . ' km' : '—' ?></td>
                <td class="text-end text-nowrap"><?= $vr['odo_end'] > 0 ? number_format($vr['odo_end']) . ' km' : '—' ?></td>
                <td class="text-end text-nowrap">
                  <?php if ($vr['distance'] > 0): ?>
                  <span class="fw-600 text-primary"><?= number_format($vr['distance']) ?> km</span>
                  <?php else: ?>
                  <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="fw-600">
                <td colspan="7" class="text-end">Łączny dystans:</td>
                <td class="text-end text-primary">
                  <?= number_format(array_sum(array_column($vehicleRecords, 'distance'))) ?> km
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>

      </div><!-- /.tp-card-body -->
    </div><!-- /.tp-card -->
  </div>
</div>
<?php endif; // !$driverId / !$driverInfo / else ?>

<style>
/* ── Driver Calendar styles ─────────────────────────────────── */
.dc-driver-card {
  display: flex;
  align-items: center;
  gap: .75rem;
  padding: .75rem;
  background: var(--tp-card-bg, #f8fafc);
  border-radius: 8px;
  border: 1px solid var(--tp-border, #e2e8f0);
}
.dc-driver-avatar {
  width: 42px; height: 42px;
  border-radius: 50%;
  background: var(--tp-primary, #2563eb);
  color: #fff;
  font-size: .9rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.dc-tabs .nav-link {
  font-size: .875rem;
  padding: .45rem .85rem;
  color: var(--tp-text-muted, #64748b);
  border-bottom: 2px solid transparent;
  border-top: none; border-left: none; border-right: none;
}
.dc-tabs .nav-link.active {
  color: var(--tp-primary, #2563eb);
  border-bottom-color: var(--tp-primary, #2563eb);
  background: transparent;
  font-weight: 600;
}
.dc-tabs .nav-link:hover:not(.active) {
  color: var(--tp-text, #1e293b);
}
.dc-file-list .list-group-item { border-left: none; border-right: none; }
.dc-file-list .list-group-item:first-child { border-top: none; }

/* Calendar grid */
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
.dc-month-header {
  display: flex;
  align-items: center;
  margin-bottom: .5rem;
}
.dc-month-title {
  font-weight: 700;
  font-size: .95rem;
  color: var(--tp-text, #1e293b);
}
.dc-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  column-gap: 3px;
  row-gap: 10px;
}
.dc-dow {
  text-align: center;
  font-size: .68rem;
  font-weight: 600;
  color: #64748b;
  padding: 3px 0;
}
.dc-cell {
  border-radius: 5px;
  min-height: 120px;
  padding: 4px 5px 3px;
  position: relative;
  cursor: default;
  overflow: hidden;
  transition: filter .1s;
  display: flex;
  flex-direction: column;
}
.dc-empty { background: transparent; }
.dc-no-data { background: #f1f5f9; }
.dc-weekend.dc-no-data { background: #f8f3f3; }
.dc-has-data { border: 1px solid rgba(0,0,0,.07); }
.dc-drive  { background: #dbeafe; }
.dc-work   { background: #fef3c7; }
.dc-avail  { background: #d1fae5; }
.dc-rest   { background: #f1f5f9; }
.dc-viol   { border: 2px solid #ef4444 !important; }
.dc-warn   { border: 2px solid #f59e0b !important; }
.dc-weekend.dc-has-data { opacity: .85; }
.dc-has-data:hover { filter: brightness(.93); cursor: pointer; }
.dc-day-num {
  font-size: .65rem;
  font-weight: 700;
  color: #374151;
  line-height: 1;
  display: block;
}

.dc-sum {
  margin-top: 3px;
  display: flex;
  flex-direction: column;
  gap: 1px;
  flex: 1;
}
.dc-si {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: .62rem;
  font-weight: 600;
  color: #374151;
  line-height: 1.35;
  white-space: nowrap;
}
.dc-si-ico {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 11px;
  min-width: 11px;
  font-size: 10px;
  line-height: 1;
  color: #334155;
}
.dc-si-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dc-si-drive { background: var(--tp-primary, #2563eb); }
.dc-si-work  { background: #f59e0b; }
.dc-si-rest  { background: #94a3b8; }
.dc-si-avail { background: #10b981; }
.dc-si-km {
  color: #64748b;
  padding-left: 9px;
}
.dc-day-header {
  display: flex;
  align-items: baseline;
  gap: 3px;
  line-height: 1;
}
.dc-day-dow {
  font-size: .58rem;
  font-weight: 500;
  color: #9ca3af;
  line-height: 1;
}
.dc-viol-dot {
  position: absolute;
  top: 3px; right: 4px;
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #ef4444;
}
.btn-xs {
  padding: .15rem .45rem;
  font-size: .75rem;
  line-height: 1.4;
}
</style>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
