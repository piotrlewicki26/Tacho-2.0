<?php
/**
 * TachoPro 2.0 – Invoices list & printable view
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';
require_once __DIR__ . '/includes/subscription.php';

requireLogin();
requireModule('core');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$company   = getCompanyPlan($companyId);

// ── Single invoice print view ─────────────────────────────────
$invoiceId = (int)($_GET['id'] ?? 0);
if ($invoiceId) {
    $stmt = $db->prepare('SELECT * FROM invoices WHERE id=? AND company_id=? LIMIT 1');
    $stmt->execute([$invoiceId, $companyId]);
    $inv = $stmt->fetch();
    if (!$inv) {
        flashSet('danger', 'Faktura nie została znaleziona.');
        redirect('/invoices.php');
    }

    // Print single invoice
    renderInvoicePrint($inv, $company);
    exit;
}

// ── List all invoices ─────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$total = (int)$db->prepare('SELECT COUNT(*) FROM invoices WHERE company_id=?')
               ->execute([$companyId]) ? (function() use ($db, $companyId) {
                   $s = $db->prepare('SELECT COUNT(*) FROM invoices WHERE company_id=?');
                   $s->execute([$companyId]);
                   return (int)$s->fetchColumn();
               })() : 0;

$p    = paginate($total, $perPage, $page);
$stmt = $db->prepare(
    'SELECT * FROM invoices WHERE company_id=?
     ORDER BY issue_date DESC LIMIT ? OFFSET ?'
);
$stmt->execute([$companyId, $p['perPage'], $p['offset']]);
$invoices = $stmt->fetchAll();

$pageTitle  = 'Faktury';
$activePage = 'billing';
include __DIR__ . '/templates/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h1 class="tp-page-title">Faktury</h1>
    <p class="tp-page-subtitle text-muted">Historia faktur za abonament</p>
  </div>
  <a href="/billing.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Abonament
  </a>
</div>

<div class="tp-card">
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead>
          <tr>
            <th>Nr faktury</th>
            <th>Data wystawienia</th>
            <th>Termin płatności</th>
            <th>Okres rozliczeniowy</th>
            <th>Netto</th>
            <th>Brutto</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv): ?>
          <?php
          $statusClass = match($inv['status']) {
              'paid'     => 'success',
              'overdue'  => 'danger',
              'canceled' => 'secondary',
              default    => 'warning',
          };
          $statusLabel = match($inv['status']) {
              'paid'     => 'Opłacona',
              'overdue'  => 'Przeterminowana',
              'canceled' => 'Anulowana',
              default    => 'Wystawiona',
          };
          ?>
          <tr>
            <td><strong><?= e($inv['invoice_number']) ?></strong></td>
            <td><?= fmtDate($inv['issue_date']) ?></td>
            <td class="<?= $inv['status'] === 'overdue' ? 'text-danger fw-600' : '' ?>">
              <?= fmtDate($inv['due_date']) ?>
            </td>
            <td class="small text-muted">
              <?= fmtDate($inv['period_start']) ?> – <?= fmtDate($inv['period_end']) ?>
            </td>
            <td><?= number_format($inv['amount_net'], 2, ',', ' ') ?> zł</td>
            <td><strong><?= number_format($inv['amount_gross'], 2, ',', ' ') ?> zł</strong></td>
            <td><span class="badge bg-<?= $statusClass ?>"><?= $statusLabel ?></span></td>
            <td>
              <a href="/invoices.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-secondary"
                 title="Podgląd / wydruk" target="_blank">
                <i class="bi bi-printer me-1"></i>Drukuj
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$invoices): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">Brak faktur</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($p['totalPages'] > 1): ?>
  <div class="tp-card-footer d-flex justify-content-center">
    <?= paginationHtml($p, '/invoices.php?page=') ?>
  </div>
  <?php endif; ?>
</div>

<style>.btn-xs{padding:.2rem .5rem;font-size:.8rem;}</style>
<?php include __DIR__ . '/templates/footer.php'; ?>
<?php

// ══════════════════════════════════════════════════════════════
// Invoice print rendering
// ══════════════════════════════════════════════════════════════

function renderInvoicePrint(array $inv, array $company): void {
    $isDemoPrint = isDemo($company['id'] ?? 0);
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faktura <?= e($inv['invoice_number']) ?> – TachoPro 2.0</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 14px; color: #1e293b; }
    .invoice-header { border-bottom: 3px solid #1a56db; padding-bottom: 1.5rem; margin-bottom: 1.5rem; }
    .invoice-table th { background: #f8fafc; }
    .invoice-total { background: #eff6ff; font-size: 1.1rem; }
    .demo-watermark {
      position: fixed; top: 50%; left: 50%;
      transform: translate(-50%,-50%) rotate(-35deg);
      font-size: 6rem; font-weight: 900; color: rgba(239,68,68,.12);
      pointer-events: none; z-index: 9999; white-space: nowrap;
    }
    @media print {
      .no-print { display: none !important; }
      body { font-size: 12px; }
    }
  </style>
</head>
<body class="p-4">
  <?php if ($isDemoPrint): ?>
  <div class="demo-watermark">DEMO</div>
  <?php endif; ?>

  <?php if ($isDemoPrint): ?>
  <div class="alert alert-warning no-print">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <strong>Wersja demonstracyjna</strong> – ten wydruk zawiera znak wodny DEMO.
    <a href="/billing.php#upgrade-section" class="alert-link ms-2">Upgrade do Pro &rarr;</a>
  </div>
  <?php endif; ?>

  <!-- Header -->
  <div class="invoice-header d-flex justify-content-between align-items-start">
    <div>
      <h2 class="fw-bold text-primary mb-1">FAKTURA VAT</h2>
      <h4 class="mb-0"><?= e($inv['invoice_number']) ?></h4>
      <small class="text-muted">
        Data wystawienia: <?= fmtDate($inv['issue_date']) ?> &nbsp;|&nbsp;
        Termin płatności: <?= fmtDate($inv['due_date']) ?>
      </small>
    </div>
    <div class="text-end">
      <div class="fw-bold fs-5">TachoPro 2.0</div>
      <div class="text-muted small">System zarządzania czasem pracy kierowców</div>
      <div class="mt-1">
        <span class="badge bg-<?= $inv['status'] === 'paid' ? 'success' : ($inv['status'] === 'overdue' ? 'danger' : 'warning text-dark') ?>">
          <?= $inv['status'] === 'paid' ? 'OPŁACONA' : ($inv['status'] === 'overdue' ? 'PRZETERMINOWANA' : 'WYSTAWIONA') ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Parties -->
  <div class="row mb-4">
    <div class="col-6">
      <h6 class="text-muted text-uppercase fw-600 small mb-2">Sprzedawca</h6>
      <div class="fw-bold">TachoPro Sp. z o.o.</div>
      <div class="text-muted small">
        ul. Przykładowa 1<br>
        00-000 Warszawa<br>
        NIP: 0000000000
      </div>
    </div>
    <div class="col-6">
      <h6 class="text-muted text-uppercase fw-600 small mb-2">Nabywca</h6>
      <div class="fw-bold"><?= e($inv['buyer_name']) ?></div>
      <?php if ($inv['buyer_address']): ?>
      <div class="text-muted small"><?= nl2br(e($inv['buyer_address'])) ?></div>
      <?php endif; ?>
      <?php if ($inv['buyer_nip']): ?>
      <div class="text-muted small">NIP: <?= e($inv['buyer_nip']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Period -->
  <p class="text-muted small mb-3">
    <i class="bi bi-calendar me-1"></i>
    Okres rozliczeniowy: <strong><?= fmtDate($inv['period_start']) ?> – <?= fmtDate($inv['period_end']) ?></strong>
  </p>

  <!-- Line items -->
  <table class="table invoice-table border mb-4">
    <thead>
      <tr>
        <th class="text-start">Lp.</th>
        <th class="text-start">Opis</th>
        <th class="text-end">Ilość</th>
        <th class="text-end">Cena jedn. netto</th>
        <th class="text-end">VAT</th>
        <th class="text-end">Wartość netto</th>
        <th class="text-end">Wartość brutto</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($inv['active_drivers'] > 0): ?>
      <?php
      $dNet = $inv['active_drivers'] * BILLING_PRICE_DRIVER;
      $dGross = round($dNet * (1 + $inv['vat_rate'] / 100), 2);
      ?>
      <tr>
        <td>1</td>
        <td>Obsługa kierowcy – miesięczny abonament</td>
        <td class="text-end"><?= $inv['active_drivers'] ?> os.</td>
        <td class="text-end"><?= number_format(BILLING_PRICE_DRIVER, 2, ',', ' ') ?> zł</td>
        <td class="text-end"><?= number_format($inv['vat_rate'], 0) ?>%</td>
        <td class="text-end"><?= number_format($dNet, 2, ',', ' ') ?> zł</td>
        <td class="text-end"><?= number_format($dGross, 2, ',', ' ') ?> zł</td>
      </tr>
      <?php endif; ?>
      <?php if ($inv['active_vehicles'] > 0): ?>
      <?php
      $vNet = $inv['active_vehicles'] * BILLING_PRICE_VEHICLE;
      $vGross = round($vNet * (1 + $inv['vat_rate'] / 100), 2);
      ?>
      <tr>
        <td><?= $inv['active_drivers'] > 0 ? 2 : 1 ?></td>
        <td>Obsługa pojazdu – miesięczny abonament</td>
        <td class="text-end"><?= $inv['active_vehicles'] ?> szt.</td>
        <td class="text-end"><?= number_format(BILLING_PRICE_VEHICLE, 2, ',', ' ') ?> zł</td>
        <td class="text-end"><?= number_format($inv['vat_rate'], 0) ?>%</td>
        <td class="text-end"><?= number_format($vNet, 2, ',', ' ') ?> zł</td>
        <td class="text-end"><?= number_format($vGross, 2, ',', ' ') ?> zł</td>
      </tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="invoice-total">
        <td colspan="5" class="text-end fw-bold">Razem netto:</td>
        <td class="text-end fw-bold"><?= number_format($inv['amount_net'], 2, ',', ' ') ?> zł</td>
        <td></td>
      </tr>
      <tr>
        <td colspan="5" class="text-end text-muted">VAT <?= number_format($inv['vat_rate'], 0) ?>%:</td>
        <td class="text-end text-muted"><?= number_format($inv['amount_vat'], 2, ',', ' ') ?> zł</td>
        <td></td>
      </tr>
      <tr class="invoice-total fw-bold">
        <td colspan="5" class="text-end fs-6">DO ZAPŁATY:</td>
        <td></td>
        <td class="text-end fs-5 text-primary"><?= number_format($inv['amount_gross'], 2, ',', ' ') ?> zł</td>
      </tr>
    </tfoot>
  </table>

  <!-- Footer -->
  <div class="row small text-muted">
    <div class="col-6">
      <strong>Forma płatności:</strong> Przelew / Stripe<br>
      <?php if ($inv['paid_at']): ?>
      <strong class="text-success">Opłacono: <?= fmtDate(substr($inv['paid_at'], 0, 10)) ?></strong>
      <?php else: ?>
      <strong>Termin płatności:</strong> <?= fmtDate($inv['due_date']) ?>
      <?php endif; ?>
    </div>
    <div class="col-6 text-end">
      Wygenerowano przez TachoPro 2.0<br>
      <?= date('d.m.Y H:i') ?>
      <?php if ($isDemoPrint): ?><br><span class="text-danger fw-600">WERSJA DEMONSTRACYJNA</span><?php endif; ?>
    </div>
  </div>

  <div class="text-center no-print mt-4">
    <button onclick="window.print()" class="btn btn-primary me-2">
      <i class="bi bi-printer me-1"></i>Drukuj
    </button>
    <a href="/invoices.php" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Wróć
    </a>
  </div>

</body>
</html>
    <?php
}
