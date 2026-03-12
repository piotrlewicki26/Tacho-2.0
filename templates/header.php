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
      <li><a class="dropdown-item" href="/settings.php"><i class="bi bi-gear me-2"></i>Ustawienia</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-danger" href="/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Wyloguj</a></li>
    </ul>
  </div>
</header>

<!-- ═══ SIDEBAR ════════════════════════════════════════════════ -->
<nav class="tp-sidebar" id="sidebar">
  <ul class="tp-nav list-unstyled mb-0">

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

    <li class="tp-nav-separator"><small>Moduły</small></li>

    <?php if (function_exists('hasModule') && hasModule('driver_analysis')): ?>
    <li class="tp-nav-item<?= ($activePage??'')==='driver_analysis' ? ' active':'' ?>">
      <a href="/modules/driver_analysis/" class="tp-nav-link">
        <i class="bi bi-bar-chart-line"></i><span>Analiza kierowców</span>
      </a>
    </li>
    <?php else: ?>
    <li class="tp-nav-item tp-nav-locked">
      <span class="tp-nav-link">
        <i class="bi bi-bar-chart-line"></i><span>Analiza kierowców</span>
        <i class="bi bi-lock-fill ms-auto small"></i>
      </span>
    </li>
    <?php endif; ?>

    <?php if (function_exists('hasModule') && hasModule('vehicle_analysis')): ?>
    <li class="tp-nav-item<?= ($activePage??'')==='vehicle_analysis' ? ' active':'' ?>">
      <a href="/modules/vehicle_analysis/" class="tp-nav-link">
        <i class="bi bi-truck-front"></i><span>Analiza pojazdów</span>
      </a>
    </li>
    <?php else: ?>
    <li class="tp-nav-item tp-nav-locked">
      <span class="tp-nav-link">
        <i class="bi bi-truck-front"></i><span>Analiza pojazdów</span>
        <i class="bi bi-lock-fill ms-auto small"></i>
      </span>
    </li>
    <?php endif; ?>

    <?php if (function_exists('hasModule') && hasModule('delegation')): ?>
    <li class="tp-nav-item<?= ($activePage??'')==='delegation' ? ' active':'' ?>">
      <a href="/modules/delegation/" class="tp-nav-link">
        <i class="bi bi-map"></i><span>Delegacje</span>
      </a>
    </li>
    <?php else: ?>
    <li class="tp-nav-item tp-nav-locked">
      <span class="tp-nav-link">
        <i class="bi bi-map"></i><span>Delegacje</span>
        <i class="bi bi-lock-fill ms-auto small"></i>
      </span>
    </li>
    <?php endif; ?>

    <li class="tp-nav-separator"><small>Raporty & Firma</small></li>

    <li class="tp-nav-item<?= ($activePage??'')==='reports' ? ' active':'' ?>">
      <a href="/reports.php" class="tp-nav-link">
        <i class="bi bi-file-earmark-bar-graph"></i><span>Raporty</span>
      </a>
    </li>

    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','superadmin'])): ?>
    <li class="tp-nav-item<?= ($activePage??'')==='company' ? ' active':'' ?>">
      <a href="/company.php" class="tp-nav-link">
        <i class="bi bi-building"></i><span>Firma</span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='license' ? ' active':'' ?>">
      <a href="/license.php" class="tp-nav-link">
        <i class="bi bi-shield-check"></i><span>Licencja</span>
      </a>
    </li>

    <li class="tp-nav-item<?= ($activePage??'')==='settings' ? ' active':'' ?>">
      <a href="/settings.php" class="tp-nav-link">
        <i class="bi bi-gear"></i><span>Ustawienia</span>
      </a>
    </li>
    <?php endif; ?>

  </ul>

  <div class="tp-sidebar-footer">
    <small class="text-muted">v2.0 · 2025-01-01<br>&copy; TachoPro</small>
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
