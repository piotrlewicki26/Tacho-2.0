<?php
/**
 * TachoPro 2.0 – Drivers management
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();
requireModule('core');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$action    = $_GET['action'] ?? 'list';
$driverId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── Handle POST (add / edit / delete) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        flashSet('danger', 'Nieprawidłowy token CSRF.');
        redirect('/drivers.php');
    }
    if (!hasRole('manager')) {
        flashSet('danger', 'Brak uprawnień.');
        redirect('/drivers.php');
    }

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        $id = (int)($_POST['driver_id'] ?? 0);
        // Fetch driver info for audit log before deleting
        $delStmt = $db->prepare('SELECT first_name, last_name FROM drivers WHERE id=? AND company_id=?');
        $delStmt->execute([$id, $companyId]);
        $delDriver = $delStmt->fetch();
        $db->prepare('UPDATE drivers SET is_active=0 WHERE id=? AND company_id=?')
           ->execute([$id, $companyId]);
        auditLog('delete', 'driver', $id, 'Dezaktywowano kierowcę: ' . ($delDriver ? $delDriver['first_name'] . ' ' . $delDriver['last_name'] : "ID $id"));
        flashSet('success', 'Kierowca został usunięty (dezaktywowany).');
        redirect('/drivers.php');
    }

    // Sanitize & validate input
    $fn    = trim($_POST['first_name']   ?? '');
    $ln    = trim($_POST['last_name']    ?? '');
    $birth = $_POST['birth_date']        ?? '';
    $group = (int)($_POST['group_id']    ?? 0) ?: null;
    $card  = trim($_POST['card_number']  ?? '');
    $cardV = $_POST['card_valid_until']  ?? '';
    $nat   = strtoupper(trim($_POST['nationality'] ?? ''));
    $lic   = trim($_POST['license_number'] ?? '');
    $licC  = trim($_POST['license_category'] ?? '');
    $emp   = $_POST['employment_date']   ?? '';
    $sal   = $_POST['base_salary']       ?? '';

    if (!$fn || !$ln) {
        flashSet('danger', 'Imię i nazwisko są wymagane.');
        redirect('/drivers.php?action=' . $postAction . ($postAction==='edit' ? '&id='.$_POST['driver_id'] : ''));
    }

    $fields = [
        'first_name'        => $fn,
        'last_name'         => $ln,
        'birth_date'        => $birth ?: null,
        'group_id'          => $group,
        'card_number'       => $card ?: null,
        'card_valid_until'  => $cardV ?: null,
        'nationality'       => ($nat !== '' && preg_match('/^[A-Z]{2,3}$/', $nat)) ? $nat : null,
        'license_number'    => $lic ?: null,
        'license_category'  => $licC ?: null,
        'employment_date'   => $emp ?: null,
        'base_salary'       => is_numeric($sal) ? $sal : null,
    ];

    if ($postAction === 'add') {
        // Enforce demo driver limit
        if (!licenseAllowsMore('drivers', $companyId)) {
            flashSet('danger', 'Osiągnięto limit kierowców dla planu DEMO (' . DEMO_MAX_DRIVERS . '). Aktywuj pakiet PRO, aby dodać więcej.');
            redirect('/drivers.php?action=add');
        }
        $fields['company_id'] = $companyId;
        $cols = implode(', ', array_keys($fields));
        $phs  = implode(', ', array_fill(0, count($fields), '?'));
        $db->prepare("INSERT INTO drivers ($cols) VALUES ($phs)")->execute(array_values($fields));
        $newId = (int)$db->lastInsertId();
        auditLog('create', 'driver', $newId, "Dodano kierowcę: $fn $ln", null, $fields);
        flashSet('success', 'Kierowca został dodany.');
    } elseif ($postAction === 'edit') {
        $id   = (int)($_POST['driver_id'] ?? 0);
        // Fetch old values for audit
        $oldStmt = $db->prepare('SELECT * FROM drivers WHERE id=? AND company_id=?');
        $oldStmt->execute([$id, $companyId]);
        $oldDriver = $oldStmt->fetch() ?: [];
        $sets = implode(', ', array_map(static function ($k) {
            return $k . ' = ?';
        }, array_keys($fields)));
        $vals = array_values($fields);
        $vals[] = $id;
        $vals[] = $companyId;
        $db->prepare("UPDATE drivers SET $sets WHERE id = ? AND company_id = ?")->execute($vals);
        auditLog('update', 'driver', $id, "Zaktualizowano kierowcę: $fn $ln", $oldDriver, $fields);
        flashSet('success', 'Dane kierowcy zostały zaktualizowane.');
    }
    redirect('/drivers.php');
}

// ── Groups list ──────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM driver_groups WHERE company_id=? ORDER BY name');
$stmt->execute([$companyId]);
$groups = $stmt->fetchAll();

// ── Load driver for edit / profile ──────────────────────────
$editDriver = null;
if (($action === 'edit' || $action === 'view' || $action === 'profile') && $driverId) {
    $stmt = $db->prepare('SELECT * FROM drivers WHERE id=? AND company_id=?');
    $stmt->execute([$driverId, $companyId]);
    $editDriver = $stmt->fetch();
    if (!$editDriver) { flashSet('danger', 'Nie znaleziono kierowcy.'); redirect('/drivers.php'); }
}

// ── Profile view – extra data ────────────────────────────────
$profileLastDownload = null;
$profileWeeks        = [];
$profileTotalDrive   = 0;
$profileChartDays    = [];
$profileVehicles     = [];
$vehFrom             = null;
$vehTo               = null;
$activityFrom        = null;
$activityTo          = null;
$activityPreset      = 'last28';
$activityRangeLabel  = 'ostatnie 28 dni';
$profileViolations   = [];
$violationByCountry  = [];
$violationTotals     = ['count' => 0, 'driver' => 0.0, 'company' => 0.0];
$violationTotalsSelected = ['count' => 0, 'driver' => 0.0, 'company' => 0.0];
$selectedPenaltyCountry = 'PL';
$selectedPenaltyCountryName = 'Polska';
if ($action === 'profile' && $editDriver) {
    // Last download date (latest period_end from card_downloads)
    $stmt = $db->prepare(
        'SELECT download_date FROM card_downloads WHERE driver_id=? ORDER BY download_date DESC LIMIT 1'
    );
    $stmt->execute([$driverId]);
    $profileLastDownload = $stmt->fetchColumn() ?: null;

    try {
        // Auto-backfill calendar from ddd_activity_days when calendar is empty
        backfillDriverActivityCalendar($db, $companyId, $driverId);

        // Resolve available activity range.
        $rangeStmt = $db->prepare(
            'SELECT MIN(date) AS dmin, MAX(date) AS dmax
             FROM driver_activity_calendar
             WHERE company_id=? AND driver_id=?'
        );
        $rangeStmt->execute([$companyId, $driverId]);
        $rangeRow = $rangeStmt->fetch();
        $dataDateMin = $rangeRow['dmin'] ?? null;
        $dataDateMax = $rangeRow['dmax'] ?? null;
        if (!$dataDateMax) {
            $rangeRawStmt = $db->prepare(
                'SELECT MIN(d.date) AS dmin, MAX(d.date) AS dmax
                 FROM ddd_activity_days d
                 JOIN ddd_files f ON f.id=d.file_id
                 WHERE f.company_id=? AND f.driver_id=? AND f.file_type=\'driver\' AND f.is_deleted=0'
            );
            $rangeRawStmt->execute([$companyId, $driverId]);
            $rangeRaw = $rangeRawStmt->fetch();
            $dataDateMin = $rangeRaw['dmin'] ?? null;
            $dataDateMax = $rangeRaw['dmax'] ?? null;
        }

        $today = new DateTime('today');
        $presetLast28From = (clone $today)->modify('-27 days')->format('Y-m-d');
        $presetLast28To = $today->format('Y-m-d');
        $presetMonthFrom = $today->format('Y-m-01');
        $presetMonthTo = $today->format('Y-m-t');
        $preset3mFrom = (clone $today)->modify('-3 months')->format('Y-m-d');
        $preset3mTo = $today->format('Y-m-d');

        $preset = isset($_GET['act_preset']) ? (string)$_GET['act_preset'] : 'last28';
        if (!in_array($preset, ['last28', 'month', 'last3m', 'custom'], true)) {
            $preset = 'last28';
        }
        $rawFrom = isset($_GET['act_from']) ? trim((string)$_GET['act_from']) : '';
        $rawTo = isset($_GET['act_to']) ? trim((string)$_GET['act_to']) : '';
        $hasFrom = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom);
        $hasTo = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo);

        if ($hasFrom || $hasTo || $preset === 'custom') {
            $activityPreset = 'custom';
            $fallbackFrom = $dataDateMin ?: $presetLast28From;
            $fallbackTo = $dataDateMax ?: $presetLast28To;
            $activityFrom = $hasFrom ? $rawFrom : $fallbackFrom;
            $activityTo = $hasTo ? $rawTo : $fallbackTo;
        } elseif ($preset === 'month') {
            $activityPreset = 'month';
            $activityFrom = $presetMonthFrom;
            $activityTo = $presetMonthTo;
        } elseif ($preset === 'last3m') {
            $activityPreset = 'last3m';
            $activityFrom = $preset3mFrom;
            $activityTo = $preset3mTo;
        } else {
            $activityPreset = 'last28';
            $activityFrom = $presetLast28From;
            $activityTo = $presetLast28To;
        }

        if ($activityFrom > $activityTo) {
            $tmp = $activityFrom;
            $activityFrom = $activityTo;
            $activityTo = $tmp;
        }

        $chartFrom = $activityFrom;
        $chartTo = $activityTo;

        if ($activityPreset === 'month') {
            $activityRangeLabel = 'obecny miesiąc';
        } elseif ($activityPreset === 'last3m') {
            $activityRangeLabel = 'ostatnie 3 miesiące';
        } elseif ($activityPreset === 'custom') {
            $activityRangeLabel = 'własny zakres';
        } else {
            $activityRangeLabel = 'ostatnie 28 dni';
        }

        $chartStmt = $db->prepare(
            'SELECT date, drive_min, work_min, avail_min, rest_min, dist_km, violations, segments
             FROM driver_activity_calendar
             WHERE company_id=? AND driver_id=? AND date BETWEEN ? AND ?
             ORDER BY date'
        );
        $chartStmt->execute([$companyId, $driverId, $chartFrom, $chartTo]);
        $chartRows = $chartStmt->fetchAll();
        if (empty($chartRows)) {
            $chartStmtRaw = $db->prepare(
                'SELECT d.date, d.drive_min, d.work_min, d.avail_min, d.rest_min, d.dist_km,
                        d.violations, d.segments
                 FROM ddd_activity_days d
                 JOIN ddd_files f ON f.id=d.file_id
                 WHERE f.company_id=? AND f.driver_id=? AND f.file_type=\'driver\' AND f.is_deleted=0
                   AND d.date BETWEEN ? AND ?
                 ORDER BY d.date'
            );
            $chartStmtRaw->execute([$companyId, $driverId, $chartFrom, $chartTo]);
            $chartRows = $chartStmtRaw->fetchAll();
        }
        $profileCrossingsByDate = [];
        try {
            $profileCrossingsByDate = getDriverBorderCrossingsByDateRange(
                $db,
                $companyId,
                $driverId,
                $chartFrom,
                $chartTo
            );
        } catch (\Throwable $e) {
            $profileCrossingsByDate = [];
        }

        foreach ($chartRows as $cr) {
            $dKey = (string)$cr['date'];
            $profileChartDays[] = [
                'date'      => $dKey,
                'segs'      => json_decode($cr['segments']  ?? '[]', true) ?: [],
                'drive'     => (int)$cr['drive_min'],
                'work'      => (int)$cr['work_min'],
                'avail'     => (int)$cr['avail_min'],
                'rest'      => (int)$cr['rest_min'],
                'dist'      => (int)$cr['dist_km'],
                'viol'      => json_decode($cr['violations'] ?? '[]', true) ?: [],
                'crossings' => $profileCrossingsByDate[$dKey] ?? [],
            ];
        }
        $profileDates = [];
        foreach ($profileChartDays as $day) {
            if (!empty($day['date'])) $profileDates[(string)$day['date']] = true;
        }
        foreach ($profileCrossingsByDate as $dKey => $crossings) {
            if (isset($profileDates[$dKey])) continue;
            $profileChartDays[] = [
                'date'      => $dKey,
                'segs'      => [],
                'drive'     => 0,
                'work'      => 0,
                'avail'     => 0,
                'rest'      => 0,
                'dist'      => 0,
                'viol'      => [],
                'crossings' => $crossings,
            ];
        }
        usort($profileChartDays, static function ($a, $b) {
            return strcmp((string)$a['date'], (string)$b['date']);
        });
    } catch (Throwable $chartErr) {
        error_log('drivers.php profile chart: ' . $chartErr->getMessage());
    }

    $euCountries = [
        'AT' => 'Austria', 'BE' => 'Belgia', 'BG' => 'Bułgaria', 'HR' => 'Chorwacja', 'CY' => 'Cypr',
        'CZ' => 'Czechy', 'DK' => 'Dania', 'EE' => 'Estonia', 'FI' => 'Finlandia', 'FR' => 'Francja',
        'DE' => 'Niemcy', 'EL' => 'Grecja', 'HU' => 'Węgry', 'IE' => 'Irlandia', 'IT' => 'Włochy',
        'LV' => 'Łotwa', 'LT' => 'Litwa', 'LU' => 'Luksemburg', 'MT' => 'Malta', 'NL' => 'Niderlandy',
        'PL' => 'Polska', 'PT' => 'Portugalia', 'RO' => 'Rumunia', 'SK' => 'Słowacja', 'SI' => 'Słowenia',
        'ES' => 'Hiszpania', 'SE' => 'Szwecja',
        'GR' => 'Grecja',
    ];
    $countryPenaltyFactor = [
        'PL' => 1.00, 'DE' => 1.35, 'FR' => 1.40, 'ES' => 1.15, 'IT' => 1.20, 'NL' => 1.25, 'BE' => 1.20,
        'AT' => 1.10, 'CZ' => 0.95, 'SK' => 0.90, 'HU' => 0.90, 'RO' => 0.85, 'BG' => 0.80, 'SI' => 0.95,
        'HR' => 0.90, 'LT' => 0.85, 'LV' => 0.85, 'EE' => 0.90, 'FI' => 1.20, 'SE' => 1.25, 'DK' => 1.30,
        'IE' => 1.15, 'PT' => 1.00, 'LU' => 1.10, 'MT' => 0.95, 'CY' => 0.90, 'EL' => 0.95, 'GR' => 0.95,
        'UNKN' => 1.00,
    ];
    $driverNat = strtoupper(trim((string)($editDriver['nationality'] ?? '')));
    $requestedPenaltyCountry = strtoupper(trim((string)($_GET['viol_country'] ?? '')));
    if ($requestedPenaltyCountry !== '' && isset($euCountries[$requestedPenaltyCountry])) {
        $selectedPenaltyCountry = $requestedPenaltyCountry;
    } elseif ($driverNat !== '' && isset($euCountries[$driverNat])) {
        $selectedPenaltyCountry = $driverNat;
    }
    $selectedPenaltyCountryName = $euCountries[$selectedPenaltyCountry] ?? 'Polska';
    $selectedCountryFactor = isset($countryPenaltyFactor[$selectedPenaltyCountry])
        ? (float)$countryPenaltyFactor[$selectedPenaltyCountry]
        : 1.0;

    foreach ($profileChartDays as $day) {
        $dayDate = (string)($day['date'] ?? '');
        if ($dayDate === '') continue;
        $dayCrossings = is_array($day['crossings'] ?? null) ? $day['crossings'] : [];
        $dayCountries = [];
        foreach ($dayCrossings as $cross) {
            $cc = strtoupper(trim((string)($cross['country'] ?? '')));
            if ($cc !== '' && isset($euCountries[$cc])) {
                $dayCountries[$cc] = true;
            }
        }
        if (empty($dayCountries) && $driverNat !== '' && isset($euCountries[$driverNat])) {
            $dayCountries[$driverNat] = true;
        }
        if (empty($dayCountries)) {
            $dayCountries['UNKN'] = true;
        }
        $countryCodes = array_keys($dayCountries);

        $dayViolations = is_array($day['viol'] ?? null) ? $day['viol'] : [];
        foreach ($dayViolations as $v) {
            $type = (string)($v['type'] ?? 'warn');
            $msg = trim((string)($v['msg'] ?? 'Naruszenie'));
            $penaltyData = violPenalty($type, $msg);
            $baseDriver = isset($v['penalty_driver']) && is_numeric($v['penalty_driver'])
                ? (float)$v['penalty_driver']
                : (float)($penaltyData['penalty_driver'] ?? 0);
            $baseCompany = isset($v['penalty_company']) && is_numeric($v['penalty_company'])
                ? (float)$v['penalty_company']
                : (float)($penaltyData['penalty_company'] ?? 0);
            $article = (string)($v['article'] ?? ($penaltyData['article'] ?? 'rozp. WE 561/2006'));

            $violationTotals['count']++;
            $violationTotalsSelected['count']++;
            $countryCount = max(1, count($countryCodes));
            $totalDriverEst = 0.0;
            $totalCompanyEst = 0.0;
            foreach ($countryCodes as $cc) {
                $factor = isset($countryPenaltyFactor[$cc]) ? (float)$countryPenaltyFactor[$cc] : 1.0;
                $drv = ($baseDriver / $countryCount) * $factor;
                $cmp = ($baseCompany / $countryCount) * $factor;
                $totalDriverEst += $drv;
                $totalCompanyEst += $cmp;
                if (!isset($violationByCountry[$cc])) {
                    $violationByCountry[$cc] = [
                        'code' => $cc,
                        'name' => $euCountries[$cc] ?? 'Nieustalony kraj UE',
                        'count' => 0,
                        'driver' => 0.0,
                        'company' => 0.0,
                    ];
                }
                $violationByCountry[$cc]['count']++;
                $violationByCountry[$cc]['driver'] += $drv;
                $violationByCountry[$cc]['company'] += $cmp;
            }
            $violationTotals['driver'] += $totalDriverEst;
            $violationTotals['company'] += $totalCompanyEst;
            $selectedDriverEst = $baseDriver * $selectedCountryFactor;
            $selectedCompanyEst = $baseCompany * $selectedCountryFactor;
            $violationTotalsSelected['driver'] += $selectedDriverEst;
            $violationTotalsSelected['company'] += $selectedCompanyEst;

            $countryNames = [];
            foreach ($countryCodes as $cc) {
                $countryNames[] = ($euCountries[$cc] ?? 'Nieustalony kraj UE') . ' (' . $cc . ')';
            }
            $profileViolations[] = [
                'date' => $dayDate,
                'type' => $type,
                'msg' => $msg,
                'article' => $article,
                'countries' => $countryNames,
                'penalty_driver' => $totalDriverEst,
                'penalty_company' => $totalCompanyEst,
                'penalty_driver_selected' => $selectedDriverEst,
                'penalty_company_selected' => $selectedCompanyEst,
            ];
        }
    }
    usort($profileViolations, static function ($a, $b) {
        $d = strcmp((string)$b['date'], (string)$a['date']);
        if ($d !== 0) return $d;
        return strcmp((string)$a['msg'], (string)$b['msg']);
    });
    uasort($violationByCountry, static function ($a, $b) {
        if ($a['company'] === $b['company']) {
            return strcmp((string)$a['code'], (string)$b['code']);
        }
        return ($a['company'] < $b['company']) ? 1 : -1;
    });

    // Weekly driving time table from driver_activity_calendar (selected range).
    $weekFrom = $activityFrom ?: (new DateTime('today'))->modify('-27 days')->format('Y-m-d');
    $weekTo = $activityTo ?: (new DateTime('today'))->format('Y-m-d');
    $stmt = $db->prepare(
        'SELECT date, drive_min
         FROM driver_activity_calendar
         WHERE company_id=? AND driver_id=? AND date BETWEEN ? AND ?
         ORDER BY date'
    );
    $stmt->execute([$companyId, $driverId, $weekFrom, $weekTo]);
    $calRows = $stmt->fetchAll();

    // Group by ISO year-week
    $weekData = [];
    foreach ($calRows as $row) {
        $dt   = new DateTime($row['date']);
        $iso  = $dt->format('o-W');   // e.g. "2024-05"
        $dow  = (int)$dt->format('N'); // 1=Mon … 7=Sun
        $weekData[$iso]['year']       = $dt->format('o');
        $weekData[$iso]['week']       = (int)$dt->format('W');
        $weekData[$iso]['days'][$dow] = ($weekData[$iso]['days'][$dow] ?? 0) + (int)$row['drive_min'];
    }
    ksort($weekData);
    foreach ($weekData as $isoKey => $w) {
        $rowTotal = array_sum($w['days'] ?? []);
        $profileTotalDrive += $rowTotal;
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            $days[$d] = $w['days'][$d] ?? 0;
        }
        $profileWeeks[] = [
            'year'  => $w['year'],
            'week'  => $w['week'],
            'days'  => $days,
            'total' => $rowTotal,
        ];
    }

    // Group profileWeeks into activity periods.
    // A new period starts when a week with 0 driving minutes is encountered
    // or when there is a gap of more than one ISO week between consecutive weeks.
    $activityPeriods = [];
    $currentPeriod   = [];
    $prevIsoKey      = null;

    foreach ($profileWeeks as $w) {
        $isoKey = $w['year'] . '-' . str_pad($w['week'], 2, '0', STR_PAD_LEFT);

        if ($w['total'] === 0) {
            // Zero-total week closes the current period (if any) and is itself skipped.
            if (!empty($currentPeriod)) {
                $activityPeriods[] = $currentPeriod;
                $currentPeriod = [];
            }
            $prevIsoKey = null;
            continue;
        }

        if ($prevIsoKey !== null) {
            // Check for gap: compute previous and current week start dates and
            // compare the difference in days; gap > 7 days means non-consecutive.
            [$py, $pw] = explode('-', $prevIsoKey);
            $prevMon   = (new DateTime())->setISODate((int)$py, (int)$pw, 1);
            $curMon    = (new DateTime())->setISODate((int)$w['year'], (int)$w['week'], 1);
            $diff      = (int)$curMon->diff($prevMon)->days;
            if ($diff > 7) {
                // Gap – close the current period and start a new one.
                if (!empty($currentPeriod)) {
                    $activityPeriods[] = $currentPeriod;
                }
                $currentPeriod = [];
            }
        }

        $currentPeriod[] = $w;
        $prevIsoKey      = $isoKey;
    }
    if (!empty($currentPeriod)) {
        $activityPeriods[] = $currentPeriod;
    }

    // ── Vehicles tab data (parsed directly from driver DDD files) ────────────
    // Default to a 20-year lookback so that all vehicles stored on the card
    // (driver cards hold the last ~84 vehicles regardless of age) are shown.
    // The same 20-year tsMin is used inside parseDriverCardVehicles() itself.
    $nowDt   = new DateTime('today');
    $vehFrom = isset($_GET['veh_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['veh_from'])
             ? $_GET['veh_from']
             : (new DateTime())->modify('-20 years')->format('Y-m-d');
    $nowDt   = new DateTime('today');
    $vehTo   = isset($_GET['veh_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['veh_to'])
             ? $_GET['veh_to']
             : $nowDt->format('Y-m-d');
    try {
        // Fetch all driver DDD files for this driver
        $vfStmt = $db->prepare(
            "SELECT * FROM ddd_files
             WHERE company_id=? AND driver_id=? AND file_type='driver' AND is_deleted=0
             ORDER BY download_date DESC"
        );
        $vfStmt->execute([$companyId, $driverId]);
        $vehFiles = $vfStmt->fetchAll();

        $rawVehicles = [];
        $checkVehStmt = $db->prepare(
            'SELECT id FROM vehicles
             WHERE company_id=? AND is_active=1 AND UPPER(registration)=UPPER(?)
             LIMIT 1'
        );
        $insVehStmt = $db->prepare(
            'INSERT INTO vehicles (company_id, registration) VALUES (?,?)'
        );
        foreach ($vehFiles as $vfRow) {
            $fp = dddPhysPath($vfRow, $companyId);
            if (!is_file($fp)) continue;
            $rawData = file_get_contents($fp);
            if ($rawData === false) continue;
            $recs = parseDriverCardVehicles($rawData);
            foreach ($recs as $r) {
                // Backfill vehicles table with any missing registrations from driver card
                $regUc = strtoupper(trim($r['reg'] ?? ''));
                if ($regUc !== '') {
                    $checkVehStmt->execute([$companyId, $regUc]);
                    if (!$checkVehStmt->fetchColumn()) {
                        try { $insVehStmt->execute([$companyId, $regUc]); } catch (Throwable $_) {}
                    }
                }
                // Include vehicle if its usage period overlaps the filter window
                if ($r['date_to']  < $vehFrom) continue;
                if ($r['date_from'] > $vehTo)   continue;
                $rawVehicles[] = $r;
            }
        }

        // Merge records from multiple DDD files and collapse consecutive usage
        // periods of the same vehicle (as in previous behavior).
        $profileVehicles = groupVehicleTrips(mergeVehicleRecords($rawVehicles));
    } catch (Throwable $vErr) {
        error_log('drivers.php vehicles tab: ' . $vErr->getMessage());
    }
}

// ── Pagination & list ────────────────────────────────────────
$search  = trim($_GET['q']       ?? '');
$perPage = max(10, min(100, (int)($_GET['perPage'] ?? 25)));
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = 'WHERE d.company_id = :cid AND d.is_active = 1';
$params = [':cid' => $companyId];
if ($search) {
    $where .= ' AND (d.first_name LIKE :q OR d.last_name LIKE :q OR d.card_number LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$cntStmt = $db->prepare("SELECT COUNT(*) FROM drivers d $where");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$pag    = paginate($total, $perPage, $page);
$params[':limit']  = $pag['perPage'];
$params[':offset'] = $pag['offset'];

$listStmt = $db->prepare(
    "SELECT d.*, g.name AS group_name,
            (SELECT download_date FROM card_downloads WHERE driver_id=d.id ORDER BY download_date DESC LIMIT 1) AS last_download
     FROM drivers d
     LEFT JOIN driver_groups g ON g.id = d.group_id
     $where
     ORDER BY d.last_name, d.first_name
     LIMIT :limit OFFSET :offset"
);
$listStmt->execute($params);
$drivers = $listStmt->fetchAll();

$pageTitle    = 'Kierowcy';
$pageSubtitle = 'Zarządzanie listą kierowców';
$activePage   = 'drivers';

include __DIR__ . '/templates/header.php';
?>

<!-- ── Toolbar ───────────────────────────────────────────────── -->
<?php if ($action !== 'profile'): ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <form method="GET" class="d-flex gap-2 flex-grow-1" style="max-width:360px">
    <input type="hidden" name="perPage" value="<?= $perPage ?>">
    <input type="search" name="q" class="form-control form-control-sm" placeholder="Szukaj kierowcy…"
           value="<?= e($search) ?>">
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
  <div class="ms-auto d-flex gap-2">
    <select class="form-select form-select-sm" style="width:auto" data-perpage-select>
      <?php foreach ([10,25,50,100] as $n): ?>
      <option value="<?= $n ?>"<?= $n==$perPage?' selected':'' ?>><?= $n ?> / str.</option>
      <?php endforeach; ?>
    </select>
    <?php if (hasRole('manager')): ?>
    <a href="/drivers.php?action=add" class="btn btn-sm btn-primary">
      <i class="bi bi-person-plus me-1"></i>Dodaj kierowcę
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- ── Add / Edit form ────────────────────────────────────────── -->
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-person-badge text-primary"></i>
    <span class="tp-card-title"><?= $action==='add' ? 'Dodaj kierowcę' : 'Edytuj kierowcę' ?></span>
    <a href="/drivers.php" class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="bi bi-x"></i> Anuluj
    </a>
  </div>
  <div class="tp-card-body">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token"  value="<?= e(getCsrfToken()) ?>">
      <input type="hidden" name="action"      value="<?= $action ?>">
      <?php if ($action==='edit'): ?>
      <input type="hidden" name="driver_id"   value="<?= $driverId ?>">
      <?php endif; ?>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-600">Imię <span class="text-danger">*</span></label>
          <input type="text" name="first_name" class="form-control" required maxlength="100"
                 value="<?= e($editDriver['first_name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Nazwisko <span class="text-danger">*</span></label>
          <input type="text" name="last_name" class="form-control" required maxlength="100"
                 value="<?= e($editDriver['last_name'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Data urodzenia</label>
          <input type="date" name="birth_date" class="form-control"
                 value="<?= e($editDriver['birth_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Grupa</label>
          <select name="group_id" class="form-select">
            <option value="">— Brak —</option>
            <?php foreach ($groups as $g): ?>
            <option value="<?= $g['id'] ?>"
              <?= ($editDriver['group_id']??'')==$g['id']?' selected':'' ?>>
              <?= e($g['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Nr karty kierowcy</label>
          <input type="text" name="card_number" class="form-control" maxlength="50"
                 value="<?= e($editDriver['card_number'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-600">Karta ważna do</label>
          <input type="date" name="card_valid_until" class="form-control"
                 value="<?= e($editDriver['card_valid_until'] ?? '') ?>">
        </div>
        <div class="col-md-1">
          <label class="form-label fw-600">Kraj</label>
          <input type="text" name="nationality" class="form-control" maxlength="3"
                 value="<?= e($editDriver['nationality'] ?? '') ?>"
                 placeholder="PL" title="Kod kraju (2-3 litery, np. PL, DE, FR)">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Nr prawa jazdy</label>
          <input type="text" name="license_number" class="form-control" maxlength="50"
                 value="<?= e($editDriver['license_number'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-600">Kategoria</label>
          <input type="text" name="license_category" class="form-control" maxlength="20"
                 value="<?= e($editDriver['license_category'] ?? '') ?>" placeholder="C+E">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-600">Data zatrudnienia</label>
          <input type="date" name="employment_date" class="form-control"
                 value="<?= e($editDriver['employment_date'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-600">Wynagrodzenie (PLN)</label>
          <input type="number" name="base_salary" class="form-control" step="0.01" min="0"
                 value="<?= e($editDriver['base_salary'] ?? '') ?>">
        </div>
      </div>
      <div class="mt-3">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-check2 me-1"></i><?= $action==='add'?'Dodaj':'Zapisz zmiany' ?>
        </button>
        <a href="/drivers.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Drivers table ──────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-people text-primary"></i>
    <span class="tp-card-title">Lista kierowców</span>
    <span class="badge bg-secondary ms-2"><?= $total ?></span>
  </div>
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead>
          <tr>
            <th>Imię i Nazwisko</th>
            <th>Grupa</th>
            <th>Data urodzenia</th>
            <th>Ostatnie pobranie</th>
            <th>Karta ważna do</th>
            <th>Status karty</th>
            <th class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($drivers as $d): ?>
          <?php $cardSt = dateStatus($d['card_valid_until'], 30); ?>
          <tr>
            <td>
              <a href="/drivers.php?action=profile&id=<?= $d['id'] ?>" class="fw-bold text-decoration-none">
                <?= e($d['last_name'] . ' ' . $d['first_name']) ?>
              </a>
            </td>
            <td><?= e($d['group_name'] ?? '—') ?></td>
            <td><?= fmtDate($d['birth_date']) ?></td>
            <td><?= fmtDate($d['last_download']) ?></td>
            <td><?= fmtDate($d['card_valid_until']) ?></td>
            <td><span class="badge bg-<?= e($cardSt['class']) ?>"><?= e($cardSt['label']) ?></span></td>
            <td class="text-end">
              <a href="/drivers.php?action=edit&id=<?= $d['id'] ?>"
                 class="btn btn-xs btn-outline-primary me-1" title="Edytuj">
                <i class="bi bi-pencil"></i>
              </a>
              <a href="/drivers.php?action=profile&id=<?= $d['id'] ?>#pane-activity"
                 class="btn btn-xs btn-outline-info me-1" title="Analiza">
                <i class="bi bi-bar-chart-line"></i>
              </a>
              <?php if (hasRole('admin')): ?>
              <form method="POST" class="d-inline"
                    onsubmit="return confirm('Czy na pewno dezaktywować tego kierowcę?')">
                <input type="hidden" name="csrf_token"  value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action"      value="delete">
                <input type="hidden" name="driver_id"   value="<?= $d['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger" title="Usuń">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$drivers): ?>
          <tr>
            <td colspan="7">
              <div class="tp-empty-state">
                <i class="bi bi-person-x"></i>
                Brak kierowców. <a href="/drivers.php?action=add">Dodaj pierwszego kierowcę</a>.
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($pag['totalPages'] > 1): ?>
  <div class="tp-card-footer d-flex justify-content-between align-items-center">
    <small class="text-muted">
      Wyniki <?= $pag['offset']+1 ?>–<?= min($pag['offset']+$pag['perPage'], $total) ?>
      z <?= $total ?>
    </small>
    <?= paginationHtml($pag, '?q=' . urlencode($search) . '&perPage=' . $perPage) ?>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ── Driver Profile ─────────────────────────────────────────── -->
<?php
$cardSt      = dateStatus($editDriver['card_valid_until'], 30);
$daysSinceDl = null;
if ($profileLastDownload) {
    $today       = new DateTime('today');
    $dlDt        = new DateTime($profileLastDownload);
    $daysSinceDl = (int)$today->diff($dlDt)->format('%a');
}
$totalH = (int)floor($profileTotalDrive / 60);
$totalM = $profileTotalDrive % 60;
?>
<div class="d-flex align-items-center gap-2 mb-3">
  <a href="/drivers.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Kierowcy
  </a>
  <h5 class="mb-0 ms-1">
    <?= e($editDriver['first_name'] . ' ' . $editDriver['last_name']) ?>
  </h5>
  <?php if (hasRole('manager')): ?>
  <a href="/drivers.php?action=edit&id=<?= $driverId ?>" class="btn btn-sm btn-outline-primary ms-auto">
    <i class="bi bi-pencil me-1"></i>Edytuj dane
  </a>
  <?php endif; ?>
</div>

<!-- Info widgets -->
<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3">
    <div class="tp-card h-100">
      <div class="tp-card-body d-flex align-items-center gap-3 py-3">
        <div class="tp-stat-icon bg-<?= e($cardSt['class']) ?>-subtle rounded-3 p-3">
          <i class="bi bi-credit-card-2-front fs-4 text-<?= e($cardSt['class']) ?>"></i>
        </div>
        <div>
          <div class="tp-stat-label text-muted small">Karta ważna do</div>
          <div class="tp-stat-value fw-bold"><?= fmtDate($editDriver['card_valid_until']) ?></div>
          <span class="badge bg-<?= e($cardSt['class']) ?>"><?= e($cardSt['label']) ?></span>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="tp-card h-100">
      <div class="tp-card-body d-flex align-items-center gap-3 py-3">
        <div class="tp-stat-icon bg-info-subtle rounded-3 p-3">
          <i class="bi bi-cloud-download fs-4 text-info"></i>
        </div>
        <div>
          <div class="tp-stat-label text-muted small">Ostatnie pobranie</div>
          <div class="tp-stat-value fw-bold"><?= fmtDate($profileLastDownload) ?></div>
          <?php if ($daysSinceDl !== null): ?>
          <small class="text-muted"><?= $daysSinceDl ?> dni temu</small>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="tp-card h-100">
      <div class="tp-card-body d-flex align-items-center gap-3 py-3">
        <div class="tp-stat-icon bg-primary-subtle rounded-3 p-3">
          <i class="bi bi-card-text fs-4 text-primary"></i>
        </div>
        <div>
          <div class="tp-stat-label text-muted small">Nr karty kierowcy</div>
          <div class="tp-stat-value fw-bold" style="font-size:.9rem"><?= e($editDriver['card_number'] ?? '—') ?></div>
          <?php if (!empty($editDriver['nationality'])): ?>
          <small class="text-muted"><i class="bi bi-flag me-1"></i><?= e($editDriver['nationality']) ?></small>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="tp-card h-100">
      <div class="tp-card-body d-flex align-items-center gap-3 py-3">
        <div class="tp-stat-icon bg-success-subtle rounded-3 p-3">
          <i class="bi bi-clock-history fs-4 text-success"></i>
        </div>
        <div>
          <div class="tp-stat-label text-muted small">Łączny czas jazdy</div>
          <div class="tp-stat-value fw-bold"><?= $totalH ?>h <?= $totalM ?>m</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<div class="d-flex gap-3">
  <!-- Left nav -->
  <div class="flex-shrink-0" style="width:190px">
    <div class="tp-card">
      <div class="tp-card-body p-2">
        <div class="list-group list-group-flush" id="profileTabList" role="tablist">
          <a class="list-group-item list-group-item-action active py-2 px-3" id="tab-activity"
             data-bs-toggle="list" href="#pane-activity" role="tab">
            <i class="bi bi-bar-chart-line me-2"></i>Aktywność
          </a>
          <a class="list-group-item list-group-item-action py-2 px-3" id="tab-violations"
             data-bs-toggle="list" href="#pane-violations" role="tab">
            <i class="bi bi-exclamation-octagon me-2"></i>Naruszenia
            <?php if (!empty($violationTotals['count'])): ?>
            <span class="badge bg-danger ms-1"><?= (int)$violationTotals['count'] ?></span>
            <?php endif; ?>
          </a>
          <a class="list-group-item list-group-item-action py-2 px-3" id="tab-delegation"
             data-bs-toggle="list" href="#pane-delegation" role="tab">
            <i class="bi bi-file-earmark-text me-2"></i>Poświadczenie czynności
          </a>
          <a class="list-group-item list-group-item-action py-2 px-3" id="tab-weeks"
             data-bs-toggle="list" href="#pane-weeks" role="tab">
            <i class="bi bi-table me-2"></i>Tygodnie
          </a>
          <a class="list-group-item list-group-item-action py-2 px-3" id="tab-vehicles"
             data-bs-toggle="list" href="#pane-vehicles" role="tab">
            <i class="bi bi-truck me-2"></i>Pojazdy
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Tab content -->
  <div class="flex-grow-1 min-w-0">
    <div class="tab-content">
      <!-- Activity chart tab -->
      <div class="tab-pane fade show active" id="pane-activity" role="tabpanel">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-activity text-primary"></i>
            <span class="tp-card-title">Oś czasu aktywności tachografu</span>
            <span class="badge bg-secondary ms-2"><?= e($activityRangeLabel) ?></span>
            <a href="/drivers.php?action=profile&id=<?= $driverId ?>#pane-activity"
               class="btn btn-sm btn-outline-primary ms-auto">
              <i class="bi bi-arrow-repeat me-1"></i>Odśwież aktywność
            </a>
          </div>
          <div class="tp-card-body">
            <form method="GET" action="/drivers.php#pane-weeks" class="row g-2 align-items-end mb-3">
              <input type="hidden" name="action" value="profile">
              <input type="hidden" name="id" value="<?= (int)$driverId ?>">
              <div class="col-12 col-xl-auto">
                <div class="d-flex flex-wrap flex-xl-nowrap gap-1">
                  <a href="/drivers.php?action=profile&id=<?= (int)$driverId ?>&act_preset=last28#pane-activity"
                     class="btn btn-sm <?= $activityPreset === 'last28' ? 'btn-primary' : 'btn-outline-primary' ?>">Ostatnie 28 dni</a>
                  <a href="/drivers.php?action=profile&id=<?= (int)$driverId ?>&act_preset=month#pane-activity"
                     class="btn btn-sm <?= $activityPreset === 'month' ? 'btn-info' : 'btn-outline-info' ?>">Obecny miesiąc</a>
                  <a href="/drivers.php?action=profile&id=<?= (int)$driverId ?>&act_preset=last3m#pane-activity"
                     class="btn btn-sm <?= $activityPreset === 'last3m' ? 'btn-success' : 'btn-outline-success' ?>">Ostatnie 3 miesiące</a>
                </div>
              </div>
              <input type="hidden" name="act_preset" value="custom">
              <div class="col-6 col-md-4 col-xl-auto">
                <label class="form-label small text-muted mb-1">Od</label>
                <input type="date" name="act_from" class="form-control form-control-sm" value="<?= e($activityFrom ?? '') ?>">
              </div>
              <div class="col-6 col-md-4 col-xl-auto">
                <label class="form-label small text-muted mb-1">Do</label>
                <input type="date" name="act_to" class="form-control form-control-sm" value="<?= e($activityTo ?? '') ?>">
              </div>
              <div class="col-12 col-md-4 col-xl-auto d-grid">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-funnel me-1"></i>Zastosuj własny zakres
                </button>
              </div>
            </form>
            <?php if ($profileChartDays): ?>
            <!-- Summary stats row -->
            <?php
              $pcsD = array_sum(array_column($profileChartDays, 'drive'));
              $pcsW = array_sum(array_column($profileChartDays, 'work'));
              $pcsR = array_sum(array_column($profileChartDays, 'rest'));
              $pcsV = array_sum(array_map(static function ($d) {
                  return count(array_filter($d['viol'], static function ($v) {
                      return ($v['type'] ?? '') === 'error';
                  }));
              }, $profileChartDays));
            ?>
            <div class="row g-2 mb-3">
              <div class="col-6 col-md-3">
                <div class="tp-stat">
                  <div class="tp-stat-icon primary"><i class="bi bi-speedometer2"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= floor($pcsD/60) ?>h <?= $pcsD%60 ?>m</div>
                    <div class="tp-stat-label">Jazda</div>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="tp-stat">
                  <div class="tp-stat-icon warning"><i class="bi bi-briefcase"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= floor($pcsW/60) ?>h <?= $pcsW%60 ?>m</div>
                    <div class="tp-stat-label">Praca</div>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="tp-stat">
                  <div class="tp-stat-icon success"><i class="bi bi-moon"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= floor($pcsR/60) ?>h <?= $pcsR%60 ?>m</div>
                    <div class="tp-stat-label">Odpoczynek</div>
                  </div>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="tp-stat">
                  <div class="tp-stat-icon <?= $pcsV>0?'danger':'success' ?>">
                    <i class="bi bi-<?= $pcsV>0?'exclamation-triangle':'check-circle' ?>"></i>
                  </div>
                  <div>
                    <div class="tp-stat-value"><?= $pcsV ?></div>
                    <div class="tp-stat-label">Naruszenia</div>
                  </div>
                </div>
              </div>
            </div>
            <div id="profileTachoTimeline" style="width:100%;overflow-x:auto;"></div>
            <?php else: ?>
            <div class="tp-empty-state py-4">
              <i class="bi bi-activity"></i>
              <p>Brak danych aktywności dla wybranego zakresu (<?= fmtDate($activityFrom) ?> – <?= fmtDate($activityTo) ?>).<br>
                 <a href="/files.php">Wgraj plik DDD</a>, aby wypełnić oś czasu.</p>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Violations tab -->
      <div class="tab-pane fade" id="pane-violations" role="tabpanel">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-exclamation-octagon text-danger"></i>
            <span class="tp-card-title">Naruszenia i potencjalne kary (UE)</span>
            <span class="badge bg-secondary ms-2"><?= e($activityRangeLabel) ?></span>
          </div>
          <div class="tp-card-body">
            <div class="alert alert-warning py-2 small mb-3">
              Szacunki kar są orientacyjne (na bazie klasyfikacji naruszeń) i mają charakter informacyjny.
            </div>
            <form method="GET" class="row g-2 align-items-end mb-3">
              <input type="hidden" name="action" value="profile">
              <input type="hidden" name="id" value="<?= (int)$driverId ?>">
              <input type="hidden" name="act_preset" value="<?= e($activityPreset) ?>">
              <input type="hidden" name="act_from" value="<?= e($activityFrom ?? '') ?>">
              <input type="hidden" name="act_to" value="<?= e($activityTo ?? '') ?>">
              <div class="col-md-8">
                <label class="form-label small text-muted mb-1">Kraj do symulacji kar</label>
                <select name="viol_country" class="form-select form-select-sm">
                  <?php foreach ($euCountries as $code => $name): ?>
                  <option value="<?= e($code) ?>"<?= $selectedPenaltyCountry === $code ? ' selected' : '' ?>>
                    <?= e($name) ?> (<?= e($code) ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                  <i class="bi bi-globe-europe-africa me-1"></i>Przelicz kary
                </button>
              </div>
            </form>
            <?php if (!empty($profileViolations)): ?>
            <div class="row g-2 mb-3">
              <div class="col-md-3">
                <div class="tp-stat">
                  <div class="tp-stat-icon danger"><i class="bi bi-exclamation-triangle"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= (int)$violationTotals['count'] ?></div>
                    <div class="tp-stat-label">Liczba naruszeń</div>
                  </div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="tp-stat">
                  <div class="tp-stat-icon warning"><i class="bi bi-person-badge"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= number_format((float)$violationTotals['driver'], 0, ',', ' ') ?> PLN</div>
                    <div class="tp-stat-label">Potencjalne kary kierowcy</div>
                  </div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="tp-stat">
                  <div class="tp-stat-icon primary"><i class="bi bi-building"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= number_format((float)$violationTotals['company'], 0, ',', ' ') ?> PLN</div>
                    <div class="tp-stat-label">Potencjalne kary firmy</div>
                  </div>
                </div>
              </div>
              <div class="col-md-3">
                <div class="tp-stat">
                  <div class="tp-stat-icon info"><i class="bi bi-geo-alt"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= e($selectedPenaltyCountry) ?></div>
                    <div class="tp-stat-label"><?= e($selectedPenaltyCountryName) ?></div>
                  </div>
                </div>
              </div>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-md-6">
                <div class="tp-stat">
                  <div class="tp-stat-icon warning"><i class="bi bi-person-badge"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= number_format((float)$violationTotalsSelected['driver'], 0, ',', ' ') ?> PLN</div>
                    <div class="tp-stat-label">Ewentualne kary kierowcy po zmianie kraju</div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="tp-stat">
                  <div class="tp-stat-icon primary"><i class="bi bi-building"></i></div>
                  <div>
                    <div class="tp-stat-value"><?= number_format((float)$violationTotalsSelected['company'], 0, ',', ' ') ?> PLN</div>
                    <div class="tp-stat-label">Ewentualne kary firmy po zmianie kraju</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="table-responsive mb-3">
              <table class="tp-table table-sm">
                <thead>
                  <tr>
                    <th>Kraj UE</th>
                    <th class="text-end">Naruszenia</th>
                    <th class="text-end">Kara kierowcy</th>
                    <th class="text-end">Kara firmy</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($violationByCountry as $row): ?>
                  <tr>
                    <td><?= e($row['name']) ?> <span class="text-muted">(<?= e($row['code']) ?>)</span></td>
                    <td class="text-end"><?= (int)$row['count'] ?></td>
                    <td class="text-end"><?= number_format((float)$row['driver'], 0, ',', ' ') ?> PLN</td>
                    <td class="text-end fw-600"><?= number_format((float)$row['company'], 0, ',', ' ') ?> PLN</td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="table-responsive">
              <table class="tp-table table-sm">
                <thead>
                  <tr>
                    <th>Data</th>
                    <th>Poziom</th>
                    <th>Naruszenie</th>
                    <th>Kraje UE</th>
                    <th class="text-end">Kierowca (pot.)</th>
                    <th class="text-end">Kierowca (po zmianie)</th>
                    <th class="text-end">Firma (pot.)</th>
                    <th class="text-end">Firma (po zmianie)</th>
                    <th>Podstawa</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($profileViolations as $v): ?>
                  <tr>
                    <td class="text-nowrap"><?= fmtDate($v['date']) ?></td>
                    <td>
                      <span class="badge bg-<?= $v['type'] === 'error' ? 'danger' : 'warning' ?>">
                        <?= $v['type'] === 'error' ? 'Błąd' : 'Ostrzeżenie' ?>
                      </span>
                    </td>
                    <td><?= e($v['msg']) ?></td>
                    <td class="small"><?= e(implode(', ', $v['countries'])) ?></td>
                    <td class="text-end"><?= number_format((float)$v['penalty_driver'], 0, ',', ' ') ?> PLN</td>
                    <td class="text-end"><?= number_format((float)$v['penalty_driver_selected'], 0, ',', ' ') ?> PLN</td>
                    <td class="text-end fw-600"><?= number_format((float)$v['penalty_company'], 0, ',', ' ') ?> PLN</td>
                    <td class="text-end fw-600"><?= number_format((float)$v['penalty_company_selected'], 0, ',', ' ') ?> PLN</td>
                    <td class="small text-muted"><?= e($v['article']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <div class="tp-empty-state py-4">
              <i class="bi bi-shield-check" style="font-size:2rem;color:#16a34a"></i>
              <p class="mt-2 text-muted small">Brak wykrytych naruszeń w wybranym zakresie.</p>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Delegation / poświadczenie tab -->
      <div class="tab-pane fade" id="pane-delegation" role="tabpanel">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-file-earmark-text text-primary"></i>
            <span class="tp-card-title">Poświadczenie czynności</span>
            <a href="/modules/delegation/?driver_id=<?= $driverId ?>"
               class="btn btn-sm btn-outline-primary ms-auto" target="_blank">
              <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz
            </a>
          </div>
          <div class="tp-card-body p-0">
            <iframe src="/modules/delegation/?driver_id=<?= $driverId ?>"
                    style="width:100%;height:580px;border:none;border-radius:0 0 8px 8px"
                    title="Poświadczenie czynności"></iframe>
          </div>
        </div>
      </div>

      <!-- Weeks table tab -->
      <div class="tab-pane fade" id="pane-weeks" role="tabpanel">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-table text-primary"></i>
            <span class="tp-card-title">Tygodnie – czas jazdy</span>
            <span class="badge bg-secondary ms-2"><?= e($activityRangeLabel) ?></span>
          </div>
          <div class="tp-card-body">
            <form method="GET" class="row g-2 align-items-end mb-3">
              <input type="hidden" name="action" value="profile">
              <input type="hidden" name="id" value="<?= (int)$driverId ?>">
              <div class="col-12 col-xl-auto">
                <div class="d-flex flex-wrap flex-xl-nowrap gap-1">
                  <a href="/drivers.php?action=profile&id=<?= (int)$driverId ?>&act_preset=last28#pane-weeks"
                     class="btn btn-sm <?= $activityPreset === 'last28' ? 'btn-primary' : 'btn-outline-primary' ?>">Ostatnie 28 dni</a>
                  <a href="/drivers.php?action=profile&id=<?= (int)$driverId ?>&act_preset=month#pane-weeks"
                     class="btn btn-sm <?= $activityPreset === 'month' ? 'btn-info' : 'btn-outline-info' ?>">Obecny miesiąc</a>
                  <a href="/drivers.php?action=profile&id=<?= (int)$driverId ?>&act_preset=last3m#pane-weeks"
                     class="btn btn-sm <?= $activityPreset === 'last3m' ? 'btn-success' : 'btn-outline-success' ?>">Ostatnie 3 miesiące</a>
                </div>
              </div>
              <input type="hidden" name="act_preset" value="custom">
              <div class="col-6 col-md-4 col-xl-auto">
                <label class="form-label small text-muted mb-1">Od</label>
                <input type="date" name="act_from" class="form-control form-control-sm" value="<?= e($activityFrom ?? '') ?>">
              </div>
              <div class="col-6 col-md-4 col-xl-auto">
                <label class="form-label small text-muted mb-1">Do</label>
                <input type="date" name="act_to" class="form-control form-control-sm" value="<?= e($activityTo ?? '') ?>">
              </div>
              <div class="col-12 col-md-4 col-xl-auto d-grid">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="bi bi-funnel me-1"></i>Zastosuj własny zakres
                </button>
              </div>
            </form>
            <?php if ($activityPeriods): ?>
            <div class="table-responsive">
              <table class="tp-table table-sm">
                <thead>
                  <tr>
                    <th>Rok</th>
                    <th>Tydzień</th>
                    <th>Pon</th>
                    <th>Wt</th>
                    <th>Śr</th>
                    <th>Czw</th>
                    <th>Pt</th>
                    <th>Sob</th>
                    <th>Nd</th>
                    <th class="text-end">Suma</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($activityPeriods as $periodIdx => $period):
                    // Compute period start (Mon of first week) and end (Sun of last week)
                    $firstWk  = $period[0];
                    $lastWk   = end($period);
                    $periodStartDt = (new DateTime())->setISODate((int)$firstWk['year'], (int)$firstWk['week'], 1);
                    $periodEndDt   = (new DateTime())->setISODate((int)$lastWk['year'],  (int)$lastWk['week'],  7);
                    $periodStart = $periodStartDt->format('d.m.Y');
                    $periodEnd   = $periodEndDt->format('d.m.Y');
                    $periodTotal = array_sum(array_column($period, 'total'));
                    $periodDays  = ((int)$periodStartDt->diff($periodEndDt)->format('%a')) + 1;
                    $activeDays  = 0;
                    foreach ($period as $pw) {
                        foreach ($pw['days'] as $dm) { if ($dm > 0) $activeDays++; }
                    }
                  ?>
                  <!-- Period header row -->
                  <tr class="table-light">
                    <td colspan="9" class="fw-600 text-primary small py-1">
                      <i class="bi bi-calendar2-week me-1"></i>
                      Okres <?= $periodIdx + 1 ?>: <?= $periodStart ?> – <?= $periodEnd ?>
                      &nbsp;<span class="badge bg-light text-muted border"><?= $periodDays ?> dni okresu</span>
                      &nbsp;<span class="badge bg-light text-muted border"><?= $activeDays ?> dni jazdy</span>
                    </td>
                    <td class="text-end fw-600 small text-primary py-1">
                      <?= floor($periodTotal/60) ?>h <?= $periodTotal%60 ?>m
                    </td>
                  </tr>
                  <?php foreach ($period as $wk): ?>
                  <tr>
                    <td><?= (int)$wk['year'] ?></td>
                    <td><?= (int)$wk['week'] ?></td>
                    <?php for ($wd = 1; $wd <= 7; $wd++): ?>
                    <?php $m = $wk['days'][$wd]; ?>
                    <td class="<?= $m > 0 ? 'text-success' : 'text-muted' ?>">
                      <?php if ($m > 0): echo floor($m/60) . 'h' . ($m%60) . 'm'; else: echo '—'; endif; ?>
                    </td>
                    <?php endfor; ?>
                    <td class="text-end fw-bold">
                      <?= floor($wk['total']/60) ?>h <?= $wk['total']%60 ?>m
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  <!-- Period subtotal row -->
                  <tr class="table-primary">
                    <td colspan="9" class="fw-bold small">
                      Suma okresu <?= $periodIdx + 1 ?> (<?= $activeDays ?> dni)
                    </td>
                    <td class="text-end fw-bold">
                      <?= floor($periodTotal/60) ?>h <?= $periodTotal%60 ?>m
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <div class="tp-empty-state py-4">
              <i class="bi bi-table"></i>
              Brak danych aktywności dla wybranego zakresu (<?= fmtDate($activityFrom) ?> – <?= fmtDate($activityTo) ?>).
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Vehicles tab -->
      <div class="tab-pane fade" id="pane-vehicles" role="tabpanel">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-truck text-primary"></i>
            <span class="tp-card-title">Pojazdy</span>
          </div>
          <div class="tp-card-body">
            <!-- Date filter -->
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3">
              <input type="hidden" name="action" value="profile">
              <input type="hidden" name="id" value="<?= $driverId ?>">
              <div>
                <label class="form-label small mb-1">Od</label>
                <input type="date" name="veh_from" class="form-control form-control-sm"
                       value="<?= e($vehFrom) ?>">
              </div>
              <div>
                <label class="form-label small mb-1">Do</label>
                <input type="date" name="veh_to" class="form-control form-control-sm"
                       value="<?= e($vehTo) ?>">
              </div>
              <button type="submit" class="btn btn-sm btn-primary" onclick="document.getElementById('tab-vehicles').click()">
                <i class="bi bi-funnel me-1"></i>Filtruj
              </button>
              <script>
              // Activate vehicles tab after filter submit
              document.addEventListener('DOMContentLoaded', function() {
                var hash = window.location.hash;
                if (hash) {
                  var genericTab = document.querySelector('#profileTabList a[href="' + hash + '"]');
                  if (genericTab) { genericTab.click(); }
                }
                if (new URLSearchParams(window.location.search).has('viol_country')) {
                  var violTab = document.getElementById('tab-violations');
                  if (violTab) { violTab.click(); }
                }
                if (hash === '#pane-vehicles' || new URLSearchParams(window.location.search).has('veh_from') || new URLSearchParams(window.location.search).has('veh_to')) {
                  var tabEl = document.getElementById('tab-vehicles');
                  if (tabEl) { tabEl.click(); }
                }
              });
              </script>
            </form>
            <?php if ($profileVehicles): ?>
            <div class="table-responsive">
              <table class="tp-table table-sm">
                <thead>
                  <tr>
                    <th>Nr rejestracyjny</th>
                    <th>Kraj</th>
                    <th>Od</th>
                    <th>Do</th>
                    <th class="text-end">Przebieg (pocz.)</th>
                    <th class="text-end">Przebieg (końc.)</th>
                    <th class="text-end">Odległość</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($profileVehicles as $pv): ?>
                  <tr>
                    <td class="fw-bold"><code><?= e($pv['reg']) ?></code></td>
                    <td><?= e($pv['nation'] ?: '—') ?></td>
                    <td><?= fmtDate($pv['date_from']) ?></td>
                    <td><?= fmtDate($pv['date_to']) ?></td>
                    <td class="text-end text-nowrap"><?= $pv['odo_begin'] > 0 ? number_format((int)$pv['odo_begin'], 0, ',', ' ') . ' km' : '—' ?></td>
                    <td class="text-end text-nowrap"><?= $pv['odo_end'] > 0 ? number_format((int)$pv['odo_end'], 0, ',', ' ') . ' km' : '—' ?></td>
                    <td class="text-end"><?= $pv['distance'] > 0 ? number_format((int)$pv['distance'], 0, ',', ' ') . ' km' : '—' ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr class="table-secondary fw-bold">
                    <td colspan="6">Łącznie</td>
                    <td class="text-end"><?= number_format(array_sum(array_column($profileVehicles, 'distance')), 0, ',', ' ') ?> km</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <?php else: ?>
            <div class="tp-empty-state py-4">
              <i class="bi bi-truck" style="font-size:2rem;color:#94a3b8"></i>
              <p class="mt-2 text-muted small">Brak danych o pojazdach w wybranym okresie.</p>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; // profile vs list ?>

<style>.btn-xs{padding:.2rem .5rem;font-size:.8rem;}</style>
<?php if ($action === 'profile'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var days = <?= json_encode($profileChartDays, JSON_UNESCAPED_UNICODE) ?>;

  function renderProfileChart() {
    if (!window.TachoChart) return;
    TachoChart.render('profileTachoTimeline', days);
  }

  // Initial render (activity pane is active on page load)
  if (days.length) {
    renderProfileChart();
  }

  // Re-render whenever the Aktywność tab is shown (fixes blank chart after tab switch)
  var actTab = document.getElementById('tab-activity');
  if (actTab) {
    actTab.addEventListener('shown.bs.tab', function () {
      if (days.length) renderProfileChart();
    });
  }
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/templates/footer.php'; ?>
