<?php
/**
 * TachoPro 2.0 – Change history / Audit log
 * Company-level view (admin sees all, others see own actions).
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
$userId    = (int)$_SESSION['user_id'];

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

// Admins see all company actions; viewers/managers see only their own
$total = countAuditLog($companyId);
$p     = paginate($total, $perPage, $page);
$rows  = getAuditLog($companyId, $p['perPage'], $p['offset']);

$pageTitle  = 'Historia zmian';
$activePage = 'audit';
include __DIR__ . '/templates/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h1 class="tp-page-title">Historia zmian</h1>
    <p class="tp-page-subtitle text-muted">Dziennik wszystkich operacji w systemie</p>
  </div>
</div>

<div class="tp-card">
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead>
          <tr>
            <th>Data i godzina</th>
            <th>Użytkownik</th>
            <th>Akcja</th>
            <th>Obiekt</th>
            <th>Opis</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td class="small text-muted font-monospace">
              <?= htmlspecialchars(substr($row['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td><?= e($row['username'] ?? '—') ?></td>
            <td>
              <span class="badge bg-<?= match($row['action']) {
                'create'  => 'success',
                'update'  => 'info',
                'delete'  => 'danger',
                'login'   => 'primary',
                'logout'  => 'secondary',
                default   => 'warning text-dark',
              } ?>">
                <?= e($row['action']) ?>
              </span>
            </td>
            <td class="small">
              <?= $row['entity_type']
                  ? e($row['entity_type'] . ($row['entity_id'] ? ' #' . $row['entity_id'] : ''))
                  : '—' ?>
            </td>
            <td class="small"><?= e($row['description'] ?? '') ?></td>
            <td class="small text-muted font-monospace"><?= e($row['ip_address'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">
            <i class="bi bi-clock-history fs-3 d-block mb-2 text-muted opacity-50"></i>
            Brak wpisów w historii zmian
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($p['totalPages'] > 1): ?>
  <div class="tp-card-footer d-flex justify-content-center">
    <?= paginationHtml($p, '/audit.php?page=') ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
