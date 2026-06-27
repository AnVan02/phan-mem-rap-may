<?php
require "thanh-dieu-huong.php";
require "config.php";

$orders = [];
$preselect_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pdo) {
    $stmt = $pdo->query("SELECT id_donhang, ma_don_hang, ten_khach_hang FROM donhang ORDER BY id_donhang DESC");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<link rel="stylesheet" href="./css/import-excel.css">

<main class="main-import">
    <nav class="breadcrumb">
        <a href="dashboard-ke-toan.php">Dashboard</a>
        <span><i class="fa-solid fa-chevron-right"></i></span>
        <span class="active">Kiểm Tra Excel</span>
    </nav>

    <div class="page-header">
        <div class="page-header-icon"><i class="fa-solid fa-file-circle-check"></i></div>
        <div class="page-header-text">
            <h1>Import & Kiểm Tra Excel</h1>
            <p>Tải lên file Excel để kiểm tra IMEI, Số máy và Serial linh kiện theo đơn hàng</p>
        </div>
    </div>

    <!-- Bước 1: Chọn đơn hàng & upload file -->
    <div class="card" id="card-upload">
        <div class="card-header">
            <span class="step-num">1</span>
            <span class="card-title-text">Chọn đơn hàng & tải file Excel</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <label for="order-select"><i class="fa-solid fa-file-lines"></i> Đơn hàng</label>
                <select id="order-select">
                    <option value="">-- Chọn đơn hàng cần kiểm tra --</option>
                    <?php foreach ($orders as $o): ?>
                    <option value="<?= (int)$o['id_donhang'] ?>"
                        <?= ($preselect_id === (int)$o['id_donhang']) ? 'selected' : '' ?>>
                        #<?= htmlspecialchars($o['ma_don_hang']) ?> — <?= htmlspecialchars($o['ten_khach_hang']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="drop-zone" id="drop-zone">
                <input type="file" id="file-input" accept=".xlsx,.xls" hidden>
                <div class="drop-icon"><i class="fa-solid fa-file-excel"></i></div>
                <div class="drop-text">Kéo thả file Excel vào đây hoặc</div>
                <label for="file-input" class="btn-browse">Chọn file</label>
                <div class="drop-hint">Hỗ trợ .xlsx và .xls</div>
            </div>

            <div class="file-preview" id="file-preview" style="display:none">
                <i class="fa-solid fa-file-excel"></i>
                <span id="file-name-display"></span>
                <span id="file-size-display" class="file-size"></span>
                <button type="button" class="btn-remove" id="btn-remove-file" title="Xoá file">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <button type="button" class="btn-check" id="btn-check" disabled>
                <i class="fa-solid fa-magnifying-glass-chart"></i>
                Xem trước
            </button>
        </div>
    </div>

    <!-- Loading spinner -->
    <div class="loading-overlay" id="loading-overlay" style="display:none">
        <div class="spinner-box">
            <div class="spinner"></div>
            <span id="loading-text">Đang kiểm tra dữ liệu...</span>
        </div>
    </div>

    <!-- Bước 2: Xem trước -->
    <div class="card" id="card-results" style="display:none">
        <div class="card-header">
            <span class="step-num">2</span>
            <span class="card-title-text">Xem trước dữ liệu</span>
        </div>
        <div class="card-body">
            <div class="summary-row" id="summary-row"></div>
            <div class="legend-row">
                <span class="legend ok"><i class="fa-solid fa-circle-check"></i> IMEI khớp — sẽ được nhập</span>
                <span class="legend error"><i class="fa-solid fa-circle-xmark"></i> IMEI không khớp — bỏ qua</span>
                <span class="legend warn"><i class="fa-solid fa-circle-minus"></i> Không có IMEI — vẫn nhập</span>
            </div>
            <div class="results-table-wrap">
                <table class="results-table" id="results-table">
                    <thead id="results-thead"></thead>
                    <tbody id="results-tbody"></tbody>
                </table>
            </div>
            <div class="import-action-row" id="import-action-row" style="display:none">
                <button type="button" class="btn-import" id="btn-import">
                    <i class="fa-solid fa-file-import"></i>
                    <span id="btn-import-label">Xác nhận nhập dữ liệu</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Bước 3: Kết quả nhập -->
    <div class="card" id="card-import-result" style="display:none">
        <div class="card-header">
            <span class="step-num">3</span>
            <span class="card-title-text">Kết quả nhập dữ liệu</span>
        </div>
        <div class="card-body">
            <div class="summary-row" id="import-summary-row"></div>
            <div class="results-table-wrap">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Số máy</th>
                            <th>Cấu hình</th>
                            <th>IMEI</th>
                            <th>Serial đã nhập</th>
                            <th>Không tìm thấy slot</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody id="import-result-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
(function() {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const filePreview = document.getElementById('file-preview');
    const fileNameDisplay = document.getElementById('file-name-display');
    const fileSizeDisplay = document.getElementById('file-size-display');
    const btnRemove = document.getElementById('btn-remove-file');
    const btnCheck = document.getElementById('btn-check');
    const orderSelect = document.getElementById('order-select');
    const loadingOverlay = document.getElementById('loading-overlay');
    const loadingText = document.getElementById('loading-text');
    const cardResults = document.getElementById('card-results');
    const summaryRow = document.getElementById('summary-row');
    const resultsThead = document.getElementById('results-thead');
    const resultsTbody = document.getElementById('results-tbody');
    const importActionRow = document.getElementById('import-action-row');
    const btnImport = document.getElementById('btn-import');
    const btnImportLabel = document.getElementById('btn-import-label');
    const cardImportResult = document.getElementById('card-import-result');
    const importSummaryRow = document.getElementById('import-summary-row');
    const importResultTbody = document.getElementById('import-result-tbody');

    let selectedFile = null;
    let previewData = null; // kết quả từ check (xem trước)

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function setFile(file) {
        if (!file) return;
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'xls'].includes(ext)) {
            alert('Chỉ hỗ trợ file .xlsx hoặc .xls');
            return;
        }
        selectedFile = file;
        fileNameDisplay.textContent = file.name;
        fileSizeDisplay.textContent = formatSize(file.size);
        dropZone.style.display = 'none';
        filePreview.style.display = 'flex';
        updateCheckBtn();
    }

    function clearFile() {
        selectedFile = null;
        fileInput.value = '';
        dropZone.style.display = 'flex';
        filePreview.style.display = 'none';
        updateCheckBtn();
    }

    function updateCheckBtn() {
        btnCheck.disabled = !(selectedFile && orderSelect.value);
    }

    fileInput.addEventListener('change', () => {
        if (fileInput.files[0]) setFile(fileInput.files[0]);
    });

    btnRemove.addEventListener('click', clearFile);
    orderSelect.addEventListener('change', updateCheckBtn);
    updateCheckBtn(); // kích hoạt nếu đã có pre-select từ URL

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('drag-over');
    });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
    });

    // ── Bước 1: Xem trước ──────────────────────────────────
    btnCheck.addEventListener('click', async () => {
        if (!selectedFile || !orderSelect.value) return;
        loadingText.textContent = 'Đang đọc file Excel...';
        loadingOverlay.style.display = 'flex';
        cardResults.style.display = 'none';
        cardImportResult.style.display = 'none';
        btnCheck.disabled = true;
        const fd = new FormData();
        fd.append('excel_file', selectedFile);
        fd.append('order_id', orderSelect.value);
        try {
            const res = await fetch('ajax-check-import-excel.php', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            loadingOverlay.style.display = 'none';
            btnCheck.disabled = false;
            if (!data.success) {
                alert('Lỗi: ' + data.message);
                return;
            }
            previewData = data;
            renderPreview(data);
        } catch (err) {
            loadingOverlay.style.display = 'none';
            btnCheck.disabled = false;
            alert('Lỗi kết nối: ' + err.message);
        }
    });

    function renderPreview(data) {
        const {
            columns,
            rows,
            summary
        } = data;
        const willImport = rows.filter(r => r.row_status !== 'error').length;

        summaryRow.innerHTML =
            `
            <div class="summary-item total"><span>${summary.total}</span><label>Tổng số máy</label></div>
            <div class="summary-item ok"><span>${summary.ok}</span><label>IMEI khớp</label></div>
            <div class="summary-item error"><span>${summary.errors}</span><label>IMEI không khớp</label></div>
            <div class="summary-item warn"><span>${summary.total - summary.ok - summary.errors}</span><label>Không có IMEI</label></div>`;

        let th = '<tr><th>Số máy</th><th>Cấu hình</th><th>IMEI</th>';
        columns.filter(c => c.key !== 'imei').forEach(col => th += `<th>${escHtml(col.label)}</th>`);
        th += '<th>Sẽ nhập?</th></tr>';
        resultsThead.innerHTML = th;

        let tbody = '';
        rows.forEach(row => {
            const isSkip = row.row_status === 'error';
            tbody += `<tr class="${isSkip ? 'row-error' : 'row-ok'}">`;
            tbody += `<td><strong>Máy ${escHtml(String(row.so_may))}</strong></td>`;
            tbody += `<td>${escHtml(row.cfg_name || '')}</td>`;

            const imeiCell = row.cells['imei'];
            if (!imeiCell || imeiCell.status === 'skip') {
                tbody += `<td class="cell-warn">— (không có)</td>`;
            } else if (imeiCell.status === 'ok') {
                tbody +=
                    `<td class="cell-ok"><i class="fa-solid fa-check"></i> ${escHtml(imeiCell.value)}</td>`;
            } else {
                tbody +=
                    `<td class="cell-error"><i class="fa-solid fa-xmark"></i> ${escHtml(imeiCell.value)}</td>`;
            }

            columns.filter(c => c.key !== 'imei').forEach(col => {
                const cell = row.cells[col.key];
                if (!cell || cell.status === 'skip') {
                    tbody += `<td class="cell-skip">—</td>`;
                } else {
                    const cls = isSkip ? 'cell-skip' : 'cell-ok';
                    tbody += `<td class="${cls}">${escHtml(cell.value)}</td>`;
                }
            });

            tbody += `<td>${isSkip
                ? '<span class="badge error"><i class="fa-solid fa-ban"></i> Bỏ qua</span>'
                : '<span class="badge ok"><i class="fa-solid fa-file-import"></i> Sẽ nhập</span>'
            }</td></tr>`;
        });
        resultsTbody.innerHTML = tbody;

        if (willImport > 0) {
            btnImportLabel.textContent = `Xác nhận nhập ${willImport} máy vào kho hàng`;
            importActionRow.style.display = 'flex';
        } else {
            importActionRow.style.display = 'none';
        }

        cardResults.style.display = 'block';
        cardResults.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }

    // ── Bước 2: Xác nhận nhập ──────────────────────────────
    btnImport.addEventListener('click', async () => {
        if (!selectedFile || !orderSelect.value) return;
        if (!confirm('Xác nhận nhập serial từ file Excel vào kho hàng?')) return;

        loadingText.textContent = 'Đang nhập dữ liệu vào hệ thống...';
        loadingOverlay.style.display = 'flex';
        btnImport.disabled = true;

        const fd = new FormData();
        fd.append('excel_file', selectedFile);
        fd.append('order_id', orderSelect.value);
        try {
            const res = await fetch('ajax-import-excel.php', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            loadingOverlay.style.display = 'none';
            btnImport.disabled = false;
            if (!data.success) {
                alert('Lỗi nhập: ' + data.message);
                return;
            }
            renderImportResult(data);
        } catch (err) {
            loadingOverlay.style.display = 'none';
            btnImport.disabled = false;
            alert('Lỗi kết nối: ' + err.message);
        }
    });

    function renderImportResult(data) {
        importSummaryRow.innerHTML =
            `
            <div class="summary-item ok"><span>${data.total_imported}</span><label>Máy đã nhập</label></div>
            <div class="summary-item error"><span>${data.total_skipped}</span><label>Bỏ qua (IMEI)</label></div>
            <div class="summary-item warn"><span>${data.total_not_found}</span><label>Serial không tìm thấy slot</label></div>`;

        let tbody = '';
        data.results.forEach(r => {
            const isOk = r.status === 'ok';
            const isSkip = r.status === 'skip_imei';
            const cls = isOk ? 'row-ok' : isSkip ? 'row-error' : 'row-warn';
            const badge = isOk ?
                '<span class="badge ok"><i class="fa-solid fa-circle-check"></i> Đã nhập</span>' :
                isSkip ?
                '<span class="badge error"><i class="fa-solid fa-ban"></i> Bỏ qua</span>' :
                '<span class="badge warn"><i class="fa-solid fa-triangle-exclamation"></i> Một phần</span>';
            tbody += `<tr class="${cls}">
                <td><strong>Máy ${escHtml(String(r.so_may))}</strong></td>
                <td>${escHtml(r.cfg_name || '')}</td>
                <td>${escHtml(r.imei || '—')}</td>
                <td>${isSkip ? '—' : r.serial_done}</td>
                <td>${isSkip ? '—' : (r.serial_fail > 0 ? `<span style="color:var(--warn)">${r.serial_fail}</span>` : '0')}</td>
                <td>${badge}</td>
            </tr>`;
        });
        importResultTbody.innerHTML = tbody;

        importActionRow.style.display = 'none';
        cardImportResult.style.display = 'block';
        cardImportResult.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }

    function escHtml(str) {
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g,
            '&quot;');
    }
})();
</script>