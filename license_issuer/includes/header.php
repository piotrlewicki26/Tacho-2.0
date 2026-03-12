<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= liE($pageTitle ?? 'License Issuer') ?> – TachoPro License Issuer</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="assets/css/issuer.css">
</head>
<body class="li-layout">

<header class="li-topbar d-flex align-items-center px-4 gap-3">
  <div class="li-brand d-flex align-items-center gap-2">
    <span class="li-brand-icon"><i class="bi bi-shield-lock-fill"></i></span>
    <span class="li-brand-name fw-bold">TachoPro <span class="text-warning">License Issuer</span></span>
  </div>
  <div class="flex-grow-1"></div>
  <?php if (isLILoggedIn()): ?>
  <span class="text-muted small me-2"><i class="bi bi-person me-1"></i><?= liE($_SESSION['li_username'] ?? '') ?></span>
  <a href="index.php?page=dashboard" class="btn btn-sm btn-outline-secondary me-1">
    <i class="bi bi-grid me-1"></i>Dashboard
  </a>
  <a href="index.php?page=logout" class="btn btn-sm btn-outline-danger">
    <i class="bi bi-box-arrow-right me-1"></i>Wyloguj
  </a>
  <?php endif; ?>
</header>

<main class="li-main">
  <div class="li-content">
    <?= liFlashHtml() ?>
