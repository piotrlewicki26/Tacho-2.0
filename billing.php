<?php
/**
 * TachoPro 2.0 – Billing & subscription management
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';
require_once __DIR__ . '/includes/subscription.php';
require_once __DIR__ . '/includes/audit.php';

requireLogin();
requireModule('core');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$company   = getCompanyPlan($companyId);
$billing   = calculateMonthlyBilling($companyId);

// Current invoices
$invStmt = $db->prepare(
    'SELECT * FROM invoices WHERE company_id=? ORDER BY issue_date DESC LIMIT 12'
);
$invStmt->execute([$companyId]);
$invoices = $invStmt->fetchAll();

// Active subscription
$subStmt = $db->prepare(
    'SELECT * FROM subscriptions WHERE company_id=? AND status IN ("active","trialing")
     ORDER BY billing_period_end DESC LIMIT 1'
);
$subStmt->execute([$companyId]);
$subscription = $subStmt->fetch() ?: null;

$pageTitle  = 'Abonament i płatności';
$activePage = 'billing';
include __DIR__ . '/templates/header.php';
?>

<!-- ── Demo banner ───────────────────────────────────────────── -->
<?php if (isDemo($companyId)): ?>
<?php $daysLeft = trialDaysRemaining($companyId); ?>
<div class="alert alert-<?= isTrialExpired($companyId) ? 'danger' : 'warning' ?> d-flex align-items-start gap-3 mb-4">
  <i class="bi bi-<?= isTrialExpired($companyId) ? 'x-circle' : 'clock' ?> fs-4 flex-shrink-0 mt-1"></i>
  <div>
    <?php if (isTrialExpired($companyId)): ?>
      <strong>Okres próbny wygasł.</strong>
      Przejdź na plan Pro, aby odblokować pełne funkcje i usunąć limity.
    <?php else: ?>
      <strong>Tryb demo – pozostało <?= $daysLeft ?> <?= $daysLeft === 1 ? 'dzień' : 'dni' ?>.</strong>
      Masz dostęp do maks. <?= DEMO_MAX_DRIVERS ?> kierowców i <?= DEMO_MAX_VEHICLES ?> pojazdów.
    <?php endif; ?>
    &nbsp;<a href="#upgrade-section" class="alert-link fw-bold">Upgrade do Pro &rarr;</a>
  </div>
</div>
<?php endif; ?>

<!-- ── Current plan card ─────────────────────────────────────── -->
<div class="row g-4 mb-4">
  <div class="col-md-4">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-shield-check text-primary"></i>
        <span class="tp-card-title">Aktualny plan</span>
      </div>
      <div class="tp-card-body">
        <div class="mb-3">
          <?php if ($company['plan'] === 'pro'): ?>
          <span class="badge bg-success fs-6 px-3 py-2">
            <i class="bi bi-crown me-1"></i>Pro
          </span>
          <?php else: ?>
          <span class="badge bg-warning text-dark fs-6 px-3 py-2">
            <i class="bi bi-hourglass-split me-1"></i>Demo
            <?php if (!isTrialExpired($companyId)): ?>
            – <?= trialDaysRemaining($companyId) ?> dni pozostało
            <?php else: ?>
            – wygasł
            <?php endif; ?>
          </span>
          <?php endif; ?>
        </div>
        <?php if ($company['plan'] === 'demo' && $company['trial_ends_at']): ?>
        <div class="small text-muted">
          <i class="bi bi-calendar me-1"></i>
          Koniec okresu próbnego: <strong><?= fmtDate($company['trial_ends_at']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($company['plan'] === 'demo'): ?>
        <hr>
        <div class="small">
          <div class="d-flex justify-content-between">
            <span>Limit kierowców</span>
            <span class="fw-600"><?= DEMO_MAX_DRIVERS ?></span>
          </div>
          <div class="d-flex justify-content-between">
            <span>Limit pojazdów</span>
            <span class="fw-600"><?= DEMO_MAX_VEHICLES ?></span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Monthly estimate -->
  <div class="col-md-4">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-calculator text-success"></i>
        <span class="tp-card-title">Szacunek miesięczny</span>
      </div>
      <div class="tp-card-body">
        <table class="tp-table">
          <tbody>
            <tr>
              <td><?= $billing['drivers'] ?> kierowców × <?= number_format(BILLING_PRICE_DRIVER, 2, ',', ' ') ?> zł</td>
              <td class="text-end fw-600"><?= number_format($billing['drivers'] * BILLING_PRICE_DRIVER, 2, ',', ' ') ?> zł</td>
            </tr>
            <tr>
              <td><?= $billing['vehicles'] ?> pojazdów × <?= number_format(BILLING_PRICE_VEHICLE, 2, ',', ' ') ?> zł</td>
              <td class="text-end fw-600"><?= number_format($billing['vehicles'] * BILLING_PRICE_VEHICLE, 2, ',', ' ') ?> zł</td>
            </tr>
            <tr class="table-light">
              <td><strong>Netto</strong></td>
              <td class="text-end fw-bold"><?= number_format($billing['amount_net'], 2, ',', ' ') ?> zł</td>
            </tr>
            <tr>
              <td class="text-muted small">VAT 23%</td>
              <td class="text-end text-muted small"><?= number_format($billing['amount_vat'], 2, ',', ' ') ?> zł</td>
            </tr>
            <tr class="table-success">
              <td><strong>Brutto</strong></td>
              <td class="text-end fw-bold text-success fs-5"><?= number_format($billing['amount_gross'], 2, ',', ' ') ?> zł</td>
            </tr>
          </tbody>
        </table>
        <div class="small text-muted mt-2">
          <i class="bi bi-info-circle me-1"></i>
          Naliczenie na koniec każdego miesiąca na podstawie aktywnych kierowców i pojazdów.
        </div>
      </div>
    </div>
  </div>

  <!-- Pricing info -->
  <div class="col-md-4">
    <div class="tp-card h-100">
      <div class="tp-card-header">
        <i class="bi bi-tags text-info"></i>
        <span class="tp-card-title">Cennik Pro</span>
      </div>
      <div class="tp-card-body">
        <div class="d-flex align-items-center gap-3 mb-3 p-3 rounded" style="background:#dbeafe">
          <i class="bi bi-person-badge fs-2 text-primary"></i>
          <div>
            <div class="fw-bold fs-5"><?= number_format(BILLING_PRICE_DRIVER, 2, ',', ' ') ?> zł netto</div>
            <div class="small text-muted">za kierowcę / miesiąc</div>
          </div>
        </div>
        <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:#d1fae5">
          <i class="bi bi-truck fs-2 text-success"></i>
          <div>
            <div class="fw-bold fs-5"><?= number_format(BILLING_PRICE_VEHICLE, 2, ',', ' ') ?> zł netto</div>
            <div class="small text-muted">za pojazd / miesiąc</div>
          </div>
        </div>
        <ul class="list-unstyled small mt-3 mb-0">
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Brak limitu kierowców i pojazdów</li>
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Pełne archiwum DDD</li>
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Wszystkie moduły analiz</li>
          <li class="mb-1"><i class="bi bi-check-circle text-success me-2"></i>Faktury VAT co miesiąc</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- ── Upgrade section ───────────────────────────────────────── -->
<?php if (isDemo($companyId)): ?>
<div class="tp-card mb-4" id="upgrade-section">
  <div class="tp-card-header" style="background:linear-gradient(135deg,#1a56db,#9333ea);color:#fff;border-radius:inherit inherit 0 0">
    <i class="bi bi-crown fs-5"></i>
    <span class="tp-card-title" style="color:#fff">Upgrade do planu Pro</span>
  </div>
  <div class="tp-card-body">
    <div class="row align-items-center">
      <div class="col-md-7">
        <p class="mb-2">Płać tylko za to, czego używasz – <strong>bez stałych opłat, bez kontraktu</strong>.</p>
        <p class="text-muted small mb-0">
          Aktywacja planu Pro spowoduje naliczenie pierwszej faktury za bieżący miesiąc
          na podstawie liczby aktywnych kierowców i pojazdów.
          Płatność obsługuje <strong>Stripe</strong> (karta, BLIK, przelew).
        </p>
      </div>
      <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <?php
        $stripeKey = defined('CFG_STRIPE_PUBLISHABLE_KEY') ? CFG_STRIPE_PUBLISHABLE_KEY : '';
        $stripeConfigured = !empty($stripeKey) && str_starts_with($stripeKey, 'pk_');
        ?>
        <?php if ($stripeConfigured): ?>
        <form action="/api/billing.php" method="POST">
          <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
          <input type="hidden" name="action" value="create_checkout">
          <button type="submit" class="btn btn-primary btn-lg px-4">
            <i class="bi bi-credit-card me-2"></i>Aktywuj plan Pro
          </button>
        </form>
        <?php else: ?>
        <div class="alert alert-secondary py-2 small mb-2">
          <i class="bi bi-gear me-1"></i>
          Płatności Stripe nie są skonfigurowane. Skontaktuj się z administratorem.
        </div>
        <?php if (hasRole('admin')): ?>
        <a href="mailto:kontakt@tachopro.pl?subject=Upgrade+do+Pro&body=Firma:+<?= urlencode($company['name']) ?>"
           class="btn btn-outline-primary">
          <i class="bi bi-envelope me-1"></i>Kontakt w sprawie aktywacji
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Invoices list ─────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-receipt text-secondary"></i>
    <span class="tp-card-title">Faktury</span>
    <?php if (hasRole('admin') && $company['plan'] === 'pro'): ?>
    <a href="/invoices.php" class="btn btn-sm btn-outline-secondary ms-auto">
      Wszystkie faktury
    </a>
    <?php endif; ?>
  </div>
  <div class="tp-card-body p-0">
    <div class="table-responsive">
      <table class="tp-table">
        <thead>
          <tr>
            <th>Nr faktury</th>
            <th>Data wystawienia</th>
            <th>Okres</th>
            <th>Kierowcy / Pojazdy</th>
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
            <td class="small text-muted">
              <?= fmtDate($inv['period_start']) ?> – <?= fmtDate($inv['period_end']) ?>
            </td>
            <td><?= $inv['active_drivers'] ?> / <?= $inv['active_vehicles'] ?></td>
            <td><?= number_format($inv['amount_net'], 2, ',', ' ') ?> zł</td>
            <td><strong><?= number_format($inv['amount_gross'], 2, ',', ' ') ?> zł</strong></td>
            <td><span class="badge bg-<?= $statusClass ?>"><?= $statusLabel ?></span></td>
            <td>
              <a href="/invoices.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-secondary"
                 title="Podgląd / wydruk">
                <i class="bi bi-printer"></i>
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
</div>

<style>.btn-xs{padding:.2rem .5rem;font-size:.8rem;}</style>
<?php include __DIR__ . '/templates/footer.php'; ?>
