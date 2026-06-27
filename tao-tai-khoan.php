<?php
require_once 'config.php';
require_once 'thanh-dieu-huong.php';

// Chỉ admin mới được truy cập trang này
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: dang-nhap.php");
    exit();
}

$message = '';
$message_type = '';

// Xử lý tạo tài khoản
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
        $message = 'Mật khẩu phải chứa ít nhất 1 ký tự đặc biệt (ví dụ: @, #, $, ...).';
        $message_type = 'error';
    } else {
        try {
            // Kiểm tra username đã tồn tại chưa
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
}

// Lấy danh sách tài khoản hiện có
$users = [];
try {
    $users = $pdo->query("SELECT id, username, fullname, role, created_at FROM users ORDER BY created_at DESC")->fetchAll();
} catch (PDOException $e) {}
?>

<!-- Custom CSS for Account Management Page -->
<style>
    :root {
        --primary-blue: #2563eb;
        --primary-hover: #1d4ed8;
        --bg-light: #f8fafc;
        --border-color: #f1f5f9;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --danger: #dc2626;
        --danger-light: #fee2e2;
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    }

    .main-content-account-mgmt {
        flex: 1;
        padding: 2rem;
        background-color: var(--bg-light);
        min-height: 100vh;
        width: 100%;
        box-sizing: border-box;
    }

    /* Header Section */
    .mgmt-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1.5rem;
    }

    .header-left h1 {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-main);
        margin: 0 0 0.5rem 0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .header-left p {
        display: flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 16px;
        font-weight: 600;
        color: #1E40AF;
        margin: 0;
    }

    .btn-add-account {
        background-color: var(--primary-blue);
        color: #fff;
        border: none;
        padding: 0.75rem 1.5rem;
        border-radius: 10px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: var(--shadow-sm);
        text-decoration: none;
    }

    .btn-add-account:hover {
        background-color: var(--primary-hover);
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }

    /* Filter / Search Controls */
    .filter-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        background: #fff;
        padding: 1rem;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: var(--shadow-sm);
    }

    .search-input-wrap {
        width: 380px;
        position: relative;
    }

    .search-input-wrap input {
        width: 100%;
        padding: 0.65rem 1rem 0.65rem 2.5rem;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        font-size: 0.9rem;
        outline: none;
        background: var(--bg-light);
        transition: all 0.2s;
    }

    .search-input-wrap input:focus {
        background: #fff;
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .search-input-wrap::before {
        content: '\f002';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: 0.9rem;
    }

    .stats-badge {
        background-color: #eff6ff;
        color: #1e40af;
        border: 1px solid #bfdbfe;
        padding: 0.4rem 0.85rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    /* Table Container */
    .status-table-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .status-table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
    }

    .status-table th {
        padding: 1rem 1.5rem;
        font-size: 0.85rem;
        text-transform: uppercase;
        color: var(--text-muted);
        font-weight: 600;
        background: var(--bg-light);
        border-bottom: 1px solid #e2e8f0;
    }

    .status-table td {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
        font-size: 15px;
        vertical-align: middle;
        color: var(--text-main);
        font-weight: 600;
    }

    .status-table tr:last-child td {
        border-bottom: none;
    }

    .status-table tr:hover {
        background-color: #fafbfc;
    }

    /* Avatars inside table */
    .user-meta-wrapper {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .user-avatar-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        text-transform: uppercase;
        border: 1.5px solid transparent;
    }

    .avatar-admin {
        background-color: #fef3c7;
        color: #d97706;
        border-color: #fde68a;
    }

    .avatar-ketoan {
        background-color: #fce7f3;
        color: #db2777;
        border-color: #fbcfe8;
    }

    .avatar-kythuat {
        background-color: #dbeafe;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }

    .td-fullname {
        color: var(--text-main);
        font-weight: 700;
    }

    .td-username {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 0.825rem;
        color: var(--primary-blue);
        background: #eff6ff;
        padding: 0.2rem 0.45rem;
        border-radius: 6px;
        font-weight: 600;
        border: 1px solid #bfdbfe;
    }

    /* Role Badges */
    .badge {
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border: 1px solid transparent;
    }

    .badge-admin {
        background: #fef9c3;
        color: #854d0e;
        border-color: #fde047;
    }

    .badge-ketoan {
        background: #fce7f3;
        color: #9d174d;
        border-color: #fbcfe8;
    }

    .badge-kythuat {
        background: #dbeafe;
        color: #1e40af;
        border-color: #bfdbfe;
    }

    /* Action Buttons */
    .actions {
        display: flex;
        gap: 0.5rem;
    }

    .btn-action-delete {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: var(--bg-light);
        color: var(--text-muted);
        border: 1px solid #e2e8f0;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-action-delete:hover {
        background: var(--danger);
        color: #fff;
        border-color: var(--danger);
    }

    .self-label {
        font-size: 0.775rem;
        color: var(--text-muted);
        background: #f1f5f9;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-weight: 600;
        border: 1px solid #e2e8f0;
    }

    /* ── POPUP MODAL ── */
    .modal-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background-color: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(4px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        animation: fadeInBackdrop 0.15s ease-out;
    }

    @keyframes fadeInBackdrop {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .modal-container {
        background: #ffffff;
        width: 460px;
        border-radius: 12px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        overflow: hidden;
        animation: slideUpModal 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes slideUpModal {
        from { transform: translateY(12px) scale(0.98); opacity: 0; }
        to { transform: translateY(0) scale(1); opacity: 1; }
    }

    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fafbfc;
    }

    .modal-header h3 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-close {
        background: transparent;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--text-muted);
        line-height: 1;
        padding: 0.25rem;
    }

    .modal-close:hover {
        color: var(--text-main);
    }

    .modal-body {
        padding: 1.5rem;
    }

    /* Form Fields inside Modal */
    .modal-form-group {
        margin-bottom: 1.25rem;
    }

    .modal-form-group label {
        display: block;
        margin-bottom: 0.45rem;
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--text-main);
    }

    .modal-form-group label span {
        color: var(--danger);
    }

    .modal-input-wrap {
        position: relative;
    }

    .modal-input-wrap i {
        position: absolute;
        left: 0.85rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: 0.9rem;
        pointer-events: none;
    }

    .modal-input-field {
        width: 100%;
        padding: 0.65rem 1rem 0.65rem 2.25rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.9rem;
        outline: none;
        box-sizing: border-box;
        transition: all 0.2s;
    }

    .modal-input-field:focus {
        border-color: var(--primary-blue);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .modal-toggle-pw {
        position: absolute;
        right: 0.85rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        cursor: pointer;
    }

    .modal-toggle-pw:hover {
        color: var(--text-main);
    }

    /* Horizontal Role Selector Grid */
    .modal-role-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 0.5rem;
    }

    .modal-role-option input[type="radio"] {
        display: none;
    }

    .modal-role-label {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.35rem;
        padding: 0.6rem 0.25rem;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.15s;
        text-align: center;
    }

    .modal-role-label:hover {
        background-color: #fafbfc;
        border-color: #94a3b8;
    }

    .modal-role-label .role-icon-box {
        width: 30px;
        height: 30px;
        border-radius: 6px;
        background: var(--bg-light);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        border: 1px solid #e2e8f0;
    }

    .modal-role-label .role-icon-box i {
        font-size: 0.85rem;
    }

    .modal-role-label .role-name {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-main);
    }

    .modal-role-option input[type="radio"]:checked + .modal-role-label {
        border-color: var(--primary-blue);
        background-color: #eff6ff;
        box-shadow: 0 0 0 1px var(--primary-blue);
    }

    .modal-role-option input[type="radio"]:checked + .modal-role-label .role-icon-box {
        background: var(--primary-blue);
        color: #fff;
        border-color: var(--primary-blue);
    }

    .modal-actions-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        margin-top: 1.5rem;
        padding-top: 1.25rem;
        border-top: 1px solid #e2e8f0;
    }

    .btn-cancel {
        background: #fff;
        border: 1px solid #cbd5e1;
        color: var(--text-main);
        padding: 0.6rem 1.25rem;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
    }

    .btn-cancel:hover {
        background: #f1f5f9;
    }

    .btn-confirm {
        background: var(--primary-blue);
        color: #fff;
        border: none;
        padding: 0.6rem 1.25rem;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        transition: all 0.15s;
    }

    .btn-confirm:hover {
        background: var(--primary-hover);
    }

    /* PHP Alert Box (placed above table) */
    .top-alert {
        padding: 0.75rem 1.25rem;
        border-radius: 10px;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        font-weight: 600;
        border: 1px solid transparent;
    }

    .top-alert-success {
        background-color: #dcfce7;
        color: #166534;
        border-color: #bbf7d0;
    }

    .top-alert-error {
        background-color: #fee2e2;
        color: #991b1b;
        border-color: #fecaca;
    }

    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: var(--text-muted);
    }

    .empty-state i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        opacity: 0.5;
    }

    @media (max-width: 768px) {
        .main-content-account-mgmt {
            padding: 1rem;
        }

        .mgmt-header {
            flex-direction: column;
            gap: 1rem;
        }

        .btn-add-account {
            width: 100%;
            justify-content: center;
        }

        .filter-controls {
            flex-direction: column;
            gap: 1rem;
            align-items: stretch;
        }

        .search-input-wrap {
            width: 100%;
        }

        .stats-badge {
            justify-content: center;
        }
    }
</style>

<main class="main-content-account-mgmt">

    <!-- ── MGMT HEADER ── -->
    <header class="mgmt-header">
        <div class="header-left">
            <h1>
                <i class="fa-solid fa-users-gear" style="color: var(--primary-blue);"></i>
                Quản Lý Tài Khoản
            </h1>
            <p>
                <i class="fa-solid fa-circle-check"></i>
                Danh sách thành viên và phân quyền vận hành hệ thống 
                <span style="color:#FF0000;"> ROSA </span>
            </p>
        </div>
        <div class="header-right">
            <button class="btn-add-account" onclick="openModal()">
                <i class="fa-solid fa-plus-circle"></i>
                Thêm tài khoản mới
            </button>
        </div>
    </header>

    <!-- PHP Success Notification Alert -->
    <?php if ($message && $message_type === 'success'): ?>
    <div class="top-alert top-alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <span><?= htmlspecialchars($message) ?></span>
    </div>
    <?php endif; ?>

    <!-- ── FILTER CONTROLS ── -->
    <div class="filter-controls">
        <div class="search-input-wrap">
            <input type="text" id="memberSearch" placeholder="Tìm kiếm thành viên theo họ tên, username...">
        </div>
        <div class="stats-badge">
            <i class="fa-solid fa-users"></i>
            Tổng số: <strong id="memberCount"><?= count($users) ?></strong> thành viên
        </div>
    </div>

    <!-- ── DANH SÁCH THÀI KHOẢN TABLE ── -->
    <div class="status-table-card">
        <?php if (empty($users)): ?>
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
                <?php foreach ($users as $u): ?>
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
                <!-- No result message for search -->
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

</main>

<!-- ── POPUP MODAL FORM TẠO TÀI KHOẢN ── -->
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
            <!-- PHP Error Alert inside Modal -->
            <?php if ($message && $message_type === 'error'): ?>
            <div class="alert alert-error" style="padding: 0.6rem 0.875rem; border-radius: 6px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; font-weight: 600; background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; line-height: 1.4;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="createForm">
                
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

<script>
    // JS: Điều khiển Modal
    const modal = document.getElementById('accountModal');

    function openModal() {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    // Đóng modal khi click ra ngoài vùng modal container
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    // Toggle Ẩn/Hiện mật khẩu
    document.getElementById('togglePw').addEventListener('click', function() {
        const pw = document.getElementById('password_field');
        const isText = pw.type === 'text';
        pw.type = isText ? 'password' : 'text';
        this.classList.toggle('fa-eye');
        this.classList.toggle('fa-eye-slash');
    });

    // JS: Tự động tạo mật khẩu mạnh (10 ký tự, đầy đủ chữ thường, chữ hoa, số và ký tự đặc biệt)
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
            
            // Trộn các ký tự ngẫu nhiên
            password = password.split('').sort(() => 0.5 - Math.random()).join('');
            
            const pwInput = document.getElementById('password_field');
            if (pwInput) {
                pwInput.value = password;
                pwInput.type = 'text'; // Hiện mật khẩu vừa tạo để admin dễ copy/ghi nhớ
                
                // Đồng bộ hóa biểu tượng mắt hiển thị
                const eye = document.getElementById('togglePw');
                if (eye) {
                    eye.className = 'fa-solid fa-eye-slash modal-toggle-pw';
                }
            }
        });
    }

    // JS: Tìm kiếm thành viên thời gian thực
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

    // JS: Xóa thành viên AJAX
    function deleteUser(userId, name) {
        if (!confirm(`Bạn có chắc chắn muốn xóa tài khoản "${name}" không?\nHành động này không thể hoàn tác!`)) return;

        const btn = document.getElementById('del-' + userId);
        if (btn) { btn.disabled = true; }

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
                        // Cập nhật lại số lượng hiển thị
                        const activeRows = document.querySelectorAll('.member-row');
                        if (memberCountEl) memberCountEl.textContent = activeRows.length;
                        if (activeRows.length === 0) location.reload();
                    }, 300);
                }
            } else {
                alert('Lỗi: ' + data.message);
                if (btn) { btn.disabled = false; }
            }
        })
        .catch(() => {
            alert('Có lỗi xảy ra, vui lòng thử lại.');
            if (btn) { btn.disabled = false; }
        });
    }
</script>

<!-- Nếu có lỗi php hoặc submit thất bại, giữ modal mở để người dùng xem/sửa lỗi -->
<?php if ($message && $message_type === 'error'): ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        openModal();
    });
</script>
<?php endif; ?>

<!-- Close tags opened in thanh-dieu-huong.php -->
</div> <!-- .app-body -->
</div> <!-- .app-container -->
</body>
</html>
