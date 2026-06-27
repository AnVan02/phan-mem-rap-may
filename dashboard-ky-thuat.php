<?php require "config.php" ?>
<?php require "thanh-dieu-huong.php" ?>

<!-- Modern Aesthetics -->
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />

<link rel="stylesheet" href="./css/dashboard-ky-thuat.css">

<?php
// --- LOGIC CƠ SỞ DỮ LIỆU ---
$orders = [];
$priority_orders = [];
$stats = ['total' => 0, 'pending' => 0, 'processing' => 0, 'done' => 0];

if ($pdo) {
    try {
        // Tính toán thống kê - Chỉ tính những đơn đã có ít nhất 1 serial (để đồng bộ với danh sách hiển thị)
        $sql_stats = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND (c.so_serial IS NULL OR c.so_serial = '')) > 0 
                                  AND (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND (c.so_serial IS NOT NULL AND c.so_serial != '')) > 0 THEN 1 ELSE 0 END) as processing,
                        SUM(CASE WHEN (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND (c.so_serial IS NOT NULL AND c.so_serial != '')) = (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER')) 
                                  AND (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER')) > 0 THEN 1 ELSE 0 END) as done
                      FROM donhang d
                      WHERE (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND c.so_serial IS NOT NULL AND c.so_serial != '') > 0";
        $stats_res = $pdo->query($sql_stats)->fetch();
        if ($stats_res) {
            $stats = $stats_res;
            // pending ở đây là những đơn đang xử lý nhưng chưa xong
            $stats['pending'] = $stats['processing'];
        }

        // Lấy danh sách đơn hàng - Chỉ lấy đơn khi đã nhập XONG số Serial cho toàn bộ linh kiện
        $sql_all = "SELECT d.*, 
                           (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER')) as total_items,
                           (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND c.so_serial IS NOT NULL AND c.so_serial != '') as done_items,
                           (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND c.linhkien_chon IS NOT NULL AND c.linhkien_chon != '') as tech_done_items
                    FROM donhang d
                    HAVING total_items > 0 AND total_items = done_items
                    ORDER BY d.ngay_tao DESC";
        $orders = $pdo->query($sql_all)->fetchAll();

        // Lấy tối đa 3 đơn hàng cần ưu tiên (Cũng chỉ lấy đơn đã có Serial)
        $sql_priority = "SELECT d.*, 
                               (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER')) as total_items,
                               (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND c.so_serial IS NOT NULL AND c.so_serial != '') as done_items
                        FROM donhang d
                        WHERE (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND c.so_serial IS NOT NULL AND c.so_serial != '') > 0
                          AND (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND UPPER(c.loai_linhkien) NOT IN ('WIN','CASE','FAN','IMEI','IMER') AND (c.so_serial IS NULL OR c.so_serial = '')) > 0
                        ORDER BY d.ngay_tao ASC
                        LIMIT 3";
        $priority_orders = $pdo->query($sql_priority)->fetchAll();
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }
}
?>

<main class="dashboard-wrapper">
    <!-- Tiêu đề trang -->
    <!-- <header class="dashboard-header animate__animated animate__fadeInDown">
        <div class="header-info">
            <h1>Dashboard Tổng Quan</h1>
            <p>Xin chào! Đây là tình hình lắp ráp máy hôm nay.</p>
        </div>
        <div class="search-filter-box">
            <input type="text" id="globalSearch" class="search-input" placeholder="Tìm kiếm đơn hàng...">
        </div>
    </header> -->

    <!-- Tổng hợp đơn hàng -->
    <!-- <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-list-check"></i></div>
            <div class="stat-details">
                <span class="value"><?php echo $stats['total']; ?></span>
                <span class="label">Tổng đơn hàng</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-details">
                <span class="value"><?php echo $stats['pending']; ?></span>
                <span class="label">Chờ kiểm tra</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-spinner"></i></div>
            <div class="stat-details">
                <span class="value"><?php echo $stats['processing']; ?></span>
                <span class="label">Đang xử lý</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details">
                <span class="value"><?php echo $stats['done']; ?></span>
                <span class="label">Đã hoàn thành</span>
            </div>
        </div>
    </section> -->

    <!-- Phần đơn hàng ưu tiên -->
    <section class="priority-section">
        <h2 class="section-title-new"><i class="fa-solid fa-chart-column" style="color:#1152D4"></i>
            Dashboard Kỹ Thuật
        </h2>
        <!-- <div class="priority-list">
            <?php if (empty($priority_orders)): ?>
                <p class="info-text" style="font-size: 17px;font-weight: 500;color: #1152D4;display: flex">Tuyệt vời! Không
                    có đơn hàng nào bị trễ.</p>
                <?php else:
                foreach ($priority_orders as $p): ?>
                    <article class="priority-card animate__animated animate__zoomIn">
                        <span class="priority-tag">Ưu tiên cao</span>
                        <div class="priority-deadline">
                            <span class="deadline-time">
                                < 1 NGÀY</span>
                        </div>
                        <h3 class="priority-name"><?php echo htmlspecialchars($p['ma_don_hang']); ?></h3>
                        <div class="priority-stats">
                            <div class="stat-item"><i class="fa-solid fa-desktop"></i> <?php echo $p['so_luong_may']; ?> Máy
                            </div>
                            <div class="stat-item"><i class="fa-solid fa-user"></i>
                                <?php echo htmlspecialchars($p['ten_khach_hang']); ?></div>
                        </div>
                        <?php if ($p['done_items'] == $p['total_items'] && $p['total_items'] > 0): ?>
                            <button class="btn-action-primary"
                                onclick="window.location.href='kho-hang.php?id=<?php echo $p['id_donhang']; ?>'"
                                style="background: var(--success);">Bắt đầu kiểm tra</button>
                        <?php else: ?>
                            <button class="btn-action-primary disabled"
                                style="background: #E2E8F0; color: #94A3B8; cursor: not-allowed;">Chờ Serial...</button>
                        <?php endif; ?>
                    </article>
            <?php endforeach;
            endif; ?>
        </div> -->
    </section>

    <!-- Danh sách lắp ráp -->
    <section class="list-section">
        <div class="list-section-header">
            <h2 class="section-title">Kiểm tra dữ liệu có trùng khớp với kho hàng
            </h2>
        </div>

        <!-- Thanh lọc -->
        <div class="filter-bar">
           
            <div class="filter-status-tabs">
                <button class="filter-tab active" data-status="all">Tất cả <span class="filter-tab-count" id="cnt-all"></span></button>
                <button class="filter-tab" data-status="Chờ kiểm tra">Chờ kiểm tra <span class="filter-tab-count" id="cnt-pending"></span></button>
                <button class="filter-tab" data-status="Đang kiểm tra">Đang kiểm tra <span class="filter-tab-count" id="cnt-processing"></span></button>
                <button class="filter-tab" data-status="HOÀN TẤT">Hoàn tất <span class="filter-tab-count" id="cnt-done"></span></button>
            </div>
             <div class="filter-search-wrap">
                <i class="fa-solid fa-magnifying-glass filter-search-icon"></i>
                <input type="text" id="globalSearch" class="filter-search-input" placeholder="Tìm mã lô, khách hàng...">
            </div>
        </div>

        <div class="table-container">
            <table class="modern-table" id="mainOrdersTable">
                <thead>
                    <tr>
                        <th>Mã lô hàng</th>
                        <th>Khách hàng</th>
                        <th>Số lượng</th>
                        <th>Ngày tạo</th>
                        <th>Trạng thái</th>
                        <th style="text-align: center;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 4rem;">Chưa có dữ liệu.</td>
                        </tr>
                    <?php else:
                        foreach ($orders as $row):
                            $done = (int) $row['done_items'];
                            $tech_done = (int) $row['tech_done_items'];
                            $total = (int) $row['total_items'];
                            if ($total > 0 && $tech_done == $total) {
                                $status = 'HOÀN TẤT';
                                $cls = 'badge-success';
                            } elseif ($tech_done > 0) {
                                $status = 'Đang kiểm tra';
                                $cls = 'badge-processing';
                            } else {
                                $status = 'Chờ kiểm tra';
                                $cls = 'badge-pending';
                            }
                            ?>
                            <tr class="order-row">
                                <td class="col-batch td-batch"><?php
                                    $display_code = (!empty($row['ma_don_hang']) && strpos($row['ma_don_hang'], 'RS-') !== 0)
                                        ? htmlspecialchars($row['ma_don_hang']) 
                                        : '#' . $row['id_donhang'];
                                    echo $display_code;
                                ?></td>
                                <td class="col-customer"><?php echo htmlspecialchars($row['ten_khach_hang']); ?></td>
                                <td class="col-quantity"><strong><?php echo $row['so_luong_may']; ?></strong></td>
                                <td class="col-date"><?php echo date('d/m/Y', strtotime($row['ngay_tao'])); ?></td>
                                <td class="col-status"><span class="badge <?php echo $cls; ?>"><?php echo $status; ?></span>
                                </td>
                                <td class="col-actions" align="center">
                                    <?php if ($total > 0 && $done == $total): ?>
                                        <a href="kho-hang.php?id=<?php echo $row['id_donhang']; ?>" class="btn-row-action"
                                            style="background: #1152D4;" title="Kiểm tra chất lượng">Kiểm tra</a>
                                    <?php else: ?>
                                        <span class="btn-row-action disabled" title="Đang chờ bên Kho nhập Serial">Xử lý</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach;
                    endif; ?>
                </tbody>
            </table>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Hiển thị <strong id="visibleCount">0</strong> trong <strong><?php echo count($orders); ?></strong>
                    đơn hàng
                </div>
                <div class="pagination" id="paginationControls">
                    <!-- Nút phân trang sẽ được tạo tự động bằng JS -->
                </div>

            </div>
        </div>
    </section>
</main>

<script>
    const rowsPerPage = 10;
    let currentPage = 1;
    let activeStatus = 'all';

    const tableBody = document.querySelector('#mainOrdersTable tbody');
    const allRows = Array.from(tableBody.querySelectorAll('.order-row'));
    const paginationControls = document.getElementById('paginationControls');
    const visibleCountText = document.getElementById('visibleCount');
    const searchInput = document.getElementById('globalSearch');

    function getRowStatus(row) {
        const badge = row.querySelector('.badge');
        return badge ? badge.textContent.trim() : '';
    }

    function updateTabCounts() {
        const text = searchInput ? searchInput.value.toLowerCase() : '';
        const textFiltered = allRows.filter(r => !text || r.innerText.toLowerCase().includes(text));

        document.getElementById('cnt-all').textContent = textFiltered.length;
        document.getElementById('cnt-pending').textContent   = textFiltered.filter(r => getRowStatus(r) === 'Chờ kiểm tra').length;
        document.getElementById('cnt-processing').textContent = textFiltered.filter(r => getRowStatus(r) === 'Đang kiểm tra').length;
        document.getElementById('cnt-done').textContent      = textFiltered.filter(r => getRowStatus(r) === 'HOÀN TẤT').length;
    }

    function displayRows() {
        const text = searchInput ? searchInput.value.toLowerCase() : '';
        const filteredRows = allRows.filter(row => {
            const matchText = !text || row.innerText.toLowerCase().includes(text);
            const matchStatus = activeStatus === 'all' || getRowStatus(row) === activeStatus;
            return matchText && matchStatus;
        });

        const totalPages = Math.ceil(filteredRows.length / rowsPerPage);
        if (currentPage > totalPages && totalPages > 0) currentPage = 1;

        allRows.forEach(row => row.style.display = 'none');
        const start = (currentPage - 1) * rowsPerPage;
        filteredRows.slice(start, start + rowsPerPage).forEach(row => row.style.display = '');

        visibleCountText.innerText = filteredRows.length;
        renderPagination(totalPages);
        updateTabCounts();
    }

    function renderPagination(totalPages) {
        paginationControls.innerHTML = '';
        if (totalPages <= 1) return;

        const container = document.createElement('div');
        container.classList.add('pages');

        for (let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('div');
            btn.innerText = i;
            btn.classList.add('page-num');
            if (i === currentPage) btn.classList.add('active');
            btn.addEventListener('click', () => {
                currentPage = i;
                displayRows();
                document.getElementById('mainOrdersTable').scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            container.appendChild(btn);
        }
        paginationControls.appendChild(container);
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => { currentPage = 1; displayRows(); });
    }

    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            activeStatus = tab.dataset.status;
            currentPage = 1;
            displayRows();
        });
    });

    displayRows();
</script>

</div> <!-- .app-body (Mở trong thanh-dieu-huong.php) -->
</div> <!-- .app-container (Mở trong thanh-dieu-huong.php) -->
</body>

</html>