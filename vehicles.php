<?php
/**
 * TachoPro 2.0 – Vehicles management
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
$vehicleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        flashSet('danger', 'Nieprawidłowy token CSRF.');
        redirect('/vehicles.php');
    }
    if (!hasRole('manager')) {
        flashSet('danger', 'Brak uprawnień.');
        redirect('/vehicles.php');
    }

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'delete') {
        $id = (int)($_POST['vehicle_id'] ?? 0);
        $delStmt = $db->prepare('SELECT registration FROM vehicles WHERE id=? AND company_id=?');
        $delStmt->execute([$id, $companyId]);
        $delVeh = $delStmt->fetch();
        $db->prepare('UPDATE vehicles SET is_active=0 WHERE id=? AND company_id=?')
           ->execute([$id, $companyId]);
        auditLog('delete', 'vehicle', $id, 'Dezaktywowano pojazd: ' . ($delVeh ? $delVeh['registration'] : "ID $id"));
        flashSet('success', 'Pojazd został dezaktywowany.');
        redirect('/vehicles.php');
    }

    $reg   = trim($_POST['registration']          ?? '');
    $make  = trim($_POST['make']                  ?? '');
    $model = trim($_POST['model']                 ?? '');
    $vin   = trim($_POST['vin']                   ?? '');
    $year  = (int)($_POST['year']                 ?? 0) ?: null;
    $ttype = trim($_POST['tachograph_type']        ?? '');
    $calL  = $_POST['last_calibration_date']       ?? '';
    $calN  = $_POST['next_calibration_date']       ?? '';

    if (!$reg) {
        flashSet('danger', 'Numer rejestracyjny jest wymagany.');
        redirect('/vehicles.php?action=' . $postAction . ($vehicleId ? '&id='.$vehicleId : ''));
    }

    $fields = [
        'registration'          => strtoupper($reg),
        'make'                  => $make ?: null,
        'model'                 => $model ?: null,
        'vin'                   => $vin ?: null,
        'year'                  => $year,
        'tachograph_type'       => $ttype ?: null,
        'last_calibration_date' => $calL ?: null,
        'next_calibration_date' => $calN ?: null,
    ];

    if ($postAction === 'add') {
        $fields['company_id'] = $companyId;
        $cols = implode(', ', array_keys($fields));
        $phs  = implode(', ', array_fill(0, count($fields), '?'));
        $db->prepare("INSERT INTO vehicles ($cols) VALUES ($phs)")->execute(array_values($fields));
        $newId = (int)$db->lastInsertId();
        auditLog('create', 'vehicle', $newId, "Dodano pojazd: " . strtoupper($reg), null, $fields);
        flashSet('success', 'Pojazd został dodany.');
    } elseif ($postAction === 'edit') {
        $id   = (int)($_POST['vehicle_id'] ?? 0);
        $oldStmt = $db->prepare('SELECT * FROM vehicles WHERE id=? AND company_id=?');
        $oldStmt->execute([$id, $companyId]);
        $oldVeh = $oldStmt->fetch() ?: [];
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $vals = array_values($fields);
        $vals[] = $id; $vals[] = $companyId;
        $db->prepare("UPDATE vehicles SET $sets WHERE id = ? AND company_id = ?")->execute($vals);
        auditLog('update', 'vehicle', $id, "Zaktualizowano pojazd: " . strtoupper($reg), $oldVeh, $fields);
        flashSet('success', 'Dane pojazdu zaktualizowane.');
    }
    redirect('/vehicles.php');
}

// ── Load vehicle for edit ─────────────────────────────────────
$editVehicle = null;
if (($action === 'edit') && $vehicleId) {
    $stmt = $db->prepare('SELECT * FROM vehicles WHERE id=? AND company_id=?');
    $stmt->execute([$vehicleId, $companyId]);
    $editVehicle = $stmt->fetch();
    if (!$editVehicle) { flashSet('danger', 'Nie znaleziono pojazdu.'); redirect('/vehicles.php'); }
}

// ── Pagination & list ─────────────────────────────────────────
$search  = trim($_GET['q']       ?? '');
$perPage = max(10, min(100, (int)($_GET['perPage'] ?? 25)));
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = 'WHERE v.company_id = :cid AND v.is_active = 1';
$params = [':cid' => $companyId];
if ($search) {
    $where .= ' AND (v.registration LIKE :q OR v.make LIKE :q OR v.model LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$cntStmt = $db->prepare("SELECT COUNT(*) FROM vehicles v $where");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$pag   = paginate($total, $perPage, $page);

$params[':limit']  = $pag['perPage'];
$params[':offset'] = $pag['offset'];

$listStmt = $db->prepare(
    "SELECT v.*,
            (SELECT download_date FROM vehicle_downloads WHERE vehicle_id=v.id ORDER BY download_date DESC LIMIT 1) AS last_download
     FROM vehicles v
     $where
     ORDER BY v.registration
     LIMIT :limit OFFSET :offset"
);
$listStmt->execute($params);
$vehicles = $listStmt->fetchAll();

$pageTitle  = 'Pojazdy';
$activePage = 'vehicles';
include __DIR__ . '/templates/header.php';
?>

<!-- ── Toolbar ───────────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <form method="GET" class="d-flex gap-2 flex-grow-1" style="max-width:360px">
    <input type="hidden" name="perPage" value="<?= $perPage ?>">
    <input type="search" name="q" class="form-control form-control-sm"
           placeholder="Szukaj pojazdu…" value="<?= e($search) ?>">
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
  </form>
  <div class="ms-auto d-flex gap-2">
    <select class="form-select form-select-sm" style="width:auto" data-perpage-select>
      <?php foreach ([10,25,50,100] as $n): ?>
      <option value="<?= $n ?>"<?= $n==$perPage?' selected':'' ?>><?= $n ?> / str.</option>
      <?php endforeach; ?>
    </select>
    <?php if (hasRole('manager')): ?>
    <a href="/vehicles.php?action=add" class="btn btn-sm btn-success">
      <i class="bi bi-plus-circle me-1"></i>Dodaj pojazd
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
<div class="tp-card mb-4">
  <div class="tp-card-header">
    <i class="bi bi-truck text-success"></i>
    <span class="tp-card-title"><?= $action==='add'?'Dodaj pojazd':'Edytuj pojazd' ?></span>
    <a href="/vehicles.php" class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="bi bi-x"></i> Anuluj
    </a>
  </div>
  <div class="tp-card-body">
    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
      <input type="hidden" name="action"     value="<?= $action ?>">
      <?php if ($action==='edit'): ?>
      <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
      <?php endif; ?>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label fw-600">Rejestracja <span class="text-danger">*</span></label>
          <input type="text" name="registration" class="form-control" required maxlength="50" style="text-transform:uppercase"
                 value="<?= e($editVehicle['registration'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-600">Marka</label>
          <input type="text" name="make" class="form-control" maxlength="100"
                 value="<?= e($editVehicle['make'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-600">Model</label>
          <input type="text" name="model" class="form-control" maxlength="100"
                 value="<?= e($editVehicle['model'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-600">Rok prod.</label>
          <input type="number" name="year" class="form-control" min="1990" max="2155"
                 value="<?= e($editVehicle['year'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">VIN</label>
          <input type="text" name="vin" class="form-control" maxlength="50"
                 value="<?= e($editVehicle['vin'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Typ tachografu</label>
          <select name="tachograph_type" class="form-select">
            <option value="">— Wybierz —</option>
            <option value="analog"<?= ($editVehicle['tachograph_type']??'')==='analog'?' selected':'' ?>>Analogowy</option>
            <option value="digital"<?= ($editVehicle['tachograph_type']??'')==='digital'?' selected':'' ?>>Cyfrowy (G1)</option>
            <option value="smart"<?= ($editVehicle['tachograph_type']??'')==='smart'?' selected':'' ?>>Smart (G2)</option>
          </select>
        </div>
        <div class="col-md-4"></div>
        <div class="col-md-4">
          <label class="form-label fw-600">Ostatnia legalizacja</label>
          <input type="date" name="last_calibration_date" class="form-control"
                 value="<?= e($editVehicle['last_calibration_date'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-600">Następna legalizacja</label>
          <input type="date" name="next_calibration_date" class="form-control"
                 value="<?= e($editVehicle['next_calibration_date'] ?? '') ?>">
        </div>
      </div>
      <div class="mt-3">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-check2 me-1"></i><?= $action==='add'?'Dodaj':'Zapisz' ?>
        </button>
        <a href="/vehicles.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Vehicles table ─────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-truck text-success"></i>
    <span class="tp-card-title">Lista pojazdów</span>
    <span class="badge bg-secondary ms-2"><?= $total ?></span>
  </div>
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead>
          <tr>
            <th>Rejestracja</th>
            <th>Marka / Model</th>
            <th>Typ tacho</th>
            <th>Ostatnie pobranie</th>
            <th>Legalizacja do</th>
            <th>Status legalizacji</th>
            <th class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vehicles as $v): ?>
          <?php $calSt = dateStatus($v['next_calibration_date'], 60); ?>
          <tr>
            <td><strong><?= e($v['registration']) ?></strong></td>
            <td><?= e($v['make'] . ' ' . $v['model']) ?></td>
            <td><?= e(ucfirst($v['tachograph_type'] ?? '—')) ?></td>
            <td><?= fmtDate($v['last_download']) ?></td>
            <td><?= fmtDate($v['next_calibration_date']) ?></td>
            <td><span class="badge bg-<?= e($calSt['class']) ?>"><?= e($calSt['label']) ?></span></td>
            <td class="text-end">
              <a href="/vehicles.php?action=edit&id=<?= $v['id'] ?>"
                 class="btn btn-xs btn-outline-primary me-1" title="Edytuj">
                <i class="bi bi-pencil"></i>
              </a>
              <a href="/modules/vehicle_analysis/?vehicle_id=<?= $v['id'] ?>"
                 class="btn btn-xs btn-outline-info me-1" title="Analiza">
                <i class="bi bi-bar-chart-line"></i>
              </a>
              <?php if (hasRole('admin')): ?>
              <form method="POST" class="d-inline"
                    onsubmit="return confirm('Dezaktywować pojazd?')">
                <input type="hidden" name="csrf_token"  value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action"      value="delete">
                <input type="hidden" name="vehicle_id"  value="<?= $v['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger" title="Usuń">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$vehicles): ?>
          <tr>
            <td colspan="7">
              <div class="tp-empty-state">
                <i class="bi bi-truck"></i>
                Brak pojazdów. <a href="/vehicles.php?action=add">Dodaj pierwszy pojazd</a>.
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
    <?= paginationHtml($pag, '?q='.urlencode($search).'&perPage='.$perPage) ?>
  </div>
  <?php endif; ?>
</div>

<style>.btn-xs{padding:.2rem .5rem;font-size:.8rem;}</style>
<?php include __DIR__ . '/templates/footer.php'; ?>
