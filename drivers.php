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
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
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
if ($action === 'profile' && $editDriver) {
    // Last download date (latest period_end from card_downloads)
    $stmt = $db->prepare(
        'SELECT download_date FROM card_downloads WHERE driver_id=? ORDER BY download_date DESC LIMIT 1'
    );
    $stmt->execute([$driverId]);
    $profileLastDownload = $stmt->fetchColumn() ?: null;

    // Activity timeline data for last 90 days (for TachoChart)
    $chartFrom = (new DateTime('today'))->modify('-90 days')->format('Y-m-d');
    $chartTo   = (new DateTime('today'))->format('Y-m-d');
    try {
        // Auto-backfill calendar from ddd_activity_days when calendar is empty
        backfillDriverActivityCalendar($db, $companyId, $driverId);

        $chartStmt = $db->prepare(
            'SELECT date, drive_min, work_min, avail_min, rest_min, dist_km, violations, segments, border_crossings, source_file_id
             FROM driver_activity_calendar
             WHERE company_id=? AND driver_id=? AND date BETWEEN ? AND ?
             ORDER BY date'
        );
        $chartStmt->execute([$companyId, $driverId, $chartFrom, $chartTo]);
        $chartRows = $chartStmt->fetchAll();

        // Re-parse border_crossings for stale/null rows (same logic as driver_calendar/index.php)
        $needsReparse = [];
        foreach ($chartRows as $cr) {
            $bc = $cr['border_crossings'];
            if ($bc === null || $bc === '[]' || $bc === 'null' || $bc === 'false' || $bc === '0') {
                $fid = (int)($cr['source_file_id'] ?? 0);
                if ($fid) $needsReparse[$fid][$cr['date']] = true;
            }
        }
        $reparsedByFile = [];
        if ($needsReparse) {
            $fileStmt = $db->prepare("SELECT * FROM ddd_files WHERE id=? AND company_id=? AND is_deleted=0");
            foreach (array_keys($needsReparse) as $fid) {
                $fileStmt->execute([$fid, $companyId]);
                $fRow = $fileStmt->fetch();
                if (!$fRow) continue;
                $fp = dddPhysPath($fRow, $companyId);
                if (!is_file($fp)) continue;
                $rawData = file_get_contents($fp);
                if ($rawData === false) continue;
                $reparseDates = array_keys($needsReparse[$fid]);
                $reYears = array_filter(
                    array_map(fn($d) => (int)substr($d, 0, 4), $reparseDates),
                    fn($y) => $y >= 1990
                );
                if ($reYears) {
                    $reYearMin = max(1990, max(min($reYears) - 1, max($reYears) - 2));
                    $reYearMax = max($reYears) + 1;
                } else {
                    $curY = (int)gmdate('Y');
                    $reYearMin = $curY - 5; $reYearMax = $curY + 1;
                }
                $crs = parseBorderCrossings($rawData, $reYearMin, $reYearMax);
                $updDays = $db->prepare('UPDATE ddd_activity_days SET border_crossings=? WHERE file_id=? AND date=?');
                $updCal  = $db->prepare('UPDATE driver_activity_calendar SET border_crossings=? WHERE driver_id=? AND date=?');
                foreach ($reparseDates as $d) {
                    $newJson = !empty($crs) ? json_encode($crs) : json_encode(0);
                    $updDays->execute([$newJson, $fid, $d]);
                    $updCal->execute([$newJson, $driverId, $d]);
                    $reparsedByFile[$fid][$d] = $crs ?: [];
                }
            }
        }

        foreach ($chartRows as $cr) {
            $crossings = json_decode($cr['border_crossings'] ?? '[]', true) ?: [];
            if (is_int($crossings)) $crossings = [];
            $fid = (int)($cr['source_file_id'] ?? 0);
            if (empty($crossings) && $fid && isset($reparsedByFile[$fid][$cr['date']])) {
                $crossings = $reparsedByFile[$fid][$cr['date']];
            }
            $profileChartDays[] = [
                'date'      => $cr['date'],
                'segs'      => json_decode($cr['segments']  ?? '[]', true) ?: [],
                'drive'     => (int)$cr['drive_min'],
                'work'      => (int)$cr['work_min'],
                'avail'     => (int)$cr['avail_min'],
                'rest'      => (int)$cr['rest_min'],
                'dist'      => (int)$cr['dist_km'],
                'viol'      => json_decode($cr['violations'] ?? '[]', true) ?: [],
                'crossings' => $crossings,
            ];
        }
    } catch (Throwable $chartErr) {
        error_log('drivers.php profile chart: ' . $chartErr->getMessage());
    }

    // Weekly driving time table from driver_activity_calendar
    $stmt = $db->prepare(
        'SELECT date, drive_min
         FROM driver_activity_calendar
         WHERE company_id=? AND driver_id=?
         ORDER BY date'
    );
    $stmt->execute([$companyId, $driverId]);
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
        foreach ($vehFiles as $vfRow) {
            $fp = dddPhysPath($vfRow, $companyId);
            if (!is_file($fp)) continue;
            $rawData = file_get_contents($fp);
            if ($rawData === false) continue;
            $recs = parseDriverCardVehicles($rawData);
            foreach ($recs as $r) {
                // Include vehicle if its usage period overlaps the filter window
                if ($r['last_use']  < $vehFrom) continue;
                if ($r['first_use'] > $vehTo)   continue;
                $rawVehicles[] = $r;
            }
        }

        // Merge records from multiple DDD files: prefer most recent last_use
        $profileVehicles = mergeVehicleRecords($rawVehicles);
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
        <div class="col-md-4">
          <label class="form-label fw-600">Karta ważna do</label>
          <input type="date" name="card_valid_until" class="form-control"
                 value="<?= e($editDriver['card_valid_until'] ?? '') ?>">
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
              <a href="/modules/driver_calendar/?driver_id=<?= $d['id'] ?>&tab=timeline"
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
            <span class="badge bg-secondary ms-2">ostatnie 90 dni</span>
            <a href="/modules/driver_calendar/?driver_id=<?= $driverId ?>&tab=timeline"
               class="btn btn-sm btn-outline-primary ms-auto" target="_blank">
              <i class="bi bi-box-arrow-up-right me-1"></i>Pełna analiza
            </a>
          </div>
          <div class="tp-card-body">
            <?php if ($profileChartDays): ?>
            <!-- Summary stats row -->
            <?php
              $pcsD = array_sum(array_column($profileChartDays, 'drive'));
              $pcsW = array_sum(array_column($profileChartDays, 'work'));
              $pcsR = array_sum(array_column($profileChartDays, 'rest'));
              $pcsV = array_sum(array_map(fn($d) => count(array_filter($d['viol'], fn($v)=>($v['type']??'')==='error')), $profileChartDays));
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
              <p>Brak danych aktywności dla ostatnich 90 dni.<br>
                 <a href="/files.php">Wgraj plik DDD</a>, aby wypełnić oś czasu.</p>
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
          </div>
          <div class="tp-card-body p-0">
            <?php if ($profileWeeks): ?>
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
                  <?php foreach ($profileWeeks as $wk): ?>
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
                </tbody>
                <tfoot>
                  <tr class="table-secondary fw-bold">
                    <td colspan="9">Łącznie</td>
                    <td class="text-end"><?= $totalH ?>h <?= $totalM ?>m</td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <?php else: ?>
            <div class="tp-empty-state py-4">
              <i class="bi bi-table"></i>
              Brak danych aktywności dla tego kierowcy.
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
                    <th>Pierwsze użycie</th>
                    <th>Ostatnie użycie</th>
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
                    <td><?= fmtDate($pv['first_use']) ?></td>
                    <td><?= fmtDate($pv['last_use']) ?></td>
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
