<?php
/**
 * TachoPro 2.0 – Subscription & billing helpers
 *
 * Business rules:
 *  - Demo plan     : 14 days, max 2 drivers, max 2 vehicles, watermark on printouts
 *  - PRO plan      : paid monthly – PLN 15 net/active driver + PLN 10 net/active vehicle
 *                    Full DDD analysis, DDD archive, driver & vehicle analysis.
 *                    ONE company per account; no delegation/violations/vacation-reports.
 *  - PRO Module+   : everything in PRO plus delegation management, driver violation
 *                    reports with penalties, vacation reports, and multi-company support.
 *  - VAT rate : 23 %
 */

require_once __DIR__ . '/db.php';

// Plan identifier constants
define('PLAN_DEMO',     'demo');
define('PLAN_PRO',      'pro');
define('PLAN_PRO_PLUS', 'pro_plus');

define('BILLING_PRICE_DRIVER',  15.00);  // PLN net / month
define('BILLING_PRICE_VEHICLE', 10.00);  // PLN net / month
define('BILLING_VAT_RATE',      0.23);   // 23 %
define('DEMO_MAX_DRIVERS',      2);
define('DEMO_MAX_VEHICLES',     2);
define('DEMO_DAYS',             14);

// ── Company plan helpers ──────────────────────────────────────

/**
 * Load the current company row with plan & trial info.
 * Caches result for request lifetime.
 */
function getCompanyPlan(?int $companyId = null): ?array {
    static $cache = [];
    $cid = $companyId ?? (int)($_SESSION['company_id'] ?? 0);
    if (!$cid) return null;
    if (isset($cache[$cid])) return $cache[$cid];

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, name, plan, trial_ends_at, stripe_customer_id, nip, address, email
         FROM companies WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$cid]);
    $cache[$cid] = $stmt->fetch() ?: null;
    return $cache[$cid];
}

/**
 * Is the current company on the demo plan?
 * Returns true for demo companies regardless of whether the trial is active.
 */
function isDemo(?int $companyId = null): bool {
    $co = getCompanyPlan($companyId);
    if (!$co) return true;  // no company = restrict
    if ($co['plan'] === PLAN_PRO || $co['plan'] === PLAN_PRO_PLUS) return false;
    return true;  // plan is 'demo' (active or expired)
}

/**
 * Is the demo trial still valid (not expired)?
 * Always returns true for paid plans.
 */
function isTrialActive(?int $companyId = null): bool {
    $co = getCompanyPlan($companyId);
    if (!$co) return false;
    if ($co['plan'] === PLAN_PRO || $co['plan'] === PLAN_PRO_PLUS) return true;
    if (!$co['trial_ends_at']) return false;
    return strtotime($co['trial_ends_at']) >= strtotime('today');
}

/**
 * Is the demo trial expired?
 * Always returns false for paid plans.
 */
function isTrialExpired(?int $companyId = null): bool {
    $co = getCompanyPlan($companyId);
    if (!$co) return true;
    if ($co['plan'] === PLAN_PRO || $co['plan'] === PLAN_PRO_PLUS) return false;
    if (!$co['trial_ends_at']) return true;
    return strtotime($co['trial_ends_at']) < strtotime('today');
}

/**
 * Days remaining in trial (0 if expired or on a paid plan).
 */
function trialDaysRemaining(?int $companyId = null): int {
    $co = getCompanyPlan($companyId);
    if (!$co || $co['plan'] === PLAN_PRO || $co['plan'] === PLAN_PRO_PLUS) return 0;
    if (!$co['trial_ends_at']) return 0;
    $diff = (int)(strtotime($co['trial_ends_at']) - strtotime('today')) / 86400;
    return max(0, $diff);
}

/**
 * Check whether the company can add more entities given demo limits.
 * Pro companies are always allowed.
 */
function subscriptionAllowsMore(string $type, ?int $companyId = null): bool {
    $cid = $companyId ?? (int)($_SESSION['company_id'] ?? 0);
    $co  = getCompanyPlan($cid);
    if (!$co) return false;

    // Paid plans (PRO and PRO Module+): always allowed (no hard limits)
    if ($co['plan'] === PLAN_PRO || $co['plan'] === PLAN_PRO_PLUS) return true;

    // Demo plan: 2 drivers, 2 vehicles max
    $limit = match($type) {
        'drivers'  => DEMO_MAX_DRIVERS,
        'vehicles' => DEMO_MAX_VEHICLES,
        default    => PHP_INT_MAX,
    };

    $db      = getDB();
    $countSql = match($type) {
        'drivers'  => 'SELECT COUNT(*) FROM drivers  WHERE company_id=? AND is_active=1',
        'vehicles' => 'SELECT COUNT(*) FROM vehicles WHERE company_id=? AND is_active=1',
        'users'    => 'SELECT COUNT(*) FROM users    WHERE company_id=? AND is_active=1 AND role != "superadmin"',
        default    => null,
    };
    if (!$countSql) return true;

    $stmt = $db->prepare($countSql);
    $stmt->execute([$cid]);
    $current = (int)$stmt->fetchColumn();

    return $current < $limit;
}

/**
 * Return demo limit for a given type.
 */
function demoLimit(string $type): int {
    return match($type) {
        'drivers'  => DEMO_MAX_DRIVERS,
        'vehicles' => DEMO_MAX_VEHICLES,
        default    => 0,
    };
}

// ── Billing calculation ───────────────────────────────────────

/**
 * Calculate monthly invoice amounts for a company.
 */
function calculateMonthlyBilling(int $companyId): array {
    $db = getDB();

    $sD = $db->prepare('SELECT COUNT(*) FROM drivers  WHERE company_id=? AND is_active=1');
    $sD->execute([$companyId]);
    $drivers = (int)$sD->fetchColumn();

    $sV = $db->prepare('SELECT COUNT(*) FROM vehicles WHERE company_id=? AND is_active=1');
    $sV->execute([$companyId]);
    $vehicles = (int)$sV->fetchColumn();

    $net   = ($drivers * BILLING_PRICE_DRIVER) + ($vehicles * BILLING_PRICE_VEHICLE);
    $vat   = round($net * BILLING_VAT_RATE, 2);
    $gross = $net + $vat;

    return [
        'drivers'        => $drivers,
        'vehicles'       => $vehicles,
        'price_driver'   => BILLING_PRICE_DRIVER,
        'price_vehicle'  => BILLING_PRICE_VEHICLE,
        'amount_net'     => round($net, 2),
        'amount_vat'     => round($vat, 2),
        'amount_gross'   => round($gross, 2),
        'vat_rate'       => BILLING_VAT_RATE * 100,
    ];
}

// ── Invoice generation ────────────────────────────────────────

/**
 * Generate the next sequential invoice number for this year.
 * Format: FV/YYYY/NNN
 */
function generateInvoiceNumber(PDO $db): string {
    $year = (int)date('Y');
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM invoices WHERE YEAR(issue_date) = ?"
    );
    $stmt->execute([$year]);
    $count = (int)$stmt->fetchColumn() + 1;
    return sprintf('FV/%d/%03d', $year, $count);
}

/**
 * Create an invoice record for a company's current billing period.
 * Returns the newly created invoice ID or null on failure.
 */
function createInvoice(int $companyId, ?int $subscriptionId = null): ?int {
    $db      = getDB();
    $co      = getCompanyPlan($companyId);
    if (!$co) return null;

    $billing = calculateMonthlyBilling($companyId);
    $invNum  = generateInvoiceNumber($db);
    $today   = date('Y-m-d');
    $due     = date('Y-m-d', strtotime('+14 days'));
    $start   = date('Y-m-01');
    $end     = date('Y-m-t');

    $db->prepare(
        'INSERT INTO invoices
         (company_id, subscription_id, invoice_number, issue_date, due_date,
          period_start, period_end, buyer_name, buyer_address, buyer_nip,
          active_drivers, active_vehicles, amount_net, amount_vat, amount_gross, vat_rate)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $companyId,
        $subscriptionId,
        $invNum,
        $today,
        $due,
        $start,
        $end,
        $co['name'],
        $co['address'],
        $co['nip'],
        $billing['drivers'],
        $billing['vehicles'],
        $billing['amount_net'],
        $billing['amount_vat'],
        $billing['amount_gross'],
        $billing['vat_rate'],
    ]);

    return (int)$db->lastInsertId();
}

// ── Upgrade company to Pro ────────────────────────────────────

/**
 * Upgrade a company from demo to PRO.
 */
function upgradeCompanyToPro(int $companyId, ?string $stripeCustomerId = null): void {
    $db = getDB();
    $db->prepare(
        'UPDATE companies SET plan="pro", stripe_customer_id=COALESCE(?, stripe_customer_id) WHERE id=?'
    )->execute([$stripeCustomerId, $companyId]);
    // Note: static cache in getCompanyPlan() persists within the same request,
    // but upgrade is followed by a redirect so no stale data is served.
}

/**
 * Upgrade a company to PRO Module+ (from demo or from PRO).
 */
function upgradeCompanyToProPlus(int $companyId, ?string $stripeCustomerId = null): void {
    $db = getDB();
    $db->prepare(
        'UPDATE companies SET plan="pro_plus", stripe_customer_id=COALESCE(?, stripe_customer_id) WHERE id=?'
    )->execute([$stripeCustomerId, $companyId]);
}

// ── Plan check helpers ────────────────────────────────────────

/**
 * Is the company on any paid plan (PRO or PRO Module+)?
 */
function isPaidPlan(?int $companyId = null): bool {
    $co = getCompanyPlan($companyId);
    if (!$co) return false;
    return $co['plan'] === PLAN_PRO || $co['plan'] === PLAN_PRO_PLUS;
}

/**
 * Is the company on the PRO Module+ plan?
 */
function isProPlus(?int $companyId = null): bool {
    $co = getCompanyPlan($companyId);
    if (!$co) return false;
    return $co['plan'] === PLAN_PRO_PLUS;
}
