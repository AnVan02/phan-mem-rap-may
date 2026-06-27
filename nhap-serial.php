<?php
require "thanh-dieu-huong.php";
require "config.php";

// Lấy ID đơn hàng từ URL
$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 1;

// Dữ liệu mẫu mặc định (Đã cập nhật tên cột mới)
$order = [
   'ma_don_hang' => 'Mới',
   'ten_khach_hang' => 'Chưa xác định'
];

$components_db = [];

// Lấy dữ liệu thật từ Database
$config_names = [];
if ($pdo) {
   try {
      $stmt = $pdo->prepare("SELECT * FROM donhang WHERE id_donhang = ?");
      $stmt->execute([$order_id]);
      $db_order = $stmt->fetch();
      if ($db_order)
         $order = $db_order;
      // Lấy chi tiết linh kiện và tên cấu hình (group name)
      $stmt = $pdo->prepare("SELECT * FROM chitiet_donhang WHERE id_donhang = ?");
      $stmt->execute([$order_id]);
      $db_components = $stmt->fetchAll();
      if (!empty($db_components)) {
         $components_db = $db_components;
         $all_configs = [];
         foreach ($db_components as $comp) {
            foreach (explode(',', (string) $comp['ten_cauhinh']) as $name) {
               $trimmed = trim($name);
               if ($trimmed !== '')
                  $all_configs[] = $trimmed;
            }
         }
         $config_names = array_unique($all_configs);
      }
   } catch (PDOException $e) {
      // Bỏ qua lỗi nếu bảng chưa có dữ liệu
   }
}
$display_config_name = !empty($config_names) ? implode(", ", $config_names) : "Cấu hình mặc định";
$total_all_target = count($components_db);
?>
<script>
   const currentOrderId = <?php echo $order_id; ?>;
</script>

<main class="main-content-order">
   <!-- ===== PROGRESS HEADER ===== -->
   <div class="progress-header-card">
      <div class="progress-header-main">
         <div class="progress-header-left">
            <h1 class="page-title">
               Tiến Độ Nhập Serial
               <span class="order-id-badge">#<?php echo $order_id; ?></span>
            </h1>
            <div class="header-meta-tags">
               <?php foreach ($config_names ?: ['Cấu hình mặc định'] as $cfg): ?>
               <span class="meta-tag tag-config" title="<?php echo htmlspecialchars($cfg); ?>">
                  <i class="fa-solid fa-layer-group"></i>
                  <span class="config-text"><?php echo htmlspecialchars($cfg); ?></span>
               </span>
               <?php endforeach; ?>
               <!--<span class="meta-tag tag-qty">-->
               <!--   <i class="fa-solid fa-computer"></i>-->
               <!--   <?php echo htmlspecialchars($order['so_luong_may'] ?? '0'); ?> máy-->
               <!--</span>-->
            </div>
            <div class="overall-percent" id="overallPercent">0%</div>
         </div>

         <div class="progress-header-right">
            <div class="serial-stat">
               <span class="stat-label">ĐÃ NHẬP</span>
               <div class="stat-numbers">
                  <span class="stat-done" id="totalDoneSerial">0</span>
                  <span class="stat-sep">/</span>
                  <span class="stat-total" id="totalAllSerial"><?php echo $total_all_target; ?></span>
                  <span class="stat-unit">Serial</span>
               </div>
            </div>
         </div>
      </div>
      <div class="overall-progress-bar">
         <div class="overall-progress-fill" id="overallProgressFill" style="width: 0%"></div>
      </div>
   </div>


   <!-- ===== COMPONENT LIST ===== -->
   <div class="component-list-header">
      <div class="component-section-header">
         <h2 class="section-title">Danh Sách Linh Kiện</h2>
      </div>
      <div class="component-list" id="componentList">
         <?php
         // 1. Nhóm linh kiện theo loại và tên (IMEI/IMER tách riêng theo từng cấu hình)
         $grouped_by_item = [];
         foreach ($components_db as $comp) {
            $loai_upper = strtoupper($comp['loai_linhkien']);
            // IMEI/IMER: nhóm riêng theo từng cấu hình để mỗi cấu hình có 1 card IMEI độc lập
            if ($loai_upper === 'IMEI' || $loai_upper === 'IMER') {
               $item_key = $comp['loai_linhkien'] . "|" . $comp['ten_linhkien'] . "|" . trim($comp['ten_cauhinh'] ?? '');
            } else {
               $item_key = $comp['loai_linhkien'] . "|" . $comp['ten_linhkien'];
            }

            if (!isset($grouped_by_item[$item_key])) {
               $grouped_by_item[$item_key] = [
                  'data' => $comp,
                  'count' => 0,
                  'configs' => [],
                  'serials' => []
               ];
            }
            $grouped_by_item[$item_key]['count']++;
            if (!empty($comp['ten_cauhinh'])) {
               foreach (explode(',', $comp['ten_cauhinh']) as $cn) {
                  $tn = trim($cn);
                  if ($tn !== '' && !in_array($tn, $grouped_by_item[$item_key]['configs'])) {
                     $grouped_by_item[$item_key]['configs'][] = $tn;
                  }
               }
            } else {
               if (!in_array("Cấu hình chung", $grouped_by_item[$item_key]['configs'])) {
                  $grouped_by_item[$item_key]['configs'][] = "Cấu hình chung";
               }
            }
            if (!empty($comp['so_serial'])) {
               $grouped_by_item[$item_key]['serials'][] = $comp['so_serial'];
            }
         }
         // Sắp xếp: IMEI/IMER luôn nằm dưới cùng
         $loai_sort_order = ['CPU' => 1, 'MAIN' => 2, 'RAM' => 3, 'SSD' => 4, 'VGA' => 5, 'PSU' => 6, 'FAN' => 7, 'CASE' =>8, 'WIN' => 9, 'IMEI' => 10, 'IMER' => 10];
         uasort($grouped_by_item, function ($a, $b) use ($loai_sort_order) {
            $typeA = strtoupper($a['data']['loai_linhkien']);
            $typeB = strtoupper($b['data']['loai_linhkien']);
            $scoreA = $loai_sort_order[$typeA] ?? 50;
            $scoreB = $loai_sort_order[$typeB] ?? 50;
            return $scoreA <=> $scoreB;
         });

         $global_idx = 0;
         $imei_header_shown = false;
         foreach ($grouped_by_item as $item_key => $group_item):
            $comp = $group_item['data'];
            $target_qty = $group_item['count'];
            $type = $comp['loai_linhkien'];
            $name = $comp['ten_linhkien'];
            $configs_str = implode(", ", $group_item['configs']);
            $isOpen = ($global_idx === 0) ? 'open' : '';

            // Thêm tiêu đề cho phần IMEI
            if (!$imei_header_shown && (strtoupper($type) === 'IMEI' || strtoupper($type) === 'IMER')) {
               $imei_header_shown = true;
         ?>
               <div class="component-section-header">
                  <h2 class="section-title">Thông Tin Số IMEI</h2>
               </div>
            <?php
            }
            ?>
            <div class="component-card <?php echo $isOpen; ?>" data-id="<?php echo $global_idx; ?>"
               data-type="<?php echo strtoupper($type); ?>" data-name="<?php echo htmlspecialchars($name); ?>"
               data-config="<?php echo htmlspecialchars($configs_str); ?>" data-choice=""
               data-target="<?php echo $target_qty; ?>">
               <div class="component-card-header" onclick="toggleCard(this)">
                  <div class="comp-icon">
                     <?php
                     $is_win = (strtoupper($type) === 'WIN' || strtoupper($type) === 'CASE' || strtoupper($type) === 'FAN');
                     switch (strtolower($type)) {
                        case 'cpu':
                           echo '<i class="fa-solid fa-microchip" style="color:#e74c3c;"></i>';
                           break;
                        case 'ram':
                           echo '<i class="fa-solid fa-memory"style="color:#27ae60;"></i>';
                           break;
                        case 'ssd':
                           echo '<i class="fa-solid fa-hard-drive" style="color:#2980b9;"></i>';
                           break;
                        case 'vga':
                           echo '<i class="fa-solid fa-film" style="color:#f39c12;"></i>';
                           break;
                        case 'gpu':
                           echo '<i class="fa-solid fa-display"></i>';
                           break;
                        case 'main':
                        case 'mainboard':
                           echo '<i class="fa-solid fa-server" style="color:#8e44ad;"></i>';
                           break;
                        case 'psu':
                           echo '<i class="fa-solid fa-plug" style="color:#2c3e50;"></i>';
                           break;
                        case 'fan':
                           echo '<i class="fa-solid fa-fan" style="color:#7f8c8d;"></i>';
                           break;
                        case 'case':
                           echo '<i class="fa-solid fa-box" style="color:#1152d4;"></i>';
                           break;
                        case 'imei':
                        case 'imer':
                           echo '<i class="fa-solid fa-barcode" style="color:#64748b;"></i>';
                           break;
                        default:
                           echo '<i class="fa-brands fa-windows" style="color:#00a8ff;"></i>';
                           break;
                     }
                     ?>
                  </div>
                  <div class="comp-info">
                     <div class="comp-name"><?php echo htmlspecialchars($name); ?></div>
                     <div class="comp-meta">
                        <!-- <span class="comp-config-tag" style="font-size: 15px; padding: 2px 6px; border-radius: 10px; color:#FF3333; font-weight:600">
                           <?php
                               echo htmlspecialchars(trim($comp['ten_cauhinh'] ?? ''));
                           ?>
                        </span> -->
                     </div>
                     <div class="comp-total-need">Tổng cần nhập: <?php echo $target_qty; ?> serial</div>
                  </div>


                 <div class="comp-status-area">
                     <?php if ($is_win): ?>
                        <span class="comp-status status-done"
                           style="color: #059669; background: #D1FAE5; border: 1px solid #A7F3D0;">Đầy đủ (<?php echo $target_qty; ?>/<?php echo $target_qty; ?>)</span>
                     <?php else: ?>
                        <span class="comp-status status-pending">Chưa nhập (0/<?php echo $target_qty; ?>)</span>
                     <?php endif; ?>
                     <div class="header-action-wrap">
                        <?php if ($is_win): ?>
                           <button class="btn-edit-serial"
                              onclick="expandCard(this.closest('.component-card'));event.stopPropagation()">
                              <i class="fa-solid fa-eye"></i> Xem
                           </button>
                        <?php else: ?>
                           <button class="btn-nhap-serial"
                              onclick="expandCard(this.closest('.component-card'));event.stopPropagation()">
                              <i class="fa-solid fa-circle-plus"></i> Nhập Serial
                           </button>
                        <?php endif; ?>
                     </div>
                  </div>
               </div>

               <div class="component-card-body">
                  <div class="serial-entry-grid">
                     <div class="serial-textarea-wrap">
                        <label class="entry-label">Dán danh sách Serial (Mỗi dòng một mã)</label>
                        <div class="textarea-hint">Ví dụ cho
                           <?php echo strtoupper($type); ?>:<br>SN-<?php echo strtoupper($type); ?>-001<br>SN-<?php echo strtoupper($type); ?>-002
                        </div>
                        <textarea class="serial-textarea" id="textarea-<?php echo $global_idx; ?>"
                           placeholder="Nhập serial cho <?php echo htmlspecialchars($type); ?>..." <?php echo $is_win ? 'readonly style="background-color: #f1f5f9; cursor: not-allowed;"' : ''; ?>
                           rows="6"><?php echo isset($group_item['serials']) ? htmlspecialchars(implode("\n", $group_item['serials'])) : ''; ?></textarea>
                        <div class="textarea-footer">
                           <span class="auto-filter-note" style="font-size: 15px;"> Hệ thống sẽ tự động loại bỏ khoản
                              trắng</span>
                           <span class="detected-count" style="font-size: 12px;">Đã nhận diện <strong
                                 id="detected-<?php echo $global_idx; ?>">0</strong> serial</span>
                        </div>
                        <div class="error-msg" id="error-<?php echo $global_idx; ?>"
                           style="color: #ef4444; font-size: 12px; font-weight: 600; margin-top: 8px; display: none; background: #FEF2F2; padding: 8px 12px; border-radius: 6px; border: 1px solid #FCA5A5;">
                           <i class="fa-solid fa-triangle-exclamation"></i> Lỗi: không thể thêm <span
                              id="excess-<?php echo $global_idx; ?>">0</span> dữ liệu
                        </div><br>
                     </div>

                     <div class="excel-upload-wrap">
                        <!-- Excel upload can be added here if needed -->
                     </div>
                  </div>
               </div>
            </div>
         <?php
            $global_idx++;
         endforeach; ?>
      </div>
      <div class="page-footer">
         <p class="footer-note">
            <i class="fa-solid fa-circle-info"></i>
            Sau khi xác nhận, toàn bộ thông tin serial sẽ được<br>chuyển đến bộ phận Kỹ thuật để tiến hành láp ráp.
         </p>
         <div class="footer-actions">
            <button class="btn-luu-nhap" id="btnLuuNhap">Lưu nháp</button>
            <button class="btn-xac-nhan" id="btnXacNhan">Xác nhận <i class="fa-solid fa-arrow-right"></i></button>
         </div>
      </div>
</main>
<link rel="stylesheet" href="./css/nhap-serial.css">
<script>
   const currentOrderId = <?php echo json_encode($order_id); ?>;
</script>

<script src="./js/nhap-serial.js"></script>

</body>

</html>