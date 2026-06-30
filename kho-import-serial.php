<?php
ob_start(); // Chống lỗi "headers already sent"
require "config.php";
require_once 'phan-quyen.php';

$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 1;
$l_cfg_req = isset($_GET['config']) ? mb_strtolower(trim($_GET['config']), 'UTF-8') : '';
$m_idx_req = isset($_GET['m']) ? (int) $_GET['m'] : 1;
$user_id = $_SESSION['user_id'] ?? null;
$is_fresh = false;

if (!$user_id) {
   header("Location: dang-nhap.php");
   echo '<script>window.location.href="dang-nhap.php";</script>';
   exit;
}

if ($pdo) {
   // --- LOGIC PHÒNG CHỐNG COPY URL TRỰC TIẾP ---
   $m_key = "{$order_id}_{$m_idx_req}_{$l_cfg_req}";
   $allow_entry = false;

   if (isset($_SESSION['ENTRY_TOKEN']) && $_SESSION['ENTRY_TOKEN'] === $m_key) {
      $allow_entry = true;
      $_SESSION['ENTRY_TOKEN_VAL_FOUND'] = true; // Cắm cờ để JS nhận diện
      unset($_SESSION['ENTRY_TOKEN']);
      $_SESSION['LAST_MACHINE_ENTERED'] = $m_key;
   } elseif (isset($_SESSION['LAST_MACHINE_ENTERED']) && $_SESSION['LAST_MACHINE_ENTERED'] === $m_key) {
      $allow_entry = true;
      unset($_SESSION['ENTRY_TOKEN_VAL_FOUND']); // Refresh thì không còn là vào bằng token nữa
   }

   if (!$allow_entry) {
      header("Location: kho-hang.php?id=" . $order_id);
      echo '<script>window.location.href="kho-hang.php?id=' . $order_id . '";</script>';
      exit;
   }
   // ---------------------------------------------

   // --- ĐÃ LOẠI BỎ: Kiểm tra bận máy khác để cho phép làm song song nhiều đơn hàng ---
   /*
   $stmt_busy = $pdo->prepare("SELECT id_donhang, so_may, config_name FROM trang_thai_lap_may WHERE user_id = ? LIMIT 1");
   $stmt_busy->execute([$user_id]);
   $current_lock = $stmt_busy->fetch(PDO::FETCH_ASSOC);

   if ($current_lock) {
      // Chỉ cần khớp ID đơn hàng và Số máy là coi như cùng 1 máy (để tránh lỗi ký tự tiếng Việt ở tên cấu hình)
      $is_same = ($current_lock['id_donhang'] == $order_id && (int)$current_lock['so_may'] == $m_idx_req);

      if (!$is_same) {
         // Nếu thực sự đang làm máy KHÁC, thì mới đẩy về
         header("Location: kho-hang.php?id=" . $current_lock['id_donhang']);
         exit;
      }
   }
   */

   // 2. Kiểm tra xem máy này có đang bị người khác khóa không (Active lock)
   $stmt_check = $pdo->prepare("SELECT user_id FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ? AND config_name = ? LIMIT 1");
   $stmt_check->execute([$order_id, $m_idx_req, $l_cfg_req]);
   $current_lock = $stmt_check->fetch(PDO::FETCH_ASSOC);

   if ($current_lock && $current_lock['user_id'] != $user_id) {
      // Nếu bị người khác khóa, không cho vào
      ob_end_clean();
      echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script><script>Swal.fire({title:'Máy Đang Được Xử Lý',text:'Máy này đang được người khác xử lý! Vui lòng chọn máy khác.',icon:'error'}).then(()=>{window.location.href='kho-hang.php?id=$order_id';});</script>";
      exit;
   }

   // 2b. Kiểm tra xem máy này đã được gán cố định cho người khác trong chitiet_donhang chưa
   $stmt_assigned = $pdo->prepare("SELECT user_id FROM chitiet_donhang WHERE id_donhang = ? AND linhkien_chon = ? AND so_may = ? AND user_id IS NOT NULL AND user_id != ? LIMIT 1");
   $stmt_assigned->execute([$order_id, $l_cfg_req, $m_idx_req, $user_id]);
   if ($stmt_assigned->fetch()) {
      ob_end_clean();
      echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script><script>Swal.fire({title:'Máy Đã Hoàn Thiện',text:'Máy này đã được xác nhận xường khác! Vui lòng chọn máy khác.',icon:'success',confirmButtonColor:'#10B981'}).then(()=>{window.location.href='kho-hang.php?id=$order_id';});</script>";
      exit;
   }


   // 3. Xử lý logic Fresh Entry (Xóa dữ liệu cũ nếu là phiên mới)
   $m_key = "{$order_id}_{$m_idx_req}_{$l_cfg_req}";
   if (isset($_SESSION['FRESH_LOCK_' . $m_key])) {
      $is_fresh = true;
      unset($_SESSION['FRESH_LOCK_' . $m_key]);
   }
   // 4. Ghi nhận/Cập nhật khóa máy cho user hiện tại (Trong bảng trạng thái)
   $stmt_lock = $pdo->prepare("INSERT INTO trang_thai_lap_may (id_donhang, so_may, config_name, user_id) 
                                VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id = ?, last_active = CURRENT_TIMESTAMP");
   $stmt_lock->execute([$order_id, $m_idx_req, $l_cfg_req, $user_id, $user_id]);
}


require "thanh-dieu-huong.php";

$order = null;
$comps = [];
$so_imei = '';
$dNameMaster = $_GET['config'] ?? '';

if ($pdo) {
   try {
      $stmt = $pdo->prepare("SELECT * FROM donhang WHERE id_donhang = ?");
      $stmt->execute([$order_id]);
      $order = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($order) {
         $stmt = $pdo->prepare("SELECT * FROM chitiet_donhang WHERE id_donhang = ? ORDER BY id_ct ASC");
         $stmt->execute([$order_id]);
         $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

         $config_qtys = [];
         $temp_all_configs = [];
         foreach ($all_rows as $row) {
            $parts = array_map(function ($p) {
               return mb_strtolower(trim($p), 'UTF-8');
            }, explode(',', $row['ten_cauhinh']));
            foreach ($parts as $p) {
               if ($p !== '')
                  $temp_all_configs[$p][] = $row;
            }
         }
         foreach ($temp_all_configs as $k => $items) {
            $qty = 0;
            $exclusive = array_filter($items, function ($item) use ($k) {
               $ps = array_map(function ($p) {
                  return mb_strtolower(trim($p), 'UTF-8');
               }, explode(',', $item['ten_cauhinh']));
               return count($ps) === 1 && $ps[0] === $k;
            });
            $t_counts = array_count_values(array_map('mb_strtolower', array_column(array_values($exclusive), 'loai_linhkien')));
            $pref = ['cpu', 'main', 'mainboard', 'vga', 'ssd', 'psu', 'fan',];
            foreach ($pref as $t) {
               if (!empty($t_counts[$t])) {
                  $qty = (int) $t_counts[$t];
                  break;
               }
            }
            if ($qty === 0) {
               $all_t_counts = array_count_values(array_map('mb_strtolower', array_column($items, 'loai_linhkien')));
               foreach ($pref as $t) {
                  if (!empty($all_t_counts[$t])) {
                     $qty = (int) $all_t_counts[$t];
                     break;
                  }
               }
            }
            $config_qtys[$k] = $qty > 0 ? $qty : 1;
         }

         $current_config_key = mb_strtolower(trim($l_cfg_req), 'UTF-8');
         $current_config_machine_count = $config_qtys[$current_config_key] ?? 0;
         $total_configs = count($config_qtys);
         $total_machines = array_sum($config_qtys);
         $imei_arr = [];
         if (!empty($order['imei'])) {
            $decoded_imei = json_decode($order['imei'], true);
            if (is_array($decoded_imei)) {
               $imei_arr = $decoded_imei;
            }
         }
         $total_order_imeis = count($imei_arr);

         // [QUAN TRỌNG] TÍNH TOÁN SỐ THỨ TỰ MÁY TOÀN CỤC ĐỂ LẤY IMEI CHÍNH XÁC (DỮ LIỆU THẬT)
         $global_machine_idx = 0;
         foreach ($config_qtys as $cfg_name => $qty) {
            if (mb_strtolower($cfg_name, 'UTF-8') === mb_strtolower($l_cfg_req, 'UTF-8')) {
               $global_machine_idx += $m_idx_req;
               break;
            }
            $global_machine_idx += $qty;
         }

         // Lấy IMEI dựa trên số thứ tự máy toàn cục
         $machine_imei_values = [];

         if (!empty($imei_arr)) {
            $target_imei = $imei_arr[$global_machine_idx - 1] ?? '';
            if (!empty(trim((string) $target_imei))) {
               $machine_imei_values[] = trim((string) $target_imei);
            }
         }

         // Ưu tiên linh kiện IMEI trong chitiet_donhang nếu đã được gán đích danh
         $stmt_imei = $pdo->prepare("SELECT so_serial FROM chitiet_donhang WHERE id_donhang = ? AND UPPER(loai_linhkien) IN ('IMEI', 'IMER') AND LOWER(linhkien_chon) = LOWER(?) AND so_may = ? AND so_serial <> '' ORDER BY id_ct ASC");
         $stmt_imei->execute([$order_id, $l_cfg_req, $m_idx_req]);
         $imei_db_vals = $stmt_imei->fetchAll(PDO::FETCH_COLUMN);
         foreach ($imei_db_vals as $imei_db_val) {
            if (!empty(trim((string) $imei_db_val))) {
               $machine_imei_values[] = trim((string) $imei_db_val);
            }
         }

         $machine_imei_values = array_values(array_unique($machine_imei_values));
         $so_imei = implode(' / ', $machine_imei_values);


         // BƯỚC 2: PHÂN BỔ - ƯU TIÊN LINH KIỆN ĐÃ GÁN
         $my_db_rows = [];
         $global_type_groups = [];
         foreach ($all_rows as $item) {
            $config_normalized = array_map(function ($p) {
               return mb_strtolower(trim($p), 'UTF-8');
            }, explode(',', $item['ten_cauhinh']));
            // sort($config_normalized); // Tắt sắp xếp Alphabet để khớp với dữ liệu tạo đơn
            $type_key = mb_strtolower(trim($item['loai_linhkien']), 'UTF-8') . '|' . mb_strtolower(trim((string) $item['ten_linhkien']), 'UTF-8') . '|' . implode(',', $config_normalized);
            $global_type_groups[$type_key][] = $item;
         }

         foreach ($global_type_groups as $type_key => $sublist) {
            $pool_configs = array_map(function ($p) {
               return mb_strtolower(trim($p), 'UTF-8');
            }, explode(',', $sublist[0]['ten_cauhinh']));
            $pool_configs = array_values(array_unique(array_map('trim', $pool_configs)));
            // sort($pool_configs); // Tắt sắp xếp Alphabet để khớp với dữ liệu tạo đơn

            $total_m = 0;
            foreach ($pool_configs as $pc) {
               $total_m += ($config_qtys[$pc] ?? 0);
            }

            if ($total_m > 0) {
               // Phân loại
               $already_assigned = [];
               $free_pool = [];

               // NEW: Đếm số linh kiện thực tế thuộc về từng cấu hình trong pool này (Space Hack)
               $items_by_config = [];
               foreach ($sublist as $it) {
                  $t_owner = rtrim($it['ten_cauhinh'], ' ');
                  $s_owner = strlen($it['ten_cauhinh']) - strlen($t_owner);
                  $p_owner = array_map('trim', explode(',', $t_owner));
                  $owner_name = mb_strtolower($p_owner[$s_owner] ?? $p_owner[0], 'UTF-8');
                  $items_by_config[$owner_name] = ($items_by_config[$owner_name] ?? 0) + 1;

                  $c_chon = mb_strtolower(trim($it['linhkien_chon'] ?? ''), 'UTF-8');
                  $m_chon = (int) ($it['so_may'] ?? 0);
                  if (!empty($it['so_serial']) && $c_chon !== '' && $m_chon > 0) {
                     $already_assigned[$c_chon][$m_chon][] = $it;
                  } else {
                     $free_pool[] = $it;
                  }
               }

               $free_idx = 0;
               foreach ($pool_configs as $pc) {
                  $pc_qty = $config_qtys[$pc] ?? 0;
                  if ($pc_qty <= 0)
                     continue;

                  $pc_total_items = $items_by_config[$pc] ?? 0;
                  $pc_base = floor($pc_total_items / $pc_qty);
                  $pc_rem = $pc_total_items % $pc_qty;

                  for ($m = 1; $m <= $pc_qty; $m++) {
                     // Phân phối dư (remainder) cho các máy đầu tiên của cấu hình đó
                     $count_needed = $pc_base + ($m <= $pc_rem ? 1 : 0);

                     // Lấy linh kiện cho Slot này
                     $slot_items = [];
                     // 1. Ưu tiên đã gán
                     $mine = $already_assigned[$pc][$m] ?? [];
                     foreach ($mine as $it) {
                        $slot_items[] = $it;
                     }
                     // 2. Lấy thêm từ pool tự do
                     $still_needed = $count_needed - count($mine);
                     if ($still_needed > 0) {
                        $added = array_slice($free_pool, $free_idx, $still_needed);
                        foreach ($added as $it) {
                           $loai_tmp = strtolower(trim($it['loai_linhkien'] ?? ''));
                           // ✅ Chỉ xóa serial của linh kiện thường, KHÔNG xóa IMEI/IMER
                           if ($loai_tmp !== 'imei' && $loai_tmp !== 'imer') {
                              $it['so_serial'] = '';
                           }
                           $slot_items[] = $it;
                        }
                        $free_idx += count($added);
                     }

                     // Nếu trúng cấu hình và máy đang yêu cầu thì nạp vào results
                     if ((string) $pc == (string) $l_cfg_req && (int) $m == (int) $m_idx_req) {
                        foreach ($slot_items as $it) {
                           $my_db_rows[] = $it;
                        }
                     }
                  }
               }
            }
         }
         usort($my_db_rows, function ($a, $b) {
            $o = ['cpu' => 1, 'main' => 2, 'ram' => 3, 'ssd' => 4, 'vga' => 5, 'psu' => 6, 'fan' => 7];
            $pA = $o[strtolower(trim($a['loai_linhkien']))] ?? 99;
            $pB = $o[strtolower(trim($b['loai_linhkien']))] ?? 99;
            return ($pA !== $pB) ? ($pA <=> $pB) : ($a['id_ct'] <=> $b['id_ct']);
         });


         // --------------------------------------------------------------------------

         foreach ($my_db_rows as $mi) {
            $l_type = strtolower(trim($mi['loai_linhkien'] ?? ''));
            if ($l_type === 'imei' || $l_type === 'imer') {
               // Vẫn lấy giá trị IMEI để hiển thị ở header
               $val_tmp = trim((string) ($mi['so_serial'] ?? ''));
               if (!empty($val_tmp)) {
                  $so_imei = $val_tmp;
               }
               continue; // Bỏ qua, không thêm vào $comps
            }

            // if ($l_type === 'imei' || $l_type === 'imer') {
            //    $val_tmp = trim((string) ($mi['so_serial'] ?? ''));
            //    if (!empty($val_tmp)) {
            //       $so_imei = $val_tmp;
            //    }
            // }
            $lbl = strtoupper($mi['loai_linhkien']);
            $has_s = !empty($mi['so_serial']);
            $is_verified = !empty($mi['user_id_save']);
            $is_m = ($has_s && (string) mb_strtolower($mi['linhkien_chon'] ?? '', 'UTF-8') == (string) $l_cfg_req && (int) $mi['so_may'] == (int) $m_idx_req);

            // Xác định icon
            $icon = 'fa-microchip';
            if ($l_type === 'imei' || $l_type === 'imer') {
               $icon = 'fa-fingerprint';
            }

            $comps[] = [
               'id_ct' => (int) $mi['id_ct'],
               'loai' => $mi['loai_linhkien'],
               'ten' => $mi['ten_linhkien'],
               'label' => $lbl,
               'icon' => $icon,
               'prefilled' => ($is_m ? $mi['so_serial'] : '')
            ];
         }
      }
   } catch (PDOException $e) {
      echo "<div style='color:red; padding:20px; background:#fff;'>Lỗi hệ thống: " . $e->getMessage() . "</div>";
   }
}
?>

<link rel="stylesheet" href="./css/kho-import-serial.css">
<main class="main-content-scan">
    <!-- Breadcrumbs -->
    <nav class="breadcrumb">
        <a href="don-hang.php">Đơn hàng</a>
        <span></span>
        <span><i class="fa-solid fa-chevron-right"></i></span>
        <a href="kho-hang.php?id=<?php echo $order_id; ?>">Kho hàng</a>
        <span><i class="fa-solid fa-chevron-right"></i></span>
        <span class="active">Quét mã Serial</span>
    </nav>
    <div class="page-header">
        <div class="header-card">
            <div class="header-machine-block">
                <span class="machine-label">Máy số</span>
                <span class="machine-number"><?php echo $m_idx_req; ?></span>
            </div>
            <div class="header-v-divider"></div>
            <div class="header-main-info">
                <span class="header-action-title">Kiểm Tra Serial Linh Kiện</span>
                <div class="header-tags">
                    <span class="header-tag">
                        <i class="fa-regular fa-file-lines"></i>
                        <?php echo htmlspecialchars($dNameMaster); ?>
                    </span>
                    <span class="header-tag">
                        <i class="fa-solid fa-barcode"></i>
                        IMEI: <span id="header-imei-display"><?php echo htmlspecialchars($so_imei ?: '—'); ?></span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="component-list-card">
        <div class="list-header">
            <h2 class="list-title">Danh sách linh kiện cần nhập</h2>
            <span class="scan-note">* Sử dụng máy quét để nhập nhanh</span>
        </div>
        <?php foreach ($comps as $c): ?>
        <div class="component-item">
            <?php
            $comp_fullname = $c['label'];
            $icon_html = '<i class="fa-solid fa-microchip"></i>';
            switch (strtoupper($c['loai'])) {
               // BỘ XỬ LÝ 
               case 'CPU':
                  $comp_fullname = 'BỘ VI XỬ LÝ (CPU)';
                  $icon_html = '<i class="fa-solid fa-microchip" style="color:#e74c3c;"></i>';
                  break;

               // BO MẠCH CHỦ 
               case 'MAIN':
                  $comp_fullname = 'BO MẠCH CHỦ (MAIN)';
                  $icon_html = '<i class="fa-solid fa-server" style="color:#8e44ad;"></i>';
                  break;
               
               // BỘ NHỚ 
               case 'RAM':
                  $comp_fullname = 'BỘ NHỚ (RAM)';
                  $icon_html = '<i class="fa-solid fa-memory" style="color:#27ae60;"></i>';
                  break;

               // Ổ CỨNG 
               case 'SSD':
               case 'HDD':
                  $comp_fullname = 'Ổ CỨNG (' . strtoupper($c['loai']) . ')';
                  $icon_html = '<i class="fa-solid fa-hard-drive" style="color:#2980b9;"></i>';
                  break;

               // CART ĐỒ HOẠ
               case 'VGA':
                  $comp_fullname = 'CARD ĐỒ HỌA (GPU)';
                  $icon_html = '<i class="fa-solid fa-film" style="color:#f39c12;"></i>';
                  break;
               
               // QUẠT
               case 'FAN':
                  $comp_fullname = 'QUẠT TẢN NHIỆT (FAN)';
                  $icon_html = '<i class="fa-solid fa-fan" style="color:#7f8c8d;"></i>';
                  break;

               // NGUỒN 
               case 'PSU':
                  $comp_fullname = 'NGUỒN (PSU)';
                  $icon_html = '<i class="fa-solid fa-plug" style="color:#2c3e50;"></i>';
                  break;

               // THÙNG MÁY 
               case 'CASE':
                  $comp_fullname = 'THÙNG MÀY (CASE)';
                  $icon_html = '<i class="fa-solid fa-box" style="color:#1152d4;"></i>';
                  break;
                  
               // HỆ ĐIỀU HÀNH 
               case 'WIN':
                  $comp_fullname = 'PHẦN MỀM';
                  $icon_html = '<i class="fa-brands fa-windows" style="color:#00a8ff;"></i>';
                  break;
            }
            ?>

            <div class="comp-info-side">
                <div class="comp-icon-box">
                    <?php echo $icon_html; ?>
                </div>
                <div class="comp-text">
                    <span class="comp-category"><?php echo htmlspecialchars($comp_fullname); ?></span>
                    <?php
                  // Chuẩn hóa "siêu sạch" để so sánh: bỏ khoảng trắng, bỏ ngoặc, về chữ thường
                  $clean_name = preg_replace('/[\s\(\)]+/', '', mb_strtolower($c['ten'], 'UTF-8'));
                  $clean_category = preg_replace('/[\s\(\)]+/', '', mb_strtolower($comp_fullname, 'UTF-8'));

                  if ($clean_name !== $clean_category):
                  ?>
                    <span class="comp-name"><?php echo htmlspecialchars($c['ten']); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="comp-input-side">
                <?php
               $l_type = strtolower(trim($c['loai'] ?? ''));
               ?>
                <div class="input-wrapper">
                    <input type="text" class="scan-input <?php echo !empty($c['prefilled']) ? 'is-valid' : ''; ?>"
                        data-id-ct="<?php echo $c['id_ct']; ?>" data-name="<?php echo htmlspecialchars($c['ten']); ?>"
                        data-loai="<?php echo htmlspecialchars($c['loai']); ?>"
                        data-choice="<?php echo htmlspecialchars($dNameMaster); ?>"
                        placeholder="<?php echo ($l_type === 'imei' || $l_type === 'imer' ) ? 'Nhập mã máy...' : 'Quét hoặc nhập serial...'; ?>"
                        value="<?php echo htmlspecialchars($c['prefilled']); ?>">
                    <!-- <?php if (strtoupper($c['loai']) === 'IMEI' || strtoupper($c['loai']) === 'IMER'): ?>
                     <div class="input-note">Không kiểm tra</div>
                  <?php endif; ?> -->
                    <div class="input-actions-group">
                        <div class="status-indicator <?php echo !empty($c['prefilled']) ? 'success' : ''; ?>">
                            <?php if (!empty($c['prefilled'])): ?>
                            <i class="fa-solid fa-circle-check"></i>
                            <?php endif; ?>
                        </div>
                        <div class="barcode-action-btn">
                            <i class="fa-solid fa-barcode scan-btn-icon" title="Nhấn để quét mã"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="footer-actions">
        <button type="button" class="btn-back"
            onclick="window.location.href='kho-hang.php?id=<?php echo $order_id; ?>'">Quay lại</button>
        <button type="button" class="btn-confirm" id="btnConfirm" data-next-url="">
            Xác nhận Lưu
        </button>
    </div>
</main>
<div id="scan-toast" class="scan-toast"></div>

<!-- SCANNER UI MỚI -->
<input type="file" id="scan-file-input" accept="image/*" capture="environment" style="display: none;">

<!-- Scanner UI Modal (Refined design) -->
<div id="scanner-ui-modal" class="scanner-modal" style="display:none;">
    <div class="scanner-ui-container">
        <div class="scanner-ui-header">
            <div class="title-wrap">
                <i class="fa-solid fa-qrcode"></i>
                <h3>QUÉT MÃ SERIAL</h3>
            </div>
            <button type="button" class="btn-close-scanner-icon btn-close-scanner"><i
                    class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="scanner-ui-body">
            <div class="scanner-preview-area" id="modalPreviewArea">
                <div class="scanner-placeholder" id="modalPlaceholder">
                    <div class="icon-circle">
                        <i class="fa-solid fa-camera"></i>
                    </div>
                    <span>Nhấn để chụp hoặc chọn ảnh</span>
                </div>
                <img id="modal-preview-img" alt="Preview" style="display:none;">
                <div class="scanner-corners" id="modalCorners">
                    <div class="scanner-corner tl"></div>
                    <div class="scanner-corner tr"></div>
                    <div class="scanner-corner bl"></div>
                    <div class="scanner-corner br"></div>
                </div>

                <div class="scanner-loading-overlay" id="modalLoading" style="display:none;">
                    <div class="spinner"></div>
                    <span id="loadingTextModal">Đang nạp ảnh...</span>
                </div>
            </div>

            <div class="scanner-status-text" id="modalStatus">Chưa chọn ảnh nào</div>

            <div class="modal-btn-grid">
                <button type="button" class="btn-mod-camera" id="btnModalCapture">
                    <i class="fa-solid fa-images"></i>Chọn ảnh
                </button>
                <button type="button" class="btn-mod-scan" id="btnModalScan" disabled>
                    <i class="fa-solid fa-microchip"></i>Xử lý
                </button>
            </div>
            <button type="button" class="btn-close-scanner"
                style="width: 100%; margin-top: 5px; padding: 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s">
                <i class="fa-solid fa-arrow-left"></i>Quay lại
            </button>

            <div class="scanner-result-container" id="modalResultArea" style="display:none;">
            </div>
        </div>
    </div>
</div>

<script>
const currentOrderId = <?php echo json_encode($order_id); ?>;
const currentConfigPure = <?php echo json_encode($l_cfg_req); ?>;
const currentMachineIdx = <?php echo (int) $m_idx_req; ?>;
const isFreshEntry = <?php echo json_encode($is_fresh); ?>;
const isAuthorizedByToken = <?php echo json_encode(isset($_SESSION['ENTRY_TOKEN_VAL_FOUND'])); ?>;
(function() {
    const machineUID = `${currentOrderId}_${currentMachineIdx}_${currentConfigPure}`;
    const tabKey = "ACTIVE_TAB_FOR_" + machineUID;

    if (isAuthorizedByToken) {
        // Nếu vào bằng token (từ kho-hang.php), đánh dấu tab này là tab "chính chủ"
        sessionStorage.setItem(tabKey, "true");
    } else {
        // Nếu không có token (vào trực tiếp hoặc copy link)
        if (!sessionStorage.getItem(tabKey)) {
            // Nếu session của riêng tab này cũng không có -> Đẩy về trang chủ
            window.location.href = "kho-hang.php?id=" + currentOrderId;
        }
    }
})();
</script>

<script src="./js/quet-ma.js?v=<?php echo time(); ?>"></script>