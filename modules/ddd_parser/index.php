<?php
/**
 * TachoPro 2.0 – Superadmin DDD File Parser
 *
 * Uploads any DDD / C1B / TGD file and shows a full diagnostic breakdown:
 *  – Driver info extracted from the binary (name, card number, birth date)
 *  – Per-day activity table (drive / work / avail / rest, violations, crossings)
 *  – EU 561/2006 violation list
 *  – Border-crossing records per day
 *  – Raw parse statistics (file size, record count, year range)
 *
 * Access: superadmin only.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/license_check.php';

requireLogin();

if (!hasRole('superadmin')) {
    flashSet('danger', 'Dostęp tylko dla Superadmin.');
    redirect('/dashboard.php');
}

$activePage = 'ddd_parser';

/* ── Handle file upload ────────────────────────────────────────────────────── */
$parseResult  = null;
$driverInfo   = null;
$vehicleReg   = null;
$uploadError  = null;
$fileInfo     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* CSRF check */
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Invalid CSRF token');
    }

    if (empty($_FILES['ddd_file']['tmp_name']) || $_FILES['ddd_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Nie przesłano pliku lub wystąpił błąd przesyłania.';
    } else {
        $uploadedTmp  = $_FILES['ddd_file']['tmp_name'];
        $uploadedName = $_FILES['ddd_file']['name'];
        $uploadedSize = $_FILES['ddd_file']['size'];

        /* Validate extension */
        $ext = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['ddd', 'c1b', 'tgd'], true)) {
            $uploadError = 'Nieobsługiwany format pliku. Dozwolone: .ddd, .c1b, .tgd';
        } elseif ($uploadedSize > 10 * 1024 * 1024) {
            $uploadError = 'Plik jest zbyt duży (maks. 10 MB).';
        } else {
            /* Copy to a private temp location so we can read it safely */
            $tmpCopy = sys_get_temp_dir() . '/tacho_parse_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($uploadedTmp, $tmpCopy)) {
                $uploadError = 'Nie udało się zapisać pliku tymczasowego.';
            } else {
                /* Parse */
                $parseResult = parseDddFile($tmpCopy);
                $rawData     = file_get_contents($tmpCopy);
                @unlink($tmpCopy);

                if ($rawData !== false) {
                    $driverInfo = dddParseDriverInfo($rawData);
                    $vehicleReg = dddParseVehicleReg($rawData);
                }

                $fileInfo = [
                    'name' => htmlspecialchars($uploadedName, ENT_QUOTES),
                    'size' => number_format($uploadedSize),
                ];
            }
        }
    }
}

/* ── Helpers ────────────────────────────────────────────────────────────────── */
function fmtMin(int $min): string {
    return floor($min / 60) . 'h ' . ($min % 60) . 'm';
}

/* ── Template ──────────────────────────────────────────────────────────────── */
?>
<?php
$pageTitle = 'Parser DDD – Superadmin';
require __DIR__ . '/../../templates/header.php';
?>

<div class="container-fluid py-4">

  <h4 class="fw-bold mb-4">
    <i class="bi bi-cpu me-2 text-danger"></i>Parser pliku DDD
    <span class="badge bg-danger ms-2" style="font-size:.7em">Superadmin</span>
  </h4>

  <!-- ── Upload form ──────────────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-cloud-upload me-2"></i>Wgraj plik DDD do analizy</div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <div class="row g-3 align-items-end">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Plik DDD / C1B / TGD</label>
            <input type="file" name="ddd_file" class="form-control" accept=".ddd,.c1b,.tgd" required>
            <div class="form-text">Obsługiwane: .ddd, .c1b, .tgd — maks. 10 MB. Plik NIE jest zapisywany.</div>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-danger w-100">
              <i class="bi bi-search me-1"></i>Analizuj
            </button>
          </div>
        </div>
      </form>

      <?php if ($uploadError): ?>
      <div class="alert alert-danger mt-3 mb-0">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($uploadError, ENT_QUOTES) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($parseResult !== null): ?>

    <?php if (isset($parseResult['error'])): ?>
    <!-- ── Parse error ──────────────────────────────────────────────────── -->
    <div class="alert alert-danger">
      <i class="bi bi-x-circle-fill me-2"></i>
      <strong>Błąd parsowania:</strong> <?= htmlspecialchars($parseResult['error'], ENT_QUOTES) ?>
    </div>

    <?php else:
      $days    = $parseResult['days']    ?? [];
      $summary = $parseResult['summary'] ?? [];
      $allViol = $summary['violations']  ?? [];
    ?>

    <!-- ── File info bar ────────────────────────────────────────────────── -->
    <div class="alert alert-secondary py-2 mb-3">
      <i class="bi bi-file-binary me-1"></i>
      <strong><?= $fileInfo['name'] ?></strong>
      &nbsp;·&nbsp; <?= $fileInfo['size'] ?> B
      &nbsp;·&nbsp; <?= count($days) ?> dni aktywności
      <?php if ($vehicleReg): ?>
        &nbsp;·&nbsp; <span class="badge bg-secondary"><?= htmlspecialchars($vehicleReg, ENT_QUOTES) ?></span>
      <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">

      <!-- ── Driver info ────────────────────────────────────────────────── -->
      <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-header fw-semibold bg-light"><i class="bi bi-person-badge me-2"></i>Dane kierowcy</div>
          <div class="card-body">
            <?php if ($driverInfo): ?>
            <dl class="row mb-0 small">
              <dt class="col-5 text-muted">Nazwisko</dt>
              <dd class="col-7"><?= htmlspecialchars($driverInfo['last_name'], ENT_QUOTES) ?></dd>
              <dt class="col-5 text-muted">Imię</dt>
              <dd class="col-7"><?= htmlspecialchars($driverInfo['first_name'], ENT_QUOTES) ?></dd>
              <?php if ($driverInfo['card_number']): ?>
              <dt class="col-5 text-muted">Nr karty</dt>
              <dd class="col-7"><code><?= htmlspecialchars($driverInfo['card_number'], ENT_QUOTES) ?></code></dd>
              <?php endif; ?>
              <?php if ($driverInfo['birth_date']): ?>
              <dt class="col-5 text-muted">Data ur.</dt>
              <dd class="col-7"><?= htmlspecialchars($driverInfo['birth_date'], ENT_QUOTES) ?></dd>
              <?php endif; ?>
            </dl>
            <?php else: ?>
            <p class="text-muted small mb-0">Nie udało się odczytać danych kierowcy z pliku.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- ── Summary stats ──────────────────────────────────────────────── -->
      <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-header fw-semibold bg-light"><i class="bi bi-bar-chart me-2"></i>Podsumowanie czasu</div>
          <div class="card-body">
            <dl class="row mb-0 small">
              <dt class="col-5 text-muted">Jazda</dt>
              <dd class="col-7 fw-bold text-danger"><?= fmtMin((int)($summary['drive'] ?? 0)) ?></dd>
              <dt class="col-5 text-muted">Praca</dt>
              <dd class="col-7 fw-bold text-warning"><?= fmtMin((int)($summary['work'] ?? 0)) ?></dd>
              <dt class="col-5 text-muted">Dyspozycja</dt>
              <dd class="col-7 fw-bold text-info"><?= fmtMin((int)($summary['avail'] ?? 0)) ?></dd>
              <dt class="col-5 text-muted">Odpoczynek</dt>
              <dd class="col-7 fw-bold text-success"><?= fmtMin((int)($summary['rest'] ?? 0)) ?></dd>
              <dt class="col-5 text-muted">Naruszenia</dt>
              <dd class="col-7">
                <?php $vc = count($allViol); ?>
                <?php if ($vc > 0): ?>
                  <span class="badge bg-danger"><?= $vc ?></span>
                <?php else: ?>
                  <span class="badge bg-success">0</span>
                <?php endif; ?>
              </dd>
            </dl>
          </div>
        </div>
      </div>

      <!-- ── Violations ─────────────────────────────────────────────────── -->
      <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-header fw-semibold bg-light">
            <i class="bi bi-exclamation-triangle me-2"></i>Naruszenia EU 561
          </div>
          <div class="card-body p-0">
            <?php if (empty($allViol)): ?>
              <p class="text-success small p-3 mb-0"><i class="bi bi-check-circle me-1"></i>Brak naruszeń</p>
            <?php else: ?>
              <ul class="list-group list-group-flush small">
                <?php foreach ($allViol as $v): ?>
                  <li class="list-group-item py-1 px-3 <?= ($v['type'] ?? '') === 'error' ? 'list-group-item-danger' : 'list-group-item-warning' ?>">
                    <span class="text-muted me-1"><?= htmlspecialchars($v['date'] ?? '', ENT_QUOTES) ?></span>
                    <?= htmlspecialchars($v['msg'] ?? '', ENT_QUOTES) ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div><!-- /.row -->

    <!-- ── Per-day activity table ─────────────────────────────────────── -->
    <?php if (!empty($days)): ?>
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header fw-semibold bg-light">
        <i class="bi bi-calendar3 me-2"></i>Aktywność dzienna (<?= count($days) ?> dni)
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 small">
          <thead class="table-light">
            <tr>
              <th>Data</th>
              <th class="text-center text-danger">Jazda</th>
              <th class="text-center text-warning">Praca</th>
              <th class="text-center text-info">Dyspoz.</th>
              <th class="text-center text-success">Odpocz.</th>
              <th class="text-center">Km</th>
              <th class="text-center">Przekroczenia</th>
              <th class="text-center">Naruszenia</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($days as $day):
              $crossings = $day['crossings'] ?? [];
              $viols     = $day['viol']      ?? [];
            ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($day['date'], ENT_QUOTES) ?></td>
              <td class="text-center <?= ($day['drive'] ?? 0) > 540 ? 'text-danger fw-bold' : '' ?>">
                <?= fmtMin((int)($day['drive'] ?? 0)) ?>
              </td>
              <td class="text-center"><?= fmtMin((int)($day['work']  ?? 0)) ?></td>
              <td class="text-center"><?= fmtMin((int)($day['avail'] ?? 0)) ?></td>
              <td class="text-center"><?= fmtMin((int)($day['rest']  ?? 0)) ?></td>
              <td class="text-center"><?= (int)($day['dist'] ?? 0) ?></td>
              <td class="text-center">
                <?php if (!empty($crossings)): ?>
                  <button class="btn btn-sm btn-outline-primary py-0"
                          data-bs-toggle="collapse"
                          data-bs-target="#cr-<?= str_replace('-', '', $day['date']) ?>">
                    <?= count($crossings) ?> <i class="bi bi-chevron-down"></i>
                  </button>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if (!empty($viols)): ?>
                  <span class="badge bg-<?= ($viols[0]['type'] ?? '') === 'error' ? 'danger' : 'warning text-dark' ?>">
                    <?= count($viols) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php if (!empty($crossings)): ?>
            <tr class="collapse" id="cr-<?= str_replace('-', '', $day['date']) ?>">
              <td colspan="8" class="bg-light">
                <div class="px-3 py-2">
                  <strong class="small">Przekroczenia granic <?= htmlspecialchars($day['date'], ENT_QUOTES) ?>:</strong>
                  <ul class="list-inline mb-0 mt-1">
                    <?php foreach ($crossings as $cr): ?>
                    <li class="list-inline-item">
                      <span class="badge bg-primary">
                        <?= htmlspecialchars($cr['country'] ?? '?', ENT_QUOTES) ?>
                        &nbsp;
                        <?= gmdate('H:i', ($cr['ts'] ?? 0)) ?>
                        <?php if (($cr['type'] ?? -1) === 0): ?>(wjazd)<?php elseif (($cr['type'] ?? -1) === 1): ?>(wyjazd)<?php endif; ?>
                      </span>
                    </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; /* no parse error */ ?>

  <?php endif; /* $parseResult !== null */ ?>

</div><!-- /.container-fluid -->

<?php require __DIR__ . '/../../templates/footer.php'; ?>
