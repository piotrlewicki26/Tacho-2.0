<?php
/**
 * TachoPro 2.0 – Billing API
 * Handles Stripe Checkout sessions and webhooks.
 *
 * Routes:
 *   POST action=create_checkout  – create Stripe Checkout session
 *   POST action=webhook          – Stripe webhook endpoint (no auth)
 */

// ── Webhook endpoint (no session auth required) ───────────────
$action = $_REQUEST['action'] ?? '';

if ($action === 'webhook') {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/subscription.php';
    handleStripeWebhook();
    exit;
}

// ── Authenticated routes ──────────────────────────────────────
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/license_check.php';
require_once __DIR__ . '/../includes/subscription.php';
require_once __DIR__ . '/../includes/audit.php';

requireLogin();
// api/billing.php handles Stripe callbacks and upgrade actions; must remain
// reachable for expired-trial users, so do NOT call requireModule() here.

header('Content-Type: application/json');

$companyId = (int)$_SESSION['company_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

switch ($action) {
    case 'create_checkout':
        handleCreateCheckout($companyId);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

// ════════════════════════════════════════════════════════════════
// Handlers
// ════════════════════════════════════════════════════════════════

function handleCreateCheckout(int $companyId): void {
    $stripeSecretKey = defined('CFG_STRIPE_SECRET_KEY') ? CFG_STRIPE_SECRET_KEY : '';

    if (empty($stripeSecretKey) || !str_starts_with($stripeSecretKey, 'sk_')) {
        echo json_encode(['error' => 'Stripe is not configured. Contact the administrator.']);
        return;
    }

    $company = getCompanyPlan($companyId);
    $billing = calculateMonthlyBilling($companyId);

    if ($billing['amount_gross'] <= 0) {
        echo json_encode(['error' => 'No billable resources. Add at least one driver or vehicle.']);
        return;
    }

    // Build line items: drivers + vehicles
    $lineItems = [];
    if ($billing['drivers'] > 0) {
        $lineItems[] = [
            'price_data' => [
                'currency'     => 'pln',
                'unit_amount'  => (int)($billing['price_driver'] * 100 * 1.23), // gross in grosz
                'product_data' => ['name' => 'Kierowca (miesięcznie)'],
            ],
            'quantity' => $billing['drivers'],
        ];
    }
    if ($billing['vehicles'] > 0) {
        $lineItems[] = [
            'price_data' => [
                'currency'     => 'pln',
                'unit_amount'  => (int)($billing['price_vehicle'] * 100 * 1.23), // gross in grosz
                'product_data' => ['name' => 'Pojazd (miesięcznie)'],
            ],
            'quantity' => $billing['vehicles'],
        ];
    }

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
             . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $payload = [
        'mode'        => 'payment',
        'line_items'  => $lineItems,
        'success_url' => $baseUrl . '/billing.php?stripe_success=1&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => $baseUrl . '/billing.php?stripe_cancel=1',
        'metadata'    => ['company_id' => $companyId],
    ];

    // Attach existing Stripe customer if available
    if (!empty($company['stripe_customer_id'])) {
        $payload['customer'] = $company['stripe_customer_id'];
    } else {
        $payload['customer_creation'] = 'always';
    }

    // Call Stripe API via cURL
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(flattenStripeParams($payload)),
        CURLOPT_USERPWD        => $stripeSecretKey . ':',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode !== 200 || empty($data['url'])) {
        error_log('Stripe checkout error: ' . $response);
        echo json_encode(['error' => 'Payment gateway error. Please try again.']);
        return;
    }

    // Redirect the form (non-XHR)
    echo json_encode(['redirect' => $data['url']]);
}

/**
 * Flatten nested PHP array to Stripe's encoded format (array[key]).
 */
function flattenStripeParams(array $params, string $prefix = ''): array {
    $result = [];
    foreach ($params as $key => $value) {
        $fullKey = $prefix ? "{$prefix}[{$key}]" : $key;
        if (is_array($value)) {
            $result = array_merge($result, flattenStripeParams($value, $fullKey));
        } else {
            $result[$fullKey] = $value;
        }
    }
    return $result;
}

// ── Webhook ──────────────────────────────────────────────────

function handleStripeWebhook(): void {
    $webhookSecret = defined('CFG_STRIPE_WEBHOOK_SECRET') ? CFG_STRIPE_WEBHOOK_SECRET : '';
    $payload       = file_get_contents('php://input');
    $sigHeader     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    // Verify signature if webhook secret is configured
    if ($webhookSecret) {
        if (!verifyStripeSignature($payload, $sigHeader, $webhookSecret)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);
            return;
        }
    }

    $event = json_decode($payload, true);
    if (!$event || empty($event['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid event']);
        return;
    }

    $db = getDB();

    // Idempotency: skip if already processed
    $exists = $db->prepare('SELECT id FROM stripe_events WHERE event_id=? LIMIT 1');
    $exists->execute([$event['id']]);
    if ($exists->fetch()) {
        http_response_code(200);
        echo json_encode(['status' => 'already_processed']);
        return;
    }

    // Log event
    $db->prepare(
        'INSERT INTO stripe_events (event_id, event_type, payload) VALUES (?,?,?)'
    )->execute([
        $event['id'],
        $event['type'] ?? 'unknown',
        json_encode($event),
    ]);
    $stripeEventDbId = (int)$db->lastInsertId();

    $companyId = null;
    $handled   = false;

    switch ($event['type'] ?? '') {
        case 'checkout.session.completed':
            $session   = $event['data']['object'] ?? [];
            $companyId = (int)($session['metadata']['company_id'] ?? 0);
            if ($companyId) {
                $customerId = $session['customer'] ?? null;
                upgradeCompanyToPro($companyId, $customerId);

                // Create invoice record
                $invoiceId = createInvoice($companyId);

                // Mark invoice paid immediately (one-time payment)
                if ($invoiceId) {
                    $db->prepare('UPDATE invoices SET status="paid", paid_at=NOW() WHERE id=?')
                       ->execute([$invoiceId]);
                }

                $handled = true;
            }
            break;

        case 'invoice.paid':
            $inv       = $event['data']['object'] ?? [];
            $custId    = $inv['customer'] ?? null;
            if ($custId) {
                $stmt = $db->prepare('SELECT id FROM companies WHERE stripe_customer_id=? LIMIT 1');
                $stmt->execute([$custId]);
                $co = $stmt->fetch();
                if ($co) {
                    $companyId = (int)$co['id'];
                    // Mark any open invoices for this customer as paid
                    $db->prepare(
                        "UPDATE invoices SET status='paid', paid_at=NOW(),
                                stripe_invoice_id=?
                         WHERE company_id=? AND status='issued'"
                    )->execute([$inv['id'] ?? null, $companyId]);
                    $handled = true;
                }
            }
            break;

        case 'customer.subscription.deleted':
            $sub    = $event['data']['object'] ?? [];
            $custId = $sub['customer'] ?? null;
            if ($custId) {
                $stmt = $db->prepare('SELECT id FROM companies WHERE stripe_customer_id=? LIMIT 1');
                $stmt->execute([$custId]);
                $co = $stmt->fetch();
                if ($co) {
                    $companyId = (int)$co['id'];
                    $db->prepare("UPDATE companies SET plan='demo' WHERE id=?")->execute([$companyId]);
                    $handled = true;
                }
            }
            break;
    }

    // Mark event processed
    $db->prepare('UPDATE stripe_events SET processed=1, company_id=? WHERE id=?')
       ->execute([$companyId, $stripeEventDbId]);

    http_response_code(200);
    echo json_encode(['status' => $handled ? 'handled' : 'ignored']);
}

/**
 * Verify Stripe webhook signature (HMAC SHA256).
 */
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    if (!$sigHeader) return false;
    $parts    = explode(',', $sigHeader);
    $ts       = null;
    $sigs     = [];
    foreach ($parts as $part) {
        [$k, $v] = explode('=', $part, 2) + ['', ''];
        if ($k === 't') $ts = $v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if (!$ts || !$sigs) return false;

    $tolerance = 300; // 5 minutes
    if (abs(time() - (int)$ts) > $tolerance) return false;

    $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
