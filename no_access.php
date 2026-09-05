<?php
/**
 * TachoPro 2.0 – Plan-gating / upgrade page
 *
 * Shown when a user tries to access a feature not included in their current plan.
 * Reason codes:
 *   trial_expired    – demo trial has ended
 *   pro_required     – feature needs at minimum PRO plan
 *   pro_plus_required– feature needs PRO+ plan
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/subscription.php';

requireLogin();

$reason    = $_GET['reason'] ?? 'pro_required';
$cid       = (int)($_SESSION['company_id'] ?? 0);
$company   = getCompanyPlan($cid);
$plan      = $company['plan'] ?? PLAN_DEMO;
$daysLeft  = trialDaysRemaining($cid);

$pageTitle  = 'Dostęp ograniczony';
$activePage = '';
include __DIR__ . '/templates/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">

    <?php if ($reason === 'trial_expired'): ?>
    <!-- ── Trial expired ────────────────────────────────────── -->
    <div class="text-center mb-4">
      <div class="display-1 mb-3">⏰</div>
      <h2 class="fw-bold">Okres próbny wygasł</h2>
      <p class="text-muted fs-5">
        Twój 14-dniowy okres testowy dobiegł końca.
        Wybierz pakiet, aby odblokować pełny dostęp.
      </p>
    </div>

    <?php elseif ($reason === 'pro_plus_required'): ?>
    <!-- ── PRO+ required ───────────────────────────────────── -->
    <div class="text-center mb-4">
      <div class="display-1 mb-3">🔒</div>
      <h2 class="fw-bold">Wymagany pakiet <span class="text-warning">PRO+</span></h2>
      <p class="text-muted fs-5">
        Ta funkcja jest dostępna wyłącznie w pakiecie <strong>PRO Module+</strong>
        (delegacje, naruszenia z karami).
        <?php if ($plan === PLAN_PRO): ?>
        Jesteś na planie PRO – kliknij poniżej, aby przejść na PRO+.
        <?php endif; ?>
      </p>
    </div>

    <?php else: ?>
    <!-- ── PRO required (generic) ─────────────────────────── -->
    <div class="text-center mb-4">
      <div class="display-1 mb-3">🔐</div>
      <h2 class="fw-bold">Wymagany pakiet <span class="text-primary">PRO</span></h2>
      <p class="text-muted fs-5">
        Aby korzystać z tej funkcji, aktywuj plan <strong>PRO</strong> lub <strong>PRO+</strong>.
        <?php if ($plan === PLAN_DEMO && $daysLeft > 0): ?>
        <br><small class="text-warning">Masz jeszcze <?= $daysLeft ?> <?= $daysLeft === 1 ? 'dzień' : 'dni' ?> okresu próbnego.</small>
        <?php endif; ?>
      </p>
    </div>
    <?php endif; ?>

    <!-- ── Package cards ────────────────────────────────────── -->
    <div class="row g-4 mb-4">

      <!-- PRO card -->
      <div class="col-md-6">
        <div class="tp-card h-100 <?= $plan === PLAN_PRO ? 'border border-success border-2' : '' ?>">
          <div class="tp-card-header">
            <i class="bi bi-shield-check text-success fs-4"></i>
            <span class="tp-card-title">PRO</span>
            <?php if ($plan === PLAN_PRO): ?>
            <span class="badge bg-success ms-auto">Aktualny plan</span>
            <?php endif; ?>
          </div>
          <div class="tp-card-body">
            <div class="mb-3">
              <span class="fs-2 fw-bold text-success">15 zł</span>
              <span class="text-muted"> netto/kierowca/mies.</span><br>
              <span class="fs-4 fw-bold text-success">10 zł</span>
              <span class="text-muted"> netto/pojazd/mies.</span>
            </div>

            <ul class="list-unstyled mb-4">
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Analiza plików DDD kierowców</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Analiza plików DDD pojazdów</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Wykres aktywności (oś czasu)</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Wskaźniki EU 561/2006</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Archiwum plików DDD</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Raporty czasu pracy</li>
              <li class="mb-2 text-muted"><i class="bi bi-x-circle me-2"></i>Delegacje</li>
              <li class="mb-2 text-muted"><i class="bi bi-x-circle me-2"></i>Naruszenia z karami</li>
            </ul>

            <?php if ($plan === PLAN_PRO): ?>
            <button class="btn btn-outline-success w-100" disabled>
              <i class="bi bi-check2 me-1"></i>Aktywny plan
            </button>
            <?php elseif ($plan === PLAN_PRO_PLUS): ?>
            <button class="btn btn-outline-secondary w-100" disabled>
              Twój plan jest wyższy
            </button>
            <?php else: ?>
            <a href="/billing.php#upgrade-section" class="btn btn-success w-100">
              <i class="bi bi-arrow-up-circle me-1"></i>Aktywuj PRO
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- PRO+ card -->
      <div class="col-md-6">
        <div class="tp-card h-100 border border-warning border-2 <?= $plan === PLAN_PRO_PLUS ? 'border-opacity-100' : '' ?>">
          <div class="tp-card-header" style="background:linear-gradient(135deg,#FFF8E1,#FFFDE7)">
            <i class="bi bi-stars text-warning fs-4"></i>
            <span class="tp-card-title">PRO Module+</span>
            <?php if ($plan === PLAN_PRO_PLUS): ?>
            <span class="badge bg-warning text-dark ms-auto">Aktualny plan</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark ms-auto">Polecany</span>
            <?php endif; ?>
          </div>
          <div class="tp-card-body">
            <div class="mb-3">
              <span class="fs-2 fw-bold text-warning">25 zł</span>
              <span class="text-muted"> netto/kierowca/mies.</span><br>
              <span class="fs-4 fw-bold text-warning">15 zł</span>
              <span class="text-muted"> netto/pojazd/mies.</span>
            </div>

            <ul class="list-unstyled mb-4">
              <li class="mb-2"><i class="bi bi-check-circle-fill text-warning me-2"></i>Wszystko z pakietu PRO</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-warning me-2"></i><strong>Delegacje</strong> (diety, pakiet mobilności)</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-warning me-2"></i><strong>Naruszenia z karami</strong> (EU 561/2006)</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-warning me-2"></i>Zaawansowane raporty urlopowe</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-warning me-2"></i>Obsługa wielu firm</li>
            </ul>

            <?php if ($plan === PLAN_PRO_PLUS): ?>
            <button class="btn btn-outline-warning w-100" disabled>
              <i class="bi bi-check2 me-1"></i>Aktywny plan
            </button>
            <?php else: ?>
            <a href="/billing.php#upgrade-section" class="btn btn-warning w-100">
              <i class="bi bi-stars me-1"></i>Aktywuj PRO Module+
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div><!-- /row -->

    <!-- ── Bottom action row ──────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center">
      <a href="/dashboard.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Wróć do dashboardu
      </a>
      <?php if ($plan !== PLAN_PRO_PLUS): ?>
      <a href="/billing.php" class="btn btn-primary">
        <i class="bi bi-credit-card me-1"></i>Zarządzaj abonamentem
      </a>
      <?php endif; ?>
    </div>

  </div><!-- /col -->
</div><!-- /row -->

<?php include __DIR__ . '/templates/footer.php'; ?>
