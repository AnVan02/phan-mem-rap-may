<?php require_once 'phan-quyen.php'; ?>
<!DOCTYPE html>
<html lang="vi">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <!-- Preconnect để browser kết nối CDN sớm, giảm latency -->
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link rel="preconnect" href="https://cdnjs.cloudflare.com">
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <link rel="stylesheet" href="./css/thanh-dieu-huong.css">
   <link rel="icon" href="/assets/images/rosa-icon.png" type="image/png">
</head>

<?php
require_once 'config.php';

$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['user_role'] ?? '';
$fullname = $_SESSION['fullname'] ?? 'User';

function isActive($page, $current_page)
{
   return ($page === $current_page) ? 'active' : '';
}

// Lấy số lượng linh kiện / máy thiếu từ Database cho phần thông báo sidebar
// $so_linh_kien_thieu = 0;
// if (isset($pdo)) {
//     try {
//         $stmt_notif = $pdo->query("
//             SELECT COUNT(DISTINCT CONCAT(c.id_donhang, '-', c.so_may))
//             FROM chitiet_donhang c
//             WHERE c.so_may IS NOT NULL AND c.so_may > 0
//               AND (
//                   c.ten_linhkien IS NULL OR c.ten_linhkien = ''
//                   OR NOT EXISTS (
//                       SELECT 1 FROM chitiet_donhang comp
//                       WHERE comp.id_donhang = c.id_donhang
//                         AND comp.so_may = c.so_may
//                         AND comp.loai_linhkien = 'CPU'
//                         AND comp.ten_linhkien != ''
//                   )
//               )
//         ");
//         $so_linh_kien_thieu = (int)$stmt_notif->fetchColumn();
//     } catch (Exception $e) {
//         $so_linh_kien_thieu = 0;
//     }
// }
// $so_linh_kien_thieu = 0;
// if (isset($pdo)) {
//     try {
//         $stmt_notif = $pdo->query("
//             SELECT COUNT(*) FROM (
//                 SELECT id_donhang, so_may
//                 FROM chitiet_donhang
//                 WHERE so_may IS NOT NULL AND so_may > 0
//                 GROUP BY id_donhang, so_may
//                 HAVING SUM(CASE WHEN ten_linhkien IS NULL OR ten_linhkien = '' THEN 1 ELSE 0 END) > 0
//                     OR SUM(CASE WHEN loai_linhkien = 'CPU' AND ten_linhkien != '' THEN 1 ELSE 0 END) = 0
//             ) as missing_groups
//         ");
//         $so_linh_kien_thieu = (int)$stmt_notif->fetchColumn();
//     } catch (Exception $e) {
//         $so_linh_kien_thieu = 0;
//     }
// }
$so_linh_kien_thieu = 0;
if (isset($pdo)) {
    // Cache kết quả đếm trong 30 giây để tránh query nặng chạy mỗi lần tải trang
    if (!isset($_SESSION['notif_count_time']) || (time() - $_SESSION['notif_count_time']) > 30) {
        try {
            $stmt_notif = $pdo->query("
                SELECT COUNT(*) FROM (
                    SELECT id_donhang, so_may
                    FROM chitiet_donhang
                    WHERE so_may IS NOT NULL AND so_may > 0
                    GROUP BY id_donhang, so_may
                    HAVING SUM(CASE WHEN ten_linhkien IS NULL OR ten_linhkien = '' THEN 1 ELSE 0 END) > 0
                        OR SUM(CASE WHEN loai_linhkien = 'CPU' AND ten_linhkien != '' THEN 1 ELSE 0 END) = 0
                ) as missing_groups
            ");
            $_SESSION['notif_count'] = (int)$stmt_notif->fetchColumn();
            $_SESSION['notif_count_time'] = time();
        } catch (Exception $e) {
            $_SESSION['notif_count'] = 0;
        }
    }
    $so_linh_kien_thieu = $_SESSION['notif_count'] ?? 0;
}
?>


<body>
   <div class="app-container">

      <!-- ===================================================
           SIDEBAR CHÍNH
           - Mặc định: MỞ RỘNG (icon + text)
           - Bấm nút 3 gạch: THU GỌN (chỉ icon)
           =================================================== -->
      <nav class="sidebar" id="main-sidebar">
         <script>
            if (window.innerWidth > 768 && localStorage.getItem('sidebar_collapsed') === '1') {
               document.getElementById('main-sidebar')?.classList.add('collapsed');
            }
         </script>

         <!-- Header: Logo + nút 3 gạch -->
         <div class="sidebar-header">
            <div class="sidebar-logo">
               <!-- Logo đầy đủ (hiện khi mở rộng) -->
               <img src="./image/logo.png" alt="ROSA Logo" class="logo-full">
               <!-- Logo icon chữ R (hiện khi thu gọn) -->
               <div class="logo-icon-r">R</div>
            </div>
            <button class="sidebar-toggle-btn" id="sidebar-toggle-btn" title="Thu gọn menu">
               <i class="fa-solid fa-bars"></i>
            </button>
         </div>

         <!-- Danh sách menu -->
         <div class="sidebar-links">

            <?php if (isAuthorized('dashboard-ky-thuat.php')): ?>
               <a href="dashboard-ky-thuat.php"
                  class="nav-item <?php echo isActive('dashboard-ky-thuat.php', $current_page); ?>"
                  data-tooltip="Dashboard Kỹ Thuật">
                  <span class="nav-icon"><i class="fa-solid fa-gauge-high"></i></span>
                  <span class="nav-label">Dashboard Kỹ Thuật</span>
               </a>
            <?php endif; ?>

            <?php if (isAuthorized('dashboard-ke-toan.php')): ?>
               <a href="dashboard-ke-toan.php"
                  class="nav-item <?php echo isActive('dashboard-ke-toan.php', $current_page); ?>"
                  data-tooltip="Dashboard Kế Toán">
                  <span class="nav-icon"><i class="fa-solid fa-chart-pie"></i></span>
                  <span class="nav-label">Dashboard Kế Toán</span>
               </a>
            <?php endif; ?>
            
             <?php if (isAuthorized('tra-cuu-linh-kien.php')): ?>
               <a href="tra-cuu-linh-kien.php"
                  class="nav-item <?php echo isActive('tra-cuu-linh-kien.php', $current_page); ?>"
                  data-tooltip="Tra Cứu Linh Kiện">
                  <span class="nav-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                  <span class="nav-label">Tra Cứu Linh Kiện</span>
               </a>
            <?php endif; ?>

            <?php if (isAuthorized('them-linh-kien-thieu.php')): ?>
               <a href="them-linh-kien-thieu.php"
                  class="nav-item <?php echo isActive('them-linh-kien-thieu.php', $current_page); ?>"
                  data-tooltip="Thêm linh kiện thiếu">
                  <span class="nav-icon"><i class="fa-solid fa-microchip"></i></span>
                  <span class="nav-label">Thêm linh kiện thiếu</span>
                  <i class="fa-solid fa-chevron-right nav-arrow"></i>
               </a>
            <?php endif; ?>

            <?php if (isAuthorized('danh_sach_don_hang.php')): ?>
               <a href="danh_sach_don_hang.php"
                  class="nav-item <?php echo isActive('danh_sach_don_hang.php', $current_page); ?>"
                  data-tooltip="Đơn hàng">
                  <span class="nav-icon"><i class="fa-solid fa-cart-shopping"></i></span>
                  <span class="nav-label">Đơn hàng</span>
               </a>
            <?php endif; ?>
            
            <?php if (isAuthorized('lich-su-lam-viec.php')): ?>
               <a href="lich-su-lam-viec.php"
                  class="nav-item <?php echo isActive('lich-su-lam-viec.php', $current_page); ?>"
                  data-tooltip="Lịch sử làm việc">
                  <span class="nav-icon"><i class="fa-solid fa-clock-rotate-left"></i></span>
                  <span class="nav-label">Lịch sử làm việc</span>
               </a>
            <?php endif; ?>


         </div><!-- /.sidebar-links -->

         <!-- Phần dưới: Thông báo + Hỗ trợ -->
         <div class="sidebar-bottom">
            <a href="them-linh-kien-thieu.php" class="sidebar-notification" data-tooltip="Thông báo" style="text-decoration: none; color: inherit;">
               <div class="notif-icon-wrap">
                  <i class="fa-regular fa-bell"></i>
                  <?php if ($so_linh_kien_thieu > 0): ?>
                     <span class="notif-badge"><?php echo $so_linh_kien_thieu; ?></span>
                  <?php endif; ?>
               </div>
               <div class="notif-content">
                  <span class="notif-title">Thông báo</span>
                  <p class="notif-desc">
                     <?php if ($so_linh_kien_thieu > 0): ?>
                        Bạn có <strong><?php echo $so_linh_kien_thieu; ?></strong> linh kiện cần cập nhật
                     <?php else: ?>
                        Tất cả máy đã đủ linh kiện
                     <?php endif; ?>
                  </p>
               </div>
               <i class="fa-solid fa-chevron-right notif-arrow"></i>
            </a>

            <!-- User avatar (hiện khi thu gọn) -->
            <a href="auth-logout.php" class="user-avatar-btn" title="<?php echo htmlspecialchars($fullname); ?> — Đăng xuất">
               <i class="fa-solid fa-circle-user"></i>
               <span class="user-avatar-badge"></span>
            </a>
         </div>

      </nav><!-- /.sidebar -->

      <!-- Overlay (mobile) -->
      <div class="sidebar-overlay" id="sidebar-overlay"></div>

      <!-- Vùng nội dung chính (mở ở đây, đóng trong từng trang con) -->
      <div class="app-body">

      <script src="./js/thanh-dieu-huong.js"></script>