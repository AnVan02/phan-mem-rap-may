<?php
// Xử lý AJAX POST trước khi in bất kỳ HTML nào
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add_component') {
    // Đảm bảo session.save_path đúng TRƯỚC khi start
    $_session_path = __DIR__ . '/sessions';
    if (!is_dir($_session_path)) mkdir($_session_path, 0755, true);
    ini_set('session.save_path', $_session_path);
    session_start();
    require "config.php";
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập.']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    
    $id_donhang = (int)($input['id_donhang'] ?? 0);
    $type = strtoupper(trim((string)($input['type'] ?? '')));
    $comp_name = trim((string)($input['comp_name'] ?? ''));
    $machines = $input['machines'] ?? []; // Mảng chứa các đối tượng {so_may, ten_cauhinh}
    
    $allowed_types = ['CPU', 'MAIN', 'RAM', 'SSD', 'HDD', 'VGA', 'PSU', 'FAN', 'CASE', 'WIN'];
    
    if ($id_donhang <= 0 || !in_array($type, $allowed_types) || $comp_name === '' || empty($machines)) {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin bắt buộc hoặc dữ liệu không hợp lệ.']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Kiểm tra xem cột co_serial có tồn tại trong bảng không bằng try/catch
        try {
            $pdo->query("SELECT co_serial FROM chitiet_donhang LIMIT 0");
            $has_co_serial = true;
        } catch (PDOException $e) {
            $has_co_serial = false;
        }
        
        // Theo thiết kế hệ thống, CASE và FAN mặc định không cần serial (co_serial = 0), các loại khác cần (co_serial = 1)
        $default_co_serial = in_array($type, ['CASE', 'FAN']) ? 0 : 1;
        $allow_multiple_types = ['RAM', 'SSD', 'HDD'];
        
        $inserted_count = 0;
        foreach ($machines as $m) {
            $so_may = (int)($m['so_may'] ?? 0);
            $ten_cauhinh = (string)($m['ten_cauhinh'] ?? '');
            
            if ($so_may <= 0) continue;
            
            // Cho phép thêm nhiều RAM/SSD trên cùng máy; các loại khác vẫn giữ 1 bản ghi mỗi máy
            if (!in_array($type, $allow_multiple_types)) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM chitiet_donhang WHERE id_donhang = ? AND so_may = ? AND ten_cauhinh = ? AND loai_linhkien = ?");
                $stmt->execute([$id_donhang, $so_may, $ten_cauhinh, $type]);
                if ($stmt->fetchColumn() > 0) {
                    continue;
                }
            }
            
            // Tìm dòng mẫu để sao chép nguyên văn ten_donhang và ten_cauhinh (nhằm bảo toàn cơ chế space hack)
            $stmt_template = $pdo->prepare("SELECT ten_donhang, ten_cauhinh FROM chitiet_donhang WHERE id_donhang = ? AND so_may = ? AND ten_cauhinh = ? LIMIT 1");
            $stmt_template->execute([$id_donhang, $so_may, $ten_cauhinh]);
            $template = $stmt_template->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                $stmt_template2 = $pdo->prepare("SELECT ten_donhang, ten_cauhinh FROM chitiet_donhang WHERE id_donhang = ? AND so_may = ? LIMIT 1");
                $stmt_template2->execute([$id_donhang, $so_may]);
                $template = $stmt_template2->fetch(PDO::FETCH_ASSOC);
            }
            
            $db_ten_donhang = $template ? $template['ten_donhang'] : null;
            $db_ten_cauhinh = $template ? $template['ten_cauhinh'] : $ten_cauhinh;
            
            if (empty($db_ten_donhang)) {
                $stmt_order = $pdo->prepare("SELECT ten_khach_hang FROM donhang WHERE id_donhang = ?");
                $stmt_order->execute([$id_donhang]);
                $db_ten_donhang = $stmt_order->fetchColumn() ?: 'Khách lẻ';
            }
            
            if ($has_co_serial) {
                $stmt_ins = $pdo->prepare("INSERT INTO chitiet_donhang (id_donhang, ten_donhang, ten_cauhinh, ten_linhkien, loai_linhkien, linhkien_chon, so_serial, so_may, user_id, user_id_save, co_serial) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, NULL, NULL, ?)");
                $stmt_ins->execute([$id_donhang, $db_ten_donhang, $db_ten_cauhinh, mb_strtoupper($comp_name, 'UTF-8'), $type, $so_may, $default_co_serial]);
            } else {
                $stmt_ins = $pdo->prepare("INSERT INTO chitiet_donhang (id_donhang, ten_donhang, ten_cauhinh, ten_linhkien, loai_linhkien, linhkien_chon, so_serial, so_may, user_id, user_id_save) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, NULL, NULL)");
                $stmt_ins->execute([$id_donhang, $db_ten_donhang, $db_ten_cauhinh, mb_strtoupper($comp_name, 'UTF-8'), $type, $so_may]);
            }
            
            $inserted_count++;
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Đã thêm thành công {$type} cho {$inserted_count} máy."]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Lỗi database: ' . $e->getMessage()]);
        exit;
    }
}

// Bắt đầu render giao diện HTML
require "thanh-dieu-huong.php";
require "config.php";

// Lấy loại linh kiện cần kiểm tra (mặc định là CPU)
$type = isset($_GET['type']) ? strtoupper(trim($_GET['type'])) : 'CPU';
$allowed_types = ['CPU', 'MAIN', 'RAM', 'SSD', 'HDD', 'VGA', 'PSU', 'FAN', 'CASE', 'WIN'];
if (!in_array($type, $allowed_types)) {
    $type = 'CPU';
}

// Hàm hỗ trợ loại bỏ space hack để hiển thị tên cấu hình sạch
function cleanConfigName($ten_cauhinh) {
    $tc = (string)($ten_cauhinh ?? '');
    if (strpos($tc, ',') === false) {
        return trim($tc);
    }
    $trailing = strlen($tc) - strlen(rtrim($tc));
    $cfgs = array_map('trim', explode(',', $tc));
    return $cfgs[$trailing] ?? trim($cfgs[0] ?? '');
}

// Hàm hỗ trợ lấy icon động dựa trên loại linh kiện
function getComponentIcon($type) {
    switch ($type) {
        case 'CPU':
            return 'fa-solid fa-microchip';
        case 'MAIN':
            return 'fa-solid fa-cubes';
        case 'RAM':
            return 'fa-solid fa-memory';
        case 'SSD':
            return 'fa-solid fa-database';
        case 'HDD':
            return 'fa-solid fa-hard-drive';
        case 'VGA':
            return 'fa-solid fa-vr-cardboard';
        case 'PSU':
            return 'fa-solid fa-bolt';
        case 'FAN':
            return 'fa-solid fa-fan';
        case 'CASE':
            return 'fa-solid fa-box-open';
        case 'WIN':
            return 'fa-brands fa-windows';
        default:
            return 'fa-solid fa-gears';
    }
}

// 1. Thống kê hệ thống cho 4 metric cards ở đầu trang
$stats = [
    'total_orders' => 0,
    'missing_machines' => 0,
    'processing_machines' => 0,
    'completed_machines' => 0
];

try {
    // Tổng số đơn hàng
    $stats['total_orders'] = (int)$pdo->query("SELECT COUNT(*) FROM donhang")->fetchColumn();
    
    // Thống kê số lượng máy theo trạng thái linh kiện $type
    $sql_stat = "
        SELECT 
            SUM(CASE WHEN comp.id_ct IS NULL OR comp.ten_linhkien IS NULL OR comp.ten_linhkien = '' THEN 1 ELSE 0 END) AS count_missing,
            SUM(CASE WHEN comp.id_ct IS NOT NULL AND comp.ten_linhkien != '' AND IFNULL(comp.co_serial, 1) = 1 AND (comp.so_serial IS NULL OR comp.so_serial = '') THEN 1 ELSE 0 END) AS count_processing,
            SUM(CASE WHEN comp.id_ct IS NOT NULL AND comp.ten_linhkien != '' AND (IFNULL(comp.co_serial, 1) = 0 OR (comp.so_serial IS NOT NULL AND comp.so_serial != '')) THEN 1 ELSE 0 END) AS count_completed
        FROM (
            SELECT DISTINCT id_donhang, so_may
            FROM chitiet_donhang
            WHERE so_may IS NOT NULL AND so_may > 0
        ) m
        LEFT JOIN chitiet_donhang comp 
            ON comp.id_donhang = m.id_donhang 
           AND comp.so_may = m.so_may 
           AND comp.loai_linhkien = :type
    ";
    $stmt_stat = $pdo->prepare($sql_stat);
    $stmt_stat->execute(['type' => $type]);
    $res_stat = $stmt_stat->fetch(PDO::FETCH_ASSOC);
    if ($res_stat) {
        $stats['missing_machines'] = (int)($res_stat['count_missing'] ?? 0);
        $stats['processing_machines'] = (int)($res_stat['count_processing'] ?? 0);
        $stats['completed_machines'] = (int)($res_stat['count_completed'] ?? 0);
    }
} catch (Exception $e) {
    // Bỏ qua lỗi fallback
}

// 2. Truy vấn danh sách đơn hàng
$donhangs = [];
try {
    $q_dh = "
        SELECT d.id_donhang, d.ma_don_hang, d.ten_khach_hang, d.ngay_tao, d.so_luong_may,
            (
                SELECT COUNT(DISTINCT CONCAT(c.so_may, '-', c.ten_cauhinh))
                FROM chitiet_donhang c
                WHERE c.id_donhang = d.id_donhang 
                  AND c.so_may IS NOT NULL 
                  AND c.so_may > 0
                  AND c.loai_linhkien != 'IMEI'
                  AND NOT EXISTS (
                      SELECT 1 FROM chitiet_donhang comp
                      WHERE comp.id_donhang = d.id_donhang
                        AND comp.so_may = c.so_may
                        AND comp.ten_cauhinh = c.ten_cauhinh
                        AND comp.loai_linhkien = :type
                        AND comp.ten_linhkien != ''
                  )
            ) AS so_may_thieu_lk
        FROM donhang d
        ORDER BY d.ngay_tao DESC
    ";
    $stmt_dh = $pdo->prepare($q_dh);
    $stmt_dh->execute(['type' => $type]);
    $donhangs = $stmt_dh->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>Lỗi tải đơn hàng: {$e->getMessage()}</div>";
}

// Gợi ý linh kiện
$suggested_comps = [];
try {
    $q_comp = "SELECT DISTINCT ten_linhkien FROM chitiet_donhang WHERE loai_linhkien = :type AND ten_linhkien != '' ORDER BY ten_linhkien ASC";
    $stmt_comp = $pdo->prepare($q_comp);
    $stmt_comp->execute(['type' => $type]);
    $suggested_comps = $stmt_comp->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// 3. Lấy thông tin đơn hàng được chọn
$selected_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($selected_id <= 0 && !empty($donhangs)) {
    // Mặc định chọn đơn hàng đầu tiên
    $selected_id = (int)$donhangs[0]['id_donhang'];
}

$selected_order = null;
$machines_info = [];
$tab_counts = ['all' => 0, 'missing' => 0, 'processing' => 0, 'completed' => 0];

if ($selected_id > 0) {
    foreach ($donhangs as $d) {
        if ((int)$d['id_donhang'] === $selected_id) {
            $selected_order = $d;
            break;
        }
    }
    
    if ($selected_order) {
        try {
            $stmt = $pdo->prepare("SELECT DISTINCT so_may, ten_cauhinh FROM chitiet_donhang WHERE id_donhang = ? AND so_may IS NOT NULL AND so_may > 0 ORDER BY so_may ASC");
            $stmt->execute([$selected_id]);
            $raw_machines = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($raw_machines as $rm) {
                $so_may = (int)$rm['so_may'];
                $ten_cauhinh = $rm['ten_cauhinh'];
                
                // Lấy tất cả linh kiện của máy này để xác định trạng thái các loại CPU, RAM, MAIN
                $stmt_m_comps = $pdo->prepare("SELECT loai_linhkien, ten_linhkien, so_serial, IFNULL(co_serial, 1) as co_serial FROM chitiet_donhang WHERE id_donhang = ? AND so_may = ?");
                $stmt_m_comps->execute([$selected_id, $so_may]);
                $m_comps = $stmt_m_comps->fetchAll(PDO::FETCH_ASSOC);
                
                $getCompStatus = function($compType) use ($m_comps) {
                    $found = array_filter($m_comps, function($c) use ($compType) {
                        return strtoupper($c['loai_linhkien']) === $compType && !empty($c['ten_linhkien']);
                    });
                    if (empty($found)) {
                        return ['code' => 'missing', 'text' => 'Thiếu'];
                    }
                    $first = reset($found);
                    if ((int)$first['co_serial'] === 1 && empty($first['so_serial'])) {
                        return ['code' => 'processing', 'text' => 'Đang kiểm tra'];
                    }
                    return ['code' => 'completed', 'text' => 'Đủ'];
                };
                
                $cpu_st = $getCompStatus('CPU');
                $ram_st = $getCompStatus('RAM');
                $main_st = $getCompStatus('MAIN');
                $target_st = $getCompStatus($type);
                
                // Trạng thái tổng thể của máy
                $machine_status = $target_st['code'];
                if ($machine_status === 'completed' && ($cpu_st['code'] === 'missing' || $ram_st['code'] === 'missing' || $main_st['code'] === 'missing')) {
                    $machine_status = 'missing';
                }
                
                $tab_counts['all']++;
                $tab_counts[$machine_status]++;
                
                $machines_info[] = [
                    'so_may' => $so_may,
                    'ten_cauhinh' => $ten_cauhinh,
                    'clean_cauhinh' => cleanConfigName($ten_cauhinh),
                    'machine_status' => $machine_status,
                    'cpu_status_code' => $cpu_st['code'],
                    'cpu_status_text' => $cpu_st['text'],
                    'ram_status_code' => $ram_st['code'],
                    'ram_status_text' => $ram_st['text'],
                    'main_status_code' => $main_st['code'],
                    'main_status_text' => $main_st['text'],
                    'target_status_code' => $target_st['code']
                ];
            }
        } catch (Exception $e) {
            echo "<div class='alert alert-danger'>Lỗi tải chi tiết máy: {$e->getMessage()}</div>";
        }
    }
}
?>

<link rel="stylesheet" href="./css/them-linh-kien-thieu.css?v=<?php echo time(); ?>">
<script src="./js/them-linh-kien-thieu.js?v=<?php echo time(); ?>" defer></script>

<main class="main-content-lk">
    <!-- Breadcrumb -->
    <nav class="breadcrumb">
        <a href="dashboard-ke-toan.php">Kế toán</a>
        <span class="sep">›</span>
        <a href="#" class="active">Thêm linh kiện thiếu</a>
    </nav>

    <!-- Header Section -->
    <header class="lk-header">
        <div class="header-left">
            <div class="header-icon-box">
                <i class="<?= getComponentIcon($type) ?>"></i>
            </div>
            <div class="header-titles">
                <h1>Thêm linh kiện thiếu cho các máy</h1>
                <p>Khắc phục sự cố cấu hình thiếu linh kiện (CPU, RAM, Mainboard...) cho các máy đã tạo đơn hàng</p>
            </div>
        </div>

        <div class="header-right">
            <!-- Selector Dropdown -->
            <div class="selector-container-header">
                <label for="componentTypeSelector" class="selector-label">Loại linh kiện cần tìm:</label>
                <select id="componentTypeSelector" class="component-type-select" onchange="changeComponentType(this.value)">
                    <?php foreach ($allowed_types as $t): ?>
                        <option value="<?= $t ?>" <?= $t === $type ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="fa-solid fa-chevron-down select-arrow"></i>
            </div>

            <!-- Filter Toggle -->
            <div class="filter-toggle-wrap">
                <span class="switch-label">Hiển thị tất cả đơn hàng</span>
                <label class="switch">
                    <input type="checkbox" id="toggleShowAll" checked>
                    <span class="slider round"></span>
                </label>
            </div>

            <!-- User Header Controls -->
            <div class="header-user-nav">
                <button type="button" class="notif-btn-circle" title="Thông báo">
                    <i class="fa-regular fa-bell"></i>
                    <span class="notif-badge-pill">3</span>
                </button>
                <div class="user-profile-pill">
                    <div class="user-avatar-circle">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <span class="user-name-text"><?= htmlspecialchars($_SESSION['fullname'] ?? 'Quản Trị Viên') ?></span>
                </div>
            </div>
        </div>
    </header>

    <!-- Metric Stat Cards (4 Cards Grid) -->
    <section class="metric-stats-grid">
        <div class="metric-card metric-purple">
            <div class="metric-icon-box">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div class="metric-info">
                <span class="metric-label">Tổng đơn hàng</span>
                <div class="metric-value-wrap">
                    <span class="metric-value"><?= number_format($stats['total_orders']) ?></span>
                    <span class="metric-unit">đơn hàng</span>
                </div>
            </div>
        </div>

        <div class="metric-card metric-orange">
            <div class="metric-icon-box">
                <i class="fa-solid fa-circle-xmark"></i>
            </div>
            <div class="metric-info">
                <span class="metric-label">Máy thiếu linh kiện</span>
                <div class="metric-value-wrap">
                    <span class="metric-value"><?= number_format($stats['missing_machines']) ?></span>
                    <span class="metric-unit">máy</span>
                </div>
            </div>
        </div>

        <div class="metric-card metric-blue">
            <div class="metric-icon-box">
                <i class="fa-solid fa-rotate-right"></i>
            </div>
            <div class="metric-info">
                <span class="metric-label">Đang xử lý</span>
                <div class="metric-value-wrap">
                    <span class="metric-value"><?= number_format($stats['processing_machines']) ?></span>
                    <span class="metric-unit">máy</span>
                </div>
            </div>
        </div>

        <div class="metric-card metric-green">
            <div class="metric-icon-box">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div class="metric-info">
                <span class="metric-label">Đã hoàn thành</span>
                <div class="metric-value-wrap">
                    <span class="metric-value"><?= number_format($stats['completed_machines']) ?></span>
                    <span class="metric-unit">máy</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Layout Grid: Cột Trái (Đơn hàng) & Cột Phải (Chi tiết máy) -->
    <div class="lk-layout-grid">
        <!-- Cột Trái: Danh sách đơn hàng -->
        <section class="orders-list-card">
            <div class="card-header">
                <div class="orders-header-title">
                    <span>Đơn hàng thiếu <?= htmlspecialchars($type) ?></span>
                    <span class="orders-count-badge" id="sidebarOrdersBadge"><?= count($donhangs) ?></span>
                </div>
            </div>

            <div class="search-box-container">
                <div class="search-input-wrapper">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" id="orderSearchInput" placeholder="Tìm mã đơn, tên khách hàng...">
                    <button type="button" class="btn-filter-icon" id="btnFilterSidebar" title="Lọc danh sách">
                        <i class="fa-solid fa-sliders"></i>
                    </button>
                </div>
            </div>

            <div class="orders-list-wrapper" id="ordersListWrapper">
                <?php if (empty($donhangs)): ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-folder-open"></i>
                        <p>Không có đơn hàng nào trong hệ thống.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($donhangs as $d):
                        $is_selected = ($selected_id === (int)$d['id_donhang']);
                        $thieu_count = (int)$d['so_may_thieu_lk'];
                        $item_class = 'order-item';
                        if ($is_selected) $item_class .= ' selected';
                        if ($thieu_count > 0) $item_class .= ' has-missing';
                    ?>
                        <div class="<?= $item_class ?>"
                             data-id="<?= $d['id_donhang'] ?>"
                             data-missing-count="<?= $thieu_count ?>"
                             data-search="<?= htmlspecialchars(mb_strtolower($d['ma_don_hang'] . ' ' . ($d['ten_khach_hang'] ?? ''), 'UTF-8')) ?>"
                             onclick="selectOrder(<?= $d['id_donhang'] ?>, '<?= $type ?>')">
                            <div class="order-item-header">
                                <div class="code-and-tag">
                                    <span class="order-code"><?= htmlspecialchars($d['ma_don_hang']) ?></span>
                                    <span class="tag-demo-pill">demo</span>
                                </div>
                                <?php if ($thieu_count > 0): ?>
                                    <span class="status-badge-pill warning">
                                        Thiếu <?= htmlspecialchars($type) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge-pill success">
                                        <span class="dot-green">●</span> Đủ <?= htmlspecialchars($type) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="order-item-footer">
                                <span class="order-user-date">
                                    <i class="fa-regular fa-user"></i> <?= date('d/m/Y H:i', strtotime($d['ngay_tao'])) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Sidebar Pagination Controls -->
            <div class="sidebar-pagination-container">
                <div class="pagination-buttons" id="sidebarPaginationControls"></div>
                <div class="pagination-text" id="sidebarPaginationInfo">
                    Hiển thị 1 - 10 trong <?= count($donhangs) ?> đơn hàng
                </div>
            </div>
        </section>

        <!-- Cột Phải: Chi tiết đơn hàng & máy -->
        <section class="order-details-card">
            <?php if (!$selected_order): ?>
                <div class="no-selection-state">
                    <i class="fa-solid fa-computer-mouse"></i>
                    <h3>Chưa chọn đơn hàng</h3>
                    <p>Hãy chọn một đơn hàng từ danh sách bên trái để kiểm tra và thêm linh kiện thiếu.</p>
                </div>
            <?php else: ?>
                <!-- Top Selected Order Banner -->
                <div class="selected-order-banner">
                    <div class="order-banner-left">
                        <h2 class="order-title-code"><?= htmlspecialchars($selected_order['ma_don_hang']) ?></h2>
                        <span class="tag-demo-pill">demo</span>
                        <span class="order-meta-info"><i class="fa-solid fa-desktop"></i> Quy mô: <?= (int)$selected_order['so_luong_may'] ?> máy</span>
                        <span class="order-meta-info"><i class="fa-regular fa-calendar"></i> <?= date('d/m/Y', strtotime($selected_order['ngay_tao'])) ?></span>
                    </div>
                    <div class="order-banner-right">
                        <a href="danh_sach_don_hang.php?search=<?= urlencode($selected_order['ma_don_hang']) ?>" class="btn-order-detail-link">
                            Chi tiết đơn hàng <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>

                <div class="details-body">
                    <!-- Quick Add Component Panel (Purple Container) -->
                    <div class="add-lk-action-panel">
                        <div class="action-panel-icon-box">
                            <i class="<?= getComponentIcon($type) ?>"></i>
                        </div>
                        <div class="action-panel-body">
                            <h4>Thêm <?= htmlspecialchars($type) ?> nhanh cho máy thiếu</h4>
                            <form id="addComponentForm" onsubmit="submitAddComponent(event)" class="action-form-row">
                                <input type="hidden" id="submit_order_id" value="<?= $selected_order['id_donhang'] ?>">
                                <input type="hidden" id="submit_comp_type" value="<?= htmlspecialchars($type) ?>">
                                <div class="action-input-wrap">
                                    <input type="text" id="comp_name" list="comp-datalist" placeholder="Nhập tên / mã <?= htmlspecialchars($type) ?> cần thêm..." required>
                                </div>
                                <button type="submit" class="btn-submit-add" id="btnSubmitAdd">
                                    <i class="fa-solid fa-plus-circle"></i> Thêm <?= htmlspecialchars($type) ?> cho máy
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Filter Tabs & Bulk Action Header -->
                    <div class="machines-tabs-bar">
                        <div class="tabs-nav-list">
                            <button type="button" class="tab-item active" data-tab="all" onclick="switchMachineTab('all')">
                                Tất cả máy (<?= count($machines_info) ?>)
                            </button>
                            <button type="button" class="tab-item" data-tab="missing" onclick="switchMachineTab('missing')">
                                Thiếu linh kiện (<?= $tab_counts['missing'] ?>)
                            </button>
                            <button type="button" class="tab-item" data-tab="processing" onclick="switchMachineTab('processing')">
                                Đang xử lý (<?= $tab_counts['processing'] ?>)
                            </button>
                            <button type="button" class="tab-item" data-tab="completed" onclick="switchMachineTab('completed')">
                                Hoàn thành (<?= $tab_counts['completed'] ?>)
                            </button>
                        </div>

                        <div class="bulk-actions-wrapper">
                            <label class="bulk-checkbox-container">
                                <input type="checkbox" id="chkSelectAllMachines" onchange="toggleSelectAllMachines(this.checked)">
                                <span>Chọn tất cả</span>
                            </label>
                            <div class="bulk-dropdown-wrap">
                                <button type="button" class="btn-bulk-dropdown" id="btnBulkDropdown" onclick="toggleBulkMenu(event)">
                                    Thao tác <i class="fa-solid fa-chevron-down"></i>
                                </button>
                                <div class="bulk-menu-dropdown" id="bulkMenuDropdown">
                                    <a href="#" onclick="selectAllMissing(true); hideBulkMenu(); return false;"><i class="fa-regular fa-square-check"></i> Chọn tất cả máy thiếu</a>
                                    <a href="#" onclick="selectAllMissing(false); hideBulkMenu(); return false;"><i class="fa-regular fa-square"></i> Bỏ chọn tất cả</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Machines Cards Grid (4 Columns) -->
                    <div class="machines-grid-container">
                        <div class="machines-grid" id="machinesGrid">
                            <?php if (empty($machines_info)): ?>
                                <div class="empty-state grid-span">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    <p>Không tìm thấy máy nào trong đơn hàng này.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($machines_info as $m): ?>
                                    <div class="machine-card status-<?= $m['machine_status'] ?>" 
                                         data-status="<?= $m['machine_status'] ?>"
                                         data-so-may="<?= $m['so_may'] ?>">
                                        
                                        <div class="machine-card-header">
                                            <div class="machine-header-title">
                                                <label class="checkbox-container" onclick="event.stopPropagation();">
                                                    <input type="checkbox" class="machine-checkbox"
                                                           data-so-may="<?= $m['so_may'] ?>"
                                                           data-ten-cauhinh="<?= htmlspecialchars($m['ten_cauhinh']) ?>">
                                                    <span class="checkmark"></span>
                                                </label>
                                                <i class="fa-solid fa-desktop machine-icon"></i>
                                                <span class="machine-name-text">Máy <?= sprintf('%02d', $m['so_may']) ?></span>
                                            </div>
                                            <span class="status-dot dot-<?= $m['machine_status'] ?>" title="Trạng thái máy"></span>
                                        </div>

                                        <div class="machine-card-body">
                                            <div class="config-name-label">
                                                <?= htmlspecialchars($m['clean_cauhinh'] ?: 'Cấu hình 1') ?>
                                            </div>

                                            <div class="comp-status-list">
                                                <!-- CPU Row -->
                                                <div class="comp-status-row status-<?= $m['cpu_status_code'] ?>">
                                                    <i class="fa-solid fa-microchip comp-icon"></i>
                                                    <span class="comp-name-lbl">CPU:</span>
                                                    <span class="comp-val-text"><?= htmlspecialchars($m['cpu_status_text']) ?></span>
                                                </div>

                                                <!-- RAM Row -->
                                                <div class="comp-status-row status-<?= $m['ram_status_code'] ?>">
                                                    <i class="fa-solid fa-memory comp-icon"></i>
                                                    <span class="comp-name-lbl">RAM:</span>
                                                    <span class="comp-val-text"><?= htmlspecialchars($m['ram_status_text']) ?></span>
                                                </div>

                                                <!-- Mainboard Row -->
                                                <div class="comp-status-row status-<?= $m['main_status_code'] ?>">
                                                    <i class="fa-solid fa-cubes comp-icon"></i>
                                                    <span class="comp-name-lbl">Main:</span>
                                                    <span class="comp-val-text"><?= htmlspecialchars($m['main_status_text']) ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="machine-card-footer">
                                            <a href="nhap-serial.php?id=<?= $selected_order['id_donhang'] ?>" class="btn-view-detail-link">
                                                Xem chi tiết <i class="fa-solid fa-arrow-right"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Grid Pagination Footer -->
                        <div class="grid-pagination-container">
                            <div class="pagination-buttons" id="gridPaginationControls"></div>
                            <div class="pagination-info-text" id="gridPaginationInfo">
                                Hiển thị 1 - 8 trong <?= count($machines_info) ?> máy
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<!-- Datalist Gợi Ý Linh Kiện -->
<datalist id="comp-datalist">
    <?php if (!empty($suggested_comps)): ?>
        <?php foreach ($suggested_comps as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"></option>
        <?php endforeach; ?>
    <?php endif; ?>
</datalist>

<!-- Toast Container -->
<div id="toast-container" class="toast-container"></div>

</div> <!-- .app-body -->
</div> <!-- .app-container -->
</body>
</html>
