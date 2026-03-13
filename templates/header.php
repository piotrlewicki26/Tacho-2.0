<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle ?? 'TachoPro 2.0') ?> – TachoPro 2.0</title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
  <!-- Custom styles -->
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="tp-layout">

<!-- ═══ TOP BAR ═══════════════════════════════════════════════ -->
<header class="tp-topbar d-flex align-items-center px-3 gap-3">
  <!-- Hamburger (mobile) -->
  <button class="tp-sidebar-toggle d-xl-none btn btn-sm btn-ghost-light" id="sidebarToggle">
    <i class="bi bi-list fs-5"></i>
  </button>

  <!-- Brand -->
  <a href="/dashboard.php" class="tp-brand text-decoration-none d-flex align-items-center gap-2">
    <span class="tp-brand-icon"><i class="bi bi-speedometer2"></i></span>
    <span class="tp-brand-name fw-bold">TachoPro <span class="text-primary">2.0</span></span>
  </a>

  <div class="flex-grow-1"></div>

  <!-- DDD Upload button -->
  <button class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1"
          data-bs-toggle="modal" data-bs-target="#dddUploadModal"
          title="Wgraj plik DDD">
    <i class="bi bi-cloud-upload"></i>
    <span class="d-none d-md-inline">Wgraj DDD</span>
  </button>

  <!-- Archive button -->
  <a href="/files.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
     title="Archiwum plików DDD">
    <i class="bi bi-archive"></i>
    <span class="d-none d-md-inline">Archiwum</span>
  </a>

  <!-- User menu -->
  <div class="dropdown">
    <button class="btn btn-sm btn-ghost-light d-flex align-items-center gap-2" data-bs-toggle="dropdown">
      <span class="tp-avatar"><i class="bi bi-person-circle fs-5"></i></span>
      <span class="d-none d-md-inline"><?= e($_SESSION['username'] ?? '') ?></span>
      <i class="bi bi-chevron-down small"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow">
      <li><h6 class="dropdown-header"><?= e($_SESSION['username'] ?? '') ?></h6></li>
      <li><small class="dropdown-header text-muted">
        <?= ($_SESSION['role'] ?? '') === 'superadmin' ? '👑 Superadmin' :
           (($_SESSION['role'] ?? '') === 'admin'   ? '🔑 Admin firmy' :
           (($_SESSION['role'] ?? '') === 'manager' ? '✏️ Użytkownik' : '👁 Podgląd')) ?>
      </small></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="/settings.php"><i class="bi bi-gear me-2"></i>Ustawienia</a></li>
      <li><a class="dropdown-item" href="/audit.php"><i class="bi bi-clock-history me-2"></i>Historia zmian</a></li>
      <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="/admin.php">
        <i class="bi bi-shield-lock-fill me-2"></i>Panel Superadmin
      </a></li>
      <?php endif; ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Wyloguj</a></li>
    </ul>
  </div>
</header>

<!-- ═══ SIDEBAR ════════════════════════════════════════════════ -->
<?php
// Load plan info once for sidebar rendering (suppresses notice when header is
// included on pages that haven't called require_once subscription.php yet).
if (!function_exists('getCompanyPlan')) {
    require_once __DIR__ . '/../includes/subscription.php';
}
$_hdrCid  = (int)($_SESSION['company_id'] ?? 0);
$_hdrPlan = $_hdrCid ? (getCompanyPlan($_hdrCid)['plan'] ?? PLAN_DEMO) : PLAN_DEMO;
$_hdrHasPro     = in_array($_hdrPlan, [PLAN_PRO, PLAN_PRO_PLUS], true);
$_hdrHasProPlus = $_hdrPlan === PLAN_PRO_PLUS;
// Plan badge label and colour
$_hdrPlanLabel = match($_hdrPlan) {
    PLAN_PRO_PLUS => ['text' => 'PRO+',  'bg' => '#F59E0B', 'fg' => '#1a1a1a'],
    PLAN_PRO      => ['text' => 'PRO',   'bg' => '#22C55E', 'fg' => '#fff'],
    default       => ['text' => 'DEMO',  'bg' => '#9CA3AF', 'fg' => '#fff'],
};
?>
<nav class="tp-sidebar" id="sidebar">
  <ul class="tp-nav list-unstyled mb-0">

    <!-- Plan badge -->
    <li class="tp-nav-item" style="pointer-events:none;">
      <a href="/billing.php" class="tp-nav-link" style="pointer-events:all;opacity:1;" title="Abonament">
        <i class="bi bi-shield-check" style="color:<?= $_hdrPlanLabel['bg'] ?>"></i>
        <span style="display:flex;align-items:center;gap:6px;">
          Plan
          <span style="background:<?= $_hdrPlanLabel['bg'] ?>;color:<?= $_hdrPlanLabel['fg'] ?>;font-size:10px;font-weight:700;padding:1px 6px;border-radius:3px;letter-spacing:.5px;"><?= $_hdrPlanLabel['text'] ?></span>
        </span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='dashboard' ? ' active':'' ?>">
      <a href="/dashboard.php" class="tp-nav-link">
        <i class="bi bi-speedometer2"></i><span>Dashboard</span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='drivers' ? ' active':'' ?>">
      <a href="/drivers.php" class="tp-nav-link">
        <i class="bi bi-person-badge"></i><span>Kierowcy</span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='vehicles' ? ' active':'' ?>">
      <a href="/vehicles.php" class="tp-nav-link">
        <i class="bi bi-truck"></i><span>Pojazdy</span>
      </a>
    </li>

    <li class="tp-nav-separator"><small>Moduły PRO</small></li>

    <li class="tp-nav-item<?= ($activePage??'')==='driver_analysis' ? ' active':'' ?>">
      <a href="/modules/driver_analysis/" class="tp-nav-link">
        <i class="bi bi-bar-chart-line"></i><span>Analiza kierowców</span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='vehicle_analysis' ? ' active':'' ?>">
      <a href="/modules/vehicle_analysis/" class="tp-nav-link">
        <i class="bi bi-truck-front"></i><span>Analiza pojazdów</span>
      </a>
    </li>

    <li class="tp-nav-separator"><small>Moduły PRO+</small></li>

    <li class="tp-nav-item<?= ($activePage??'')==='delegation' ? ' active':'' ?><?= !$_hdrHasProPlus ? ' tp-nav-locked' : '' ?>">
      <a href="/modules/delegation/" class="tp-nav-link" <?= !$_hdrHasProPlus ? 'title="Wymagany pakiet PRO+"' : '' ?>>
        <i class="bi bi-map"></i>
        <span>Delegacje</span>
        <?php if (!$_hdrHasProPlus): ?>
        <i class="bi bi-lock-fill ms-auto" style="font-size:11px;opacity:.55;" title="PRO+"></i>
        <?php endif; ?>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='violations' ? ' active':'' ?><?= !$_hdrHasProPlus ? ' tp-nav-locked' : '' ?>">
      <a href="/modules/violations/" class="tp-nav-link" <?= !$_hdrHasProPlus ? 'title="Wymagany pakiet PRO+"' : '' ?>>
        <i class="bi bi-exclamation-triangle"></i>
        <span>Naruszenia</span>
        <?php if (!$_hdrHasProPlus): ?>
        <i class="bi bi-lock-fill ms-auto" style="font-size:11px;opacity:.55;" title="PRO+"></i>
        <?php endif; ?>
      </a>
    </li>

    <li class="tp-nav-separator"><small>Raporty & Firma</small></li>

    <li class="tp-nav-item<?= ($activePage??'')==='reports' ? ' active':'' ?>">
      <a href="/reports.php" class="tp-nav-link">
        <i class="bi bi-file-earmark-bar-graph"></i><span>Raporty</span>
      </a>
    </li>

    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','superadmin'])): ?>
    <li class="tp-nav-item<?= ($activePage??'')==='billing' ? ' active':'' ?>">
      <a href="/billing.php" class="tp-nav-link">
        <i class="bi bi-credit-card"></i><span>Abonament</span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='company' ? ' active':'' ?>">
      <a href="/company.php" class="tp-nav-link">
        <i class="bi bi-building"></i><span>Firma</span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='settings' ? ' active':'' ?>">
      <a href="/settings.php" class="tp-nav-link">
        <i class="bi bi-gear"></i><span>Ustawienia</span>
      </a>
    </li>
    <?php endif; ?>

    <li class="tp-nav-separator"><small>Archiwum</small></li>

    <li class="tp-nav-item<?= ($activePage??'')==='files' ? ' active':'' ?>">
      <a href="/files.php" class="tp-nav-link">
        <i class="bi bi-archive"></i><span>Archiwum DDD</span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='audit' ? ' active':'' ?>">
      <a href="/audit.php" class="tp-nav-link">
        <i class="bi bi-clock-history"></i><span>Historia zmian</span>
      </a>
    </li>

    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
    <li class="tp-nav-separator"><small>Superadmin</small></li>
    <li class="tp-nav-item<?= ($activePage??'')==='admin' ? ' active':'' ?>">
      <a href="/admin.php" class="tp-nav-link" style="color:#ef4444">
        <i class="bi bi-shield-lock-fill"></i><span>Panel zarządzania</span>
      </a>
    </li>
    <?php endif; ?>

  </ul>

  <div class="tp-sidebar-footer">
    <small class="text-muted">v2.0 · <?= date('Y') ?><br>&copy; TachoPro</small>
  </div>
</nav>

<!-- ═══ OVERLAY (mobile) ════════════════════════════════════════ -->
<div class="tp-sidebar-overlay" id="sidebarOverlay"></div>

<!-- ═══ MAIN CONTENT ════════════════════════════════════════════ -->
<main class="tp-main">
  <div class="tp-content">
    <!-- Page header -->
    <?php if (!empty($pageTitle)): ?>
    <div class="tp-page-header mb-4">
      <h1 class="tp-page-title"><?= e($pageTitle) ?></h1>
      <?php if (!empty($pageSubtitle)): ?>
      <p class="tp-page-subtitle text-muted mb-0"><?= e($pageSubtitle) ?></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Flash messages -->
    <?= flashHtml() ?>

    <!-- Plan / trial status banner (shown on every page except billing/no_access) -->
    <?php if (!in_array($activePage ?? '', ['billing', ''], true) || ($activePage??'') === ''): ?>
    <?php if ($_hdrPlan === PLAN_DEMO && isTrialExpired($_hdrCid)): ?>
    <div class="alert alert-danger d-flex align-items-center gap-3 mb-4 py-2" role="alert">
      <i class="bi bi-x-circle-fill fs-5 flex-shrink-0"></i>
      <div class="flex-grow-1">
        <strong>Okres próbny wygasł.</strong>
        Wybierz pakiet, aby korzystać ze wszystkich funkcji.
      </div>
      <a href="/billing.php#upgrade-section" class="btn btn-sm btn-danger text-white fw-bold flex-shrink-0">
        Kup pakiet →
      </a>
    </div>
    <?php elseif ($_hdrPlan === PLAN_DEMO && $_hdrCid): ?>
    <?php $__dLeft = trialDaysRemaining($_hdrCid); ?>
    <div class="alert alert-warning d-flex align-items-center gap-3 mb-4 py-2" role="alert">
      <i class="bi bi-clock-fill fs-5 flex-shrink-0"></i>
      <div class="flex-grow-1">
        <strong>Wersja DEMO</strong> – pozostało <?= $__dLeft ?> <?= $__dLeft === 1 ? 'dzień' : ($__dLeft < 5 ? 'dni' : 'dni') ?> okresu próbnego.
      </div>
      <a href="/billing.php#upgrade-section" class="btn btn-sm btn-warning fw-bold flex-shrink-0">
        Wybierz pakiet →
      </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
