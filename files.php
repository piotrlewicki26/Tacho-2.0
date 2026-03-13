<?php
/**
 * TachoPro 2.0 – DDD File Archive
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';

requireLogin();

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// ── Filters ───────────────────────────────────────────────────
$typeFilter   = $_GET['type']      ?? '';
$search       = trim($_GET['q']    ?? '');
$perPage      = max(10, min(100, (int)($_GET['perPage'] ?? 25)));
$page         = max(1, (int)($_GET['page'] ?? 1));

$where  = 'WHERE f.company_id = :cid AND f.is_deleted = 0';
$params = [':cid' => $companyId];
if ($typeFilter === 'driver' || $typeFilter === 'vehicle') {
    $where .= ' AND f.file_type = :type';
    $params[':type'] = $typeFilter;
}
if ($search) {
    $where .= ' AND (f.original_name LIKE :q OR CONCAT(d.first_name,\' \',d.last_name) LIKE :q OR v.registration LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$cntStmt = $db->prepare(
    "SELECT COUNT(*) FROM ddd_files f
     LEFT JOIN drivers d ON d.id = f.driver_id
     LEFT JOIN vehicles v ON v.id = f.vehicle_id
     $where"
);
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$pag   = paginate($total, $perPage, $page);

$params[':limit']  = $pag['perPage'];
$params[':offset'] = $pag['offset'];

$listStmt = $db->prepare(
    "SELECT f.*,
            d.first_name, d.last_name,
            v.registration,
            u.username AS uploader
     FROM ddd_files f
     LEFT JOIN drivers d  ON d.id = f.driver_id
     LEFT JOIN vehicles v ON v.id = f.vehicle_id
     LEFT JOIN users u    ON u.id = f.uploaded_by
     $where
     ORDER BY f.uploaded_at DESC
     LIMIT :limit OFFSET :offset"
);
$listStmt->execute($params);
$files = $listStmt->fetchAll();

$csrf       = getCsrfToken();
$pageTitle  = 'Archiwum plików DDD';
$activePage = 'files';
include __DIR__ . '/templates/header.php';
?>

<!-- ── Toolbar ───────────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <form method="GET" class="d-flex gap-2 flex-wrap flex-grow-1">
    <input type="search" name="q" class="form-control form-control-sm" style="max-width:280px"
           placeholder="Szukaj pliku, kierowcy, pojazdu…" value="<?= e($search) ?>">
    <select name="type" class="form-select form-select-sm" style="width:auto">
      <option value="">Wszystkie typy</option>
      <option value="driver"<?= $typeFilter==='driver'?' selected':'' ?>>Karta kierowcy</option>
      <option value="vehicle"<?= $typeFilter==='vehicle'?' selected':'' ?>>Pojazd</option>
    </select>
    <input type="hidden" name="perPage" value="<?= $perPage ?>">
    <button type="submit" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-search"></i> Filtruj
    </button>
  </form>
  <div class="ms-auto d-flex gap-2">
    <select class="form-select form-select-sm" style="width:auto" data-perpage-select>
      <?php foreach ([10,25,50,100] as $n): ?>
      <option value="<?= $n ?>"<?= $n==$perPage?' selected':'' ?>><?= $n ?> / str.</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#dddUploadModal">
      <i class="bi bi-cloud-upload me-1"></i>Wgraj DDD
    </button>
  </div>
</div>

<!-- ── Files table ───────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-archive text-primary"></i>
    <span class="tp-card-title">Archiwum DDD</span>
    <span class="badge bg-secondary ms-2"><?= $total ?></span>
  </div>
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead>
          <tr>
            <th>Plik</th>
            <th>Typ</th>
            <th>Kierowca / Pojazd</th>
            <th>Nr karty</th>
            <th>Data pobrania</th>
            <th>Rozmiar</th>
            <th>Wgrano przez</th>
            <th>Wgrano o</th>
            <th class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($files as $f): ?>
          <tr>
            <td>
              <i class="bi bi-file-earmark-binary text-muted me-1"></i>
              <?= e($f['original_name']) ?>
            </td>
            <td>
              <?php if ($f['file_type']==='driver'): ?>
                <span class="badge bg-primary">Karta</span>
              <?php else: ?>
                <span class="badge bg-success">Pojazd</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($f['file_type']==='driver'): ?>
                <?= e($f['first_name'] . ' ' . $f['last_name']) ?>
              <?php else: ?>
                <?= e($f['registration'] ?? '—') ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($f['file_type']==='driver' && !empty($f['card_number'])): ?>
                <code class="small"><?= e($f['card_number']) ?></code>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td><?= fmtDate($f['download_date']) ?></td>
            <td><?= $f['file_size'] ? formatBytes((int)$f['file_size']) : '—' ?></td>
            <td><?= e($f['uploader'] ?? '—') ?></td>
            <td>
              <small class="text-muted">
                <?= htmlspecialchars(substr($f['uploaded_at'],0,16), ENT_QUOTES, 'UTF-8') ?>
              </small>
            </td>
            <td class="text-end">
              <a href="/api/files.php?action=download&id=<?= $f['id'] ?>&csrf=<?= urlencode($csrf) ?>"
                 class="btn btn-xs btn-outline-primary me-1" title="Pobierz">
                <i class="bi bi-download"></i>
              </a>
              <?php if (hasRole('manager')): ?>
              <button class="btn btn-xs btn-outline-danger"
                      data-delete-file="<?= $f['id'] ?>"
                      data-csrf="<?= e($csrf) ?>"
                      title="Usuń z archiwum">
                <i class="bi bi-trash"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$files): ?>
          <tr>
            <td colspan="9">
              <div class="tp-empty-state">
                <i class="bi bi-archive"></i>
                Brak plików DDD. Wgraj pierwszy plik klikając "Wgraj DDD".
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
      Wyniki <?= $pag['offset']+1 ?>–<?= min($pag['offset']+$pag['perPage'], $total) ?> z <?= $total ?>
    </small>
    <?= paginationHtml($pag, '?q='.urlencode($search).'&type='.urlencode($typeFilter).'&perPage='.$perPage) ?>
  </div>
  <?php endif; ?>
</div>

<style>.btn-xs{padding:.2rem .5rem;font-size:.8rem;}</style>
<?php include __DIR__ . '/templates/footer.php'; ?>
