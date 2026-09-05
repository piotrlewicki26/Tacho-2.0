<?php
/**
 * TachoPro 2.0 – Ewidencja czasu pracy – Generator ręczny
 *
 * Moduł PRO – dla kierowców pojazdów 2,8-3,5t (bez tachografu cyfrowego).
 * Umożliwia ręczne wprowadzanie czasu pracy i generuje kolorowy raport
 * miesięczny z eksportem do PDF (A4 poziomo).
 *
 * Zgodny z Ustawą o czasie pracy kierowców (Dz.U. 2004 Nr 92 poz. 879).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();
requireModule('working_time');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// ── Ensure table exists (auto-migrate) ────────────────────────
$tableReady = false;
try {
    $db->exec("SELECT 1 FROM working_time_manual_entries LIMIT 0");
    $tableReady = true;
} catch (\Throwable $e) {
    try {
        $sqlPath = __DIR__ . '/../../sql/migrate_025_working_time_manual.sql';
        if (file_exists($sqlPath)) {
            $db->exec(file_get_contents($sqlPath));
            $tableReady = true;
        }
    } catch (\Throwable $e2) {
        error_log('TachoPro: failed to create working_time_manual_entries table: ' . $e2->getMessage());
    }
}

// ── Handle AJAX save ──────────────────────────────────────────
if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'save_entries') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'CSRF']);
        exit;
    }
    $entries = json_decode($_POST['entries'] ?? '[]', true);
    if (!is_array($entries)) {
        echo json_encode(['ok' => false, 'error' => 'Nieprawidłowe dane']);
        exit;
    }

    $saved = 0;
    foreach ($entries as $ent) {
        $driverId   = !empty($ent['driver_id']) ? (int)$ent['driver_id'] : null;
        $driverName = trim($ent['driver_name'] ?? '');
        $entryDate  = $ent['entry_date'] ?? '';
        if (!$entryDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) continue;

        // Validate time format HH:MM
        $startTime = (isset($ent['start_time']) && preg_match('/^\d{2}:\d{2}$/', $ent['start_time'])) ? $ent['start_time'] : null;
        $endTime   = (isset($ent['end_time'])   && preg_match('/^\d{2}:\d{2}$/', $ent['end_time']))   ? $ent['end_time']   : null;

        $stmt = $db->prepare(
            'INSERT INTO working_time_manual_entries
             (company_id, driver_id, driver_name, entry_date, start_time, end_time,
              drive_min, other_work_min, avail_min, break_min, rest_min, night_min,
              duty_min, idle_min, dist_km, route, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
              start_time=VALUES(start_time), end_time=VALUES(end_time),
              drive_min=VALUES(drive_min), other_work_min=VALUES(other_work_min),
              avail_min=VALUES(avail_min), break_min=VALUES(break_min),
              rest_min=VALUES(rest_min), night_min=VALUES(night_min),
              duty_min=VALUES(duty_min), idle_min=VALUES(idle_min),
              dist_km=VALUES(dist_km), route=VALUES(route), notes=VALUES(notes)'
        );
        $stmt->execute([
            $companyId,
            $driverId,
            $driverName ?: null,
            $entryDate,
            $startTime,
            $endTime,
            max(0, (int)($ent['drive_min'] ?? 0)),
            max(0, (int)($ent['other_work_min'] ?? 0)),
            max(0, (int)($ent['avail_min'] ?? 0)),
            max(0, (int)($ent['break_min'] ?? 0)),
            max(0, (int)($ent['rest_min'] ?? 0)),
            max(0, (int)($ent['night_min'] ?? 0)),
            max(0, (int)($ent['duty_min'] ?? 0)),
            max(0, (int)($ent['idle_min'] ?? 0)),
            max(0, (int)($ent['dist_km'] ?? 0)),
            trim($ent['route'] ?? '') ?: null,
            trim($ent['notes'] ?? '') ?: null,
            $userId,
        ]);
        $saved++;
    }
    echo json_encode(['ok' => true, 'saved' => $saved]);
    exit;
}

// ── Handle AJAX delete ────────────────────────────────────────
if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'delete_entry') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'CSRF']);
        exit;
    }
    $entryId = (int)($_POST['entry_id'] ?? 0);
    if ($entryId > 0) {
        $db->prepare('DELETE FROM working_time_manual_entries WHERE id=? AND company_id=?')
           ->execute([$entryId, $companyId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ── Load drivers ──────────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT d.id, d.first_name, d.last_name, d.card_number,
            (SELECT COUNT(*) FROM ddd_files df WHERE df.driver_id=d.id AND df.file_type="driver") AS ddd_count
     FROM drivers d
     WHERE d.company_id=? AND d.is_active=1
     ORDER BY d.last_name, d.first_name'
);
$stmt->execute([$companyId]);
$allDrivers = $stmt->fetchAll();

// Separate DDD vs manual drivers
$dddDrivers    = [];
$manualDrivers = [];
foreach ($allDrivers as $drv) {
    if ((int)$drv['ddd_count'] > 0) {
        $dddDrivers[] = $drv;
    } else {
        $manualDrivers[] = $drv;
    }
}

// ── Filters ───────────────────────────────────────────────────
$selDriverId   = isset($_GET['driver_id']) ? (int)$_GET['driver_id'] : 0;
$selDriverName = trim($_GET['driver_name'] ?? '');
$selMonth      = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selYear       = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
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

// Polish holidays
function getPolishHolidaysManual(int $year): array {
    // Compute Easter Sunday using the Anonymous Gregorian algorithm.
    // This avoids the ext/calendar dependency (easter_date() / easter_days()).
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
    $easterMonday  = date('Y-m-d', strtotime('+1 day', $easterTs));
    $corpusChristi = date('Y-m-d', strtotime('+60 days', $easterTs));
    return [
        sprintf('%04d-01-01', $year), sprintf('%04d-01-06', $year),
        $easterDate, $easterMonday,
        sprintf('%04d-05-01', $year), sprintf('%04d-05-03', $year),
        $corpusChristi,
        sprintf('%04d-08-15', $year), sprintf('%04d-11-01', $year),
        sprintf('%04d-11-11', $year), sprintf('%04d-12-25', $year),
        sprintf('%04d-12-26', $year),
    ];
}
$holidays = getPolishHolidaysManual($selYear);

// ── Load existing entries ─────────────────────────────────────
$driverInfo  = null;
$entries     = [];
$hasReport   = false;

$resolvedDriverName = $selDriverName;

if ($selDriverId) {
    $dStmt = $db->prepare('SELECT id, first_name, last_name, card_number FROM drivers WHERE id=? AND company_id=? AND is_active=1');
    $dStmt->execute([$selDriverId, $companyId]);
    $driverInfo = $dStmt->fetch();
    if ($driverInfo) {
        $resolvedDriverName = $driverInfo['last_name'] . ' ' . $driverInfo['first_name'];
    }
}

if ($tableReady && ($selDriverId || $selDriverName)) {
    $hasReport = true;
    if ($selDriverId) {
        $eStmt = $db->prepare(
            'SELECT * FROM working_time_manual_entries WHERE company_id=? AND driver_id=? AND entry_date BETWEEN ? AND ? ORDER BY entry_date'
        );
        $eStmt->execute([$companyId, $selDriverId, $dateFrom, $dateTo]);
    } else {
        $eStmt = $db->prepare(
            'SELECT * FROM working_time_manual_entries WHERE company_id=? AND driver_id IS NULL AND driver_name=? AND entry_date BETWEEN ? AND ? ORDER BY entry_date'
        );
        $eStmt->execute([$companyId, $selDriverName, $dateFrom, $dateTo]);
    }
    foreach ($eStmt->fetchAll() as $row) {
        $entries[$row['entry_date']] = $row;
    }
} elseif ($selDriverId || $selDriverName) {
    $hasReport = true;
}

// ── Build day data ────────────────────────────────────────────
$STD_DAILY_HOURS = 8 * 60;
$dayData = [];
$totals  = [
    'plan_min' => 0, 'work_time' => 0, 'drive_min' => 0, 'other_work' => 0,
    'break_min' => 0, 'avail_min' => 0, 'duty_min' => 0, 'rest_min' => 0,
    'paid_time' => 0, 'night_min' => 0, 'sunday_holiday' => 0,
    'overtime_50' => 0, 'overtime_100' => 0,
    'idle_min' => 0, 'dist_km' => 0,
    'work_days' => 0, 'free_days' => 0,
    'norm_work_days' => 0, 'norm_free_days' => 0,
];

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateKey = sprintf('%04d-%02d-%02d', $selYear, $selMonth, $d);
    $dow     = (int)date('w', mktime(0, 0, 0, $selMonth, $d, $selYear));
    $dayName = $polishDayShort[$dow];
    $isHoliday  = in_array($dateKey, $holidays);
    $isWeekend  = ($dow === 0 || $dow === 6);
    $isWorkDay  = !$isWeekend && !$isHoliday;

    $ent = $entries[$dateKey] ?? null;

    $entry = [
        'id'         => $ent ? (int)$ent['id'] : 0,
        'date'       => $dateKey,
        'day'        => $d,
        'dow'        => $dow,
        'dayName'    => $dayName,
        'isWorkDay'  => $isWorkDay,
        'isWeekend'  => $isWeekend,
        'isHoliday'  => $isHoliday,
        'symbol'     => '',
        'plan_min'   => 0,
        'start_time' => $ent['start_time'] ?? '',
        'end_time'   => $ent['end_time'] ?? '',
        'drive_min'  => (int)($ent['drive_min'] ?? 0),
        'other_work' => (int)($ent['other_work_min'] ?? 0),
        'work_time'  => 0,
        'break_min'  => (int)($ent['break_min'] ?? 0),
        'avail_min'  => (int)($ent['avail_min'] ?? 0),
        'duty_min'   => (int)($ent['duty_min'] ?? 0),
        'rest_min'   => (int)($ent['rest_min'] ?? 0),
        'night_min'  => (int)($ent['night_min'] ?? 0),
        'idle_min'   => (int)($ent['idle_min'] ?? 0),
        'dist_km'    => (int)($ent['dist_km'] ?? 0),
        'route'      => $ent['route'] ?? '',
        'notes'      => $ent['notes'] ?? '',
        'paid_time'  => 0,
        'sunday_holiday' => 0,
        'overtime_50'  => 0,
        'overtime_100' => 0,
        'has_data'   => false,
    ];

    $entry['work_time'] = $entry['drive_min'] + $entry['other_work'];

    if ($entry['work_time'] > 0 || $entry['start_time']) {
        $entry['has_data'] = true;
        $entry['paid_time'] = $entry['work_time'];
        $entry['symbol'] = 'W';
        $totals['work_days']++;

        if ($isWeekend || $isHoliday) {
            $entry['sunday_holiday'] = $entry['work_time'];
        }
    } else {
        if ($isWeekend || $isHoliday) {
            $entry['symbol'] = ($dow === 0 || $isHoliday) ? 'Św' : 'W6';
        } else {
            $entry['symbol'] = '-';
        }
        $totals['free_days']++;
    }

    if ($isWorkDay) {
        $entry['plan_min'] = $STD_DAILY_HOURS;
        $totals['plan_min'] += $STD_DAILY_HOURS;
        $totals['norm_work_days']++;
    } else {
        $totals['norm_free_days']++;
    }

    // Overtime
    if ($isWorkDay && $entry['work_time'] > $entry['plan_min']) {
        $entry['overtime_50'] = $entry['work_time'] - $entry['plan_min'];
    } elseif (!$isWorkDay && $entry['work_time'] > 0) {
        $entry['overtime_100'] = $entry['work_time'];
    }

    $totals['work_time']   += $entry['work_time'];
    $totals['drive_min']   += $entry['drive_min'];
    $totals['other_work']  += $entry['other_work'];
    $totals['break_min']   += $entry['break_min'];
    $totals['avail_min']   += $entry['avail_min'];
    $totals['duty_min']    += $entry['duty_min'];
    $totals['rest_min']    += $entry['rest_min'];
    $totals['night_min']   += $entry['night_min'];
    $totals['idle_min']    += $entry['idle_min'];
    $totals['dist_km']     += $entry['dist_km'];
    $totals['paid_time']   += $entry['paid_time'];
    $totals['sunday_holiday'] += $entry['sunday_holiday'];
    $totals['overtime_50'] += $entry['overtime_50'];
    $totals['overtime_100'] += $entry['overtime_100'];

    $dayData[$d] = $entry;
}

$overtimeTotal = $totals['overtime_50'] + $totals['overtime_100'];

function fmtMinM(int $minutes): string {
    if ($minutes <= 0) return '-';
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}
function fmtMinFullM(int $minutes): string {
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}

// ── Page render ───────────────────────────────────────────────
$pageTitle    = 'Ewidencja czasu pracy – Generator';
$pageSubtitle = 'Ręczne wprowadzanie czasu pracy kierowcy (pojazdy 2,8-3,5t)';
$activePage   = 'working_time_manual';

include __DIR__ . '/../../templates/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════
     CSS – kolorowy raport + wydruk A4 landscape
     ══════════════════════════════════════════════════════════════ -->
<style>
/* ── Print: A4 landscape ────────────────────────────────────── */
@media print {
    @page { size: A4 landscape; margin: 5mm 8mm; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tp-topbar, .tp-sidebar, .tp-sidebar-overlay, .tp-page-header,
    .no-print, .alert, .tp-content > .tp-page-header,
    #filterCard, #generatorCard, .btn-export-pdf { display: none !important; }
    .tp-main { margin: 0 !important; padding: 0 !important; }
    .tp-content { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
    .wtm-report { box-shadow: none !important; border: none !important; padding: 2mm !important; }
    .wtm-report h2 { font-size: 13pt !important; }
    .wtm-report p { font-size: 8pt !important; margin-bottom: 1mm !important; }
    .wtm-table { font-size: 6.5pt !important; }
    .wtm-table th, .wtm-table td { padding: 1px 2px !important; line-height: 1.15 !important; }
    .wtm-table .wtm-rh { min-width: 90px !important; max-width: 120px !important; font-size: 6pt !important; }
    .wtm-footer { font-size: 7.5pt !important; margin-top: 2mm !important; }
    .wtm-footer td, .wtm-footer th { padding: 1px 3px !important; }
    .wtm-badge { font-size: 6pt !important; padding: 0 2px !important; }
}

/* ── Screen ─────────────────────────────────────────────────── */
.wtm-report {
    background: #fff; border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,.12); padding: 20px; overflow-x: auto;
}
.wtm-report-header { text-align: center; margin-bottom: 14px; }
.wtm-report-header h2 { font-size: 18px; font-weight: 700; margin-bottom: 4px; color: #1a3c5e; }
.wtm-report-header p { font-size: 13px; color: #555; margin-bottom: 2px; }

.wtm-table {
    width: 100%; border-collapse: collapse; font-size: 11px; table-layout: fixed;
}
.wtm-table th, .wtm-table td {
    border: 1px solid #b0bec5; padding: 2px 3px; text-align: center;
    vertical-align: middle; white-space: nowrap; line-height: 1.25;
}
.wtm-table .wtm-rh {
    text-align: left; font-weight: 500; min-width: 130px; max-width: 170px;
    white-space: normal; background: #f5f7fa; position: sticky; left: 0; z-index: 2;
}
.wtm-table thead th {
    background: linear-gradient(135deg, #1a3c5e, #2c5f8a); color: #fff;
    font-weight: 600; position: sticky; top: 0; z-index: 3; font-size: 10px;
}
.wtm-table thead th.wtm-rh {
    z-index: 4; background: linear-gradient(135deg, #1a3c5e, #2c5f8a); color: #fff;
}

/* Kolory dni */
.wtm-d-work { background-color: #e8f5e9; }
.wtm-d-sat  { background-color: #fff8e1; }
.wtm-d-sun  { background-color: #ffebee; }
.wtm-d-hol  { background-color: #fce4ec; }

/* Sekcje */
.wtm-sec td, .wtm-sec th {
    background: linear-gradient(90deg, #1565c0, #1976d2) !important;
    color: #fff !important; font-weight: 700 !important; text-align: left !important;
    font-size: 10px; letter-spacing: .3px;
}
.wtm-sec-green td { background: linear-gradient(90deg, #2e7d32, #43a047) !important; color: #fff !important; font-weight: 700 !important; text-align: left !important; font-size: 10px; }
.wtm-sec-orange td { background: linear-gradient(90deg, #e65100, #f57c00) !important; color: #fff !important; font-weight: 700 !important; text-align: left !important; font-size: 10px; }
.wtm-sec-red td { background: linear-gradient(90deg, #b71c1c, #d32f2f) !important; color: #fff !important; font-weight: 700 !important; text-align: left !important; font-size: 10px; }

.wtm-total { background: #e3f2fd !important; font-weight: 700 !important; min-width: 50px; color: #0d47a1; }
.wtm-bold td { font-weight: 700; }

.wtm-footer { margin-top: 16px; }
.wtm-footer table { border-collapse: collapse; font-size: 12px; }
.wtm-footer td, .wtm-footer th { padding: 3px 8px; border: 1px solid #ddd; }
.wtm-footer th { background: #e3f2fd; font-weight: 600; text-align: left; color: #1565c0; }

.wtm-badge {
    display: inline-block; padding: 1px 5px; border-radius: 3px;
    font-size: 9px; font-weight: 700; letter-spacing: .3px;
}
.wtm-badge-ddd { background: #1565c0; color: #fff; }
.wtm-badge-manual { background: #f57c00; color: #fff; }

/* Generator modal */
.gen-table { font-size: 12px; }
.gen-table input { font-size: 12px; padding: 2px 4px; }
.gen-table .form-control-sm { height: 28px; }
</style>

<!-- ═══ INFO BADGE ══════════════════════════════════════════════ -->
<div class="alert alert-info d-flex align-items-start gap-2 no-print" role="alert">
  <i class="bi bi-info-circle-fill fs-5 flex-shrink-0 mt-1"></i>
  <div>
    <strong>Ewidencja czasu pracy – Generator ręczny</strong><br>
    <small>
      Ten moduł służy do ręcznego prowadzenia ewidencji czasu pracy kierowców
      pojazdów o DMC <strong>2,8 – 3,5 tony</strong>, którzy nie posiadają karty kierowcy
      i tachografu cyfrowego (brak plików DDD). Dane wprowadzasz ręcznie za pomocą
      wygodnego generatora, a raport generowany jest automatycznie w formacie
      zgodnym z Ustawą o czasie pracy kierowców.
    </small>
  </div>
</div>

<!-- ═══ FILTERS ═════════════════════════════════════════════════ -->
<div class="card mb-4 no-print" id="filterCard">
  <div class="card-header bg-white py-2">
    <strong><i class="bi bi-funnel me-1"></i>Wybierz kierowcę i okres</strong>
  </div>
  <div class="card-body py-3">
    <form method="GET" id="reportForm" class="row g-2 align-items-end">

      <!-- Driver selection -->
      <div class="col-md-4">
        <label class="form-label small fw-semibold mb-1">
          Kierowca z listy
          <span class="wtm-badge wtm-badge-manual ms-1">2,8-3,5t</span>
        </label>
        <select name="driver_id" id="selDriverId" class="form-select form-select-sm" onchange="toggleDriverName()">
          <option value="">— wybierz z listy lub wpisz ręcznie —</option>
          <?php if (!empty($manualDrivers)): ?>
          <optgroup label="🚛 Pojazdy 2,8-3,5t (bez DDD)">
            <?php foreach ($manualDrivers as $drv): ?>
            <option value="<?= $drv['id'] ?>" <?= $drv['id'] == $selDriverId ? 'selected' : '' ?>>
              <?= e($drv['last_name'] . ' ' . $drv['first_name']) ?>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if (!empty($dddDrivers)): ?>
          <optgroup label="📇 Kierowcy z plikami DDD (tachograf)">
            <?php foreach ($dddDrivers as $drv): ?>
            <option value="<?= $drv['id'] ?>" <?= $drv['id'] == $selDriverId ? 'selected' : '' ?>>
              <?= e($drv['last_name'] . ' ' . $drv['first_name']) ?>
              <?= $drv['card_number'] ? ' (' . e($drv['card_number']) . ')' : '' ?>
              <span class="wtm-badge wtm-badge-ddd">DDD</span>
            </option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <div class="col-md-3" id="driverNameCol" style="<?= $selDriverId ? 'display:none' : '' ?>">
        <label class="form-label small fw-semibold mb-1">Lub wpisz ręcznie imię i nazwisko</label>
        <input type="text" name="driver_name" class="form-control form-control-sm"
               value="<?= e($selDriverName) ?>" placeholder="np. Jan Kowalski">
      </div>

      <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Miesiąc</label>
        <select name="month" class="form-select form-select-sm">
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m == $selMonth ? 'selected' : '' ?>><?= $polishMonths[$m] ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="col-md-1">
        <label class="form-label small fw-semibold mb-1">Rok</label>
        <select name="year" class="form-select form-select-sm">
          <?php for ($y = (int)date('Y') - 5; $y <= (int)date('Y') + 1; $y++): ?>
          <option value="<?= $y ?>" <?= $y == $selYear ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm flex-fill">
          <i class="bi bi-search me-1"></i>Pokaż
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($hasReport): ?>

<!-- ═══ GENERATOR BUTTON ════════════════════════════════════════ -->
<div class="d-flex gap-2 mb-3 no-print" id="generatorCard">
  <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#generatorModal">
    <i class="bi bi-pencil-square me-1"></i>Otwórz generator – wprowadź czasy
  </button>
  <button type="button" class="btn btn-outline-success btn-sm btn-export-pdf" onclick="window.print()">
    <i class="bi bi-file-pdf me-1"></i>Eksport PDF
  </button>
</div>

<!-- ═══ KOLOROWY RAPORT ═════════════════════════════════════════ -->
<div class="wtm-report">

  <div class="wtm-report-header">
    <h2><i class="bi bi-clock-history me-2"></i>Ewidencja czasu pracy kierowcy</h2>
    <p>
      <strong><?= e($resolvedDriverName) ?></strong>
      <?php if ($driverInfo && $driverInfo['card_number']): ?>
        &middot; Nr karty: <?= e($driverInfo['card_number']) ?>
      <?php endif; ?>
      &middot; <span class="wtm-badge wtm-badge-manual">Pojazdy 2,8-3,5t</span>
    </p>
    <p><strong><?= e($polishMonths[$selMonth]) ?> <?= $selYear ?></strong></p>
  </div>

  <div style="overflow-x:auto;">
  <table class="wtm-table">
    <thead>
      <tr>
        <th class="wtm-rh" style="min-width:150px;">Składnik</th>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dd = $dayData[$d];
            $cls = $dd['isHoliday'] ? 'wtm-d-hol' : ($dd['dow'] === 0 ? 'wtm-d-sun' : ($dd['dow'] === 6 ? 'wtm-d-sat' : 'wtm-d-work'));
        ?>
        <th class="<?= $cls ?>"><?= $d ?></th>
        <?php endfor; ?>
        <th class="wtm-total">Razem</th>
      </tr>
      <tr>
        <th class="wtm-rh"></th>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $dd = $dayData[$d];
            $cls = $dd['isHoliday'] ? 'wtm-d-hol' : ($dd['dow'] === 0 ? 'wtm-d-sun' : ($dd['dow'] === 6 ? 'wtm-d-sat' : 'wtm-d-work'));
        ?>
        <th class="<?= $cls ?>" style="font-size:9px;"><?= e($dd['dayName']) ?></th>
        <?php endfor; ?>
        <th class="wtm-total"></th>
      </tr>
    </thead>
    <tbody>

      <!-- SEKCJA: Zatrudnienie -->
      <tr class="wtm-sec"><td colspan="<?= $daysInMonth + 2 ?>">📋 Zatrudnienie</td></tr>
      <tr>
        <td class="wtm-rh">Symbol dnia</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td style="<?= $dd['has_data'] ? 'color:#2e7d32;font-weight:700' : ($dd['isHoliday']||$dd['dow']===0 ? 'color:#c62828' : '') ?>"><?= e($dd['symbol']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total">—</td>
      </tr>

      <!-- SEKCJA: Plan -->
      <tr class="wtm-sec"><td colspan="<?= $daysInMonth + 2 ?>">📊 Plan / Wymiar</td></tr>
      <tr>
        <td class="wtm-rh">Plan (wymiar zasadniczy)</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= $dd['plan_min'] > 0 ? fmtMinM($dd['plan_min']) : '-' ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['plan_min']) ?></td>
      </tr>

      <!-- SEKCJA: Składniki rzeczywiste -->
      <tr class="wtm-sec-green"><td colspan="<?= $daysInMonth + 2 ?>">🕐 Składniki rzeczywiste</td></tr>
      <tr>
        <td class="wtm-rh">Godzina rozpoczęcia</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td style="<?= $dd['start_time'] ? 'color:#1565c0;font-weight:600' : '' ?>"><?= $dd['start_time'] ?: '-' ?></td>
        <?php endfor; ?>
        <td class="wtm-total">—</td>
      </tr>
      <tr>
        <td class="wtm-rh">Godzina zakończenia</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td style="<?= $dd['end_time'] ? 'color:#1565c0;font-weight:600' : '' ?>"><?= $dd['end_time'] ?: '-' ?></td>
        <?php endfor; ?>
        <td class="wtm-total">—</td>
      </tr>
      <tr class="wtm-bold">
        <td class="wtm-rh" style="color:#2e7d32"><strong>⏱ Czas pracy</strong></td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td style="color:#2e7d32"><?= fmtMinM($dd['work_time']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total" style="color:#2e7d32;font-size:12px"><?= fmtMinFullM($totals['work_time']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">🚗 Jazda</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMinM($dd['drive_min']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['drive_min']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">🔧 Inna praca</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMinM($dd['other_work']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['other_work']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">☕ Przerwy</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMinM($dd['break_min']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['break_min']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">📞 Dyspozycje</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMinM($dd['avail_min']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['avail_min']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">⏰ Dyżury 50%</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMinM($dd['duty_min']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['duty_min']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">🛌 Odpoczynki dobowe</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMinM($dd['rest_min']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['rest_min']) ?></td>
      </tr>

      <!-- SEKCJA: Składniki do wynagrodzenia -->
      <tr class="wtm-sec-orange"><td colspan="<?= $daysInMonth + 2 ?>">💰 Składniki do wynagrodzenia</td></tr>
      <tr>
        <td class="wtm-rh">Przestoje</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMinM($dd['idle_min']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['idle_min']) ?></td>
      </tr>
      <tr class="wtm-bold">
        <td class="wtm-rh" style="color:#e65100"><strong>💵 Czas płatny</strong></td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td style="color:#e65100"><?= fmtMinM($dd['paid_time']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total" style="color:#e65100;font-size:12px"><?= fmtMinFullM($totals['paid_time']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">🌙 Praca nocna</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= fmtMinM($dd['night_min']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['night_min']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">🔴 CP Nd i Św</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td style="<?= $dd['sunday_holiday'] > 0 ? 'color:#c62828;font-weight:600' : '' ?>"><?= fmtMinM($dd['sunday_holiday']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['sunday_holiday']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">📍 Dystans (km)</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td><?= $dd['dist_km'] > 0 ? $dd['dist_km'] : '-' ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= $totals['dist_km'] ?></td>
      </tr>

      <!-- SEKCJA: Nadgodziny -->
      <tr class="wtm-sec-red"><td colspan="<?= $daysInMonth + 2 ?>">⚡ Nadgodziny do wypłaty</td></tr>
      <tr>
        <td class="wtm-rh">Nadgodziny 50%</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td style="<?= $dd['overtime_50'] > 0 ? 'color:#e65100;font-weight:600' : '' ?>"><?= fmtMinM($dd['overtime_50']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['overtime_50']) ?></td>
      </tr>
      <tr>
        <td class="wtm-rh">Nadgodziny 100%</td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++): $dd = $dayData[$d]; ?>
        <td style="<?= $dd['overtime_100'] > 0 ? 'color:#c62828;font-weight:600' : '' ?>"><?= fmtMinM($dd['overtime_100']) ?></td>
        <?php endfor; ?>
        <td class="wtm-total"><?= fmtMinFullM($totals['overtime_100']) ?></td>
      </tr>

    </tbody>
  </table>
  </div>

  <!-- Footer / summary -->
  <div class="wtm-footer mt-3">
    <div class="row">
      <div class="col-md-4">
        <table>
          <tr><th colspan="2">📊 Informacje dodatkowe</th></tr>
          <tr><td>Godziny normatywne:</td><td><strong><?= fmtMinFullM($totals['plan_min']) ?></strong></td></tr>
          <tr><td>Godziny przepracowane:</td><td><strong style="color:#2e7d32"><?= fmtMinFullM($totals['work_time']) ?></strong></td></tr>
          <tr><td>Godziny ponadwymiarowe:</td><td><strong style="color:#c62828"><?= fmtMinFullM($overtimeTotal) ?></strong></td></tr>
        </table>
      </div>
      <div class="col-md-4">
        <table>
          <tr><th colspan="2">📅 Podsumowanie dni</th></tr>
          <tr><td>Normatywne dni pracy:</td><td><strong><?= $totals['norm_work_days'] ?></strong></td></tr>
          <tr><td>Normatywne dni wolne:</td><td><strong><?= $totals['norm_free_days'] ?></strong></td></tr>
          <tr><td>Dni z danymi:</td><td><strong style="color:#2e7d32"><?= $totals['work_days'] ?></strong></td></tr>
          <tr><td>Dni wolne:</td><td><strong><?= $totals['free_days'] ?></strong></td></tr>
        </table>
      </div>
      <div class="col-md-4">
        <table>
          <tr><th colspan="2">🚗 Podsumowanie trasy</th></tr>
          <tr><td>Łączny dystans:</td><td><strong><?= $totals['dist_km'] ?> km</strong></td></tr>
          <tr><td>Praca nocna łącznie:</td><td><strong><?= fmtMinFullM($totals['night_min']) ?></strong></td></tr>
          <tr><td>Typ ewidencji:</td><td><span class="wtm-badge wtm-badge-manual">Ręczna (2,8-3,5t)</span></td></tr>
        </table>
      </div>
    </div>

    <div class="row mt-3">
      <div class="col-md-6">
        <table>
          <tr><td style="padding:12px 8px;border:1px solid #ddd;">
            <small class="text-muted">Podpis kierowcy:</small><br><br><br>
            <div style="border-top:1px dotted #999;width:200px;"></div>
          </td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <table>
          <tr><td style="padding:12px 8px;border:1px solid #ddd;">
            <small class="text-muted">Podpis pracodawcy / osoby kontrolującej:</small><br><br><br>
            <div style="border-top:1px dotted #999;width:200px;"></div>
          </td></tr>
        </table>
      </div>
    </div>

    <div class="row mt-2 no-print">
      <div class="col-12 text-muted" style="font-size:11px;">
        <i class="bi bi-info-circle me-1"></i>
        Raport wygenerowany na podstawie danych ręcznie wprowadzonych w module „Ewidencja – Generator".
        Zgodny z Ustawą o czasie pracy kierowców (Dz.U. 2004 Nr 92 poz. 879 z późn. zm.).
        Dotyczy pojazdów o DMC 2,8-3,5t niepodlegających obowiązkowi posiadania tachografu cyfrowego.
        <br>
        <strong>Symbole:</strong> W = dzień pracy, W6 = sobota, Św = niedziela/święto, - = brak danych.
        <br>
        <strong>Eksport PDF:</strong> Kliknij „Eksport PDF" lub Ctrl+P → orientacja: pozioma A4.
      </div>
    </div>
  </div>

</div><!-- /.wtm-report -->

<!-- ═══ GENERATOR MODAL ═════════════════════════════════════════ -->
<div class="modal fade no-print" id="generatorModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header bg-warning bg-opacity-25 py-2">
        <h5 class="modal-title">
          <i class="bi bi-pencil-square me-2"></i>Generator czasu pracy –
          <strong><?= e($resolvedDriverName) ?></strong>,
          <?= e($polishMonths[$selMonth]) ?> <?= $selYear ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-2" style="overflow-x:auto;">
        <p class="small text-muted mb-2">
          <i class="bi bi-lightbulb me-1"></i>
          Wprowadź czasy w minutach (np. 480 = 8h) lub w formacie H:MM.
          Godziny rozpoczęcia/zakończenia w formacie HH:MM.
          Po uzupełnieniu kliknij <strong>„Zapisz wszystko"</strong>.
        </p>
        <table class="table table-sm table-bordered gen-table" id="genTable">
          <thead class="table-dark">
            <tr>
              <th style="width:35px">Dzień</th>
              <th style="width:30px">Dn</th>
              <th style="width:75px">Rozp.</th>
              <th style="width:75px">Zak.</th>
              <th style="width:60px">Jazda</th>
              <th style="width:65px">Inna pr.</th>
              <th style="width:60px">Dyspo.</th>
              <th style="width:60px">Przerwy</th>
              <th style="width:65px">Odpocz.</th>
              <th style="width:60px">Nocna</th>
              <th style="width:60px">Dyżury</th>
              <th style="width:60px">Przest.</th>
              <th style="width:55px">km</th>
              <th style="width:120px">Trasa</th>
              <th style="width:100px">Uwagi</th>
            </tr>
          </thead>
          <tbody>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $dd = $dayData[$d];
                $rowBg = $dd['isHoliday'] ? '#fce4ec' : ($dd['dow'] === 0 ? '#ffebee' : ($dd['dow'] === 6 ? '#fff8e1' : ''));
            ?>
            <tr style="<?= $rowBg ? "background:$rowBg" : '' ?>" data-day="<?= $d ?>" data-date="<?= $dd['date'] ?>">
              <td class="text-center fw-bold"><?= $d ?></td>
              <td class="text-center" style="font-size:10px;<?= $dd['isHoliday']||$dd['dow']===0 ? 'color:#c62828;font-weight:700' : '' ?>"><?= e($dd['dayName']) ?></td>
              <td><input type="text" class="form-control form-control-sm g-start" value="<?= e($dd['start_time']) ?>" placeholder="HH:MM" maxlength="5"></td>
              <td><input type="text" class="form-control form-control-sm g-end" value="<?= e($dd['end_time']) ?>" placeholder="HH:MM" maxlength="5"></td>
              <td><input type="number" class="form-control form-control-sm g-drive" value="<?= $dd['drive_min'] ?: '' ?>" min="0" max="1440" placeholder="min"></td>
              <td><input type="number" class="form-control form-control-sm g-other" value="<?= $dd['other_work'] ?: '' ?>" min="0" max="1440" placeholder="min"></td>
              <td><input type="number" class="form-control form-control-sm g-avail" value="<?= $dd['avail_min'] ?: '' ?>" min="0" max="1440" placeholder="min"></td>
              <td><input type="number" class="form-control form-control-sm g-break" value="<?= $dd['break_min'] ?: '' ?>" min="0" max="1440" placeholder="min"></td>
              <td><input type="number" class="form-control form-control-sm g-rest" value="<?= $dd['rest_min'] ?: '' ?>" min="0" max="1440" placeholder="min"></td>
              <td><input type="number" class="form-control form-control-sm g-night" value="<?= $dd['night_min'] ?: '' ?>" min="0" max="1440" placeholder="min"></td>
              <td><input type="number" class="form-control form-control-sm g-duty" value="<?= $dd['duty_min'] ?: '' ?>" min="0" max="1440" placeholder="min"></td>
              <td><input type="number" class="form-control form-control-sm g-idle" value="<?= $dd['idle_min'] ?: '' ?>" min="0" max="1440" placeholder="min"></td>
              <td><input type="number" class="form-control form-control-sm g-km" value="<?= $dd['dist_km'] ?: '' ?>" min="0" max="9999" placeholder="km"></td>
              <td><input type="text" class="form-control form-control-sm g-route" value="<?= e($dd['route']) ?>" placeholder="np. WAW-KRK" maxlength="200"></td>
              <td><input type="text" class="form-control form-control-sm g-notes" value="<?= e($dd['notes']) ?>" maxlength="200"></td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <div class="modal-footer py-2">
        <span id="genStatus" class="text-muted small me-auto"></span>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Zamknij</button>
        <button type="button" class="btn btn-success btn-sm" id="btnSaveAll" onclick="saveAllEntries()">
          <i class="bi bi-check2-all me-1"></i>Zapisz wszystko
        </button>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- ═══ JavaScript ══════════════════════════════════════════════ -->
<script>
function toggleDriverName() {
    const sel = document.getElementById('selDriverId');
    const col = document.getElementById('driverNameCol');
    if (sel && col) {
        col.style.display = sel.value ? 'none' : '';
    }
}

function saveAllEntries() {
    const rows = document.querySelectorAll('#genTable tbody tr');
    const entries = [];
    rows.forEach(tr => {
        const date = tr.dataset.date;
        const entry = {
            entry_date: date,
            driver_id: '<?= $selDriverId ?>',
            driver_name: '<?= e($selDriverName) ?>',
            start_time: tr.querySelector('.g-start')?.value || '',
            end_time: tr.querySelector('.g-end')?.value || '',
            drive_min: tr.querySelector('.g-drive')?.value || '0',
            other_work_min: tr.querySelector('.g-other')?.value || '0',
            avail_min: tr.querySelector('.g-avail')?.value || '0',
            break_min: tr.querySelector('.g-break')?.value || '0',
            rest_min: tr.querySelector('.g-rest')?.value || '0',
            night_min: tr.querySelector('.g-night')?.value || '0',
            duty_min: tr.querySelector('.g-duty')?.value || '0',
            idle_min: tr.querySelector('.g-idle')?.value || '0',
            dist_km: tr.querySelector('.g-km')?.value || '0',
            route: tr.querySelector('.g-route')?.value || '',
            notes: tr.querySelector('.g-notes')?.value || '',
        };
        // Only save rows with any data
        if (entry.start_time || entry.end_time || parseInt(entry.drive_min) > 0 ||
            parseInt(entry.other_work_min) > 0 || parseInt(entry.avail_min) > 0 ||
            parseInt(entry.break_min) > 0 || parseInt(entry.rest_min) > 0 ||
            parseInt(entry.night_min) > 0 || parseInt(entry.duty_min) > 0 ||
            parseInt(entry.idle_min) > 0 || parseInt(entry.dist_km) > 0 ||
            entry.route || entry.notes) {
            entries.push(entry);
        }
    });

    if (entries.length === 0) {
        document.getElementById('genStatus').textContent = 'Brak danych do zapisania.';
        return;
    }

    const btn = document.getElementById('btnSaveAll');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Zapisuję...';

    const formData = new FormData();
    formData.append('ajax_action', 'save_entries');
    formData.append('csrf_token', '<?= e(getCsrfToken()) ?>');
    formData.append('entries', JSON.stringify(entries));

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('genStatus').innerHTML =
                    '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Zapisano ' + data.saved + ' wpisów. Odświeżam...</span>';
                setTimeout(() => window.location.reload(), 800);
            } else {
                document.getElementById('genStatus').innerHTML =
                    '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Błąd: ' + (data.error || 'nieznany') + '</span>';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Zapisz wszystko';
            }
        })
        .catch(err => {
            document.getElementById('genStatus').innerHTML =
                '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Błąd połączenia</span>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Zapisz wszystko';
        });
}
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
