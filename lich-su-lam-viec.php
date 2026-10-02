<?php
require "config.php";
require "thanh-dieu-huong.php";

$is_admin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'user';

// --- DATA CHO TAB ACCOUNT (CHỈ ADMIN) ---
$message = '';
$message_type = '';
$account_users = [];

if ($is_admin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
        $new_username = trim($_POST['username'] ?? '');
        $new_fullname = trim($_POST['fullname'] ?? '');
        $new_password = $_POST['password'] ?? '';
        $new_role     = $_POST['role'] ?? 'kythuat';

        if (empty($new_username) || empty($new_fullname) || empty($new_password)) {
            $message = 'Vui lòng điền đầy đủ tất cả các trường bắt buộc.';
            $message_type = 'error';
        } elseif (strlen($new_password) < 8) {
            $message = 'Mật khẩu phải có ít nhất 8 ký tự.';
            $message_type = 'error';
        } elseif (!preg_match('/[^a-zA-Z0-9]/', $new_password)) {
            $message = 'Mật khẩu phải chứa ít nhất 1 ký tự đặc biệt.';
            $message_type = 'error';
        } else {
            try {
                $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $checkStmt->execute([$new_username]);
                if ($checkStmt->fetch()) {
                    $message = "Tên đăng nhập \"$new_username\" đã tồn tại. Vui lòng chọn tên khác.";
                    $message_type = 'error';
                } else {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $insertStmt = $pdo->prepare("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, ?)");
                    $insertStmt->execute([$new_username, $hashed, $new_fullname, $new_role]);
                    $message = "Tài khoản \"$new_fullname\" (@$new_username) đã được tạo thành công!";
                    $message_type = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Lỗi hệ thống: ' . $e->getMessage();
                $message_type = 'error';
            }
        }
        $active_tab = 'account';
    }

    try {
        $account_users = $pdo->query("SELECT id, username, fullname, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();
    } catch (PDOException $e) {}
} else {
    if ($active_tab === 'account') {
        $active_tab = 'user';
    }
}

// --- DATA CHO TAB USER ---
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($active_tab === 'user' && $user_id === 0 && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
}
$user_info = null;
$history = [];
$all_users = [];

// --- DATA CHO TAB MACHINE ---
$id_donhang = isset($_GET['id_donhang']) ? (int)$_GET['id_donhang'] : 0;
$so_may = isset($_GET['so_may']) ? (int)$_GET['so_may'] : 0;
$ma_don_hang_search = isset($_GET['ma_don_hang']) ? trim($_GET['ma_don_hang']) : '';

if ($id_donhang > 0 && $so_may > 0) {
    $active_tab = 'machine';
}

$order_info = null;
$participants = [];

if ($pdo) {
    try {
        // Lấy danh sách kỹ thuật viên
        $stmt_all = $pdo->query("SELECT id, username, fullname, role FROM users WHERE role = 'kythuat' OR role = 'admin' ORDER BY fullname ASC");
        $all_users = $stmt_all->fetchAll();

        // 1. Xử lý Tab User
        if ($user_id > 0) {
            $stmt_user = $pdo->prepare("SELECT username, fullname, role FROM users WHERE id = ?");
            $stmt_user->execute([$user_id]);
            $user_info = $stmt_user->fetch();

            $sql_history = "
                SELECT DISTINCT c.id_donhang, c.so_may, d.ma_don_hang, d.ten_khach_hang, d.ngay_tao 
                FROM chitiet_donhang c
                JOIN donhang d ON d.id_donhang = c.id_donhang
                WHERE c.user_id = ? OR c.user_id_save = ?
                ORDER BY d.ngay_tao DESC
            ";
            $stmt_history = $pdo->prepare($sql_history);
            $stmt_history->execute([$user_id, $user_id]);
            $history = $stmt_history->fetchAll();
        }

        // 2. Xử lý Tab Machine
        if ($ma_don_hang_search !== '' && $so_may > 0 && $id_donhang == 0) {
            // Tìm id_donhang dựa trên mã đơn
            $stmt_find = $pdo->prepare("SELECT id_donhang FROM donhang WHERE ma_don_hang = ? OR id_donhang = ? LIMIT 1");
            $stmt_find->execute([$ma_don_hang_search, str_replace('#', '', $ma_don_hang_search)]);
            $res = $stmt_find->fetch();
            if ($res) {
                $id_donhang = $res['id_donhang'];
            }
        }

        if ($id_donhang > 0 && $so_may > 0) {
            $stmt_order = $pdo->prepare("SELECT ma_don_hang, ten_khach_hang FROM donhang WHERE id_donhang = ?");
            $stmt_order->execute([$id_donhang]);
            $order_info = $stmt_order->fetch();

            if ($order_info) {
                $sql = "
                    SELECT DISTINCT u.id, u.username, u.fullname, u.role
                    FROM users u
                    JOIN chitiet_donhang c ON (u.id = c.user_id OR u.id = c.user_id_save)
                    WHERE c.id_donhang = ? AND c.so_may = ?
                ";
                $stmt_users = $pdo->prepare($sql);
                $stmt_users->execute([$id_donhang, $so_may]);
                $participants = $stmt_users->fetchAll();
            }
        }
    } catch (PDOException $e) {
        $error = "Lỗi truy vấn: " . $e->getMessage();
    }
}
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />
<link rel="stylesheet" href="./css/lich-su-lam-viec.css">
<link rel="stylesheet" href="./css/nguoi-tham-gia.css">
<?php if ($is_admin): ?>
<link rel="stylesheet" href="./css/tao-tai-khoan.css">
<?php endif; ?>

<style>
    /* Tabs Styles */
    .tabs-container {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 0;
    }
    .tab-btn {
        background: transparent;
        border: none;
        padding: 1rem 1.5rem;
        font-size: 1.05rem;
        font-weight: 600;
        color: var(--text-muted);
        cursor: pointer;
        position: relative;
        transition: color 0.2s;
    }
    .tab-btn:hover {
        color: var(--primary);
    }
    .tab-btn.active {
        color: var(--primary);
    }
    .tab-btn.active::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 100%;
        height: 3px;
        background: var(--primary);
        border-radius: 3px 3px 0 0;
    }
    .tab-content {
        display: none;
        animation: fadeIn 0.3s;
    }
    .tab-content.active {
        display: block;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .search-machine-form {
        display: flex;
        gap: 1rem;
        align-items: flex-end;
    }
</style>

<div class="history-wrapper animate__animated animate__fadeIn">
    <div class="page-header" style="border-bottom: none; padding-bottom: 0; margin-bottom: 1rem;">
        <div class="page-title">
            <h1><i class="fa-solid fa-clock-rotate-left" style="margin-right: 0.5rem;"></i>Quản lý Lịch sử & Nhân sự</h1>
            <p>Tra cứu lịch sử làm việc của nhân viên và danh sách nhân sự tham gia theo từng máy.</p>
        </div>
    </div>

    <!-- Tabs Header -->
    <div class="tabs-container">
        <button class="tab-btn <?php echo $active_tab == 'user' ? 'active' : ''; ?>" onclick="switchTab('user')">
            <i class="fa-solid fa-user-check"></i> Tra cứu theo Nhân sự
        </button>
        <button class="tab-btn <?php echo $active_tab == 'machine' ? 'active' : ''; ?>" onclick="switchTab('machine')">
            <i class="fa-solid fa-desktop"></i> Tra cứu theo Máy
        </button>
        <?php if ($is_admin): ?>
        <button class="tab-btn <?php echo $active_tab == 'account' ? 'active' : ''; ?>" onclick="switchTab('account')">
            <i class="fa-solid fa-users-gear"></i> Quản lý Tài khoản
        </button>
        <?php endif; ?>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- TAB 1: USER HISTORY -->
    <div id="tab-user" class="tab-content <?php echo $active_tab == 'user' ? 'active' : ''; ?>">
        <div class="filter-section">
            <form method="GET" action="lich-su-lam-viec.php">
                <input type="hidden" name="tab" value="user">
                <div class="form-group">
                    <label for="user_id">Chọn nhân sự</label>
                    <select name="user_id" id="user_id" required onchange="this.form.submit()">
                        <option value="">-- Chọn kỹ thuật viên --</option>
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo ($user_id == $u['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['fullname'] ?: $u['username']); ?> (<?php echo htmlspecialchars($u['username']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-search"><i class="fa-solid fa-magnifying-glass"></i> Tra cứu</button>
            </form>
        </div>

        <?php if ($user_id > 0 && $user_info): ?>
            <div class="history-card">
                <div class="history-card-header">
                    <div style="font-size: 1.5rem; color: var(--primary);"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <h2><?php echo htmlspecialchars($user_info['fullname'] ?: $user_info['username']); ?></h2>
                        <div style="font-size: 0.85rem; color: var(--text-muted);">
                            @<?php echo htmlspecialchars($user_info['username']); ?> • <?php echo htmlspecialchars($user_info['role']); ?>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($history)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-box-open"></i>
                        <h3>Chưa có lịch sử làm việc</h3>
                        <p>Nhân sự này chưa tham gia vào bất kỳ máy nào.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <div style="margin-bottom: 1rem;">
                            <button id="btnDeleteSelected" class="btn-cancel" style="display: none; padding: 0.5rem 1rem; border-radius: 6px; border: none; font-weight: 600; cursor: pointer;">
                                <i class="fa-solid fa-trash-can"></i> Xoá các mục đã chọn
                            </button>
                        </div>
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAllHistory"></th>
                                    <th>Mã Đơn Hàng</th>
                                    <th>Khách Hàng</th>
                                    <th>Máy Số</th>
                                    <th>Ngày Tạo Đơn</th>
                                    <th>Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $item): ?>
                                    <tr>
                                        <td style="text-align: center;">
                                            <input type="checkbox" class="machine-checkbox" value='{"id_donhang":<?php echo $item["id_donhang"]; ?>,"so_may":<?php echo $item["so_may"]; ?>,"user_id":<?php echo $user_id; ?>}'>
                                        </td>
                                        <td>
                                            <strong>
                                                <?php 
                                                echo (!empty($item['ma_don_hang']) && strpos($item['ma_don_hang'], 'RS-') !== 0) 
                                                    ? htmlspecialchars($item['ma_don_hang']) 
                                                    : '#' . $item['id_donhang']; 
                                                ?>
                                            </strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['ten_khach_hang']); ?></td>
                                        <td><span class="badge-machine">Máy <?php echo $item['so_may']; ?></span></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($item['ngay_tao'])); ?></td>
                                        <td>
                                            <a href="kiemtra.php?id=<?php echo $item['id_donhang']; ?>&may=<?php echo $item['so_may']; ?>" class="btn-view" title="Xem chi tiết máy">
                                                <i class="fa-solid fa-eye"></i> Xem máy
                                            </a>
                                            <!-- Chuyển sang tab machine để xem -->
                                            <a href="?tab=machine&id_donhang=<?php echo $item['id_donhang']; ?>&so_may=<?php echo $item['so_may']; ?>" class="btn-view" style="margin-left: 0.5rem;" title="Những ai cùng làm máy này?">
                                                <i class="fa-solid fa-users"></i>
                                            </a>
                                            <button type="button" class="btn-view btn-delete" style="margin-left: 0.5rem; color: #ef4444; border-color: #fca5a5;" title="Xoá máy này khỏi lịch sử" onclick="removeMachine(<?php echo $item['id_donhang']; ?>, <?php echo $item['so_may']; ?>, <?php echo $user_id; ?>)">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif (!isset($_GET['user_id'])): ?>
            <div class="empty-state">
                <i class="fa-solid fa-hand-pointer"></i>
                <h3>Chọn nhân sự để tra cứu</h3>
                <p>Vui lòng chọn một kỹ thuật viên ở trên để xem danh sách các máy đã thực hiện.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 2: MACHINE PARTICIPANTS -->
    <div id="tab-machine" class="tab-content <?php echo $active_tab == 'machine' ? 'active' : ''; ?>">
        <div class="filter-section">
            <form method="GET" action="lich-su-lam-viec.php" class="search-machine-form">
                <input type="hidden" name="tab" value="machine">
                <div class="form-group" style="flex: 2;">
                    <label for="ma_don_hang">Mã đơn hàng</label>
                    <input type="text" name="ma_don_hang" id="ma_don_hang" class="form-group select" style="padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border-color);" placeholder="VD: RS-123456" value="<?php echo htmlspecialchars($ma_don_hang_search ?: ($order_info['ma_don_hang'] ?? '')); ?>" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label for="so_may_search">Số máy</label>
                    <input type="number" name="so_may" id="so_may_search" class="form-group select" style="padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid var(--border-color);" min="1" value="<?php echo $so_may > 0 ? $so_may : 1; ?>" required>
                </div>
                <button type="submit" class="btn-search"><i class="fa-solid fa-users"></i> Xem nhân sự</button>
            </form>
        </div>

        <?php if ($id_donhang > 0 && $so_may > 0): ?>
            <?php if ($order_info): ?>
                <div class="page-title" style="margin-bottom: 1.5rem; text-align: center;">
                    <p style="font-size: 1.1rem; color: var(--primary);">Đơn hàng: <strong><?php echo htmlspecialchars($order_info['ma_don_hang']); ?></strong> - Máy số: <strong><?php echo $so_may; ?></strong><br><span style="font-size: 0.9rem; color: var(--text-muted);">(Khách: <?php echo htmlspecialchars($order_info['ten_khach_hang']); ?>)</span></p>
                </div>
                
                <?php if (empty($participants)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-user-xmark"></i>
                        <h3>Chưa có người tham gia</h3>
                        <p>Chưa có dữ liệu nhân sự nào được ghi nhận cho máy số <?php echo $so_may; ?> thuộc đơn hàng này.</p>
                    </div>
                <?php else: ?>
                    <div class="participant-grid">
                        <?php foreach ($participants as $user): ?>
                            <?php 
                                $initials = mb_substr($user['fullname'] ?: $user['username'], 0, 1, 'UTF-8'); 
                                $initials = mb_strtoupper($initials, 'UTF-8');
                            ?>
                            <div class="participant-card">
                                <div class="avatar"><?php echo $initials; ?></div>
                                <div class="user-info">
                                    <h3><?php echo htmlspecialchars($user['fullname'] ?: $user['username']); ?></h3>
                                    <p><i class="fa-solid fa-at"></i> <?php echo htmlspecialchars($user['username']); ?></p>
                                    <span class="role-badge"><?php echo htmlspecialchars($user['role']); ?></span>
                                </div>
                                <button class="btn-remove-participant" title="Gỡ nhân sự khỏi máy này" onclick="removeParticipant(<?php echo $id_donhang; ?>, <?php echo $so_may; ?>, <?php echo $user['id']; ?>, '<?php echo addslashes($user['fullname'] ?: $user['username']); ?>')">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <h3>Không tìm thấy máy</h3>
                    <p>Mã đơn hàng hoặc số máy không tồn tại.</p>
                </div>
            <?php endif; ?>
        <?php elseif ($active_tab == 'machine'): ?>
            <div class="empty-state">
                <i class="fa-solid fa-desktop"></i>
                <h3>Tra cứu nhân sự theo máy</h3>
                <p>Nhập mã đơn hàng và số máy ở trên để xem danh sách kỹ thuật viên đã tham gia.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- TAB 3: QUẢN LÝ TÀI KHOẢN (ADMIN) -->
    <?php if ($is_admin): ?>
    <div id="tab-account" class="tab-content <?php echo $active_tab == 'account' ? 'active' : ''; ?>">
        
        <div class="main-content-account-mgmt" style="min-height: auto; padding: 0;">
            <header class="mgmt-header">
                <div class="header-right">
                    <button class="btn-add-account" onclick="openModal()">
                        <i class="fa-solid fa-plus-circle"></i>
                        Thêm tài khoản mới
                    </button>
                </div>
            </header>

            <?php if ($message && $message_type === 'success'): ?>
            <div class="top-alert top-alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <?php endif; ?>

            <div class="filter-controls">
                <div class="search-input-wrap">
                    <input type="text" id="memberSearch" placeholder="Tìm kiếm thành viên theo họ tên, username...">
                </div>
                <div class="stats-badge">
                    <i class="fa-solid fa-users"></i>
                    Tổng số: <strong id="memberCount"><?= count($account_users) ?></strong> thành viên
                </div>
            </div>

            <div class="status-table-card">
                <?php if (empty($account_users)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-folder-open"></i>
                    <p>Chưa có tài khoản thành viên nào được tạo.</p>
                </div>
                <?php else: ?>
                <table class="status-table" id="membersTable">
                    <thead>
                        <tr>
                            <th>Họ và tên</th>
                            <th>Tên đăng nhập</th>
                            <th>Vai trò truy cập</th>
                            <th>Ngày khởi tạo</th>
                            <th style="text-align: right; padding-right: 1.5rem;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="membersTableBody">
                        <?php foreach ($account_users as $u): ?>
                        <tr class="member-row" id="row-<?= $u['id'] ?>" 
                            data-search="<?= htmlspecialchars(strtolower($u['fullname'] . ' ' . $u['username'])) ?>">
                            <td>
                                <div class="user-meta-wrapper">
                                    <div class="user-avatar-circle avatar-<?= $u['role'] ?>">
                                        <?= preg_match('/./u', htmlspecialchars($u['fullname']), $m) ? $m[0] : '?' ?>
                                    </div>
                                    <span class="td-fullname"><?= htmlspecialchars($u['fullname']) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="td-username">@<?= htmlspecialchars($u['username']) ?></span>
                            </td>
                            <td>
                                <?php
                                    switch ($u['role']) {
                                        case 'admin':
                                            $badge = ['badge-admin', 'fa-shield-halved', 'Admin'];
                                            break;
                                        case 'ketoan':
                                            $badge = ['badge-ketoan', 'fa-calculator', 'Kế toán'];
                                            break;
                                        default:
                                            $badge = ['badge-kythuat', 'fa-screwdriver-wrench', 'Kỹ thuật'];
                                            break;
                                    }
                                ?>
                                <span class="badge <?= $badge[0] ?>">
                                    <i class="fa-solid <?= $badge[1] ?>" style="font-size: 0.75rem;"></i>
                                    <?= $badge[2] ?>
                                </span>
                            </td>
                            <td><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                            <td style="text-align: right; padding-right: 1.5rem;">
                                <div class="actions" style="justify-content: flex-end;">
                                    <?php if ($u['username'] !== $_SESSION['username']): ?>
                                    <button class="btn-action-delete" title="Xóa tài khoản"
                                            onclick="deleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['fullname']) ?>')"
                                            id="del-<?= $u['id'] ?>">
                                        <i class="fa-regular fa-trash-can"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="self-label">Đang trực tuyến</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="noResultsRow" style="display: none;">
                            <td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                                <i class="fa-solid fa-magnifying-glass" style="font-size: 1.5rem; margin-bottom: 0.5rem; opacity: 0.5; display: block;"></i>
                                Không tìm thấy thành viên nào phù hợp.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- POPUP MODAL FORM TẠO TÀI KHOẢN -->
        <div class="modal-backdrop" id="accountModal">
            <div class="modal-container">
                <div class="modal-header">
                    <h3>
                        <i class="fa-solid fa-user-plus" style="color: var(--primary-blue);"></i>
                        Tạo tài khoản mới
                    </h3>
                    <button class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <?php if ($message && $message_type === 'error'): ?>
                    <div class="alert alert-error" style="padding: 0.6rem 0.875rem; border-radius: 6px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 600; background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; line-height: 1.4;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span><?= htmlspecialchars($message) ?></span>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="lich-su-lam-viec.php?tab=account" id="createForm">
                        <div class="modal-form-group">
                            <label>Họ và tên thành viên <span>*</span></label>
                            <div class="modal-input-wrap">
                                <i class="fa-solid fa-signature"></i>
                                <input type="text" name="fullname" id="fullname" class="modal-input-field"
                                       placeholder="Ví dụ: Nguyễn Văn A"
                                       value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="modal-form-group">
                            <label>Tên đăng nhập (Username) <span>*</span></label>
                            <div class="modal-input-wrap">
                                <i class="fa-solid fa-user"></i>
                                <input type="text" name="username" id="username_field" class="modal-input-field"
                                       placeholder="Ví dụ: kythuat2"
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                       autocomplete="off" required>
                            </div>
                        </div>

                        <div class="modal-form-group">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                                <label style="margin-bottom: 0; font-weight: 600; font-size: 0.85rem; color: var(--text-main);">Mật khẩu truy cập <span>*</span></label>
                                <button type="button" id="btnGenPw" style="background: none; border: none; color: var(--primary-blue); font-size: 0.775rem; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 0.25rem; padding: 0; outline: none;">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Tự tạo mật khẩu
                                </button>
                            </div>
                            <div class="modal-input-wrap">
                                <i class="fa-solid fa-key"></i>
                                <input type="password" name="password" id="password_field" class="modal-input-field"
                                       placeholder="Tối thiểu 8 ký tự, gồm ký tự đặc biệt" autocomplete="new-password" required>
                                <i class="fa-solid fa-eye modal-toggle-pw" id="togglePw"></i>
                            </div>
                        </div>

                        <div class="modal-form-group">
                            <label>Vai trò & Cấp quyền <span>*</span></label>
                            <div class="modal-role-grid">
                                <div class="modal-role-option">
                                    <input type="radio" name="role" id="role_kythuat" value="kythuat"
                                        <?= (($_POST['role'] ?? 'kythuat') === 'kythuat') ? 'checked' : '' ?>>
                                    <label for="role_kythuat" class="modal-role-label">
                                        <div class="role-icon-box">
                                            <i class="fa-solid fa-screwdriver-wrench"></i>
                                        </div>
                                        <span class="role-name">Kỹ thuật</span>
                                    </label>
                                </div>
                                <div class="modal-role-option">
                                    <input type="radio" name="role" id="role_ketoan" value="ketoan"
                                        <?= (($_POST['role'] ?? '') === 'ketoan') ? 'checked' : '' ?>>
                                    <label for="role_ketoan" class="modal-role-label">
                                        <div class="role-icon-box">
                                            <i class="fa-solid fa-calculator"></i>
                                        </div>
                                        <span class="role-name">Kế toán</span>
                                    </label>
                                </div>
                                <div class="modal-role-option">
                                    <input type="radio" name="role" id="role_admin" value="admin"
                                        <?= (($_POST['role'] ?? '') === 'admin') ? 'checked' : '' ?>>
                                    <label for="role_admin" class="modal-role-label">
                                        <div class="role-icon-box">
                                            <i class="fa-solid fa-shield-halved"></i>
                                        </div>
                                        <span class="role-name">Admin</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="modal-actions-footer">
                            <button type="button" class="btn-cancel" onclick="closeModal()">Hủy bỏ</button>
                            <button type="submit" name="create_user" class="btn-confirm" id="submitBtn">
                                <i class="fa-solid fa-circle-plus"></i>
                                Tạo tài khoản
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function switchTab(tabId) {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);

        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        event.currentTarget.classList.add('active');

        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.getElementById('tab-' + tabId).classList.add('active');
    }

    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('mouseover', function() {
            this.style.background = '#ef4444';
            this.style.color = 'white';
        });
        btn.addEventListener('mouseout', function() {
            this.style.background = 'white';
            this.style.color = '#ef4444';
        });
    });

    // --- LOGIC CHỌN TẤT CẢ VÀ XÓA HÀNG LOẠT ---
    const selectAllHistory = document.getElementById('selectAllHistory');
    const machineCheckboxes = document.querySelectorAll('.machine-checkbox');
    const btnDeleteSelected = document.getElementById('btnDeleteSelected');

    function updateDeleteSelectedBtn() {
        if (!btnDeleteSelected) return;
        const anyChecked = Array.from(machineCheckboxes).some(cb => cb.checked);
        btnDeleteSelected.style.display = anyChecked ? 'inline-block' : 'none';
        
        if (selectAllHistory) {
            const allChecked = Array.from(machineCheckboxes).every(cb => cb.checked);
            selectAllHistory.checked = (machineCheckboxes.length > 0 && allChecked);
        }
    }

    if (selectAllHistory) {
        selectAllHistory.addEventListener('change', function() {
            machineCheckboxes.forEach(cb => cb.checked = this.checked);
            updateDeleteSelectedBtn();
        });
    }

    machineCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateDeleteSelectedBtn);
    });

    if (btnDeleteSelected) {
        btnDeleteSelected.addEventListener('click', function() {
            const selectedItems = [];
            machineCheckboxes.forEach(cb => {
                if (cb.checked) {
                    selectedItems.push(JSON.parse(cb.value));
                }
            });

            if (selectedItems.length === 0) return;

            Swal.fire({
                title: 'Xoá nhiều máy khỏi lịch sử?',
                text: `Bạn đang chọn ${selectedItems.length} máy để gỡ khỏi danh sách của kỹ thuật viên này. Bạn có chắc không?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#94a3b8',
                confirmButtonText: 'Đồng ý, gỡ!',
                cancelButtonText: 'Hủy'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('ajax-xoa-nhieu-ky-thuat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ items: selectedItems })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Thành công', data.message, 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Lỗi', data.message, 'error');
                        }
                    })
                    .catch(err => {
                        Swal.fire('Lỗi', 'Không thể kết nối đến máy chủ', 'error');
                    });
                }
            });
        });
    }

    function removeMachine(id_donhang, so_may, user_id) {
        Swal.fire({
            title: 'Xoá khỏi lịch sử?',
            text: 'Máy ' + so_may + ' sẽ được gỡ khỏi danh sách của kỹ thuật viên này. Hành động này sẽ reset dữ liệu để người khác có thể vào làm máy này thay thế. Bạn có chắc không?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Đồng ý, gỡ!',
            cancelButtonText: 'Hủy'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('ajax-xoa-ky-thuat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id_donhang=${id_donhang}&so_may=${so_may}&user_id=${user_id}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Thành công', data.message, 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Lỗi', data.message, 'error');
                    }
                })
                .catch(err => {
                    Swal.fire('Lỗi', 'Không thể kết nối đến máy chủ', 'error');
                });
            }
        });
    }

    function removeParticipant(id_donhang, so_may, user_id, userName) {
        Swal.fire({
            title: 'Gỡ nhân sự?',
            html: `Bạn có chắc muốn gỡ <strong>${userName}</strong> khỏi máy ${so_may}?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Đồng ý, gỡ!',
            cancelButtonText: 'Hủy'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('ajax-xoa-ky-thuat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id_donhang=${id_donhang}&so_may=${so_may}&user_id=${user_id}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Thành công', data.message, 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Lỗi', data.message, 'error');
                    }
                })
                .catch(err => {
                    Swal.fire('Lỗi', 'Không thể kết nối đến máy chủ', 'error');
                });
            }
        });
    }

    <?php if ($is_admin): ?>
    // ACCOUNT MGMT JS
    const modal = document.getElementById('accountModal');
    function openModal() {
        if(modal) {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
    }
    function closeModal() {
        if(modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
    if(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });
    }

    const togglePw = document.getElementById('togglePw');
    if(togglePw) {
        togglePw.addEventListener('click', function() {
            const pw = document.getElementById('password_field');
            const isText = pw.type === 'text';
            pw.type = isText ? 'password' : 'text';
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }

    const btnGenPw = document.getElementById('btnGenPw');
    if (btnGenPw) {
        btnGenPw.addEventListener('click', function() {
            const length = 10;
            const uppercase = "ABCDEFGHJKLMNOPQRSTUVWXYZ";
            const lowercase = "abcdefghijkmnopqrstuvwxyz";
            const numbers = "23456789";
            const specials = "@#$%&*!+?";
            
            let password = "";
            password += uppercase.charAt(Math.floor(Math.random() * uppercase.length));
            password += lowercase.charAt(Math.floor(Math.random() * lowercase.length));
            password += numbers.charAt(Math.floor(Math.random() * numbers.length));
            password += specials.charAt(Math.floor(Math.random() * specials.length));
            
            const allChars = uppercase + lowercase + numbers + specials;
            for (let i = password.length; i < length; i++) {
                password += allChars.charAt(Math.floor(Math.random() * allChars.length));
            }
            password = password.split('').sort(() => 0.5 - Math.random()).join('');
            
            const pwInput = document.getElementById('password_field');
            if (pwInput) {
                pwInput.value = password;
                pwInput.type = 'text';
                const eye = document.getElementById('togglePw');
                if (eye) eye.className = 'fa-solid fa-eye-slash modal-toggle-pw';
            }
        });
    }

    const searchInput = document.getElementById('memberSearch');
    const rows = document.querySelectorAll('.member-row');
    const noResultsRow = document.getElementById('noResultsRow');
    const memberCountEl = document.getElementById('memberCount');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            let countVisible = 0;
            rows.forEach(row => {
                const searchStr = row.getAttribute('data-search');
                if (searchStr.includes(query)) {
                    row.style.display = '';
                    countVisible++;
                } else {
                    row.style.display = 'none';
                }
            });
            if (noResultsRow) {
                noResultsRow.style.display = (countVisible === 0) ? '' : 'none';
            }
            if (memberCountEl) {
                memberCountEl.textContent = countVisible;
            }
        });
    }

    function deleteUser(userId, name) {
        if (!confirm(`Bạn có chắc chắn muốn xóa tài khoản "${name}" không?\nHành động này không thể hoàn tác!`)) return;

        const btn = document.getElementById('del-' + userId);
        if (btn) btn.disabled = true;

        fetch('ajax-delete-user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: userId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const row = document.getElementById('row-' + userId);
                if (row) {
                    row.style.transition = 'all 0.3s ease-out';
                    row.style.opacity = '0';
                    row.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        row.remove();
                        const activeRows = document.querySelectorAll('.member-row');
                        if (memberCountEl) memberCountEl.textContent = activeRows.length;
                        if (activeRows.length === 0) location.reload();
                    }, 300);
                }
            } else {
                alert('Lỗi: ' + data.message);
                if (btn) btn.disabled = false;
            }
        })
        .catch(() => {
            alert('Có lỗi xảy ra, vui lòng thử lại.');
            if (btn) btn.disabled = false;
        });
    }

    <?php if ($message && $message_type === 'error'): ?>
    document.addEventListener('DOMContentLoaded', () => {
        openModal();
    });
    <?php endif; ?>
    <?php endif; ?>
</script>

</div> <!-- .app-body (Mở trong thanh-dieu-huong.php) -->
</div> <!-- .app-container (Mở trong thanh-dieu-huong.php) -->
</body>
</html>
