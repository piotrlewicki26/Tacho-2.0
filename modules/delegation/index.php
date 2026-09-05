<?php
/**
 * TachoPro 2.0 – Delegation Module
 * Per-leg diet + Mobility Package minimum-wage calculator.
 * Ported logic from DelegationPanel in truck-delegate-pro.jsx
 * (commit ea1fcf7b808040c2256107ee0b6ba4cd4b3c3589).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();
requireModule('delegation');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];

/* ── Country definitions (from JSX DEFAULT_COUNTRIES) ────────── */
$COUNTRIES = [
    'DE' => ['name' => 'Niemcy',    'flag' => '🇩🇪', 'dietRate' => 49,  'minWageEUR' => 12.41],
    'FR' => ['name' => 'Francja',   'flag' => '🇫🇷', 'dietRate' => 50,  'minWageEUR' => 11.65],
    'NL' => ['name' => 'Holandia',  'flag' => '🇳🇱', 'dietRate' => 45,  'minWageEUR' => 13.27],
    'BE' => ['name' => 'Belgia',    'flag' => '��🇪', 'dietRate' => 45,  'minWageEUR' => 11.08],
    'IT' => ['name' => 'Włochy',    'flag' => '🇮🇹', 'dietRate' => 48,  'minWageEUR' =>  9.50],
    'ES' => ['name' => 'Hiszpania', 'flag' => '🇪🇸', 'dietRate' => 50,  'minWageEUR' =>  9.10],
    'AT' => ['name' => 'Austria',   'flag' => '🇦🇹', 'dietRate' => 52,  'minWageEUR' => 12.38],
    'CH' => ['name' => 'Szwajcaria','flag' => '🇨🇭', 'dietRate' => 88,  'minWageEUR' => 24.00],
    'NO' => ['name' => 'Norwegia',  'flag' => '🇳🇴', 'dietRate' => 82,  'minWageEUR' => 20.00],
    'SE' => ['name' => 'Szwecja',   'flag' => '🇸🇪', 'dietRate' => 64,  'minWageEUR' => 14.00],
    'DK' => ['name' => 'Dania',     'flag' => '🇩🇰', 'dietRate' => 76,  'minWageEUR' => 18.00],
    'CZ' => ['name' => 'Czechy',    'flag' => '🇨🇿', 'dietRate' => 45,  'minWageEUR' =>  5.33],
    'SK' => ['name' => 'Słowacja',  'flag' => '🇸🇰', 'dietRate' => 45,  'minWageEUR' =>  5.74],
    'HU' => ['name' => 'Węgry',     'flag' => '🇭🇺', 'dietRate' => 50,  'minWageEUR' =>  4.50],
    'RO' => ['name' => 'Rumunia',   'flag' => '🇷🇴', 'dietRate' => 45,  'minWageEUR' =>  3.74],
    'PL' => ['name' => 'Polska',    'flag' => '🇵🇱', 'dietRate' => 45,  'minWageEUR' =>  5.82],
];

$OPERATION_TYPES = [
    'international' => 'Przewóz międzynarodowy',
    'cabotage'      => 'Kabotaż',
    'cross_trade'   => 'Cross-trade',
    'transit'       => 'Tranzyt',
];

$EUR_PLN = 4.28;   // indicative rate (matches JSX)

/* ── Handle POST ─────────────────────────────────────────────── */
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
        $startDt   = trim($_POST['start_datetime'] ?? '');
        $endDt     = trim($_POST['end_datetime']   ?? '');
        $route     = trim($_POST['route']          ?? '');
        $mileage   = (int)($_POST['mileage_km']    ?? 0) ?: null;
        $notes     = trim($_POST['notes']          ?? '');

        // Per-leg trasa data
        $legCountries  = $_POST['leg_country']   ?? [];
        $legDays       = $_POST['leg_days']       ?? [];
        $legHours      = $_POST['leg_hours']      ?? [];
        $legOp         = $_POST['leg_optype']     ?? [];
        $legKm         = $_POST['leg_km']         ?? [];

        if (!$driverId || !$startDt) {
            flashSet('danger', 'Kierowca i data wyjazdu są wymagane.');
            redirect('/modules/delegation/');
        }

        // Build trasa array and calculate totals (matching JSX logic)
        $trasa        = [];
        $dietTotal    = 0.0;
        $minWageTotal = 0.0;

        foreach ($legCountries as $i => $cc) {
            $cc   = strtoupper(trim($cc));
            if (!isset($COUNTRIES[$cc])) continue;
            $days  = max(1, (int)($legDays[$i] ?? 1));
            $hours = max(1, (float)($legHours[$i] ?? 8));
            $op    = in_array($legOp[$i] ?? '', array_keys($OPERATION_TYPES)) ? $legOp[$i] : 'international';
            $km    = max(0, (int)($legKm[$i] ?? 0));

            $cr = $COUNTRIES[$cc];
            $dietAmt    = $cr['dietRate']    * $days;           // EUR/day × days
            $minWageAmt = $cr['minWageEUR']  * $hours * $days;  // EUR/h × h/day × days

            $dietTotal    += $dietAmt;
            $minWageTotal += $minWageAmt;

            $trasa[] = [
                'country'       => $cc,
                'days'          => $days,
                'hours'         => $hours,
                'operationType' => $op,
                'kilometers'    => $km,
                'dietAmount'    => round($dietAmt, 2),
                'minWageAmount' => round($minWageAmt, 2),
            ];
        }

        // Driver's base salary for delta calculation
        $drvStmt = $db->prepare('SELECT base_salary FROM drivers WHERE id=? AND company_id=? LIMIT 1');
        $drvStmt->execute([$driverId, $companyId]);
        $drvRow      = $drvStmt->fetch();
        $baseSalary  = $drvRow ? (float)($drvRow['base_salary'] ?? 0) : 0;

        // Insert – uses new columns if they exist (graceful fallback via column list)
        try {
            $db->prepare(
                'INSERT INTO delegations
                 (company_id, driver_id, vehicle_id, start_datetime, end_datetime,
                  route, countries, trasa, diet_total, min_wage_total, base_salary_pln,
                  mileage_km, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $companyId, $driverId, $vehicleId,
                $startDt, $endDt ?: null, $route,
                count($trasa) ? json_encode(array_column($trasa, 'country')) : null,
                count($trasa) ? json_encode($trasa) : null,
                $dietTotal > 0 ? round($dietTotal, 2) : null,
                $minWageTotal > 0 ? round($minWageTotal, 2) : null,
                $baseSalary > 0 ? $baseSalary : null,
                $mileage, $notes,
                (int)$_SESSION['user_id'],
            ]);
        } catch (\PDOException $ex) {
            // Migration 008 not yet run – fall back to legacy columns
            if (str_contains($ex->getMessage(), 'trasa') || str_contains($ex->getMessage(), 'min_wage')) {
                $db->prepare(
                    'INSERT INTO delegations
                     (company_id, driver_id, vehicle_id, start_datetime, end_datetime,
                      route, countries, diet_total, mileage_km, notes, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $companyId, $driverId, $vehicleId,
                    $startDt, $endDt ?: null, $route,
                    count($trasa) ? json_encode(array_column($trasa, 'country')) : null,
                    $dietTotal > 0 ? round($dietTotal, 2) : null,
                    $mileage, $notes,
                    (int)$_SESSION['user_id'],
                ]);
            } else {
                throw $ex;
            }
        }

        $msg = 'Delegacja dodana. Dieta: ' . number_format($dietTotal, 2) . ' EUR';
        if ($minWageTotal > 0) {
            $minWagePLN = $minWageTotal * $EUR_PLN;
            $delta      = $minWagePLN - $baseSalary;
            $msg .= ' | Min. płaca PM: ' . number_format($minWageTotal, 2) . ' EUR';
            if ($baseSalary > 0) {
                $msg .= ' | ' . ($delta > 0
                    ? '⚠️ Dopłata: +' . number_format($delta, 0) . ' PLN'
                    : '✅ Brak dopłaty');
            }
        }
        flashSet('success', $msg);
        redirect('/modules/delegation/');
    }

    if ($postAction === 'delete' && hasRole('admin')) {
        $id = (int)($_POST['delegation_id'] ?? 0);
        $db->prepare('DELETE FROM delegations WHERE id=? AND company_id=?')->execute([$id, $companyId]);
        flashSet('success', 'Delegacja usunięta.');
        redirect('/modules/delegation/');
    }
}

/* ── Load lists ──────────────────────────────────────────────── */
$stmt = $db->prepare('SELECT id, first_name, last_name, base_salary FROM drivers WHERE company_id=? AND is_active=1 ORDER BY last_name,first_name');
$stmt->execute([$companyId]);
$drivers = $stmt->fetchAll();

$stmt = $db->prepare('SELECT id, registration FROM vehicles WHERE company_id=? AND is_active=1 ORDER BY registration');
$stmt->execute([$companyId]);
$vehicles = $stmt->fetchAll();

/* Pagination */
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
     LIMIT ? OFFSET ?"
);
$listStmt->execute([$companyId, $pag['perPage'], $pag['offset']]);
$delegations = $listStmt->fetchAll();

$csrf       = getCsrfToken();
$pageTitle  = 'Delegacje';
$activePage = 'delegation';
include __DIR__ . '/../../templates/header.php';
?>

<div class="row g-4">
  <!-- ── Add delegation form ─────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="tp-card">
      <div class="tp-card-header">
        <i class="bi bi-plus-circle text-primary"></i>
        <span class="tp-card-title">Nowa delegacja</span>
      </div>
      <div class="tp-card-body">
        <form method="POST" novalidate id="delForm">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <input type="hidden" name="action"     value="add">

          <!-- Driver -->
          <div class="mb-2">
            <label class="form-label fw-600">Kierowca <span class="text-danger">*</span></label>
            <select name="driver_id" id="delDriverSel" class="form-select" required>
              <option value="">— Wybierz —</option>
              <?php foreach ($drivers as $d): ?>
              <option value="<?= $d['id'] ?>" data-salary="<?= (float)($d['base_salary']??0) ?>">
                <?= e($d['last_name'].' '.$d['first_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label fw-600">Wynagrodzenie bazowe (PLN/mc)</label>
            <input type="number" id="delBaseSalary" class="form-control form-control-sm" min="0" step="1" placeholder="Auto z karty kierowcy" readonly>
            <div class="form-text small">Pobierane z danych kierowcy; edytuj w Kierowcy → Edytuj.</div>
          </div>

          <!-- Vehicle -->
          <div class="mb-2">
            <label class="form-label fw-600">Pojazd</label>
            <select name="vehicle_id" class="form-select">
              <option value="">— Bez pojazdu —</option>
              <?php foreach ($vehicles as $v): ?>
              <option value="<?= $v['id'] ?>"><?= e($v['registration']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Dates -->
          <div class="row g-2 mb-2">
            <div class="col">
              <label class="form-label fw-600">Wyjazd <span class="text-danger">*</span></label>
              <input type="datetime-local" name="start_datetime" id="delStart" class="form-control" required>
            </div>
            <div class="col">
              <label class="form-label fw-600">Powrót</label>
              <input type="datetime-local" name="end_datetime" id="delEnd" class="form-control">
            </div>
          </div>

          <!-- Route description -->
          <div class="mb-2">
            <label class="form-label fw-600">Trasa (opis)</label>
            <input type="text" name="route" class="form-control form-control-sm" maxlength="500" placeholder="np. Warszawa – Berlin – Paryż">
          </div>

          <!-- Per-leg route builder -->
          <div class="mb-2">
            <label class="form-label fw-600">Etapy trasy <small class="text-muted">(kraj · dni · h/dzień · typ)</small></label>
            <div id="legContainer"></div>
            <button type="button" class="btn btn-sm btn-outline-primary mt-1 w-100" id="addLegBtn">
              <i class="bi bi-plus-circle me-1"></i>Dodaj etap
            </button>
          </div>

          <!-- Live calculation preview -->
          <div id="calcPreview" class="d-none alert alert-info py-2 small mb-2"></div>

          <!-- Extra -->
          <div class="mb-2">
            <label class="form-label fw-600">Przebieg (km)</label>
            <input type="number" name="mileage_km" class="form-control form-control-sm" min="0">
          </div>
          <div class="mb-3">
            <label class="form-label fw-600">Uwagi</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-check2 me-1"></i>Zapisz delegację
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Delegation list ─────────────────────────────────────── -->
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
                <th>Wyjazd</th>
                <th>Trasa</th>
                <th>Dieta</th>
                <th>Min.płaca</th>
                <th class="text-end">Akcje</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($delegations as $del):
                $trasa = null;
                if (!empty($del['trasa'])) {
                    $trasa = json_decode($del['trasa'], true);
                }
              ?>
              <tr>
                <td><?= e($del['last_name'].' '.$del['first_name']) ?></td>
                <td><small><?= htmlspecialchars(substr($del['start_datetime'],0,16), ENT_QUOTES, 'UTF-8') ?></small></td>
                <td>
                  <?php if ($trasa): ?>
                    <small><?php foreach ($trasa as $leg):
                      $cc = $COUNTRIES[$leg['country']] ?? null;
                    ?><span title="<?= e($cc ? $cc['name'] : $leg['country']) ?>"><?= e($cc ? $cc['flag'] : $leg['country']) ?></span><?php endforeach; ?></small>
                  <?php else: ?>
                    <small><?= e(mb_substr($del['route']??'—', 0, 30)) ?></small>
                  <?php endif; ?>
                </td>
                <td class="fw-600"><?= $del['diet_total'] ? number_format((float)$del['diet_total'],2).' EUR' : '—' ?></td>
                <td class="fw-600"><?= $del['min_wage_total'] ? number_format((float)$del['min_wage_total'],2).' EUR' : '—' ?></td>
                <td class="text-end">
                  <!-- Expand detail -->
                  <?php if ($trasa): ?>
                  <button type="button" class="btn btn-xs btn-outline-secondary me-1"
                          onclick="toggleLegDetail(this, 'det-<?= $del['id'] ?>', <?= htmlspecialchars(json_encode($trasa), ENT_QUOTES) ?>, <?= (float)($del['base_salary_pln']??0) ?>)">
                    <i class="bi bi-eye"></i>
                  </button>
                  <?php endif; ?>
                  <?php if (hasRole('admin')): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Usunąć delegację?')">
                    <input type="hidden" name="csrf_token"    value="<?= e($csrf) ?>">
                    <input type="hidden" name="action"        value="delete">
                    <input type="hidden" name="delegation_id" value="<?= $del['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
              <!-- Detail expansion row -->
              <tr class="del-detail-row d-none" id="det-<?= $del['id'] ?>">
                <td colspan="6" class="p-0">
                  <div class="del-detail-body p-3 bg-light border-top border-bottom small"></div>
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

<style>
.btn-xs{padding:.2rem .5rem;font-size:.8rem;}
.leg-row{background:#f8fafc;border:1px solid #dee2e6;border-radius:6px;padding:.5rem .75rem;margin-bottom:.4rem;}
</style>

<script>
(function(){
  /* ── Country data from PHP ────────────────────────────────── */
  const COUNTRIES = <?= json_encode($COUNTRIES, JSON_UNESCAPED_UNICODE) ?>;
  const OP_TYPES  = <?= json_encode($OPERATION_TYPES, JSON_UNESCAPED_UNICODE) ?>;
  const EUR_PLN   = <?= $EUR_PLN ?>;

  const legContainer = document.getElementById('legContainer');
  const addLegBtn    = document.getElementById('addLegBtn');
  const calcPreview  = document.getElementById('calcPreview');
  const driverSel    = document.getElementById('delDriverSel');
  const baseSalEl    = document.getElementById('delBaseSalary');

  /* build country select options */
  function ccOptions(selected) {
    return Object.entries(COUNTRIES).map(([code, c]) =>
      `<option value="${code}"${code===selected?' selected':''}>${c.flag} ${c.name}</option>`
    ).join('');
  }
  /* build op-type select options */
  function opOptions(selected) {
    return Object.entries(OP_TYPES).map(([v, l]) =>
      `<option value="${v}"${v===selected?' selected':''}>${l}</option>`
    ).join('');
  }

  function addLeg(cc='DE', days=1, hours=8, op='international', km=0) {
    const idx = legContainer.children.length;
    const div = document.createElement('div');
    div.className = 'leg-row d-flex align-items-center gap-2 flex-wrap';
    div.innerHTML =
      `<select name="leg_country[]" class="form-select form-select-sm" style="max-width:160px" onchange="recalc()">${ccOptions(cc)}</select>` +
      `<div class="d-flex align-items-center gap-1"><input type="number" name="leg_days[]" class="form-control form-control-sm" style="width:60px" min="1" max="365" value="${days}" onchange="recalc()"><span class="text-muted small">dni</span></div>` +
      `<div class="d-flex align-items-center gap-1"><input type="number" name="leg_hours[]" class="form-control form-control-sm" style="width:60px" min="1" max="24" step="0.5" value="${hours}" onchange="recalc()"><span class="text-muted small">h/dz.</span></div>` +
      `<select name="leg_optype[]" class="form-select form-select-sm" style="max-width:180px" onchange="recalc()">${opOptions(op)}</select>` +
      `<div class="d-flex align-items-center gap-1"><input type="number" name="leg_km[]" class="form-control form-control-sm" style="width:70px" min="0" value="${km}" onchange="recalc()"><span class="text-muted small">km</span></div>` +
      `<button type="button" class="btn btn-xs btn-outline-danger ms-auto" onclick="this.closest('.leg-row').remove();recalc()"><i class="bi bi-trash"></i></button>`;
    legContainer.appendChild(div);
    recalc();
  }

  addLegBtn.addEventListener('click', () => addLeg());

  /* auto-fill base salary when driver selected */
  driverSel.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    baseSalEl.value = opt ? (opt.dataset.salary || '') : '';
    recalc();
  });

  /* live calculation */
  window.recalc = function() {
    const rows = legContainer.querySelectorAll('.leg-row');
    let dietTotal = 0, minWage = 0;
    const breakdown = [];
    rows.forEach(row => {
      const cc    = row.querySelector('[name="leg_country[]"]').value;
      const days  = parseFloat(row.querySelector('[name="leg_days[]"]').value) || 1;
      const hours = parseFloat(row.querySelector('[name="leg_hours[]"]').value) || 8;
      const c     = COUNTRIES[cc];
      if (!c) return;
      const diet   = c.dietRate    * days;
      const mw     = c.minWageEUR  * hours * days;
      dietTotal   += diet;
      minWage     += mw;
      breakdown.push(`${c.flag} ${c.name}: dieta ${diet.toFixed(2)} EUR · min.wynagrodzenie ${mw.toFixed(2)} EUR`);
    });
    if (!breakdown.length) { calcPreview.classList.add('d-none'); return; }
    const baseSal = parseFloat(baseSalEl.value) || 0;
    const minWagePLN = minWage * EUR_PLN;
    const delta  = minWagePLN - baseSal;
    let html = `<strong>Łączna dieta:</strong> ${dietTotal.toFixed(2)} EUR &nbsp;|&nbsp; <strong>Min. płaca PM:</strong> ${minWage.toFixed(2)} EUR (≈ ${minWagePLN.toFixed(0)} PLN)`;
    if (baseSal > 0) {
      html += `<br><strong>${delta > 0 ? '⚠️ Dopłata' : '✅ Brak dopłaty'}:</strong> ${delta > 0 ? '+' : ''}${delta.toFixed(0)} PLN`;
    }
    html += `<details class="mt-1"><summary class="small text-muted cursor-pointer">Rozbicie per etap</summary><ul class="mb-0 mt-1 small">${breakdown.map(b=>`<li>${b}</li>`).join('')}</ul></details>`;
    calcPreview.innerHTML = html;
    calcPreview.classList.remove('d-none');
  };

  /* auto-compute days from start/end datetimes */
  function autoFillDays() {
    const s = document.getElementById('delStart').value;
    const e = document.getElementById('delEnd').value;
    if (!s || !e) return;
    const diff = (new Date(e) - new Date(s)) / (1000 * 60 * 60 * 24);
    if (diff > 0 && legContainer.children.length === 1) {
      const daysInput = legContainer.querySelector('[name="leg_days[]"]');
      if (daysInput) { daysInput.value = Math.ceil(diff); recalc(); }
    }
  }
  document.getElementById('delEnd').addEventListener('change', autoFillDays);

  /* start with one default leg */
  addLeg();

  /* ── Toggle detail row in list ─────────────────────────────── */
  window.toggleLegDetail = function(btn, rowId, trasa, baseSalary) {
    const detRow = document.getElementById(rowId);
    if (!detRow) return;
    const body = detRow.querySelector('.del-detail-body');
    if (detRow.classList.contains('d-none')) {
      // build breakdown
      let html = '<table class="table table-sm table-bordered mb-0"><thead><tr><th>Kraj</th><th>Typ</th><th>Dni</th><th>h/dz.</th><th>Km</th><th>Dieta EUR</th><th>Min.płaca EUR</th></tr></thead><tbody>';
      let totDiet=0, totMW=0;
      trasa.forEach(leg => {
        const c = COUNTRIES[leg.country];
        html += `<tr><td>${c ? c.flag+' '+c.name : leg.country}</td><td>${leg.operationType}</td><td>${leg.days}</td><td>${leg.hours}</td><td>${leg.kilometers||0}</td><td class="fw-bold text-primary">${(leg.dietAmount||0).toFixed(2)}</td><td class="fw-bold text-indigo">${(leg.minWageAmount||0).toFixed(2)}</td></tr>`;
        totDiet += leg.dietAmount||0; totMW += leg.minWageAmount||0;
      });
      html += `<tr class="table-light fw-bold"><td colspan="5">Łącznie</td><td class="text-primary">${totDiet.toFixed(2)}</td><td style="color:#4f46e5">${totMW.toFixed(2)}</td></tr>`;
      html += '</tbody></table>';
      if (baseSalary > 0) {
        const mwPLN = totMW * EUR_PLN, delta = mwPLN - baseSalary;
        html += `<div class="mt-2 small"><strong>Wynagrodzenie bazowe:</strong> ${baseSalary.toFixed(0)} PLN &nbsp;|&nbsp; <strong>Min. płaca PM:</strong> ${mwPLN.toFixed(0)} PLN`;
        html += `<span class="ms-2 badge ${delta>0?'bg-warning text-dark':'bg-success'}">${delta>0?'⚠️ Dopłata +'+Math.ceil(delta)+' PLN':'✅ Brak dopłaty'}</span></div>`;
      }
      body.innerHTML = html;
      detRow.classList.remove('d-none');
      btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    } else {
      detRow.classList.add('d-none');
      btn.innerHTML = '<i class="bi bi-eye"></i>';
    }
  };
})();
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
