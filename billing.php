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
// billing.php must remain accessible even when the trial is expired so the
// user can upgrade; do NOT call requireModule() here.

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
      Wybierz pakiet PRO lub PRO Module+, aby odblokować pełne funkcje.
    <?php else: ?>
      <strong>Wersja DEMO – pozostało <?= $daysLeft ?> <?= $daysLeft === 1 ? 'dzień' : 'dni' ?>.</strong>
      Masz dostęp do maks. <?= DEMO_MAX_DRIVERS ?> kierowców i <?= DEMO_MAX_VEHICLES ?> pojazdów.
    <?php endif; ?>
    &nbsp;<a href="#upgrade-section" class="alert-link fw-bold">Wybierz pakiet &rarr;</a>
  </div>
</div>
<?php endif; ?>

<?php if ($company['plan'] === PLAN_PRO): ?>
<div class="alert alert-info d-flex align-items-start gap-3 mb-4">
  <i class="bi bi-info-circle fs-4 flex-shrink-0 mt-1"></i>
  <div>
    <strong>Jesteś na planie PRO.</strong>
    Aby uzyskać dostęp do delegacji, raportów urlopowych i naruszeń z karami,
    przejdź na <a href="#upgrade-section" class="alert-link fw-bold">PRO Module+ &rarr;</a>
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
          <?php if ($company['plan'] === PLAN_PRO_PLUS): ?>
          <span class="badge fs-6 px-3 py-2" style="background:linear-gradient(135deg,#1a56db,#9333ea);color:#fff">
            <i class="bi bi-crown-fill me-1"></i>PRO Module+
          </span>
          <?php elseif ($company['plan'] === PLAN_PRO): ?>
          <span class="badge bg-success fs-6 px-3 py-2">
            <i class="bi bi-crown me-1"></i>PRO
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
        <?php if ($company['plan'] === PLAN_DEMO && $company['trial_ends_at']): ?>
        <div class="small text-muted">
          <i class="bi bi-calendar me-1"></i>
          Koniec okresu próbnego: <strong><?= fmtDate($company['trial_ends_at']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($company['plan'] === PLAN_DEMO): ?>
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
              <td><?= $billing['drivers'] ?> kierowców × <?= number_format($billing['price_driver'], 2, ',', ' ') ?> zł</td>
              <td class="text-end fw-600"><?= number_format($billing['drivers'] * $billing['price_driver'], 2, ',', ' ') ?> zł</td>
            </tr>
            <tr>
              <td><?= $billing['vehicles'] ?> pojazdów × <?= number_format($billing['price_vehicle'], 2, ',', ' ') ?> zł</td>
              <td class="text-end fw-600"><?= number_format($billing['vehicles'] * $billing['price_vehicle'], 2, ',', ' ') ?> zł</td>
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
        <span class="tp-card-title">Cennik</span>
      </div>
      <div class="tp-card-body p-0">
        <table class="tp-table">
          <thead>
            <tr>
              <th></th>
              <th class="text-center">PRO</th>
              <th class="text-center">PRO Module+</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><i class="bi bi-person-badge text-primary me-1"></i>Kierowca / miesiąc</td>
              <td class="text-center fw-600"><?= number_format(BILLING_PRICE_DRIVER, 2, ',', ' ') ?> zł</td>
              <td class="text-center fw-600"><?= number_format(BILLING_PRICE_PLUS_DRIVER, 2, ',', ' ') ?> zł</td>
            </tr>
            <tr>
              <td><i class="bi bi-truck text-success me-1"></i>Pojazd / miesiąc</td>
              <td class="text-center fw-600"><?= number_format(BILLING_PRICE_VEHICLE, 2, ',', ' ') ?> zł</td>
              <td class="text-center fw-600"><?= number_format(BILLING_PRICE_PLUS_VEHICLE, 2, ',', ' ') ?> zł</td>
            </tr>
          </tbody>
        </table>
        <p class="small text-muted px-3 pt-2 mb-2">
          Ceny netto. Płacisz tylko za aktywnych kierowców i pojazdy.
        </p>
      </div>
    </div>
  </div>
</div>

<!-- ── Upgrade section – two plan cards ─────────────────────── -->
<?php if ($company['plan'] !== PLAN_PRO_PLUS): ?>
<div id="upgrade-section">
  <h5 class="fw-bold mb-3">
    <i class="bi bi-arrow-up-circle-fill text-primary me-2"></i>Wybierz pakiet
  </h5>
  <div class="row g-4 mb-4">

    <!-- PRO plan card -->
    <div class="col-md-6">
      <div class="tp-card h-100 <?= $company['plan'] === PLAN_PRO ? 'border border-success border-2' : '' ?>">
        <div class="tp-card-header" style="background:#16a34a;color:#fff;border-radius:inherit inherit 0 0">
          <i class="bi bi-crown fs-5"></i>
          <span class="tp-card-title" style="color:#fff">PRO</span>
          <?php if ($company['plan'] === PLAN_PRO): ?>
          <span class="badge bg-light text-success ms-auto">Aktualny plan</span>
          <?php endif; ?>
        </div>
        <div class="tp-card-body">
          <ul class="list-unstyled small mb-3">
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Pełna analiza plików DDD kierowców</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Archiwum plików DDD</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Moduł analizy kierowców</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Moduł analizy pojazdów</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Brak limitu kierowców i pojazdów</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Faktury VAT co miesiąc</li>
            <li class="mb-2 text-muted"><i class="bi bi-x-circle me-2"></i>Tylko jedna firma</li>
            <li class="mb-2 text-muted"><i class="bi bi-x-circle me-2"></i>Bez delegacji i diet</li>
            <li class="mb-2 text-muted"><i class="bi bi-x-circle me-2"></i>Bez raportów urlopowych</li>
            <li class="text-muted"><i class="bi bi-x-circle me-2"></i>Bez naruszeń z karami</li>
          </ul>
          <div class="fw-bold mb-3">
            <?= number_format(BILLING_PRICE_DRIVER, 2, ',', ' ') ?> zł netto/kierowca
            + <?= number_format(BILLING_PRICE_VEHICLE, 2, ',', ' ') ?> zł netto/pojazd
          </div>
          <?php if ($company['plan'] === PLAN_PRO): ?>
          <button class="btn btn-outline-success w-100" disabled>
            <i class="bi bi-check2 me-1"></i>Aktywny plan
          </button>
          <?php else: ?>
          <?php
          $stripeKey = getSystemSetting('stripe_publishable_key')
              ?: (defined('CFG_STRIPE_PUBLISHABLE_KEY') ? CFG_STRIPE_PUBLISHABLE_KEY : '');
          $stripeConfigured = !empty($stripeKey) && str_starts_with($stripeKey, 'pk_');
          ?>
          <?php if ($stripeConfigured): ?>
          <form action="/api/billing.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_checkout">
            <input type="hidden" name="plan" value="<?= PLAN_PRO ?>">
            <button type="submit" class="btn btn-success w-100">
              <i class="bi bi-credit-card me-2"></i>Przejdź na PRO
            </button>
          </form>
          <?php else: ?>
          <div class="alert alert-secondary py-2 small mb-2">
            <i class="bi bi-gear me-1"></i>Płatności Stripe nie są skonfigurowane.
          </div>
          <?php if (hasRole('admin')): ?>
          <a href="mailto:kontakt@tachopro.pl?subject=Upgrade+do+PRO&body=Firma:+<?= urlencode($company['name']) ?>"
             class="btn btn-outline-success w-100">
            <i class="bi bi-envelope me-1"></i>Kontakt w sprawie aktywacji PRO
          </a>
          <?php endif; ?>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- PRO Module+ plan card -->
    <div class="col-md-6">
      <div class="tp-card h-100 border border-2" style="border-color:#9333ea!important">
        <div class="tp-card-header" style="background:linear-gradient(135deg,#1a56db,#9333ea);color:#fff;border-radius:inherit inherit 0 0">
          <i class="bi bi-crown-fill fs-5"></i>
          <span class="tp-card-title" style="color:#fff">PRO Module+</span>
          <span class="badge bg-warning text-dark ms-auto">Polecany</span>
        </div>
        <div class="tp-card-body">
          <ul class="list-unstyled small mb-3">
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Wszystko z pakietu PRO</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Naruszenia kierowców z potencjalnymi karami</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Delegacje (diety + pakiet mobilności)</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Raporty (urlopy)</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Możliwość dodawania kolejnych firm</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Brak limitu kierowców i pojazdów</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Faktury VAT co miesiąc</li>
          </ul>
          <div class="fw-bold mb-3">
            <?= number_format(BILLING_PRICE_PLUS_DRIVER, 2, ',', ' ') ?> zł netto/kierowca
            + <?= number_format(BILLING_PRICE_PLUS_VEHICLE, 2, ',', ' ') ?> zł netto/pojazd
          </div>
          <?php
          $stripeKey = getSystemSetting('stripe_publishable_key')
              ?: (defined('CFG_STRIPE_PUBLISHABLE_KEY') ? CFG_STRIPE_PUBLISHABLE_KEY : '');
          $stripeConfigured = !empty($stripeKey) && str_starts_with($stripeKey, 'pk_');
          ?>
          <?php if ($stripeConfigured): ?>
          <form action="/api/billing.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_checkout">
            <input type="hidden" name="plan" value="<?= PLAN_PRO_PLUS ?>">
            <button type="submit" class="btn btn-primary w-100" style="background:linear-gradient(135deg,#1a56db,#9333ea);border:none">
              <i class="bi bi-credit-card me-2"></i>Przejdź na PRO Module+
            </button>
          </form>
          <?php else: ?>
          <div class="alert alert-secondary py-2 small mb-2">
            <i class="bi bi-gear me-1"></i>Płatności Stripe nie są skonfigurowane.
          </div>
          <?php if (hasRole('admin')): ?>
          <a href="mailto:kontakt@tachopro.pl?subject=Upgrade+do+PRO+Module%2B&body=Firma:+<?= urlencode($company['name']) ?>"
             class="btn btn-outline-primary w-100">
            <i class="bi bi-envelope me-1"></i>Kontakt w sprawie aktywacji PRO Module+
          </a>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div><!-- /row -->
  <p class="text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    Płacisz tylko za to, czego używasz – bez stałych opłat, bez kontraktu.
    Aktywacja planu spowoduje naliczenie pierwszej faktury za bieżący miesiąc.
    Płatność obsługuje <strong>Stripe</strong> (karta, BLIK, przelew).
  </p>
</div><!-- /#upgrade-section -->
<?php endif; ?>

<!-- ── Invoices list ─────────────────────────────────────────── -->
<div class="tp-card">
  <div class="tp-card-header">
    <i class="bi bi-receipt text-secondary"></i>
    <span class="tp-card-title">Faktury</span>
    <?php if (hasRole('admin') && ($company['plan'] === PLAN_PRO || $company['plan'] === PLAN_PRO_PLUS)): ?>
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
