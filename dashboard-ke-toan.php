<?php require "config.php" ?>
<?php require "thanh-dieu-huong.php" ?>

<link rel="stylesheet" href="./css/dashboard-ke-toan.css">

<main class="main-content-order-status">
    <!-- Header: Tiêu đề và nút tạo đơn -->
    <header class="status-header">
        <div class="header-left">
            <h1>
                <i class="fa-solid fa-bars-progress" style="color:#1152D4"></i>
                Trạng Thái Đơn Hàng
            </h1>
            <p>Quản lý và theo dõi tiến độ nhập số serial</p>
        </div>

        <div class="header-right">
            <button id="btnDeleteSelected" class="btn-delete-multi" style="display: none;"
                onclick="deleteSelectedOrders()">
                <i class="fa-regular fa-trash-can"></i> Xóa <span id="selectedCount">0</span> mục
            </button>
            <button class="btn-create-order" onclick="window.location.href='ke-toan-tao-don.php'">
                <i class="fa-solid fa-circle-plus"></i> Tạo đơn hàng
            </button>
        </div>
    </header>

    <!-- Filters: Các tab trạng thái và tìm kiếm -->
    <div class="filter-controls">
        <div class="status-tabs">
            <div class="tab active" data-filter="all">Tất cả <span class="tab-count" id="count-all">0</span></div>
            <div class="tab" data-filter="pending">Chờ nhập Serial <span class="tab-count" id="count-pending">0</span>
            </div>
            <div class="tab" data-filter="completed">Đang lắp ráp <span class="tab-count" id="count-completed">0</span>
            </div>
            <div class="tab" data-filter="checking">Chờ đối chiếu <span class="tab-count" id="count-checking">0</span>
            </div>
            <div class="tab" data-filter="processing">Hoàn thành <span class="tab-count" id="count-processing">0</span>
            </div>
        </div>
        <div class="search-input-wrap">
            <input type="text" id="searchInput" placeholder="Tìm kiếm mã đơn, khách hàng, model...">
        </div>
    </div>

    <!-- Table Container: Bảng hiển thị đơn hàng -->
    <div class="status-table-card">
        <table class="status-table" id="ordersTable">
            <thead>
                <tr>
                    <th class="col-check">
                        <input type="checkbox" id="selectAll">
                    </th>
                    <th class="col-id">ID đơn hàng</th>
                    <th class="col-customer">Khách hàng</th>
                    <th class="col-date">Ngày tạo</th>
                    <th class="col-total">Số lượng</th>
                    <th class="col-status">Trạng thái</th>
                    <th class="col-actions">Thao tác</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php
            // --- LẤY DỮ LIỆU THẬT TỪ DATABASE ---
               $orders = [];
            // Linh kiện co_serial=0 (Không serial) luôn tính là đã hoàn thành
            $serial_type_filter = "UPPER(c.loai_linhkien) NOT IN ('WIN', 'CASE', 'FAN', 'IMEI', 'IMER')";
            $serial_need_filter = "{$serial_type_filter} AND IFNULL(c.co_serial, 1) = 1";
            $serial_done_filter = "{$serial_type_filter} AND (IFNULL(c.co_serial, 1) = 0 OR (c.so_serial IS NOT NULL AND c.so_serial != ''))";
            if ($pdo) {
               try {
                  try { $pdo->query("SELECT co_serial FROM chitiet_donhang LIMIT 0"); }
                  catch (PDOException $eCo) { $pdo->exec("ALTER TABLE chitiet_donhang ADD COLUMN co_serial TINYINT(1) NOT NULL DEFAULT 1 AFTER so_may"); }

                  // Truy vấn đơn hàng kèm thông tin tóm tắt và trạng thái thực tế
                  $sql = "SELECT d.*, 
                                 (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND {$serial_type_filter}) as total_items,
                                 (SELECT COUNT(*) FROM chitiet_donhang c WHERE c.id_donhang = d.id_donhang AND {$serial_done_filter}) as done_items
                          FROM donhang d
                          ORDER BY d.ngay_tao DESC";
                  $stmt = $pdo->query($sql);
                  $orders = $stmt->fetchAll();
               } catch (PDOException $e) {
                  echo "<tr><td colspan='7'>Lỗi: " . $e->getMessage() . "</td></tr>";
               }
            }
            // Hiển thị thông báo nếu không có đơn hàng
            if (empty($orders)) {
               echo "<tr id='emptyRow'><td colspan='7' style='text-align:center; padding: 2rem; color: #94a3b8;'>Chưa có dữ liệu đơn hàng.</td></tr>";
            } else {
               // Tạo dòng trống để hiện khi filter không có kết quả
               echo "<tr id='noResultRow' style='display:none; '><td colspan='7' style='text-align:center; padding: 2rem; color: #94a3b8;'>Không tìm thấy đơn hàng nào phù hợp.</td></tr>";
            }
            // Lặp qua từng đơn hàng để hiển thị lên bảng
            foreach ($orders as $row):
               //Tính toán dựa trên số serial đã nhập
               $total = (int) $row['total_items'];
               $done = (int) $row['done_items'];

               $status_slug = "all";
               if ($total === 0 || $done === 0) {
                  $status = "Chờ serial";
                  $status_class = "badge-pending";
                  $status_slug = "pending";
               } elseif ($done < $total) {
                  $status = "Đang nhập (" . round(($done / $total) * 100) . "%)";
                  $status_class = "badge-pending"; // Vẫn để màu vàng/cam của Chờ nhập serial
                  $status_slug = "pending";
               } else {
                  // Đã nhập đủ serial -> Chuyển sang trạng thái Ráp máy
                  $status = "HOÀN TẤT";
                  $status_class = "badge-processing"; // Màu xanh của Đang ráp
                  $status_slug = "processing";
               }

               // Chuỗi tìm kiếm tổng hợp
               $search_str = strtolower($row['id_donhang'] . ' ' . $row['ma_don_hang'] . ' ' . $row['ten_khach_hang'] . ' ' . ($row['danh_sach_nhom'] ?? ''));
            ?>
                <tr class="order-row" data-id="<?php echo $row['id_donhang']; ?>"
                    data-status="<?php echo $status_slug; ?>"
                    data-search="<?php echo htmlspecialchars($search_str); ?>">
                    <td class="col-check">
                        <input type="checkbox" class="order-checkbox" value="<?php echo $row['id_donhang']; ?>">
                    </td>
                    <td class="col-id">
                        <a href="nhap-serial.php?id=<?php echo $row['id_donhang']; ?>" class="order-id">
                            #<?php echo htmlspecialchars($row['id_donhang']); ?>
                        </a>
                    </td>
                    <td class="col-customer">
                        <?php echo htmlspecialchars($row['ten_khach_hang']); ?>
                    </td>
                    <td class="col-date">
                        <?php echo date('d/m/Y', strtotime($row['ngay_tao'])); ?>
                    </td>
                    <td class="col-total">
                        <?php echo str_pad($row['so_luong_may'], 2, '0', STR_PAD_LEFT); ?> Máy
                    </td>
                    <td class="col-status"><span
                            class="badge <?php echo $status_class; ?>"><?php echo $status; ?></span>
                    </td>
                    <td class="col-actions">
                        <div class="actions">
                            <a href="nhap-serial.php?id=<?php echo $row['id_donhang']; ?>" title="Nhập Serial"><i
                                    class="fa-regular fa-pen-to-square"></i></a>
                            <a href="javascript:void(0)" onclick="deleteSingleOrder(<?php echo $row['id_donhang']; ?>)"
                                title="Xóa đơn hàng"><i class="fa-regular fa-trash-can"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Footer / Pagination: Thông tin phân trang -->
        <div class="table-footer">
            <div class="pagination-info">
                Hiển thị <strong id="visibleCount">0</strong> trong <strong><?php echo count($orders); ?></strong> đơn
                hàng
            </div>
            <div class="pagination" id="paginationControls">
                <!-- Nút phân trang sẽ được tạo tự động bằng JS -->
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.tab');
    const searchInput = document.getElementById('searchInput');
    const rows = document.querySelectorAll('.order-row');
    const visibleCountEl = document.getElementById('visibleCount');
    const noResultRow = document.getElementById('noResultRow');
    const paginationControls = document.getElementById('paginationControls');

    // Multi-delete elements
    const selectAllCb = document.getElementById('selectAll');
    const btnDeleteSelected = document.getElementById('btnDeleteSelected');
    const selectedCountEl = document.getElementById('selectedCount');

    const itemsPerPage = 10;
    let currentPage = 1;

    function filterTable() {
        const activeTab = document.querySelector('.tab.active').dataset.filter;
        const searchText = searchInput.value.toLowerCase().trim();

        let filteredRows = [];
        rows.forEach(row => {
            const rowStatus = row.dataset.status;
            const rowSearch = row.dataset.search;

            const matchesTab = (activeTab === 'all' || rowStatus === activeTab);
            const matchesSearch = rowSearch.includes(searchText);

            if (matchesTab && matchesSearch) {
                filteredRows.push(row);
            } else {
                row.style.display = 'none';
                const cb = row.querySelector('.order-checkbox');
                if (cb) cb.checked = false;
            }
        });

        const totalFiltered = filteredRows.length;
        const totalPages = Math.ceil(totalFiltered / itemsPerPage) || 1;

        if (currentPage > totalPages) currentPage = 1;

        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;

        filteredRows.forEach((row, index) => {
            if (index >= startIndex && index < endIndex) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        if (visibleCountEl) visibleCountEl.textContent = totalFiltered;
        if (noResultRow) {
            noResultRow.style.display = (totalFiltered === 0 && rows.length > 0) ? '' : 'none';
        }

        renderPagination(totalPages);
        updateDeleteButton();
    }

    function renderPagination(totalPages) {
        paginationControls.innerHTML = '';
        if (totalPages <= 1) return;

        for (let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('div');
            btn.className = `page-btn ${i === currentPage ? 'active' : ''}`;
            btn.textContent = i;
            btn.addEventListener('click', () => {
                currentPage = i;
                filterTable();
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
            paginationControls.appendChild(btn);
        }
    }

    if (selectAllCb) {
        selectAllCb.addEventListener('change', function() {
            const isChecked = this.checked;
            rows.forEach(row => {
                if (row.style.display !== 'none') {
                    const cb = row.querySelector('.order-checkbox');
                    if (cb) cb.checked = isChecked;
                }
            });
            updateDeleteButton();
        });
    }

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('order-checkbox')) {
            updateDeleteButton();
        }
    });

    function updateDeleteButton() {
        const checkedBoxes = document.querySelectorAll('.order-checkbox:checked');
        const count = checkedBoxes.length;

        if (count > 0) {
            btnDeleteSelected.style.display = 'flex';
            selectedCountEl.textContent = count;
        } else {
            btnDeleteSelected.style.display = 'none';
        }

        const visibleRows = Array.from(document.querySelectorAll('.order-row')).filter(r => r.style.display !==
            'none');
        if (visibleRows.length > 0) {
            const allChecked = visibleRows.every(r => r.querySelector('.order-checkbox').checked);
            selectAllCb.checked = allChecked;
        } else {
            selectAllCb.checked = false;
        }
    }

    window.deleteSelectedOrders = function() {
        const checkedBoxes = document.querySelectorAll('.order-checkbox:checked');
        const ids = Array.from(checkedBoxes).map(cb => cb.value);

        if (ids.length === 0) return;

        if (confirm(`Bạn có chắc muốn xóa ${ids.length} đơn hàng đã chọn không?`)) {
            performDelete(ids);
        }
    };

    window.deleteSingleOrder = function(id) {
        if (confirm(`Bạn có chắc muốn xóa đơn hàng #${id} không?`)) {
            performDelete([id]);
        }
    };

    function performDelete(ids) {
        fetch('xoa-nhieu-don-hang.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    ids: ids
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Lỗi: ' + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert('Lỗi kết nối server.');
            });
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentPage = 1;
            filterTable();
        });
    });

    searchInput.addEventListener('input', () => {
        currentPage = 1;
        filterTable();
    });

    function updateTabCounts() {
        const counts = {
            all: 0,
            pending: 0,
            completed: 0,
            checking: 0,
            processing: 0
        };
        rows.forEach(row => {
            const s = row.dataset.status;
            counts.all++;
            if (counts[s] !== undefined) counts[s]++;
        });
        Object.entries(counts).forEach(([key, val]) => {
            const el = document.getElementById('count-' + key);
            if (el) el.textContent = val;
        });
    }

    filterTable();
    updateTabCounts();
});
</script>

<!-- Close tags opened in thanh-dieu-huong.php -->
</div> <!-- .app-body -->
</div> <!-- .app-container -->
</body>

</html>