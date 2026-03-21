<?php
/**
 * TachoPro 2.0 – Configuration
 * Copy this file to config.php and fill in your credentials.
 * NEVER commit config.php to version control.
 */

// ── Database connection ───────────────────────────────────────
define('CFG_DB_HOST', 'localhost');
define('CFG_DB_NAME', 'tachopro');
define('CFG_DB_USER', 'tachopro_user');
define('CFG_DB_PASS', 'YOUR_STRONG_PASSWORD_HERE');

// ── Stripe payment gateway ────────────────────────────────────
// Get your keys from https://dashboard.stripe.com/apikeys
// Leave empty to disable Stripe payments.
define('CFG_STRIPE_PUBLISHABLE_KEY', '');   // pk_live_... or pk_test_...
define('CFG_STRIPE_SECRET_KEY',      '');   // sk_live_... or sk_test_...
define('CFG_STRIPE_WEBHOOK_SECRET',  '');   // whsec_... from Stripe dashboard

// ── Application settings ─────────────────────────────────────
// Base URL (no trailing slash), used in e-mail links etc.
define('CFG_APP_URL', 'https://your-domain.com');

