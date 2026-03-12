/* TachoPro 2.0 – Client-side JavaScript */
'use strict';

/* ── Sidebar toggle ─────────────────────────────────────────── */
(function () {
  const toggle  = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (!toggle) return;

  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  toggle.addEventListener('click', () => {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  overlay.addEventListener('click', closeSidebar);
})();

/* ── DDD Upload form ────────────────────────────────────────── */
(function () {
  const form    = document.getElementById('dddUploadForm');
  if (!form) return;
  const btn     = document.getElementById('dddUploadBtn');
  const spinner = document.getElementById('dddUploadSpinner');
  const msgBox  = document.getElementById('dddUploadMsg');

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    btn.disabled = true;
    spinner.classList.remove('d-none');
    msgBox.innerHTML = '';

    try {
      const fd   = new FormData(form);
      const resp = await fetch(form.action, { method: 'POST', body: fd });
      const data = await resp.json();

      if (data.success) {
        msgBox.innerHTML = `<div class="alert alert-success py-2">${escHtml(data.message)}</div>`;
        form.reset();
        // Refresh page archive if on files.php
        if (window.location.pathname === '/files.php') {
          setTimeout(() => window.location.reload(), 800);
        }
      } else {
        msgBox.innerHTML = `<div class="alert alert-danger py-2">${escHtml(data.error || 'Błąd wgrywania pliku.')}</div>`;
      }
    } catch (err) {
      msgBox.innerHTML = `<div class="alert alert-danger py-2">Błąd sieci. Spróbuj ponownie.</div>`;
    } finally {
      btn.disabled = false;
      spinner.classList.add('d-none');
    }
  });
})();

/* ── File delete confirmation ───────────────────────────────── */
document.addEventListener('click', async function (e) {
  const btn = e.target.closest('[data-delete-file]');
  if (!btn) return;
  if (!confirm('Czy na pewno chcesz usunąć ten plik z archiwum?')) return;

  const fileId = btn.dataset.deleteFile;
  const csrf   = btn.dataset.csrf;
  const row    = btn.closest('tr');

  try {
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('file_id', fileId);
    fd.append('csrf_token', csrf);
    const resp = await fetch('/api/files.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (data.success) {
      row?.remove();
    } else {
      alert(data.error || 'Nie można usunąć pliku.');
    }
  } catch {
    alert('Błąd sieci. Spróbuj ponownie.');
  }
});

/* ── Auto-dismiss flash alerts ──────────────────────────────── */
document.querySelectorAll('.alert-dismissible').forEach(el => {
  setTimeout(() => {
    const bs = bootstrap.Alert.getOrCreateInstance(el);
    bs?.close();
  }, 5000);
});

/* ── Utility: escape HTML ───────────────────────────────────── */
function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/* ── Per-page selector ──────────────────────────────────────── */
document.querySelectorAll('[data-perpage-select]').forEach(sel => {
  sel.addEventListener('change', function () {
    const url = new URL(window.location.href);
    url.searchParams.set('perPage', this.value);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
  });
});
