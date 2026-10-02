<?php
require "thanh-dieu-huong.php";
require "config.php";

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 1;

// Default dummy data if DB fails
$ma_don = "ROSA-A512312";
$ten_khach = "Khách hàng mẫu";
$ngay_tao = "dd/mm/yyyy";
$so_luong_may = 25;

if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM donhang WHERE id_donhang = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        if ($order) {
            $ma_don = $order['ma_don_hang'];
            $ten_khach = $order['ten_khach_hang'];
            $ngay_tao = date('d/m/Y', strtotime($order['ngay_tao']));
            $so_luong_may = $order['so_luong_may'];
        }
    } catch (PDOException $e) {
        // Fallback to defaults
    }
}

// Fetch components for this order to know what to display in details
$order_components = [];
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM chitiet_donhang WHERE id_donhang = ?");
        $stmt->execute([$order_id]);
        $order_components = $stmt->fetchAll();
    } catch (PDOException $e) {
    }
}

$comp_count = count($order_components);

// Nhóm linh kiện theo số máy để sinh model
$comp_by_machine = [];
foreach ($order_components as $comp) {
    $m_num = (int)($comp['so_may'] ?? 0);
    $type  = strtoupper(trim($comp['loai_linhkien']));
    if (!isset($comp_by_machine[$m_num][$type])) {
        $comp_by_machine[$m_num][$type] = $comp['ten_linhkien'];
    }
}

// Sinh mã model kiểu ROSA-XXX từ CPU + RAM + SSD
function generateModel($cpu = '', $ram = '', $ssd = '') {
    $cpu_upper = strtoupper($cpu);

    // Tiền tố thương hiệu
    if (strpos($cpu_upper, 'INTEL') !== false || preg_match('/\b[Ii][3579]\b/', $cpu)) {
        $prefix = 'I';
    } elseif (strpos($cpu_upper, 'RYZEN') !== false || strpos($cpu_upper, 'AMD') !== false) {
        $prefix = 'A';
    } else {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $cpu), 0, 1)) ?: 'X';
    }

    // Bóc mã CPU
    if (preg_match('/[Ii](\d+)-(\d+)([A-Za-z]*)/i', $cpu, $m)) {
        // Intel iX-NNNNN[F/K/...]: bỏ đuôi 0 → I3-12100F → "I121F"
        $digits = rtrim($m[2], '0') ?: $m[2][0];
        $cpu_code = $prefix . $digits . strtoupper($m[3]);
    } elseif (preg_match('/(\d{3,5})([A-Za-z]*)/i', $cpu, $m)) {
        // AMD 3200G, 5600X: bỏ đuôi 0, bỏ chữ G (integrated)
        $digits = rtrim($m[1], '0') ?: $m[1][0];
        $suffix = strtoupper($m[2]);
        if ($suffix === 'G' || $suffix === 'GE') $suffix = '';
        $cpu_code = $prefix . $digits . $suffix;
    } else {
        $cpu_code = $prefix . strtoupper(preg_replace('/[^A-Z0-9]/', '', $cpu_upper));
    }

    // Dung lượng RAM (số GB)
    $ram_code = '';
    if (preg_match('/(\d+)\s*GB/i', $ram, $m))      $ram_code = $m[1];
    elseif (preg_match('/(\d+)/i',  $ram, $m))       $ram_code = $m[1];

    // Dung lượng SSD (số GB)
    $ssd_code = '';
    if (preg_match('/(\d+)\s*TB/i', $ssd, $m))      $ssd_code = (string)((int)$m[1] * 1000);
    elseif (preg_match('/(\d+)\s*GB/i', $ssd, $m))  $ssd_code = $m[1];
    elseif (preg_match('/(\d+)/i',       $ssd, $m)) $ssd_code = $m[1];

    $code = strtoupper($cpu_code) . $ram_code . $ssd_code;
    return $code ? 'ROSA-' . $code : '';
}

// Generate machines based on so_luong_may
$machines = [];
for ($i = 1; $i <= $so_luong_may; $i++) {
    $mc    = $comp_by_machine[$i] ?? [];
    $model = generateModel(
        $mc['CPU'] ?? ($mc['MAIN'] ?? ($mc['MAINBOARD'] ?? '')),
        $mc['RAM'] ?? '',
        $mc['SSD'] ?? ''
    );

    $machines[] = [
        'id'               => $i,
        'name'             => 'MÁY ' . $i,
        'model'            => $model,
        'status'           => 'pending',
        'components_status'=> empty($mc) ? 'pending' : 'success',
        'win_key'          => '',
        'office_key'       => '',
        'error_note'       => ''
    ];
}

// Pre-calculate summary totals
$total_machines = count($machines);
$done_machines = 0;
$error_machines = 0;
foreach ($machines as $m) {
    if ($m['status'] === 'success')
        $done_machines++;
    if ($m['status'] === 'error')
        $error_machines++;
}
$pct = $total_machines > 0 ? round(($done_machines / $total_machines) * 100, 1) : 0;

// Pagination calculations
$items_per_page = 10;
$total_pages = ceil($total_machines / $items_per_page);
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1)
    $current_page = 1;
if ($current_page > $total_pages && $total_pages > 0)
    $current_page = $total_pages;

$start_index = ($current_page - 1) * $items_per_page;
$display_count = min($items_per_page, $total_machines - $start_index);
if ($display_count < 0)
    $display_count = 0;
?>

<link rel="stylesheet" href="./css/check-quality.css">

<main class="main-content-check-order">
    <!-- Header -->
    <header class="check-header">
        <div class="header-left">
            <div class="order-meta">
                <i class="fa-solid fa-file-invoice"></i> MÃ ĐƠN: <?php echo $ma_don; ?>
            </div>
            <h1>Kiểm tra đơn hàng</h1>
            <div class="delivery-date">Ngày tạo: <?php echo $ngay_tao; ?> | Khách hàng: <?php echo $ten_khach; ?></div>
        </div>
        <div class="header-right">
            <button class="btn-update">
                <i class="fa-solid fa-circle-check"></i> Cập nhật
            </button>
        </div>
    </header>


    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Tìm kiếm sản phẩm">
        </div>
        <div class="filter-tabs">
            <div class="tab-item active">Tất cả</div>
            <div class="tab-item">Chờ kiểm tra</div>
            <div class="tab-item">Đã đạt</div>
            <div class="tab-item">Lỗi</div>
        </div>
        <button class="btn-filter">
            <i class="fa-solid fa-sliders"></i> Bộ lọc
        </button>
    </div>

    <!-- Summary Section -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-icon blue">
                <i class="fa-solid fa-chart-line"></i>
            </div>
            <div class="summary-data">
                <span class="label">Tiến độ kiểm tra</span>
                <span class="value"><?php echo $done_machines; ?> / <?php echo $total_machines; ?></span>
                <span class="pct">(<?php echo $pct; ?>%)</span>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon green">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div class="summary-data">
                <span class="label">Số lượng đạt</span>
                <span class="value"><?php echo $done_machines; ?> máy</span>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon red">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="summary-data">
                <span class="label">Số lượng lỗi</span>
                <span class="value"><?php echo $error_machines; ?> máy</span>
            </div>
        </div>
    </div>


    <!-- Table -->
    <div class="table-container">
        <table class="custom-table">
            <thead>
                <tr>
                    <th style="width: 50px;">STT</th>
                    <th>SERIAL MÁY / MODEL</th>
                    <th>CHI TIẾT LINH KIỆN (<?php echo $comp_count ?: 6; ?>)</th>
                    <th>KEY WINDOWS / OFFICE</th>
                    <th style="text-align: center;">XÁC NHẬN</th>
                    <th style="text-align: center;">THAO TÁC</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $paged_machines = array_slice($machines, $start_index, $items_per_page);
                foreach ($paged_machines as $index => $m):
                ?>
                    <tr>
                        <td class="stt"><?php echo $m['id']; ?></td>
                        <td>
                            <div class="machine-info">
                                <span class="name"><?php echo $m['name']; ?></span>
                                <span class="model"><?php echo $m['model']; ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($m['components_status'] === 'success'): ?>
                                <button class="btn-view-components">Xem linh kiện <i class="fa-solid fa-chevron-down"></i></button>
                            <?php
                            elseif ($m['components_status'] === 'error'): ?>
                                <button class="btn-view-error">Lỗi linh kiện <i class="fa-solid fa-chevron-down"></i></button>
                            <?php
                            else: ?>
                                <button class="btn-filter" style="background:#f8fafc; border-color:#e2e8f0; font-size: 0.8rem; color: #94a3b8;">
                                    <span>Xem chi tiết</span> <i class="fa-solid fa-chevron-down"></i>
                                </button>
                            <?php
                            endif; ?>
                        </td>
                        <td>
                            <?php if ($m['id'] === 1): ?>
                                <div class="key-box success"><?php echo $m['win_key']; ?></div>
                                <div class="key-box success"><?php echo $m['office_key']; ?></div>
                            <?php
                            elseif ($m['id'] === 2): ?>
                                <div class="key-box empty">Windows Key...</div>
                                <div class="key-box empty">Office Key...</div>
                            <?php
                            else: ?>
                                <div class="error-note">
                                    <span class="label">Ghi chú lỗi:</span>
                                    <?php echo $m['error_note']; ?>
                                </div>
                            <?php
                            endif; ?>
                        </td>
                        <td align="center">
                            <?php if ($m['status'] === 'success'): ?>
                                <div class="check-status success">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                            <?php
                            elseif ($m['status'] === 'error'): ?>
                                <div class="check-status error">
                                    <i class="fa-solid fa-circle-xmark"></i>
                                </div>
                            <?php
                            else: ?>
                                <div class="check-status pending">
                                    <i class="fa-regular fa-square"></i>
                                </div>
                            <?php
                            endif; ?>
                        </td>
                        <td align="center">
                            <?php if ($m['status'] === 'error'): ?>
                                <a href="#" class="action-link edit">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                    Sửa ghi chú
                                </a>
                            <?php
                            else: ?>
                                <a href="#" class="action-link report">
                                    <i class="fa-solid fa-circle-exclamation"></i>
                                    BÁO LỖI
                                </a>
                            <?php
                            endif; ?>
                        </td>
                    </tr>
                    <!-- Row Chi tiết linh kiện -->
                    <tr class="details-row" id="details-<?php echo $m['id']; ?>" style="display: none; background: #fcfdfe;">
                        <td colspan="6" style="padding: 0;">
                            <div class="details-content" style="padding: 1.5rem 3rem; border-bottom: 2px solid #edf2f7;">
                                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                                    <?php if (!empty($order_components)): ?>
                                        <?php foreach ($order_components as $comp):
                                            if ((int)$comp['so_may'] !== (int)$m['id']) continue;
                                            $icon = 'fa-box';
                                            $loai_lk = strtolower($comp['loai_linhkien']);
                                            switch ($loai_lk) {
                                                case 'cpu':
                                                    $icon = 'fa-microchip';
                                                    break;
                                                case 'ram':
                                                    $icon = 'fa-memory';
                                                    break;
                                                case 'ssd':
                                                    $icon = 'fa-hard-drive';
                                                    break;
                                                case 'gpu':
                                                    $icon = 'fa-display';
                                                    break;
                                                case 'mainboard':
                                                    $icon = 'fa-clover';
                                                    break;
                                                case 'psu':
                                                    $icon = 'fa-plug';
                                                    break;
                                            }
                                        ?>
                                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem; display: flex; align-items: center; gap: 0.75rem;">
                                                <div style="width: 32px; height: 32px; background: #eff6ff; color: #3b82f6; display: flex; align-items: center; justify-content: center; border-radius: 8px;">
                                                    <i class="fa-solid <?php echo $icon; ?>" style="font-size: 0.8rem;"></i>
                                                </div>
                                                <div style="flex: 1;">
                                                    <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; font-weight: 700;"><?php echo $comp['loai_linhkien']; ?></div>
                                                    <div style="font-size: 0.85rem; font-weight: 600; color: #1e293b;"><?php echo $comp['ten_linhkien']; ?></div>
                                                    <div style="font-family: Montserrat; font-size: 0.75rem; color: #16a34a; margin-top: 0.2rem;">SN-<?php echo strtoupper($comp['loai_linhkien']); ?>-<?php echo str_pad($m['id'], 5, '0', STR_PAD_LEFT); ?></div>
                                                </div>
                                            </div>
                                        <?php
                                        endforeach; ?>
                                    <?php
                                    else: ?>
                                        <div style="grid-column: span 3; color: #94a3b8; font-style: Montserrat; font-size: 0.9rem;">Không tìm thấy thông tin linh kiện cho đơn hàng này.</div>
                                    <?php
                                    endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php
                endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="pagination-row">
        <div class="page-info">
            Hiển thị <strong><?php echo $display_count; ?></strong> / <?php echo $total_machines; ?> máy
        </div>
        <div class="page-nav">
            <button class="nav-btn" <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>
                onclick="window.location.href='?id=<?php echo $order_id; ?>&page=<?php echo $current_page - 1; ?>'">Trước</button>

            <?php
            if ($total_pages <= 1): ?>
                <div class="nav-page active">1</div>
                <?php
            else:
                for ($p = 1; $p <= $total_pages; $p++): ?>
                    <?php if ($p <= 3 || $p == $total_pages || ($p >= $current_page - 1 && $p <= $current_page + 1)): ?>
                        <div class="nav-page <?php echo ($p == $current_page) ? 'active' : ''; ?>"
                            onclick="window.location.href='?id=<?php echo $order_id; ?>&page=<?php echo $p; ?>'">
                            <?php echo $p; ?>
                        </div>
                        <?php
                    elseif (($p == 4 && $total_pages > 5) || ($p == $total_pages - 1 && $total_pages > 5)):
                        // Only show dots once
                        if (($p == 4 && $current_page < 4) || ($p == $total_pages - 1 && $current_page > $total_pages - 3)):
                        ?>
                            <span style="align-self: flex-end; padding-bottom: 8px; color: #94a3b8;">...</span>
                        <?php
                        endif; ?>
                    <?php
                    endif; ?>
                <?php
                endfor; ?>
            <?php
            endif; ?>

            <button class="nav-btn" <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>
                onclick="window.location.href='?id=<?php echo $order_id; ?>&page=<?php echo $current_page + 1; ?>'">Tiếp</button>
        </div>
    </div>


</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Xử lý click button xem linh kiện
        const buttons = document.querySelectorAll('.custom-table tbody .btn-view-components, .custom-table tbody .btn-view-error, .custom-table tbody .btn-filter');

        buttons.forEach(btn => {
            btn.addEventListener('click', function() {
                const row = this.closest('tr');
                const machineId = row.querySelector('.stt').textContent.trim();
                const detailsRow = document.getElementById('details-' + machineId);

                if (detailsRow) {
                    const isVisible = detailsRow.style.display !== 'none';

                    // Đóng tất cả các row khác (optional)
                    // document.querySelectorAll('.details-row').forEach(dr => dr.style.display = 'none');

                    if (isVisible) {
                        detailsRow.style.display = 'none';
                        this.querySelector('i').classList.replace('fa-chevron-up', 'fa-chevron-down');
                    } else {
                        detailsRow.style.display = 'table-row';
                        this.querySelector('i').classList.replace('fa-chevron-down', 'fa-chevron-up');
                    }
                }
            });
        });
    });
</script>

<!-- Close tags opened in thanh-dieu-huong.php -->
</div> <!-- .app-body -->
</div> <!-- .app-container -->
</body>

</html>