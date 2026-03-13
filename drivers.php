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

// ── Load driver for edit ─────────────────────────────────────
$editDriver = null;
if (($action === 'edit' || $action === 'view') && $driverId) {
    $stmt = $db->prepare('SELECT * FROM drivers WHERE id=? AND company_id=?');
    $stmt->execute([$driverId, $companyId]);
    $editDriver = $stmt->fetch();
    if (!$editDriver) { flashSet('danger', 'Nie znaleziono kierowcy.'); redirect('/drivers.php'); }
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
              <strong><?= e($d['last_name'] . ' ' . $d['first_name']) ?></strong>
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
              <a href="/modules/driver_analysis/?driver_id=<?= $d['id'] ?>"
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

<style>.btn-xs{padding:.2rem .5rem;font-size:.8rem;}</style>
<?php include __DIR__ . '/templates/footer.php'; ?>
