<?php

/**
 * FILE: phan-quyen.php
 * CHỨC NĂNG: Kiểm soát mọi quyền truy cập của người dùng.
 * CÁCH DÙNG: File này được 'require' ở đầu thanh-dieu-huong.php,
 * nên mọi trang có thanh menu đều sẽ được bảo vệ bởi logic này.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start(); // Bật session để nhận diện người dùng đã đăng nhập chưa
}

// --- DANH SÁCH FILE CHO PHÉP ---
// Nếu bạn muốn chặn/cho phép thêm file nào, hãy sửa ở 2 mảng này.

// Danh sách các trang mà nhân viên Kỹ thuật ĐƯỢC PHÉP truy cập
$allowed_kythuat = [
    'dashboard-ky-thuat.php',
    'nhap-serial.php',
    'tra-cuu-linh-kien.php',
    'kho-hang.php',
    'kho-import-serial.php',
    'kiemtra.php',
    'auth-logout.php',
    'dang-nhap.php'
];

// Danh sách các trang mà nhân viên Kế toán ĐƯỢC PHÉP truy cập
$allowed_ketoan = [
    'dashboard-ke-toan.php',
    'ke-toan-tao-don.php',
    'dashboard-ky-thuat.php',
    'tra-cuu-linh-kien.php',
    'nhap-serial.php',
    'xuat-file.php',
    'import-excel.php',
    'auth-logout.php',
    'dang-nhap.php'
];

// Tên trang hiện tại người dùng đang đứng (Ví dụ: nhap-serial.php)
$current_page = basename($_SERVER['PHP_SELF']);

// --- LOGIC KIỂM TRA QUYỀN TRUY CẬP (Khi người dùng gõ URL) ---

if (!isset($_SESSION['user_role'])) {
    // Nếu chưa đăng nhập (không có user_role) mà không phải đang ở trang login -> đá về trang login
    if ($current_page !== 'dang-nhap.php') {
        header("Location: dang-nhap.php");
        exit();
    }
} else {
    // Nếu ĐÃ đăng nhập, lấy vai trò của họ ra (admin, ketoan, kythuat)
    $role = $_SESSION['user_role'];

    // 1. Kiểm tra cho Kỹ thuật
    if ($role == 'kythuat') {
        // Cho phép các file xử lý ngầm (AJAX) không bị chặn
        $is_ajax = (strpos($current_page, 'ajax-') === 0 || strpos($current_page, 'luu-') === 0 || strpos($current_page, 'xoa-') === 0);

        // [ĐÃ LOẠI BỎ] Bỏ qua bước xác thực lần 2 để tăng tốc độ làm việc cho kỹ thuật.
        // Chỉ cần đăng nhập hệ thống là đủ.

        // Nếu trang đang vào không nằm trong danh sách cho phép -> đá về Dashboard Kỹ thuật
        if (!in_array($current_page, $allowed_kythuat) && !$is_ajax) {
            header("Location: dashboard-ky-thuat.php?error=no_access");
            exit();
        }
    }

    // 2. Kiểm tra cho Kế toán
    if ($role == 'ketoan') {
        $is_ajax = (strpos($current_page, 'ajax-') === 0 || strpos($current_page, 'luu-') === 0 || strpos($current_page, 'xoa-') === 0);

        // Nếu trang đang vào không nằm trong danh sách cho phép -> đá về Dashboard Kế toán
        if (!in_array($current_page, $allowed_ketoan) && !$is_ajax) {
            header("Location: dashboard-ke-toan.php?error=no_access");
            exit();
        }
    }

    // 3. Admin: Không bị kiểm tra, được vào mọi nơi.
}

/**
 * KIỂM TRA XÁC THỰC QUÉT MÃ (LẦN 2) - DÀNH RIÊNG CHO KỸ THUẬT
 * Trả về true nếu đã xác thực mật khẩu trong phiên này
 */
function isScanVerified()
{
    // Luôn trả về true để bỏ qua bước xác thực lần 2 theo yêu cầu
    return true;
}

/**
 * HÀM KIỂM TRA QUYỀN TRUY CẬP TRANG (Dùng cho Menu & Redirect)
 */
function isAuthorized($page)
{
    global $allowed_kythuat, $allowed_ketoan;

    if (!isset($_SESSION['user_id']))
        return false;

    $role = $_SESSION['user_role'];

    // Admin toàn quyền
    if ($role === 'admin')
        return true;

    // Logic cho Kỹ thuật
    if ($role === 'kythuat') {
        return in_array($page, $allowed_kythuat);
    }

    // Logic cho Kế toán
    if ($role === 'ketoan') {
        return in_array($page, $allowed_ketoan);
    }

    return false;
}

/**
 * HÀM: hasPermission(cấp_độ)
 * CHỨC NĂNG: Kiểm tra quyền thực thi các hành động cụ thể dựa trên vai trò.
 */
function hasPermission($required_role_level)
{
    if (!isset($_SESSION['user_role']))
        return false;

    $current_role = $_SESSION['user_role'];

    // Admin có mọi quyền
    if ($current_role === 'admin')
        return true;

    // Logic cho từng role
    if ($required_role_level === 'ketoan') {
        return ($current_role === 'ketoan');
    }

    if ($required_role_level === 'kythuat') {
        return ($current_role === 'kythuat' || $current_role === 'ketoan');
    }

    return false;
}
