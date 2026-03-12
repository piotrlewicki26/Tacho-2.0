  </div><!-- /.tp-content -->
</main><!-- /.tp-main -->

<!-- ═══ DDD UPLOAD MODAL ════════════════════════════════════════ -->
<div class="modal fade" id="dddUploadModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="/api/files.php" method="POST" enctype="multipart/form-data" id="dddUploadForm">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <input type="hidden" name="action" value="upload">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Wgraj plik DDD</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Typ pliku</label>
            <select name="file_type" class="form-select" required>
              <option value="driver">Karta kierowcy</option>
              <option value="vehicle">Urządzenie rejestrujące (pojazd)</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Plik DDD / C1B / TGD</label>
            <input type="file" name="ddd_file" class="form-control" accept=".ddd,.DDD,.c1b,.C1B,.tgd,.TGD" required>
            <div class="form-text">Obsługiwane formaty: .ddd, .c1b, .tgd (maks. 10 MB)</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Data pobrania</label>
            <input type="date" name="download_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Uwagi (opcjonalne)</label>
            <input type="text" name="notes" class="form-control" maxlength="500">
          </div>
          <div id="dddUploadMsg"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary" id="dddUploadBtn">
            <span class="spinner-border spinner-border-sm d-none me-1" id="dddUploadSpinner"></span>
            Wgraj
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- App JS -->
<script src="/assets/js/app.js"></script>
</body>
</html>
