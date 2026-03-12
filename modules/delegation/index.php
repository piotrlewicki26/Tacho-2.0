<?php
/**
 * TachoPro 2.0 – Delegation Module
 * Calculate diet, wages, border crossings for each trip.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();
requireModule('delegation');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        flashSet('danger', 'Nieprawidłowy token CSRF.');
        redirect('/modules/delegation/');
    }
    if (!hasRole('manager')) { flashSet('danger', 'Brak uprawnień.'); redirect('/modules/delegation/'); }

    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add') {
        $driverId  = (int)($_POST['driver_id']   ?? 0);
        $vehicleId = (int)($_POST['vehicle_id']  ?? 0) ?: null;
        $startDt   = $_POST['start_datetime']    ?? '';
        $endDt     = $_POST['end_datetime']      ?? '';
        $route     = trim($_POST['route']        ?? '');
        $countries = $_POST['countries']         ?? [];
        $mileage   = (int)($_POST['mileage_km']  ?? 0) ?: null;
        $notes     = trim($_POST['notes']        ?? '');

        if (!$driverId || !$startDt) {
            flashSet('danger', 'Kierowca i data początkowa są wymagane.');
            redirect('/modules/delegation/');
        }

        // Calculate diet
        $dietTotal = 0;
        if ($startDt && $endDt && $countries) {
            $DIET_RATES = [
                'DE'=>49,'FR'=>50,'NL'=>45,'BE'=>45,'IT'=>48,'ES'=>50,
                'AT'=>52,'CH'=>88,'NO'=>82,'SE'=>64,'DK'=>76,'CZ'=>45,
                'SK'=>45,'HU'=>50,'RO'=>45,'PL'=>45,
            ];
            $hours  = max(0, (strtotime($endDt) - strtotime($startDt)) / 3600);
            $days   = floor($hours / 24);
            $remHrs = $hours - $days * 24;
            foreach ((array)$countries as $cc) {
                $rate = $DIET_RATES[$cc] ?? 45;
                $dietTotal += ($days * $rate) + ($remHrs >= 8 ? $rate * 0.5 : ($remHrs >= 2 ? $rate * 0.25 : 0));
            }
        }

        $db->prepare(
            'INSERT INTO delegations (company_id, driver_id, vehicle_id, start_datetime, end_datetime, route, countries, diet_total, mileage_km, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $companyId, $driverId, $vehicleId,
            $startDt, $endDt ?: null, $route,
            $countries ? json_encode($countries) : null,
            $dietTotal ?: null, $mileage, $notes,
            (int)$_SESSION['user_id'],
        ]);
        flashSet('success', 'Delegacja została dodana. Dieta: ' . number_format($dietTotal, 2) . ' EUR');
        redirect('/modules/delegation/');
    }

    if ($postAction === 'delete' && hasRole('admin')) {
        $id = (int)($_POST['delegation_id'] ?? 0);
        $db->prepare('DELETE FROM delegations WHERE id=? AND company_id=?')->execute([$id, $companyId]);
        flashSet('success', 'Delegacja usunięta.');
        redirect('/modules/delegation/');
    }
}

// ── Load ──────────────────────────────────────────────────────
$stmt = $db->prepare('SELECT id, first_name, last_name FROM drivers WHERE company_id=? AND is_active=1 ORDER BY last_name,first_name');
$stmt->execute([$companyId]);
$drivers = $stmt->fetchAll();

$stmt = $db->prepare('SELECT id, registration FROM vehicles WHERE company_id=? AND is_active=1 ORDER BY registration');
$stmt->execute([$companyId]);
$vehicles = $stmt->fetchAll();

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$cntStmt = $db->prepare('SELECT COUNT(*) FROM delegations WHERE company_id=?');
$cntStmt->execute([$companyId]);
$total = (int)$cntStmt->fetchColumn();
$pag   = paginate($total, $perPage, $page);

$listStmt = $db->prepare(
    "SELECT del.*, d.first_name, d.last_name, v.registration
     FROM delegations del
     JOIN drivers d ON d.id = del.driver_id
     LEFT JOIN vehicles v ON v.id = del.vehicle_id
     WHERE del.company_id = ?
     ORDER BY del.start_datetime DESC
     LIMIT :limit OFFSET :offset"
);
$listStmt->execute([$companyId, ':limit' => $perPage, ':offset' => $pag['offset']]);
// Re-execute properly
$listStmt = $db->prepare(
    "SELECT del.*, d.first_name, d.last_name, v.registration
     FROM delegations del
     JOIN drivers d ON d.id = del.driver_id
     LEFT JOIN vehicles v ON v.id = del.vehicle_id
     WHERE del.company_id = ?
     ORDER BY del.start_datetime DESC
     LIMIT ? OFFSET ?"
);
$listStmt->execute([$companyId, $pag['perPage'], $pag['offset']]);
$delegations = $listStmt->fetchAll();

$COUNTRIES = [
    'DE'=>'🇩🇪 Niemcy','FR'=>'🇫🇷 Francja','NL'=>'🇳🇱 Holandia','BE'=>'🇧🇪 Belgia',
    'IT'=>'🇮🇹 Włochy','ES'=>'🇪🇸 Hiszpania','AT'=>'🇦🇹 Austria','CH'=>'🇨🇭 Szwajcaria',
    'NO'=>'🇳🇴 Norwegia','SE'=>'🇸🇪 Szwecja','DK'=>'🇩🇰 Dania','CZ'=>'🇨🇿 Czechy',
    'SK'=>'🇸🇰 Słowacja','HU'=>'🇭🇺 Węgry','RO'=>'🇷🇴 Rumunia','PL'=>'🇵🇱 Polska',
];

$csrf       = getCsrfToken();
$pageTitle  = 'Delegacje';
$activePage = 'delegation';
include __DIR__ . '/../../templates/header.php';
?>

<div class="row g-4">
  <!-- Add delegation form -->
  <div class="col-lg-5">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-plus-circle text-primary"></i>
        <span class="tp-card-title">Nowa delegacja</span>
      </div>
      <div class="tp-card-body">
        <form method="POST" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action"     value="add">
          <div class="mb-2">
            <label class="form-label fw-600">Kierowca <span class="text-danger">*</span></label>
            <select name="driver_id" class="form-select" required>
              <option value="">— Wybierz —</option>
              <?php foreach ($drivers as $d): ?>
              <option value="<?= $d['id'] ?>"><?= e($d['last_name'].' '.$d['first_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-600">Pojazd</label>
            <select name="vehicle_id" class="form-select">
              <option value="">— Bez pojazdu —</option>
              <?php foreach ($vehicles as $v): ?>
              <option value="<?= $v['id'] ?>"><?= e($v['registration']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-2">
            <div class="col">
              <label class="form-label fw-600">Start <span class="text-danger">*</span></label>
              <input type="datetime-local" name="start_datetime" class="form-control" required>
            </div>
            <div class="col">
              <label class="form-label fw-600">Koniec</label>
              <input type="datetime-local" name="end_datetime" class="form-control">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-600">Trasa</label>
            <input type="text" name="route" class="form-control" maxlength="500" placeholder="np. Warszawa – Berlin – Paryż">
          </div>
          <div class="mb-2">
            <label class="form-label fw-600">Kraje (do obliczenia diety)</label>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($COUNTRIES as $code => $name): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="countries[]"
                       value="<?= e($code) ?>" id="cc_<?= e($code) ?>">
                <label class="form-check-label small" for="cc_<?= e($code) ?>">
                  <?= e($name) ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label fw-600">Przebieg (km)</label>
            <input type="number" name="mileage_km" class="form-control" min="0">
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Uwagi</label>
            <textarea name="notes" class="form-control" rows="2" maxlength="1000"></textarea>
          </div>
          <div class="alert alert-info py-2 small">
            <i class="bi bi-calculator me-1"></i>
            Dieta zostanie obliczona automatycznie na podstawie czasu trwania i wybranych krajów.
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check2 me-1"></i>Dodaj delegację
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Delegations list -->
  <div class="col-lg-7">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-map text-primary"></i>
        <span class="tp-card-title">Lista delegacji</span>
        <span class="badge bg-secondary ms-2"><?= $total ?></span>
      </div>
      <div class="tp-card-body p-0">
        <div class="table-responsive">
          <table class="tp-table">
            <thead>
              <tr>
                <th>Kierowca</th>
                <th>Start</th>
                <th>Koniec</th>
                <th>Trasa</th>
                <th>Dieta (EUR)</th>
                <th class="text-end">Akcje</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($delegations as $del): ?>
              <tr>
                <td><?= e($del['last_name'].' '.$del['first_name']) ?></td>
                <td><small><?= htmlspecialchars(substr($del['start_datetime'],0,16), ENT_QUOTES, 'UTF-8') ?></small></td>
                <td><small><?= $del['end_datetime'] ? htmlspecialchars(substr($del['end_datetime'],0,16), ENT_QUOTES, 'UTF-8') : '—' ?></small></td>
                <td><small><?= e(mb_substr($del['route']??'—', 0, 40)) ?></small></td>
                <td><?= $del['diet_total'] ? number_format((float)$del['diet_total'],2) : '—' ?></td>
                <td class="text-end">
                  <?php if (hasRole('admin')): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Usunąć delegację?')">
                    <input type="hidden" name="csrf_token"     value="<?= e($csrf) ?>">
                    <input type="hidden" name="action"         value="delete">
                    <input type="hidden" name="delegation_id"  value="<?= $del['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$delegations): ?>
              <tr><td colspan="6"><div class="tp-empty-state"><i class="bi bi-map"></i>Brak delegacji.</div></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php if ($pag['totalPages'] > 1): ?>
      <div class="tp-card-footer d-flex justify-content-end">
        <?= paginationHtml($pag, '?') ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>.btn-xs{padding:.2rem .5rem;font-size:.8rem;}</style>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
