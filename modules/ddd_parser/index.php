<?php
/**
 * TachoPro 2.0 – Superadmin DDD File Parser (rozszerzone surowe dane)
 *
 * Wgrywa plik DDD / C1B / TGD i wyświetla pełny diagnostyczny rozkład:
 *  – Dane kierowcy (imię, nazwisko, nr karty, data urodzenia, nr pojazdu)
 *  – Podsumowanie czasu pracy (jazda / praca / dyspozycja / odpoczynek)
 *  – Naruszenia EU 561/2006
 *  – Aktywność dzienna z dokładnymi segmentami (surowe segs[])
 *  – Przekroczenia granic z surowymi polami (ts, typ, kod kraju)
 *  – Pełny zrzut JSON wszystkich odczytanych danych
 *  – Informacje binarne o pliku (rozmiar, hex dump pierwszych i ostatnich bajtów)
 *
 * Dostęp: wyłącznie superadmin.
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

/* ── Handle file upload ─────────────────────────────────────────────────── */
$parseResult    = null;
$driverInfo     = null;
$vehicleReg     = null;
$uploadError    = null;
$fileInfo       = null;
$rawHexHead     = null;   // hex dump of first 128 bytes
$rawHexTail     = null;   // hex dump of last 64 bytes
$fileSizeBytes  = 0;
$allBorderRaw   = [];     // all crossings flat with raw fields

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

        $ext = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));

        /* Validate MIME / magic bytes: EU tachograph DDD files are raw binary;
         * we accept octet-stream or any binary type reported by finfo.
         * We explicitly reject common script/document MIME types. */
        $finfo        = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($uploadedTmp);
        $blockedMimes = ['text/html', 'text/x-php', 'application/x-httpd-php',
                         'application/x-sh', 'text/x-shellscript'];
        $mimeBlocked  = in_array($detectedMime, $blockedMimes, true)
                     || str_starts_with($detectedMime, 'text/x-php')
                     || str_starts_with($detectedMime, 'application/x-httpd');

        if (!in_array($ext, ['ddd', 'c1b', 'tgd'], true)) {
            $uploadError = 'Nieobsługiwany format pliku. Dozwolone: .ddd, .c1b, .tgd';
        } elseif ($mimeBlocked) {
            $uploadError = 'Niedozwolony typ pliku (wykryty MIME: ' . htmlspecialchars($detectedMime, ENT_QUOTES) . ').';
        } elseif ($uploadedSize > 10 * 1024 * 1024) {
            $uploadError = 'Plik jest zbyt duży (maks. 10 MB).';
        } else {
            /* Use a dedicated subdirectory inside sys_get_temp_dir() that we
             * control, so it is not shared with arbitrary web-accessible dirs. */
            $tmpDir = sys_get_temp_dir() . '/tacho_parser';
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0700, true);
            }
            $tmpCopy = $tmpDir . '/parse_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($uploadedTmp, $tmpCopy)) {
                $uploadError = 'Nie udało się zapisać pliku tymczasowego.';
            } else {
                $parseResult   = parseDddFile($tmpCopy);
                $rawData       = file_get_contents($tmpCopy);
                if (!unlink($tmpCopy)) {
                    error_log('[tacho_parser] Failed to delete temp file: ' . $tmpCopy);
                }

                if ($rawData !== false) {
                    $driverInfo    = dddParseDriverInfo($rawData);
                    $vehicleReg    = dddParseVehicleReg($rawData);
                    $fileSizeBytes = strlen($rawData);

                    /* Hex dump: first 128 bytes */
                    $headBytes  = substr($rawData, 0, 128);
                    $rawHexHead = implode(' ', array_map(fn($b) => sprintf('%02X', ord($b)), str_split($headBytes)));

                    /* Hex dump: last 64 bytes */
                    $tailBytes  = substr($rawData, -64);
                    $rawHexTail = implode(' ', array_map(fn($b) => sprintf('%02X', ord($b)), str_split($tailBytes)));

                    /* Collect all border crossings with raw numeric fields */
                    $curYear = (int)gmdate('Y');
                    $bcAll   = parseBorderCrossings($rawData, $curYear - 5, $curYear + 1);
                    foreach ($bcAll as $date => $crList) {
                        foreach ($crList as $cr) {
                            $allBorderRaw[] = array_merge($cr, ['date' => $date]);
                        }
                    }
                }

                $fileInfo = [
                    'name' => htmlspecialchars($uploadedName, ENT_QUOTES),
                    'size' => number_format($uploadedSize),
                ];
            }
        }
    }
}

/* ── Helpers ─────────────────────────────────────────────────────────────── */
function fmtMin(int $min): string {
    return floor($min / 60) . 'h ' . ($min % 60) . 'm';
}

/** Return Bootstrap color class for activity code */
function actColor(int $act): string {
    return match ($act) {
        0 => 'success',   // REST
        1 => 'info',      // AVAIL
        2 => 'warning',   // WORK
        3 => 'danger',    // DRIVE
        default => 'secondary',
    };
}

/** Return Polish label for activity code */
function actLabel(int $act): string {
    return match ($act) {
        0 => 'Odpoczynek',
        1 => 'Dyspozycja',
        2 => 'Praca',
        3 => 'Jazda',
        default => '?',
    };
}

/* ── Template ────────────────────────────────────────────────────────────── */
$pageTitle = 'Parser DDD – Superadmin';
require __DIR__ . '/../../templates/header.php';
?>

<style>
.hex-dump { font-family: monospace; font-size: .75rem; word-break: break-all; background:#1e1e2e; color:#cdd6f4; padding:1rem; border-radius:.4rem; }
.seg-bar  { display:inline-block; height:14px; min-width:2px; }
</style>

<div class="container-fluid py-4">

  <h4 class="fw-bold mb-4">
    <i class="bi bi-cpu me-2 text-danger"></i>Parser pliku DDD
    <span class="badge bg-danger ms-2" style="font-size:.7em">Superadmin</span>
    <small class="text-muted fw-normal ms-2" style="font-size:.6em">— surowe dane</small>
  </h4>

  <!-- ── Upload form ─────────────────────────────────────────────────────── -->
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
      &nbsp;·&nbsp; <?= number_format($fileSizeBytes) ?> B
      &nbsp;·&nbsp; <?= count($days) ?> dni aktywności
      <?php if ($vehicleReg): ?>
        &nbsp;·&nbsp; <span class="badge bg-secondary"><?= htmlspecialchars($vehicleReg, ENT_QUOTES) ?></span>
      <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         SEKCJA 1: Dane kierowcy + podsumowanie + naruszenia
    ════════════════════════════════════════════════════════════════════ -->
    <div class="row g-3 mb-4">

      <!-- Driver info -->
      <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-header fw-semibold bg-light"><i class="bi bi-person-badge me-2"></i>Dane kierowcy / pojazdu</div>
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
            <p class="text-muted small mb-0">Nie udało się odczytać danych kierowcy.</p>
            <?php endif; ?>
            <?php if ($vehicleReg): ?>
            <hr class="my-2">
            <div class="small"><span class="text-muted">Nr rej. pojazdu:</span>
              <strong><?= htmlspecialchars($vehicleReg, ENT_QUOTES) ?></strong></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Summary -->
      <div class="col-md-4">
        <div class="card h-100 shadow-sm border-0">
          <div class="card-header fw-semibold bg-light"><i class="bi bi-bar-chart me-2"></i>Podsumowanie czasu</div>
          <div class="card-body">
            <dl class="row mb-0 small">
              <dt class="col-5 text-muted">Jazda łącznie</dt>
              <dd class="col-7 fw-bold text-danger"><?= fmtMin((int)($summary['drive'] ?? 0)) ?></dd>
              <dt class="col-5 text-muted">Praca łącznie</dt>
              <dd class="col-7 fw-bold text-warning"><?= fmtMin((int)($summary['work'] ?? 0)) ?></dd>
              <dt class="col-5 text-muted">Dyspozycja</dt>
              <dd class="col-7 fw-bold text-info"><?= fmtMin((int)($summary['avail'] ?? 0)) ?></dd>
              <dt class="col-5 text-muted">Odpoczynek</dt>
              <dd class="col-7 fw-bold text-success"><?= fmtMin((int)($summary['rest'] ?? 0)) ?></dd>
              <dt class="col-5 text-muted">Naruszenia</dt>
              <dd class="col-7">
                <?php if (count($allViol) > 0): ?>
                  <span class="badge bg-danger"><?= count($allViol) ?></span>
                <?php else: ?>
                  <span class="badge bg-success">0</span>
                <?php endif; ?>
              </dd>
              <dt class="col-5 text-muted">Przekrocz. granic</dt>
              <dd class="col-7"><span class="badge bg-primary"><?= count($allBorderRaw) ?></span></dd>
              <dt class="col-5 text-muted">Rozmiar pliku</dt>
              <dd class="col-7"><code><?= number_format($fileSizeBytes) ?> B</code></dd>
            </dl>
          </div>
        </div>
      </div>

      <!-- Violations -->
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

    </div><!-- /.row top cards -->

    <!-- ═══════════════════════════════════════════════════════════════════
         SEKCJA 2: Aktywność dzienna z surowymi segmentami
    ════════════════════════════════════════════════════════════════════ -->
    <?php if (!empty($days)): ?>
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header fw-semibold bg-light">
        <i class="bi bi-calendar3 me-2"></i>Aktywność dzienna — szczegóły segmentów
        <span class="badge bg-secondary ms-2"><?= count($days) ?> dni</span>
        <small class="text-muted ms-2 fw-normal">Kliknij wiersz aby rozwinąć surowe segmenty i przekroczenia granic</small>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 small">
          <thead class="table-light">
            <tr>
              <th style="width:100px">Data</th>
              <th class="text-center text-danger">Jazda</th>
              <th class="text-center text-warning">Praca</th>
              <th class="text-center text-info">Dyspoz.</th>
              <th class="text-center text-success">Odpocz.</th>
              <th class="text-center">Km</th>
              <th class="text-center">Segm.</th>
              <th class="text-center">Przekrocz.</th>
              <th class="text-center">Naruszenia</th>
              <th style="width:200px">Oś czasu</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($days as $idx => $day):
              $crossings = $day['crossings'] ?? [];
              $viols     = $day['viol']      ?? [];
              $segs      = $day['segs']      ?? [];
              $rowId     = 'day-' . str_replace('-', '', $day['date']);
            ?>
            <tr style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#<?= $rowId ?>">
              <td class="fw-semibold"><?= htmlspecialchars($day['date'], ENT_QUOTES) ?></td>
              <td class="text-center <?= ($day['drive'] ?? 0) > 540 ? 'text-danger fw-bold' : '' ?>">
                <?= fmtMin((int)($day['drive'] ?? 0)) ?>
              </td>
              <td class="text-center"><?= fmtMin((int)($day['work']  ?? 0)) ?></td>
              <td class="text-center"><?= fmtMin((int)($day['avail'] ?? 0)) ?></td>
              <td class="text-center"><?= fmtMin((int)($day['rest']  ?? 0)) ?></td>
              <td class="text-center"><?= (int)($day['dist'] ?? 0) ?></td>
              <td class="text-center"><span class="badge bg-light text-dark border"><?= count($segs) ?></span></td>
              <td class="text-center">
                <?= !empty($crossings)
                    ? '<span class="badge bg-primary">' . count($crossings) . '</span>'
                    : '<span class="text-muted">—</span>' ?>
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
              <td>
                <!-- Mini activity bar (1440 min = 100%) -->
                <div style="width:180px;height:14px;background:#e9ecef;border-radius:3px;overflow:hidden;display:flex">
                  <?php foreach ($segs as $seg):
                    $pct = round($seg['dur'] / 14.4, 1);
                  ?>
                    <span class="seg-bar bg-<?= actColor($seg['act']) ?>"
                          style="width:<?= $pct ?>%"
                          title="<?= actLabel($seg['act']) ?>: <?= $seg['start'] ?>–<?= $seg['end'] ?> min (<?= $seg['dur'] ?> min)"></span>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
            <!-- ── Collapsible detail row ──────────────────────────────── -->
            <tr class="collapse" id="<?= $rowId ?>">
              <td colspan="10" class="bg-light p-0">
                <div class="p-3">

                  <!-- Segments table -->
                  <div class="mb-3">
                    <strong class="small d-block mb-1">
                      <i class="bi bi-list-ol me-1"></i>Surowe segmenty aktywności (<?= count($segs) ?>):
                    </strong>
                    <?php if (!empty($segs)): ?>
                    <table class="table table-xs table-bordered mb-0 small" style="width:auto">
                      <thead class="table-secondary">
                        <tr>
                          <th>#</th>
                          <th>Kod</th>
                          <th>Aktywność</th>
                          <th>Start (min)</th>
                          <th>Koniec (min)</th>
                          <th>Czas trwania</th>
                          <th>Start (gg:mm)</th>
                          <th>Koniec (gg:mm)</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($segs as $si => $seg): ?>
                        <tr class="table-<?= actColor($seg['act']) ?> bg-opacity-25">
                          <td><?= $si + 1 ?></td>
                          <td><code><?= $seg['act'] ?></code></td>
                          <td class="fw-semibold"><?= actLabel($seg['act']) ?></td>
                          <td><?= $seg['start'] ?></td>
                          <td><?= $seg['end'] ?></td>
                          <td><?= fmtMin($seg['dur']) ?></td>
                          <td><?= sprintf('%02d:%02d', intdiv($seg['start'], 60), $seg['start'] % 60) ?></td>
                          <td><?= sprintf('%02d:%02d', intdiv($seg['end'],   60), $seg['end']   % 60) ?></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                    <?php else: ?>
                    <em class="text-muted small">Brak segmentów</em>
                    <?php endif; ?>
                  </div>

                  <!-- Border crossings raw table -->
                  <?php if (!empty($crossings)): ?>
                  <div class="mb-2">
                    <strong class="small d-block mb-1">
                      <i class="bi bi-geo-alt me-1"></i>Surowe dane przekroczeń granic (<?= count($crossings) ?>):
                    </strong>
                    <table class="table table-xs table-bordered mb-0 small" style="width:auto">
                      <thead class="table-secondary">
                        <tr>
                          <th>#</th>
                          <th>Unix TS</th>
                          <th>UTC Data/Czas</th>
                          <th>Min od półn.</th>
                          <th>Typ (raw)</th>
                          <th>Typ opis</th>
                          <th>Kraj</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($crossings as $ci => $cr): ?>
                        <tr>
                          <td><?= $ci + 1 ?></td>
                          <td><code><?= (int)($cr['ts'] ?? 0) ?></code></td>
                          <td><?= gmdate('Y-m-d H:i:s', (int)($cr['ts'] ?? 0)) ?></td>
                          <td><code><?= (int)($cr['tmin'] ?? 0) ?></code></td>
                          <td><code><?= (int)($cr['type'] ?? -1) ?></code></td>
                          <td><?= match((int)($cr['type'] ?? -1)) {
                                0 => 'Początek pracy/wjazd',
                                1 => 'Koniec pracy/wyjazd',
                                2 => 'Przerwa',
                                3 => 'Koniec okresu',
                                default => '?'
                              } ?></td>
                          <td><strong><?= htmlspecialchars($cr['country'] ?? '?', ENT_QUOTES) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                  <?php endif; ?>

                  <!-- Violations detail -->
                  <?php if (!empty($viols)): ?>
                  <div>
                    <strong class="small d-block mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Naruszenia:</strong>
                    <?php foreach ($viols as $v): ?>
                      <span class="badge bg-<?= ($v['type'] ?? '') === 'error' ? 'danger' : 'warning text-dark' ?> me-1">
                        <?= htmlspecialchars($v['msg'] ?? '', ENT_QUOTES) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════════
         SEKCJA 3: Wszystkie przekroczenia granic (tabela zbiorcza surowa)
    ════════════════════════════════════════════════════════════════════ -->
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header fw-semibold bg-light">
        <i class="bi bi-geo-alt me-2"></i>Wszystkie przekroczenia granic – surowe dane
        <span class="badge bg-primary ms-2"><?= count($allBorderRaw) ?></span>
      </div>
      <?php if (empty($allBorderRaw)): ?>
      <div class="card-body"><p class="text-muted small mb-0">Brak danych o przekroczeniach granic w tym pliku.</p></div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 small">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Data</th>
              <th>Unix TS</th>
              <th>UTC Data/Czas</th>
              <th>Min od północy</th>
              <th>Typ (raw int)</th>
              <th>Typ opis</th>
              <th>Kraj</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allBorderRaw as $bi => $cr): ?>
            <tr>
              <td><?= $bi + 1 ?></td>
              <td><?= htmlspecialchars($cr['date'] ?? '', ENT_QUOTES) ?></td>
              <td><code><?= (int)($cr['ts'] ?? 0) ?></code></td>
              <td><?= gmdate('Y-m-d H:i:s', (int)($cr['ts'] ?? 0)) ?></td>
              <td><code><?= (int)($cr['tmin'] ?? 0) ?></code></td>
              <td><code><?= (int)($cr['type'] ?? -1) ?></code></td>
              <td><?= match((int)($cr['type'] ?? -1)) {
                    0 => 'Początek pracy/wjazd',
                    1 => 'Koniec pracy/wyjazd',
                    2 => 'Przerwa',
                    3 => 'Koniec okresu',
                    default => '?'
                  } ?></td>
              <td><strong class="text-primary"><?= htmlspecialchars($cr['country'] ?? '?', ENT_QUOTES) ?></strong></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         SEKCJA 4: Binary file info + Hex dump
    ════════════════════════════════════════════════════════════════════ -->
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header fw-semibold bg-light">
        <i class="bi bi-hdd me-2"></i>Informacje binarne o pliku
      </div>
      <div class="card-body">
        <dl class="row small mb-2">
          <dt class="col-3 text-muted">Nazwa pliku</dt>
          <dd class="col-9"><code><?= $fileInfo['name'] ?></code></dd>
          <dt class="col-3 text-muted">Rozmiar (bajty)</dt>
          <dd class="col-9"><code><?= number_format($fileSizeBytes) ?></code></dd>
          <dt class="col-3 text-muted">Rozmiar (hex)</dt>
          <dd class="col-9"><code>0x<?= strtoupper(dechex($fileSizeBytes)) ?></code></dd>
        </dl>

        <div class="mb-2">
          <strong class="small">Pierwsze 128 bajtów (hex):</strong>
          <div class="hex-dump mt-1"><?= htmlspecialchars($rawHexHead ?? '', ENT_QUOTES) ?></div>
        </div>
        <div>
          <strong class="small">Ostatnie 64 bajty (hex):</strong>
          <div class="hex-dump mt-1"><?= htmlspecialchars($rawHexTail ?? '', ENT_QUOTES) ?></div>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════
         SEKCJA 5: Pełny zrzut JSON (surowe dane)
    ════════════════════════════════════════════════════════════════════ -->
    <div class="card shadow-sm border-0 mb-4">
      <div class="card-header fw-semibold bg-dark text-light" data-bs-toggle="collapse" data-bs-target="#jsonDump" style="cursor:pointer">
        <i class="bi bi-code-square me-2"></i>Pełny zrzut JSON – wszystkie odczytane dane
        <span class="badge bg-secondary ms-2">kliknij aby rozwinąć</span>
      </div>
      <div class="collapse" id="jsonDump">
        <div class="card-body p-0">
          <pre class="hex-dump mb-0" style="max-height:600px;overflow:auto"><?= htmlspecialchars(
            json_encode([
                'file'        => $fileInfo,
                'file_size_b' => $fileSizeBytes,
                'driver_info' => $driverInfo,
                'vehicle_reg' => $vehicleReg,
                'summary'     => $summary,
                'days'        => $days,
                'all_border_crossings' => $allBorderRaw,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ENT_QUOTES
          ) ?></pre>
        </div>
      </div>
    </div>

    <?php endif; /* no parse error */ ?>

  <?php endif; /* $parseResult !== null */ ?>

</div><!-- /.container-fluid -->

<?php require __DIR__ . '/../../templates/footer.php'; ?>
