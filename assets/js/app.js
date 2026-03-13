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

/* ── DDD Upload form – two-step preview then upload ─────────── */
(function () {
  const form          = document.getElementById('dddUploadForm');
  if (!form) return;

  const fileInput     = document.getElementById('dddFileInput');
  const fileTypeEl    = document.getElementById('dddFileType');
  const previewBtn    = document.getElementById('dddPreviewBtn');
  const previewSpinner= document.getElementById('dddPreviewSpinner');
  const previewError  = document.getElementById('dddPreviewError');
  const uploadBtn     = document.getElementById('dddUploadBtn');
  const uploadSpinner = document.getElementById('dddUploadSpinner');
  const backBtn       = document.getElementById('dddBackBtn');
  const msgBox        = document.getElementById('dddUploadMsg');
  const step1         = document.getElementById('dddStep1');
  const step2         = document.getElementById('dddStep2');
  const previewResult = document.getElementById('dddPreviewResult');

  // ── Reset modal to step 1 when it closes ──────────────────
  const modal = document.getElementById('dddUploadModal');
  if (modal) {
    modal.addEventListener('hidden.bs.modal', resetModal);
  }

  function resetModal() {
    step1.classList.remove('d-none');
    step2.classList.add('d-none');
    previewBtn.classList.remove('d-none');
    uploadBtn.classList.add('d-none');
    backBtn.classList.add('d-none');
    previewError.innerHTML = '';
    previewResult.innerHTML = '';
    msgBox.innerHTML = '';
    form.reset();
  }

  // ── Step 1: "Sprawdź plik" click → run preview ────────────
  previewBtn.addEventListener('click', async function () {
    previewError.innerHTML = '';
    if (!fileInput.files || !fileInput.files.length) {
      previewError.innerHTML = '<div class="alert alert-warning py-2">Wybierz plik DDD, aby go sprawdzić.</div>';
      return;
    }

    previewBtn.disabled = true;
    previewSpinner.classList.remove('d-none');

    try {
      // Build FormData manually – avoids Chrome edge-cases with form-element
      // FormData construction (e.g. required-file-input inside Bootstrap modal).
      const csrfEl  = form.querySelector('[name="csrf_token"]');
      const dlEl    = form.querySelector('[name="download_date"]');
      const notesEl = form.querySelector('[name="notes"]');

      const fd = new FormData();
      fd.append('action',        'preview');
      fd.append('csrf_token',    csrfEl  ? csrfEl.value  : '');
      fd.append('file_type',     fileTypeEl.value);
      fd.append('ddd_file',      fileInput.files[0], fileInput.files[0].name);
      if (dlEl)    fd.append('download_date', dlEl.value    || '');
      if (notesEl) fd.append('notes',         notesEl.value || '');

      const resp = await fetch(form.getAttribute('action'), {
        method:      'POST',
        body:        fd,
        credentials: 'same-origin',
      });
      const text = await resp.text();
      let data;
      try { data = JSON.parse(text); } catch (_) {
        previewError.innerHTML = `<div class="alert alert-danger py-2">Nieoczekiwana odpowiedź serwera (kod ${resp.status}).</div>`;
        return;
      }

      if (data.error) {
        previewError.innerHTML = `<div class="alert alert-danger py-2">${escHtml(data.error)}</div>`;
        return;
      }

      // ── Render preview panel ─────────────────────────────
      previewResult.innerHTML = buildPreviewHtml(data);

      // Show step 2
      step1.classList.add('d-none');
      step2.classList.remove('d-none');
      previewBtn.classList.add('d-none');
      backBtn.classList.remove('d-none');

      if (data.ok) {
        uploadBtn.classList.remove('d-none');
      }
      // If !data.ok, upload button stays hidden – user must fix the file

    } catch (err) {
      previewError.innerHTML = '<div class="alert alert-danger py-2">Błąd połączenia z serwerem. Sprawdź połączenie i spróbuj ponownie.</div>';
    } finally {
      previewBtn.disabled = false;
      previewSpinner.classList.add('d-none');
    }
  });

  // ── Back button ───────────────────────────────────────────
  backBtn.addEventListener('click', function () {
    step2.classList.add('d-none');
    step1.classList.remove('d-none');
    previewBtn.classList.remove('d-none');
    uploadBtn.classList.add('d-none');
    backBtn.classList.add('d-none');
    previewResult.innerHTML = '';
    msgBox.innerHTML = '';
  });

  // ── Step 2: actual upload ─────────────────────────────────
  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    uploadBtn.disabled = true;
    uploadSpinner.classList.remove('d-none');
    msgBox.innerHTML = '';

    try {
      // Build FormData manually for Chrome compatibility
      const csrfEl  = form.querySelector('[name="csrf_token"]');
      const dlEl    = form.querySelector('[name="download_date"]');
      const notesEl = form.querySelector('[name="notes"]');

      const fd = new FormData();
      fd.append('action',        'upload');
      fd.append('csrf_token',    csrfEl  ? csrfEl.value  : '');
      fd.append('file_type',     fileTypeEl.value);
      if (fileInput.files && fileInput.files.length) {
        fd.append('ddd_file', fileInput.files[0], fileInput.files[0].name);
      }
      if (dlEl)    fd.append('download_date', dlEl.value    || '');
      if (notesEl) fd.append('notes',         notesEl.value || '');

      const resp = await fetch(form.getAttribute('action'), {
        method:      'POST',
        body:        fd,
        credentials: 'same-origin',
      });
      const text = await resp.text();
      let data;
      try { data = JSON.parse(text); } catch (_) {
        msgBox.innerHTML = `<div class="alert alert-danger py-2">Nieoczekiwana odpowiedź serwera (kod ${resp.status}). Odśwież stronę i spróbuj ponownie.</div>`;
        return;
      }

      if (data.success) {
        msgBox.innerHTML = `<div class="alert alert-success py-2">${escHtml(data.message)}</div>`;
        setTimeout(() => {
          const bsModal = bootstrap.Modal.getInstance(document.getElementById('dddUploadModal'));
          bsModal?.hide();
          if (window.location.pathname === '/files.php') {
            window.location.reload();
          }
        }, 900);
      } else {
        msgBox.innerHTML = `<div class="alert alert-danger py-2">${escHtml(data.error || 'Błąd wgrywania pliku.')}</div>`;
      }
    } catch (err) {
      msgBox.innerHTML = '<div class="alert alert-danger py-2">Błąd połączenia z serwerem. Sprawdź połączenie i spróbuj ponownie.</div>';
    } finally {
      uploadBtn.disabled = false;
      uploadSpinner.classList.add('d-none');
    }
  });

  // ── Build HTML for the preview result panel ───────────────
  function buildPreviewHtml(data) {
    const info     = data.info     || {};
    const issues   = data.issues   || [];
    const warnings = data.warnings || [];
    const isDriver = info.file_type === 'driver';

    let html = '';

    // ── Compliance status banner ─────────────────────────────
    if (!data.ok) {
      html += `<div class="alert alert-danger d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-x-circle-fill flex-shrink-0 fs-5 mt-1"></i>
        <div><strong>Plik niezgodny</strong> – nie można wgrać.<ul class="mb-0 mt-1">`;
      for (const issue of issues) {
        html += `<li>${escHtml(issue)}</li>`;
      }
      html += `</ul></div></div>`;
    } else {
      html += `<div class="alert alert-success d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <strong>Plik wygląda poprawnie.</strong>
      </div>`;
    }

    // ── Warnings ─────────────────────────────────────────────
    if (warnings.length) {
      html += `<div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 fs-5 mt-1"></i>
        <div><strong>Uwagi:</strong><ul class="mb-0 mt-1">`;
      for (const w of warnings) {
        html += `<li>${escHtml(w)}</li>`;
      }
      html += `</ul></div></div>`;
    }

    // ── Extracted data table ─────────────────────────────────
    html += `<table class="table table-sm table-bordered mb-3">
      <tbody>`;

    html += row('Plik', escHtml(info.file_name || '—'));
    html += row('Rozmiar', info.file_size ? fmtBytes(info.file_size) : '—');
    html += row('Typ', isDriver ? 'Karta kierowcy' : 'Pojazd');

    if (isDriver) {
      const name = (info.first_name || info.last_name)
        ? escHtml((info.first_name || '') + ' ' + (info.last_name || '')).trim()
        : '<span class="text-danger fw-semibold">Brak danych</span>';
      html += row('Kierowca', name);

      const card = info.card_number
        ? `<code>${escHtml(info.card_number)}</code>`
        : '<span class="text-danger fw-semibold">Nie znaleziono</span>';
      html += row('Nr karty', card);

      if (info.existing_driver_name) {
        html += row('Powiązanie', `<span class="badge bg-success">Istniejący kierowca: ${escHtml(info.existing_driver_name)}</span>`);
      } else if (info.action_hint === 'auto_create') {
        html += row('Powiązanie', '<span class="badge bg-info text-dark">Nowy kierowca zostanie utworzony automatycznie</span>');
      }

      if (info.violations > 0) {
        html += row('Naruszenia', `<span class="badge bg-warning text-dark">${info.violations} naruszeń EU</span>`);
      }
    } else {
      const reg = info.registration
        ? `<code>${escHtml(info.registration)}</code>`
        : '<span class="text-danger fw-semibold">Nie znaleziono</span>';
      html += row('Nr rejestracyjny', reg);

      if (info.existing_vehicle_reg) {
        html += row('Powiązanie', `<span class="badge bg-success">Istniejący pojazd: ${escHtml(info.existing_vehicle_reg)}</span>`);
      } else if (info.action_hint === 'auto_create') {
        html += row('Powiązanie', '<span class="badge bg-info text-dark">Nowy pojazd zostanie utworzony automatycznie</span>');
      }

      if (info.total_km != null) {
        html += row('Łącznie km', escHtml(String(info.total_km)) + ' km');
      }
    }

    if (info.period_start && info.period_end) {
      html += row('Okres danych', escHtml(info.period_start) + ' – ' + escHtml(info.period_end));
    }
    if (info.day_count != null) {
      html += row('Liczba dni', escHtml(String(info.day_count)));
    }
    if (isDriver && info.drive_total_h != null) {
      html += row('Łącznie jazda', escHtml(String(info.drive_total_h)) + ' h');
    }

    html += `</tbody></table>`;
    return html;
  }

  function row(label, value) {
    return `<tr><th class="text-nowrap" style="width:40%">${escHtml(label)}</th><td>${value}</td></tr>`;
  }

  function fmtBytes(n) {
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024)    return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
  }
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
    const text = await resp.text();
    let data;
    try { data = JSON.parse(text); } catch (_) {
      alert('Nieoczekiwana odpowiedź serwera. Odśwież stronę i spróbuj ponownie.');
      return;
    }
    if (data.success) {
      row?.remove();
    } else {
      alert(data.error || 'Nie można usunąć pliku.');
    }
  } catch {
    alert('Błąd połączenia z serwerem. Spróbuj ponownie.');
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
