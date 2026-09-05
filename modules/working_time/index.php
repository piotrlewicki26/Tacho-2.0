<?php
/**
 * TachoPro 2.0 – Ewidencja czasu pracy kierowcy (Working Time Records)
 *
 * Moduł PRO – profesjonalny raport miesięczny czasu pracy kierowcy
 * zgodny z Rozporządzeniem (WE) nr 561/2006 oraz Ustawą o czasie pracy kierowców.
 *
 * Funkcjonalności:
 *  - Tabela miesięczna z kolumnami dla każdego dnia (1–31)
 *  - Wiersze: składniki rzeczywiste, składniki do wynagrodzenia, nadgodziny
 *  - Podsumowanie miesiąca (normatywne, planowane, ponadwymiarowe)
 *  - Eksport do PDF (A4 poziomo) przez @media print
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();
requireModule('working_time');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// ── Driver filter ─────────────────────────────────────────────
$driverId = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;

$stmt = $db->prepare(
    'SELECT id, first_name, last_name, card_number FROM drivers WHERE company_id=? AND is_active=1 ORDER BY last_name, first_name'
);
$stmt->execute([$companyId]);
$allDrivers = $stmt->fetchAll();

// ── Month / Year filter ───────────────────────────────────────
$selMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($selMonth < 1 || $selMonth > 12) $selMonth = (int)date('m');
if ($selYear < 2000 || $selYear > 2099) $selYear = (int)date('Y');

$daysInMonth = (int)date('t', mktime(0, 0, 0, $selMonth, 1, $selYear));
$dateFrom    = sprintf('%04d-%02d-01', $selYear, $selMonth);
$dateTo      = sprintf('%04d-%02d-%02d', $selYear, $selMonth, $daysInMonth);

$polishMonths = [
    1 => 'Styczeń', 2 => 'Luty', 3 => 'Marzec', 4 => 'Kwiecień',
    5 => 'Maj', 6 => 'Czerwiec', 7 => 'Lipiec', 8 => 'Sierpień',
    9 => 'Wrzesień', 10 => 'Październik', 11 => 'Listopad', 12 => 'Grudzień',
];
$polishDayShort = ['Nd', 'Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'Sb'];

// ── Load calendar data ────────────────────────────────────────
$driverInfo = null;
$calDays    = [];

if ($driverId) {
    $dStmt = $db->prepare('SELECT id, first_name, last_name, card_number FROM drivers WHERE id=? AND company_id=? AND is_active=1');
    $dStmt->execute([$driverId, $companyId]);
    $driverInfo = $dStmt->fetch();

    if ($driverInfo) {
        // Backfill data
        try {
            backfillDriverActivityCalendar($db, $companyId, $driverId);
        } catch (Throwable $e) {
            error_log('working_time: backfill error: ' . $e->getMessage());
        }

        // Load calendar rows
        try {
            $rows = $db->prepare(
                'SELECT date, drive_min, work_min, avail_min, rest_min, dist_km, segments, border_crossings
                 FROM driver_activity_calendar
                 WHERE driver_id=? AND date BETWEEN ? AND ?
                 ORDER BY date ASC'
            );
            $rows->execute([$driverId, $dateFrom, $dateTo]);
            foreach ($rows->fetchAll() as $row) {
                $segs = json_decode($row['segments'] ?? '[]', true) ?: [];
                $calDays[$row['date']] = [
                    'drive'            => (int)$row['drive_min'],
                    'work'             => (int)$row['work_min'],
                    'avail'            => (int)$row['avail_min'],
                    'rest'             => (int)$row['rest_min'],
                    'dist'             => (int)$row['dist_km'],
                    'segs'             => $segs,
                    'border_crossings' => $row['border_crossings'],
                ];
            }
        } catch (Throwable $e) {
            error_log('working_time: query error: ' . $e->getMessage());
        }
    }
}

// ── Polish holidays (for the given year) ──────────────────────
function getPolishHolidays(int $year): array {
    // Anonymous Gregorian algorithm – no ext/calendar required.
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $easterMonth = intdiv($h + $l - 7 * $m + 114, 31);
    $easterDay   = (($h + $l - 7 * $m + 114) % 31) + 1;
    $easterTs    = mktime(0, 0, 0, $easterMonth, $easterDay, $year);

    $easterDate    = date('Y-m-d', $easterTs);
    $easterMonday  = date('Y-m-d', strtotime('+1 day',  $easterTs));
    $corpusChristi = date('Y-m-d', strtotime('+60 days', $easterTs));

    return [
        sprintf('%04d-01-01', $year),  // Nowy Rok
        sprintf('%04d-01-06', $year),  // Trzech Króli
        $easterDate,                    // Wielkanoc
        $easterMonday,                  // Poniedziałek Wielkanocny
        sprintf('%04d-05-01', $year),  // Święto Pracy
        sprintf('%04d-05-03', $year),  // Konstytucja 3 Maja
        $corpusChristi,                 // Boże Ciało
        sprintf('%04d-08-15', $year),  // Wniebowzięcie NMP
        sprintf('%04d-11-01', $year),  // Wszystkich Świętych
        sprintf('%04d-11-11', $year),  // Święto Niepodległości
        sprintf('%04d-12-25', $year),  // Boże Narodzenie
        sprintf('%04d-12-26', $year),  // Drugi dzień BN
    ];
}

$holidays = getPolishHolidays($selYear);

// ── Compute per-day working time data ─────────────────────────
// Standard working hours: 8h/day for working days (Mon-Fri, not holidays)
$STD_DAILY_HOURS = 8 * 60; // 480 min

$dayData = [];
$totals  = [
    'plan_min' => 0, 'work_time' => 0, 'drive_min' => 0, 'other_work' => 0,
    'break_15' => 0, 'avail_min' => 0, 'duty_50' => 0, 'daily_rest' => 0,
    'paid_time' => 0, 'night_hours' => 0, 'sunday_holiday' => 0,
    'overtime_base_50' => 0, 'overtime_base_100' => 0,
    'overtime_add_50' => 0, 'overtime_add_100' => 0,
    'idle_time' => 0,
    'start_hour' => '', 'end_hour' => '',
    'work_days' => 0, 'free_days' => 0,
    'norm_work_days' => 0, 'norm_free_days' => 0,
];

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateKey = sprintf('%04d-%02d-%02d', $selYear, $selMonth, $d);
    $dow     = (int)date('w', mktime(0, 0, 0, $selMonth, $d, $selYear)); // 0=Sun, 6=Sat
    $dayName = $polishDayShort[$dow];
    $isHoliday  = in_array($dateKey, $holidays);
    $isWeekend  = ($dow === 0 || $dow === 6);
    $isWorkDay  = !$isWeekend && !$isHoliday;

    $cd = $calDays[$dateKey] ?? null;

    $entry = [
        'date'      => $dateKey,
        'day'       => $d,
        'dow'       => $dow,
        'dayName'   => $dayName,
        'isWorkDay' => $isWorkDay,
        'isWeekend' => $isWeekend,
        'isHoliday' => $isHoliday,
        'symbol'    => '',
        'plan_min'  => 0,
        'start_hour'  => '',
        'end_hour'    => '',
        'work_time'   => 0, // czas pracy (jazda + inna praca)
        'drive_min'   => 0, // jazda
        'other_work'  => 0, // inna praca
        'break_15'    => 0, // przerwy 15 min
        'avail_min'   => 0, // dyspozycje
        'duty_50'     => 0, // dyżury 50%
        'daily_rest'  => 0, // odpoczynki dobowe
        'idle_time'   => 0, // przestoje
        'paid_time'   => 0, // czas płatny
        'night_hours' => 0, // godziny nocne
        'sunday_holiday' => 0, // CP Nd i Św
        'overtime_base_50' => 0,
        'overtime_base_100' => 0,
        'overtime_add_50' => 0,
        'overtime_add_100' => 0,
        'has_data'  => false,
    ];

    if ($cd) {
        $entry['has_data']  = true;
        $entry['drive_min'] = $cd['drive'];
        $entry['other_work'] = $cd['work'];
        $entry['work_time'] = $cd['drive'] + $cd['work'];
        $entry['avail_min'] = $cd['avail'];
        $entry['daily_rest'] = $cd['rest'];

        // Compute start/end hours from segments
        $segs = $cd['segs'] ?? [];
        if (!empty($segs)) {
            // Find first non-rest segment
            $firstActive = null;
            $lastActive  = null;
            foreach ($segs as $seg) {
                if (($seg['act'] ?? -1) !== 0) { // not rest
                    if ($firstActive === null) $firstActive = $seg;
                    $lastActive = $seg;
                }
            }
            if ($firstActive !== null) {
                $startMin = $firstActive['tmin'] ?? 0;
                $entry['start_hour'] = sprintf('%02d:%02d', intdiv($startMin, 60), $startMin % 60);
            }
            if ($lastActive !== null) {
                $endMin = ($lastActive['tmin'] ?? 0) + ($lastActive['dur'] ?? 0);
                if ($endMin > 1440) $endMin = 1440;
                $entry['end_hour'] = sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60);
            }

            // Break counting (rest segments >= 15 min within work period)
            foreach ($segs as $seg) {
                if (($seg['act'] ?? -1) === 0 && ($seg['dur'] ?? 0) >= 15) {
                    // Only count breaks that are within the work period (not daily rest)
                    if (($seg['dur'] ?? 0) < 180) { // breaks < 3h are likely work breaks
                        $entry['break_15'] += $seg['dur'];
                    }
                }
            }

            // Night hours (22:00-06:00 = 1320-1440 + 0-360 minutes)
            foreach ($segs as $seg) {
                $act = $seg['act'] ?? -1;
                if ($act === 3 || $act === 2) { // drive or work
                    $segStart = $seg['tmin'] ?? 0;
                    $segEnd   = $segStart + ($seg['dur'] ?? 0);
                    // Night: 0-360 (00:00-06:00) and 1320-1440 (22:00-24:00)
                    $nightMin = 0;
                    // 00:00-06:00
                    if ($segStart < 360) {
                        $nightMin += min($segEnd, 360) - $segStart;
                    }
                    // 22:00-24:00
                    if ($segEnd > 1320) {
                        $nightMin += $segEnd - max($segStart, 1320);
                    }
                    if ($nightMin > 0) {
                        $entry['night_hours'] += $nightMin;
                    }
                }
            }
        }

        // Duty 50% = availability time (dyspozycje/dyżury)
        $entry['duty_50'] = $cd['avail'];

        // Paid time = work_time (jazda + inna praca)
        $entry['paid_time'] = $entry['work_time'];

        // Sunday / Holiday work
        if ($isWeekend || $isHoliday) {
            $entry['sunday_holiday'] = $entry['work_time'];
        }

        // Day symbol
        if ($entry['work_time'] > 0) {
            $entry['symbol'] = 'W'; // working
            $totals['work_days']++;
        } elseif ($isWeekend || $isHoliday) {
            $entry['symbol'] = ($dow === 0 || $isHoliday) ? 'Św' : 'W6';
            $totals['free_days']++;
        } else {
            $entry['symbol'] = 'P'; // puste (free/no data)
            $totals['free_days']++;
        }

        // Plan (norm for work days)
        if ($isWorkDay) {
            $entry['plan_min'] = $STD_DAILY_HOURS;
            $totals['plan_min'] += $STD_DAILY_HOURS;
            $totals['norm_work_days']++;
        } else {
            $totals['norm_free_days']++;
        }

        // Overtime: work_time - plan_min (if positive, on work days)
        $overtime = 0;
        if ($isWorkDay && $entry['work_time'] > $entry['plan_min']) {
            $overtime = $entry['work_time'] - $entry['plan_min'];
        } elseif (!$isWorkDay && $entry['work_time'] > 0) {
            // All work on non-work days is 100% overtime
            $overtime = $entry['work_time'];
        }

        if ($overtime > 0) {
            if (!$isWorkDay || $isHoliday) {
                // 100% overtime (weekends/holidays)
                $entry['overtime_base_100'] = $overtime;
                $entry['overtime_add_100']  = $overtime;
            } else {
                // 50% overtime (regular workday excess)
                $entry['overtime_base_50'] = $overtime;
                $entry['overtime_add_50']  = $overtime;
            }
        }
    } else {
        // No data
        if ($isWorkDay) {
            $entry['plan_min'] = $STD_DAILY_HOURS;
            $totals['plan_min'] += $STD_DAILY_HOURS;
            $entry['symbol'] = '-';
            $totals['norm_work_days']++;
        } else {
            $entry['symbol'] = ($dow === 0 || $isHoliday) ? 'Św' : 'W6';
            $totals['norm_free_days']++;
        }
        $totals['free_days']++;
    }

    // Accumulate totals
    $totals['work_time']   += $entry['work_time'];
    $totals['drive_min']   += $entry['drive_min'];
    $totals['other_work']  += $entry['other_work'];
    $totals['break_15']    += $entry['break_15'];
    $totals['avail_min']   += $entry['avail_min'];
    $totals['duty_50']     += $entry['duty_50'];
    $totals['daily_rest']  += $entry['daily_rest'];
    $totals['idle_time']   += $entry['idle_time'];
    $totals['paid_time']   += $entry['paid_time'];
    $totals['night_hours'] += $entry['night_hours'];
    $totals['sunday_holiday']    += $entry['sunday_holiday'];
    $totals['overtime_base_50']  += $entry['overtime_base_50'];
    $totals['overtime_base_100'] += $entry['overtime_base_100'];
    $totals['overtime_add_50']   += $entry['overtime_add_50'];
    $totals['overtime_add_100']  += $entry['overtime_add_100'];

    $dayData[$d] = $entry;
}

// Overtime total
$overtimeTotal = $totals['overtime_base_50'] + $totals['overtime_base_100'];

// ── Border crossing processing ────────────────────────────────
// Collect all per-day crossings for the month and compute time per country.
$borderData      = []; // date → ['countries' => 'PL DE', 'count' => N]
$allMonthCrossings = []; // unified sorted list

foreach ($calDays as $date => $cd) {
    $raw = $cd['border_crossings'] ?? null;
    if (!$raw || $raw === '0' || $raw === 'false') {
        $borderData[$date] = ['countries' => '', 'count' => 0];
        continue;
    }
    $crossings = json_decode($raw, true);
    if (!is_array($crossings) || empty($crossings)) {
        $borderData[$date] = ['countries' => '', 'count' => 0];
        continue;
    }
    usort($crossings, fn($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
    $countries = [];
    foreach ($crossings as $c) {
        $co = $c['country'] ?? '';
        if ($co !== '' && !in_array($co, $countries, true)) {
            $countries[] = $co;
        }
    }
    $borderData[$date] = [
        'countries' => implode(' ', $countries),
        'count'     => count($crossings),
    ];
    foreach ($crossings as $c) {
        $allMonthCrossings[] = $c + ['date' => $date];
    }
}

// Sort all month crossings by timestamp
usort($allMonthCrossings, fn($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));

// Determine the country active at the very start of the reporting period
// by loading the last crossing from before the month start.
$startingCountry = null;
if ($driverId && !empty($calDays)) {
    try {
        $preStmt = $db->prepare(
            "SELECT border_crossings FROM driver_activity_calendar
             WHERE driver_id=? AND date < ?
               AND border_crossings IS NOT NULL
               AND border_crossings NOT IN ('0','[]','null','false')
             ORDER BY date DESC LIMIT 1"
        );
        $preStmt->execute([$driverId, $dateFrom]);
        $preRow = $preStmt->fetch();
        if ($preRow) {
            $preList = json_decode($preRow['border_crossings'], true) ?: [];
            if (!empty($preList)) {
                usort($preList, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
                $startingCountry = $preList[0]['country'] ?? null;
            }
        }
    } catch (Throwable $e) {
        error_log('working_time: border crossing pre-query error: ' . $e->getMessage());
    }
}

// Compute accumulated driving/work time per country for the month.
// We walk through all crossings in chronological order and credit the
// interval [lastTs … currentTs] to $currentCountry.  Only minutes that
// fall within a day that has actual driving/work activity are credited so
// rest and off-duty time does not inflate the totals.
$countryMinutes  = []; // country → total minutes
$currentCountry  = $startingCountry;
$periodStartTs   = strtotime($dateFrom . ' 00:00:00');
$periodEndTs     = strtotime($dateTo   . ' 23:59:59');
$lastTs          = $periodStartTs;

// Build a quick lookup of dates that have work activity
$workDates = [];
foreach ($calDays as $date => $cd) {
    if (($cd['drive'] ?? 0) + ($cd['work'] ?? 0) > 0) {
        $workDates[$date] = true;
    }
}

foreach ($allMonthCrossings as $c) {
    $ts = (int)($c['ts'] ?? 0);
    if ($ts <= 0 || $ts < $periodStartTs || $ts > $periodEndTs) continue;
    $elapsed = max(0, $ts - $lastTs);
    if ($currentCountry !== null && $elapsed > 0) {
        // Only credit time on working days
        $cDate = gmdate('Y-m-d', (int)round(($lastTs + $ts) / 2));
        if ($workDates[$cDate] ?? false) {
            $countryMinutes[$currentCountry] = ($countryMinutes[$currentCountry] ?? 0) + (int)round($elapsed / 60);
        }
    }
    $currentCountry = $c['country'] ?? $currentCountry;
    $lastTs = $ts;
}
// Credit time after the last crossing to end of period
if ($currentCountry !== null && $lastTs < $periodEndTs) {
    $elapsed = $periodEndTs - $lastTs;
    $cDate   = gmdate('Y-m-d', (int)round(($lastTs + $periodEndTs) / 2));
    if ($workDates[$cDate] ?? false) {
        $countryMinutes[$currentCountry] = ($countryMinutes[$currentCountry] ?? 0) + (int)round($elapsed / 60);
    }
}
arsort($countryMinutes);
$totalBorderCrossings = count($allMonthCrossings);

// ── Helpers ───────────────────────────────────────────────────
function fmtMin(int $minutes): string {
    if ($minutes <= 0) return '-';
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}
function fmtMinFull(int $minutes): string {
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}

// ── Page render ───────────────────────────────────────────────
$pageTitle    = 'Ewidencja czasu pracy';
$pageSubtitle = 'Miesięczny raport czasu pracy kierowcy';
$activePage   = 'working_time';

include __DIR__ . '/../../templates/header.php';
?>

<!-- Print-specific styles for A4 landscape PDF export -->
<style>
@media print {
    /* A4 landscape */
    @page {
        size: A4 landscape;
        margin: 5mm 8mm;
    }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tp-topbar, .tp-sidebar, .tp-sidebar-overlay, .tp-page-header,
    .no-print, .alert, .tp-content > .tp-page-header,
    #filterForm, .btn-export-pdf { display: none !important; }
    .tp-main { margin: 0 !important; padding: 0 !important; }
    .tp-content { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
    .wt-report-container { box-shadow: none !important; border: none !important; padding: 2mm !important; }
    .wt-report-header { margin-bottom: 2mm !important; }
    .wt-report-header h2 { font-size: 12pt !important; }
    .wt-report-header p { font-size: 8pt !important; margin-bottom: 1mm !important; }
    .wt-table { font-size: 6.5pt !important; }
    .wt-table th, .wt-table td { padding: 1px 2px !important; line-height: 1.1 !important; }
    .wt-table .wt-row-header { min-width: 100px !important; max-width: 130px !important; font-size: 6pt !important; }
    .wt-footer { font-size: 7pt !important; margin-top: 2mm !important; }
    .wt-footer td, .wt-footer th { padding: 1px 3px !important; }
}

/* Screen styles */
.wt-report-container {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,.12);
    padding: 20px;
    overflow-x: auto;
}
.wt-report-header {
    text-align: center;
    margin-bottom: 16px;
}
.wt-report-header h2 {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 4px;
}
.wt-report-header p {
    font-size: 13px;
    color: #555;
    margin-bottom: 2px;
}
.wt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    table-layout: fixed;
}
.wt-table th, .wt-table td {
    border: 1px solid #ccc;
    padding: 2px 3px;
    text-align: center;
    vertical-align: middle;
    white-space: nowrap;
    line-height: 1.2;
}
.wt-table .wt-row-header {
    text-align: left;
    font-weight: 500;
    min-width: 140px;
    max-width: 180px;
    white-space: normal;
    background: #fafafa;
    position: sticky;
    left: 0;
    z-index: 2;
}
.wt-table thead th {
    background: #f0f0f0;
    font-weight: 600;
    position: sticky;
    top: 0;
    z-index: 3;
}
.wt-table thead th.wt-row-header {
    z-index: 4;
}
.wt-day-work { background-color: #e8f5e9; }
.wt-day-sat  { background-color: #fff8e1; }
.wt-day-sun  { background-color: #ffebee; }
.wt-day-hol  { background-color: #fce4ec; }
.wt-section-header {
    background: #e3e8f0 !important;
    font-weight: 700 !important;
    text-align: left !important;
    font-size: 10px;
}
.wt-section-header td {
    background: #e3e8f0 !important;
    font-weight: 700 !important;
    text-align: left !important;
}
.wt-total-col {
    background: #f5f5f5 !important;
    font-weight: 700 !important;
    min-width: 50px;
}
.wt-footer {
    margin-top: 16px;
}
.wt-footer table {
    border-collapse: collapse;
    font-size: 12px;
}
.wt-footer td, .wt-footer th {
    padding: 3px 8px;
    border: 1px solid #ddd;
}
.wt-footer th {
    background: #f0f0f0;
    font-weight: 600;
    text-align: left;
}
.wt-country-badge {
    display: inline-block; padding: 0 4px; border-radius: 3px;
    font-size: 9px; font-weight: 700; background: #e3f2fd; color: #0d47a1;
    margin: 1px;
}
</style>

<!-- ═══ FILTERS ═════════════════════════════════════════════════ -->
<div class="card mb-4 no-print" id="filterForm">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label small fw-semibold mb-1">Kierowca</label>
        <select name="driver_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— wybierz kierowcę —</option>
          <?php foreach ($allDrivers as $drv): ?>
          <option value="<?= $drv['id'] ?>" <?= $drv['id'] == $driverId ? 'selected' : '' ?>>
            <?= e($drv['last_name'] . ' ' . $drv['first_name']) ?>
            <?= $drv['card_number'] ? ' (' . e($drv['card_number']) . ')' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Miesiąc</label>
        <select name="month" class="form-select form-select-sm">
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m == $selMonth ? 'selected' : '' ?>><?= $polishMonths[$m] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Rok</label>
        <select name="year" class="form-select form-select-sm">
          <?php for ($y = (int)date('Y') - 5; $y <= (int)date('Y') + 1; $y++): ?>
          <option value="<?= $y ?>" <?= $y == $selYear ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-search me-1"></i>Generuj raport
        </button>
      </div>
      <?php if ($driverInfo): ?>
      <div class="col-md-2">
        <button type="button" class="btn btn-outline-success btn-sm w-100 btn-export-pdf" onclick="window.print()">
          <i class="bi bi-file-pdf me-1"></i>Eksport PDF
        </button>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (!$driverId || !$driverInfo): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-2"></i>
  Wybierz kierowcę i okres, aby wygenerować ewidencję czasu pracy.
</div>
<?php else: ?>

<!-- ═══ REPORT ══════════════════════════════════════════════════ -->
<div class="wt-report-container">

  <!-- Header -->
  <div class="wt-report-header">
    <h2>Ewidencja czasu pracy kierowcy</h2>
    <p><strong><?= e($driverInfo['last_name'] . ' ' . $driverInfo['first_name']) ?></strong>
       <?= $driverInfo['card_number'] ? ' &middot; Nr karty: ' . e($driverInfo['card_number']) : '' ?></p>
    <p><strong><?= e($polishMonths[$selMonth]) ?> <?= $selYear ?></strong></p>
  </div>

  <!-- Main table -->
  <div style="overflow-x:auto;">
  <table class="wt-table">
    <thead>
      <tr>
        <th class="wt-row-header" style="min-width:160px;">Widoczność składników</th>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dd = $dayData[$d];
            $cls = $dd['isHoliday'] ? 'wt-day-hol' : ($dd['dow'] === 0 ? 'wt-day-sun' : ($dd['dow'] === 6 ? 'wt-day-sat' : 'wt-day-work'));
        ?>
        <th class="<?= $cls ?>" style="width:<?= round(80/$daysInMonth, 1) ?>%"><?= $d ?></th>
        <?php endfor; ?>
        <th class="wt-total-col">Razem<br>mies.</th>
      </tr>
      <tr>
        <th class="wt-row-header"></th>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dd = $dayData[$d];
            $cls = $dd['isHoliday'] ? 'wt-day-hol' : ($dd['dow'] === 0 ? 'wt-day-sun' : ($dd['dow'] === 6 ? 'wt-day-sat' : 'wt-day-work'));
        ?>
        <th class="<?= $cls ?>" style="font-size:9px;"><?= e($dd['dayName']) ?></th>
        <?php endfor; ?>
        <th class="wt-total-col"></th>
      </tr>
    </thead>
    <tbody>

      <!-- Zatrudnienie -->
      <tr class="wt-section-header">
        <td colspan="<?= $daysInMonth + 2 ?>"><strong>Zatrudnienie</strong></td>
      </tr>
      <tr>
        <td class="wt-row-header">Symbol dnia</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= e($dd['symbol']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col">—</td>
      </tr>

      <!-- Informacje dodatkowe -->
      <tr class="wt-section-header">
        <td colspan="<?= $daysInMonth + 2 ?>"><strong>Informacje dodatkowe</strong></td>
      </tr>
      <tr>
        <td class="wt-row-header">Plan (wymiar zasadniczy)</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= $dd['plan_min'] > 0 ? fmtMin($dd['plan_min']) : '-' ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['plan_min']) ?></td>
      </tr>

      <!-- Składniki rzeczywiste -->
      <tr class="wt-section-header">
        <td colspan="<?= $daysInMonth + 2 ?>"><strong>Składniki rzeczywiste</strong></td>
      </tr>
      <tr>
        <td class="wt-row-header">Godzina rozpoczęcia</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= $dd['start_hour'] ?: '-' ?></td>
        <?php endfor; ?>
        <td class="wt-total-col">—</td>
      </tr>
      <tr>
        <td class="wt-row-header">Godzina zakończenia</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= $dd['end_hour'] ?: '-' ?></td>
        <?php endfor; ?>
        <td class="wt-total-col">—</td>
      </tr>
      <tr>
        <td class="wt-row-header"><strong>Czas pracy</strong></td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><strong><?= fmtMin($dd['work_time']) ?></strong></td>
        <?php endfor; ?>
        <td class="wt-total-col"><strong><?= fmtMinFull($totals['work_time']) ?></strong></td>
      </tr>
      <tr>
        <td class="wt-row-header">Jazda</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['drive_min']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['drive_min']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">Inna praca</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['other_work']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['other_work']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">Przerwa 15 min</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['break_15']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['break_15']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">Dyspozycje zalicz. do CP</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['avail_min']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['avail_min']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">Dyżury 50%</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['duty_50']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['duty_50']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">Odpoczynki dobowe</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['daily_rest']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['daily_rest']) ?></td>
      </tr>

      <!-- Składniki do wynagrodzenia -->
      <tr class="wt-section-header">
        <td colspan="<?= $daysInMonth + 2 ?>"><strong>Składniki do wynagrodzenia</strong></td>
      </tr>
      <tr>
        <td class="wt-row-header">Przestoje</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['idle_time']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['idle_time']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header"><strong>Czas płatny</strong></td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><strong><?= fmtMin($dd['paid_time']) ?></strong></td>
        <?php endfor; ?>
        <td class="wt-total-col"><strong><?= fmtMinFull($totals['paid_time']) ?></strong></td>
      </tr>
      <tr>
        <td class="wt-row-header">CP godziny nocne</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['night_hours']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['night_hours']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">CP Nd i Św</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['sunday_holiday']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['sunday_holiday']) ?></td>
      </tr>

      <!-- Nadgodziny do wypłaty -->
      <tr class="wt-section-header">
        <td colspan="<?= $daysInMonth + 2 ?>"><strong>Nadgodziny do wypłaty</strong></td>
      </tr>
      <tr>
        <td class="wt-row-header">Nadg. podstawy 50%</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['overtime_base_50']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['overtime_base_50']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">Nadg. podstawy 100%</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['overtime_base_100']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['overtime_base_100']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">Nadg. dodatku 50%</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['overtime_add_50']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['overtime_add_50']) ?></td>
      </tr>
      <tr>
        <td class="wt-row-header">Nadg. dodatku 100%</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMin($dd['overtime_add_100']) ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= fmtMinFull($totals['overtime_add_100']) ?></td>
      </tr>

      <?php if ($totalBorderCrossings > 0): ?>
      <!-- Przekroczenia granic -->
      <tr class="wt-section-header">
        <td colspan="<?= $daysInMonth + 2 ?>">🌍 Przekroczenia granic</td>
      </tr>
      <tr>
        <td class="wt-row-header">Kraje (wjazd)</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dd  = $dayData[$d];
            $bd  = $borderData[$dd['date']] ?? ['countries' => '', 'count' => 0];
            $cos = $bd['countries'] !== '' ? explode(' ', $bd['countries']) : [];
        ?>
        <td style="font-size:9px;line-height:1.4;">
          <?php if (!empty($cos)):
              foreach ($cos as $co): ?>
          <span class="wt-country-badge"><?= e($co) ?></span>
          <?php endforeach; else: ?>-<?php endif; ?>
        </td>
        <?php endfor; ?>
        <td class="wt-total-col" style="font-size:9px;">
          <?php foreach (array_keys($countryMinutes) as $co): ?>
          <span class="wt-country-badge"><?= e($co) ?></span>
          <?php endforeach; ?>
        </td>
      </tr>
      <tr>
        <td class="wt-row-header">Liczba przekroczeń</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $bd = $borderData[$dayData[$d]['date']] ?? ['count' => 0];
        ?>
        <td><?= $bd['count'] > 0 ? $bd['count'] : '-' ?></td>
        <?php endfor; ?>
        <td class="wt-total-col"><?= $totalBorderCrossings ?></td>
      </tr>
      <?php endif; ?>

    </tbody>
  </table>
  </div><!-- /overflow -->

  <!-- Footer summary -->
  <div class="wt-footer mt-3">
    <div class="row">
      <div class="col-md-6">
        <table>
          <tr><th colspan="2">Informacje dodatkowe</th></tr>
          <tr>
            <td>Godziny normatywne:</td>
            <td><strong><?= fmtMinFull($totals['plan_min']) ?></strong></td>
          </tr>
          <tr>
            <td>Godziny planowane:</td>
            <td><strong><?= fmtMinFull($totals['plan_min']) ?></strong></td>
          </tr>
          <tr>
            <td>Godziny ponadwymiarowe:</td>
            <td><strong><?= fmtMinFull($overtimeTotal) ?></strong></td>
          </tr>
        </table>
      </div>
      <div class="col-md-6">
        <table>
          <tr><th colspan="2">Podsumowanie dni</th></tr>
          <tr>
            <td>Normatywne dni pracy:</td>
            <td><strong><?= $totals['norm_work_days'] ?></strong></td>
          </tr>
          <tr>
            <td>Normatywne dni wolne:</td>
            <td><strong><?= $totals['norm_free_days'] ?></strong></td>
          </tr>
          <tr>
            <td>Dni pracy (z danymi):</td>
            <td><strong><?= $totals['work_days'] ?></strong></td>
          </tr>
          <tr>
            <td>Dni wolne:</td>
            <td><strong><?= $totals['free_days'] ?></strong></td>
          </tr>
        </table>
      </div>
    </div>

    <?php if (!empty($countryMinutes)): ?>
    <div class="row mt-3">
      <div class="col-md-6">
        <table>
          <tr><th colspan="3">🌍 Czas pracy wg krajów (szacunkowy)</th></tr>
          <tr>
            <th>Kraj</th>
            <th>Czas (h:mm)</th>
            <th>Przekroczeń</th>
          </tr>
          <?php
          $countryCrossCount = [];
          foreach ($allMonthCrossings as $c) {
              $co = $c['country'] ?? '';
              if ($co !== '') $countryCrossCount[$co] = ($countryCrossCount[$co] ?? 0) + 1;
          }
          foreach ($countryMinutes as $country => $mins): ?>
          <tr>
            <td><span class="wt-country-badge" style="font-size:11px;"><?= e($country) ?></span></td>
            <td><strong><?= fmtMinFull($mins) ?></strong></td>
            <td><?= $countryCrossCount[$country] ?? 0 ?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div class="col-md-6">
        <table>
          <tr><th colspan="2">📍 Podsumowanie przekroczeń</th></tr>
          <tr>
            <td>Łączna liczba przekroczeń:</td>
            <td><strong><?= $totalBorderCrossings ?></strong></td>
          </tr>
          <tr>
            <td>Liczba krajów:</td>
            <td><strong><?= count($countryMinutes) ?></strong></td>
          </tr>
          <?php if ($startingCountry !== null): ?>
          <tr>
            <td>Kraj na początku okresu:</td>
            <td><span class="wt-country-badge" style="font-size:11px;"><?= e($startingCountry) ?></span></td>
          </tr>
          <?php endif; ?>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <div class="row mt-3 no-print">
      <div class="col-12 text-muted" style="font-size:11px;">
        <i class="bi bi-info-circle me-1"></i>
        Raport wygenerowany na podstawie danych z plików DDD (karta kierowcy).
        Dane dotyczące czasu pracy obliczone zgodnie z Rozporządzeniem (WE) nr 561/2006
        oraz Ustawą o czasie pracy kierowców (Dz.U. 2004 Nr 92 poz. 879 z późn. zm.).
        <br>
        <strong>Symbole dni:</strong>
        W = dzień pracy, W6 = sobota, Św = niedziela/święto, P = wolne, - = brak danych.
        <?php if ($totalBorderCrossings > 0): ?>
        <br>
        <strong>Czas wg krajów:</strong> szacowany na podstawie znaczników przekroczeń granic z pliku DDD.
        <?php endif; ?>
        <br>
        <strong>Eksport PDF:</strong> Kliknij przycisk „Eksport PDF" lub użyj Ctrl+P → drukuj jako PDF (orientacja: pozioma A4).
      </div>
    </div>
  </div>

</div><!-- /.wt-report-container -->

<?php endif; ?>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
