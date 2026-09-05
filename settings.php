<?php
/**
 * TachoPro 2.0 – Settings
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/license_check.php';

requireLogin();
requireModule('core');

$db        = getDB();
$companyId = (int)$_SESSION['company_id'];
$userId    = (int)$_SESSION['user_id'];

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        flashSet('danger', 'Nieprawidłowy token CSRF.');
        redirect('/settings.php');
    }

    $tab = $_POST['tab'] ?? 'profile';

    if ($tab === 'profile') {
        $newEmail = trim($_POST['email'] ?? '');
        if ($newEmail && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            flashSet('danger', 'Nieprawidłowy adres e-mail.');
            redirect('/settings.php');
        }
        $db->prepare('UPDATE users SET email=? WHERE id=?')->execute([$newEmail ?: null, $userId]);
        flashSet('success', 'Profil zaktualizowany.');
    }

    if ($tab === 'password') {
        $current  = $_POST['current_pass'] ?? '';
        $newPass  = $_POST['new_pass']     ?? '';
        $newPass2 = $_POST['new_pass2']    ?? '';

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id=?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($current, $u['password_hash'])) {
            flashSet('danger', 'Aktualne hasło jest nieprawidłowe.');
            redirect('/settings.php');
        }
        if (strlen($newPass) < 10) {
            flashSet('danger', 'Nowe hasło musi mieć co najmniej 10 znaków.');
            redirect('/settings.php');
        }
        if ($newPass !== $newPass2) {
            flashSet('danger', 'Nowe hasła nie są zgodne.');
            redirect('/settings.php');
        }
        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $userId]);
        flashSet('success', 'Hasło zostało zmienione.');
    }

    if ($tab === 'groups' && hasRole('admin')) {
        $groupName = trim($_POST['group_name'] ?? '');
        if ($groupName) {
            $db->prepare('INSERT INTO driver_groups (company_id, name) VALUES (?,?)')->execute([$companyId, $groupName]);
            flashSet('success', 'Grupa kierowców dodana.');
        }
    }

    if ($tab === 'delete_group' && hasRole('admin')) {
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid) {
            $db->prepare('DELETE FROM driver_groups WHERE id=? AND company_id=?')->execute([$gid, $companyId]);
            flashSet('success', 'Grupa usunięta.');
        }
    }

    if ($tab === 'users' && hasRole('admin')) {
        $uname = trim($_POST['username'] ?? '');
        $uemail = trim($_POST['user_email'] ?? '');
        $upass  = $_POST['user_pass'] ?? '';
        $urole  = in_array($_POST['user_role'] ?? '', ['admin','manager','viewer']) ? $_POST['user_role'] : 'viewer';

        if ($uname && $upass && strlen($upass) >= 10) {
            $hash = password_hash($upass, PASSWORD_BCRYPT, ['cost' => 12]);
            try {
                $db->prepare('INSERT INTO users (company_id, username, email, password_hash, role) VALUES (?,?,?,?,?)')
                   ->execute([$companyId, $uname, $uemail ?: null, $hash, $urole]);
                flashSet('success', 'Użytkownik dodany.');
            } catch (PDOException $e) {
                flashSet('danger', 'Login już istnieje.');
            }
        } else {
            flashSet('danger', 'Wypełnij login i hasło (min. 10 znaków).');
        }
    }

    redirect('/settings.php');
}

// ── Load data ─────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM users WHERE id=?');
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();

$groups = [];
$stmt = $db->prepare('SELECT * FROM driver_groups WHERE company_id=? ORDER BY name');
$stmt->execute([$companyId]);
$groups = $stmt->fetchAll();

$users = [];
if (hasRole('admin')) {
    $stmt = $db->prepare('SELECT id, username, email, role, is_active, last_login FROM users WHERE company_id=? ORDER BY username');
    $stmt->execute([$companyId]);
    $users = $stmt->fetchAll();
}

$csrf       = getCsrfToken();
$pageTitle  = 'Ustawienia';
$activePage = 'settings';
include __DIR__ . '/templates/header.php';
?>

<ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-profile">
    <i class="bi bi-person me-1"></i>Profil
  </button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-password">
    <i class="bi bi-key me-1"></i>Hasło
  </button></li>
  <?php if (hasRole('admin')): ?>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-groups">
    <i class="bi bi-collection me-1"></i>Grupy kierowców
  </button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-users">
    <i class="bi bi-people me-1"></i>Użytkownicy
  </button></li>
  <?php endif; ?>
</ul>

<div class="tab-content">

  <!-- Profile tab -->
  <div class="tab-pane fade show active" id="tab-profile">
    <div class="row">
      <div class="col-lg-5 col-xl-4">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-person text-primary"></i>
            <span class="tp-card-title">Mój profil</span>
          </div>
          <div class="tp-card-body">
            <form method="POST" novalidate>
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="tab" value="profile">
              <div class="mb-3">
                <label class="form-label fw-600">Login</label>
                <input type="text" class="form-control" value="<?= e($currentUser['username']) ?>" disabled>
              </div>
              <div class="mb-3">
                <label class="form-label fw-600">E-mail</label>
                <input type="email" name="email" class="form-control"
                       value="<?= e($currentUser['email'] ?? '') ?>">
              </div>
              <div class="mb-3">
                <label class="form-label fw-600">Rola</label>
                <input type="text" class="form-control" value="<?= e($currentUser['role']) ?>" disabled>
              </div>
              <button type="submit" class="btn btn-primary">Zapisz</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Password tab -->
  <div class="tab-pane fade" id="tab-password">
    <div class="row">
      <div class="col-lg-5 col-xl-4">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-key text-warning"></i>
            <span class="tp-card-title">Zmień hasło</span>
          </div>
          <div class="tp-card-body">
            <form method="POST" novalidate>
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="tab" value="password">
              <div class="mb-3">
                <label class="form-label fw-600">Aktualne hasło</label>
                <input type="password" name="current_pass" class="form-control" required autocomplete="current-password">
              </div>
              <div class="mb-3">
                <label class="form-label fw-600">Nowe hasło (min. 10 znaków)</label>
                <input type="password" name="new_pass" class="form-control" required minlength="10" autocomplete="new-password">
              </div>
              <div class="mb-3">
                <label class="form-label fw-600">Powtórz nowe hasło</label>
                <input type="password" name="new_pass2" class="form-control" required autocomplete="new-password">
              </div>
              <button type="submit" class="btn btn-warning text-white">Zmień hasło</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if (hasRole('admin')): ?>
  <!-- Groups tab -->
  <div class="tab-pane fade" id="tab-groups">
    <div class="row g-4">
      <div class="col-lg-4 col-xl-3">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-plus-circle text-primary"></i>
            <span class="tp-card-title">Dodaj grupę</span>
          </div>
          <div class="tp-card-body">
            <form method="POST" novalidate>
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="tab" value="groups">
              <div class="input-group">
                <input type="text" name="group_name" class="form-control" placeholder="Nazwa grupy…" required maxlength="100">
                <button type="submit" class="btn btn-primary">Dodaj</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-5 col-xl-4">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-collection text-secondary"></i>
            <span class="tp-card-title">Grupy kierowców</span>
          </div>
          <div class="tp-card-body p-0">
            <table class="tp-table">
              <tbody>
                <?php foreach ($groups as $g): ?>
                <tr>
                  <td><?= e($g['name']) ?></td>
                  <td class="text-end">
                    <form method="POST" class="d-inline" onsubmit="return confirm('Usunąć grupę?')">
                      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                      <input type="hidden" name="tab" value="delete_group">
                      <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                      <button type="submit" class="btn btn-xs btn-outline-danger">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$groups): ?>
                <tr><td class="text-center text-muted py-3">Brak grup</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Users tab -->
  <div class="tab-pane fade" id="tab-users">
    <div class="row g-4">
      <div class="col-lg-4 col-xl-3">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-person-plus text-primary"></i>
            <span class="tp-card-title">Dodaj użytkownika</span>
          </div>
          <div class="tp-card-body">
            <form method="POST" novalidate>
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
              <input type="hidden" name="tab" value="users">
              <div class="mb-2">
                <label class="form-label fw-600">Login</label>
                <input type="text" name="username" class="form-control" required maxlength="100">
              </div>
              <div class="mb-2">
                <label class="form-label fw-600">E-mail</label>
                <input type="email" name="user_email" class="form-control" maxlength="255">
              </div>
              <div class="mb-2">
                <label class="form-label fw-600">Hasło (min. 10 zn.)</label>
                <input type="password" name="user_pass" class="form-control" required minlength="10" autocomplete="new-password">
              </div>
              <div class="mb-3">
                <label class="form-label fw-600">Rola</label>
                <select name="user_role" class="form-select">
                  <option value="viewer">Przeglądający</option>
                  <option value="manager">Kierownik</option>
                  <?php if (hasRole('superadmin')): ?>
                  <option value="admin">Admin</option>
                  <?php endif; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-primary w-100">Dodaj użytkownika</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-8 col-xl-9">
        <div class="tp-card">
          <div class="tp-card-header">
            <i class="bi bi-people text-secondary"></i>
            <span class="tp-card-title">Użytkownicy</span>
          </div>
          <div class="tp-card-body p-0">
            <div class="table-responsive">
              <table class="tp-table">
                <thead>
                  <tr><th>Login</th><th>E-mail</th><th>Rola</th><th>Ostatnie logowanie</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $u): ?>
                  <tr>
                    <td><?= e($u['username']) ?> <?= $u['id']==$userId?'<span class="badge bg-primary ms-1">Ty</span>':'' ?></td>
                    <td><?= e($u['email'] ?? '—') ?></td>
                    <td><span class="badge bg-secondary"><?= e($u['role']) ?></span></td>
                    <td><small class="text-muted"><?= $u['last_login'] ? htmlspecialchars(substr($u['last_login'],0,16), ENT_QUOTES, 'UTF-8') : '—' ?></small></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>.btn-xs{padding:.2rem .5rem;font-size:.8rem;}</style>
<?php include __DIR__ . '/templates/footer.php'; ?>
