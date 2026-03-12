<?php
/**
 * License Issuer – Dashboard
 * Lists all companies with their active licenses and usage counters.
 */
liRequireLogin();
$db = liGetDB();

// Load all companies with license summary and usage counts
$companies = $db->query(
    'SELECT c.id,
            c.name,
            c.nip,
            c.email,
            c.unique_code,
            c.created_at,
            (SELECT COUNT(*) FROM users    u WHERE u.company_id=c.id AND u.is_active=1 AND u.role != "superadmin") AS cnt_users,
            (SELECT COUNT(*) FROM vehicles v WHERE v.company_id=c.id AND v.is_active=1)  AS cnt_vehicles,
            (SELECT COUNT(*) FROM drivers  d WHERE d.company_id=c.id AND d.is_active=1)  AS cnt_drivers,
            (SELECT l.id FROM licenses l WHERE l.company_id=c.id AND l.valid_until >= CURDATE() ORDER BY l.valid_until DESC LIMIT 1) AS active_lic_id,
            (SELECT l.valid_until FROM licenses l WHERE l.company_id=c.id AND l.valid_until >= CURDATE() ORDER BY l.valid_until DESC LIMIT 1) AS valid_until,
            (SELECT l.version    FROM licenses l WHERE l.company_id=c.id AND l.valid_until >= CURDATE() ORDER BY l.valid_until DESC LIMIT 1) AS lic_version,
            (SELECT l.max_users  FROM licenses l WHERE l.company_id=c.id AND l.valid_until >= CURDATE() ORDER BY l.valid_until DESC LIMIT 1) AS max_users,
            (SELECT l.max_vehicles FROM licenses l WHERE l.company_id=c.id AND l.valid_until >= CURDATE() ORDER BY l.valid_until DESC LIMIT 1) AS max_vehicles,
            (SELECT l.max_drivers  FROM licenses l WHERE l.company_id=c.id AND l.valid_until >= CURDATE() ORDER BY l.valid_until DESC LIMIT 1) AS max_drivers
     FROM companies c
     ORDER BY c.name'
)->fetchAll();

$pageTitle = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-grid me-2 text-primary"></i>Dashboard</h1>
  <a href="index.php?page=issue" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Wystaw licencję
  </a>
</div>

<!-- Stats bar -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="li-card mb-0 text-center py-3">
      <div class="h2 fw-bold text-primary mb-1"><?= count($companies) ?></div>
      <div class="text-muted small">Firm łącznie</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="li-card mb-0 text-center py-3">
      <?php $active = array_filter($companies, fn($c) => !empty($c['active_lic_id'])); ?>
      <div class="h2 fw-bold text-success mb-1"><?= count($active) ?></div>
      <div class="text-muted small">Aktywnych licencji</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="li-card mb-0 text-center py-3">
      <div class="h2 fw-bold text-danger mb-1"><?= count($companies) - count($active) ?></div>
      <div class="text-muted small">Bez aktywnej licencji</div>
    </div>
  </div>
</div>

<!-- Companies table -->
<div class="li-card">
  <div class="li-card-header">
    <i class="bi bi-building text-primary"></i>
    Firmy i licencje
  </div>
  <div class="table-responsive">
    <table class="li-table">
      <thead>
        <tr>
          <th>Firma</th>
          <th>NIP</th>
          <th>Użytkownicy</th>
          <th>Pojazdy</th>
          <th>Kierowcy</th>
          <th>Wersja</th>
          <th>Ważna do</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $c):
            $hasLic  = !empty($c['active_lic_id']);
            $maxU    = (int)($c['max_users']    ?? 0);
            $maxV    = (int)($c['max_vehicles'] ?? 0);
            $maxD    = (int)($c['max_drivers']  ?? 0);
            $cntU    = (int)$c['cnt_users'];
            $cntV    = (int)$c['cnt_vehicles'];
            $cntD    = (int)$c['cnt_drivers'];
            $overU   = $maxU > 0 && $cntU > $maxU;
            $overV   = $maxV > 0 && $cntV > $maxV;
            $overD   = $maxD > 0 && $cntD > $maxD;
        ?>
        <tr>
          <td>
            <div class="fw-600"><?= liE($c['name']) ?></div>
            <small class="text-muted font-monospace" title="Kod unikalny"><?= liE(substr($c['unique_code'], 0, 16)) ?>…</small>
          </td>
          <td><?= liE($c['nip'] ?? '—') ?></td>
          <td>
            <span class="li-limit-badge <?= $overU ? 'text-danger border-danger bg-red-50' : '' ?>">
              <i class="bi bi-people"></i>
              <?= $cntU ?><?= $maxU > 0 ? '/' . $maxU : '' ?>
            </span>
          </td>
          <td>
            <span class="li-limit-badge <?= $overV ? 'text-danger border-danger' : '' ?>">
              <i class="bi bi-truck"></i>
              <?= $cntV ?><?= $maxV > 0 ? '/' . $maxV : '' ?>
            </span>
          </td>
          <td>
            <span class="li-limit-badge <?= $overD ? 'text-danger border-danger' : '' ?>">
              <i class="bi bi-person-badge"></i>
              <?= $cntD ?><?= $maxD > 0 ? '/' . $maxD : '' ?>
            </span>
          </td>
          <td><?= $hasLic ? liE($c['lic_version']) : '—' ?></td>
          <td><?= $hasLic ? fmtDate($c['valid_until']) : '—' ?></td>
          <td>
            <?php if ($hasLic): ?>
              <span class="badge bg-success">Aktywna</span>
            <?php else: ?>
              <span class="badge bg-danger">Brak licencji</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="index.php?page=issue&company_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Wystaw licencję">
              <i class="bi bi-plus-circle"></i>
            </a>
            <a href="index.php?page=view&company_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Historia licencji">
              <i class="bi bi-clock-history"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$companies): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">Brak firm w bazie danych</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
