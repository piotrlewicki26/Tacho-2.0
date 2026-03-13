  </div><!-- /.tp-content -->
</main><!-- /.tp-main -->

<!-- ═══ DDD UPLOAD MODAL ════════════════════════════════════════ -->
<div class="modal fade" id="dddUploadModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form action="/api/files.php" method="POST" enctype="multipart/form-data" id="dddUploadForm">
        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
        <input type="hidden" name="action" value="upload">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Wgraj plik DDD</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">

          <!-- ── STEP 1: file selection ─────────────────────────── -->
          <div id="dddStep1">
            <div class="mb-3">
              <label class="form-label fw-semibold">Typ pliku</label>
              <select name="file_type" id="dddFileType" class="form-select">
                <option value="driver">Karta kierowcy</option>
                <option value="vehicle">Urządzenie rejestrujące (pojazd)</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Plik DDD / C1B / TGD</label>
              <input type="file" name="ddd_file" id="dddFileInput" class="form-control"
                     accept=".ddd,.c1b,.tgd">
              <!-- Chrome handles extensions case-insensitively; server validates regardless -->
              <div class="form-text">Obsługiwane formaty: .ddd, .c1b, .tgd (maks. 10 MB)</div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Data pobrania</label>
              <input type="date" name="download_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Uwagi (opcjonalne)</label>
              <input type="text" name="notes" class="form-control" maxlength="500">
            </div>
            <div id="dddPreviewError"></div>
          </div><!-- /#dddStep1 -->

          <!-- ── STEP 2: preview results ────────────────────────── -->
          <div id="dddStep2" class="d-none">
            <div id="dddPreviewResult"></div>
          </div><!-- /#dddStep2 -->

          <!-- Final upload message -->
          <div id="dddUploadMsg"></div>

        </div><!-- /.modal-body -->
        <div class="modal-footer d-flex justify-content-between">
          <div>
            <!-- Back button (step 2 only) -->
            <button type="button" class="btn btn-outline-secondary d-none" id="dddBackBtn">
              <i class="bi bi-arrow-left me-1"></i>Wróć
            </button>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
            <!-- Preview button (step 1) -->
            <button type="button" class="btn btn-primary" id="dddPreviewBtn">
              <span class="spinner-border spinner-border-sm d-none me-1" id="dddPreviewSpinner"></span>
              <i class="bi bi-search me-1"></i>Sprawdź plik
            </button>
            <!-- Upload button (step 2, initially hidden) -->
            <button type="submit" class="btn btn-success d-none" id="dddUploadBtn">
              <span class="spinner-border spinner-border-sm d-none me-1" id="dddUploadSpinner"></span>
              <i class="bi bi-cloud-upload me-1"></i>Wgraj
            </button>
          </div>
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
