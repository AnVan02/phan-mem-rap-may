<?php
require "config.php";
require "thanh-dieu-huong.php";

$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 1;
$order = null;
$grouped_configs = [];

if ($pdo) {
   try {
      $stmt = $pdo->prepare("SELECT * FROM donhang WHERE id_donhang = ?");
      $stmt->execute([$order_id]);
      $order = $stmt->fetch(PDO::FETCH_ASSOC);

      if ($order) {
         $stmt = $pdo->prepare("SELECT * FROM chitiet_donhang WHERE id_donhang = ? ORDER BY id_ct ASC");
         $stmt->execute([$order_id]);
         $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

         // BƯỚC 1: XÁC ĐỊNH MÁY CHO TỪNG CẤU HÌNH
         $config_qtys = [];
         $temp_all_configs = [];

         // Trích xuất cấu hình chủ sở hữu dựa trên "Space Hack"
         $config_display_names = []; // lowercase_key => tên gốc từ DB
         foreach ($all_rows as $row) {
            $trimmed = rtrim($row['ten_cauhinh'], ' ');
            $spaces = strlen($row['ten_cauhinh']) - strlen($trimmed);
            $parts = array_map('trim', explode(',', $trimmed));
            $owner_original = $parts[$spaces] ?? $parts[0];
            $owner = mb_strtolower($owner_original, 'UTF-8');
            $temp_all_configs[$owner][] = $row;
            if (!isset($config_display_names[$owner])) {
               $config_display_names[$owner] = $owner_original;
            }
         }

         foreach ($temp_all_configs as $k => $items) {
            $preferred = ['cpu', 'main', 'mainboard', 'vga', 'ssd', 'psu', 'fan','case', 'win'];
            $type_counts = array_count_values(array_map('mb_strtolower', array_column($items, 'loai_linhkien')));

            $qty = 0;
            foreach ($preferred as $t) {
               if (!empty($type_counts[$t])) {
                  $qty = (int) $type_counts[$t];
                  break;
               }
            }
            $config_qtys[$k] = $qty > 0 ? $qty : 1;
         }

         // BƯỚC 2: PHÂN BỔ (POOLING) - CẢI TIẾN: ƯU TIÊN LINH KIỆN ĐÃ GÁN
         $global_type_groups = [];
         foreach ($all_rows as $item) {
            $config_normalized = array_map(function ($p) {
               return mb_strtolower(trim($p), 'UTF-8');
            }, explode(',', $item['ten_cauhinh']));
            // sort($config_normalized);
            $type_key = mb_strtolower(trim($item['loai_linhkien']), 'UTF-8') . '|' . mb_strtolower(trim((string) $item['ten_linhkien']), 'UTF-8') . '|' . implode(',', $config_normalized);
            $global_type_groups[$type_key][] = $item;
         }

         foreach ($global_type_groups as $type_key => $sublist) {
            $pool_configs = array_map(function ($p) {
               return mb_strtolower(trim($p), 'UTF-8');
            }, explode(',', $sublist[0]['ten_cauhinh']));
            $pool_configs = array_values(array_unique(array_map('trim', $pool_configs)));
            // sort($pool_configs);

            $total_m_sharing = 0;
            foreach ($pool_configs as $pc) {
               $total_m_sharing += ($config_qtys[$pc] ?? 0);
            }

            if ($total_m_sharing > 0) {
               // Tách biệt: Linh kiện đã gán và linh kiện tự do
               $already_assigned = []; // [config][machine][] = item
               $free_pool = [];

               // Đếm số linh kiện thực tế thuộc về từng cấu hình trong pool này (Space Hack)
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

                  if (!isset($grouped_configs[$pc])) {
                     $grouped_configs[$pc] = ['display_name' => $config_display_names[$pc] ?? $pc, 'machines' => []];
                  }

                  for ($m = 1; $m <= $pc_qty; $m++) {
                     // Phân phối dư (remainder) cho các máy đầu tiên của cấu hình đó
                     $count_needed = $pc_base + ($m <= $pc_rem ? 1 : 0);

                     // 1. Lấy linh kiện đã gán đúng config/máy này
                     $mine = $already_assigned[$pc][$m] ?? [];
                     foreach ($mine as $it) {
                        $grouped_configs[$pc]['machines'][$m][] = $it;
                     }

                     // 2. Lấy thêm từ pool tự do cho đủ số lượng mong muốn của cấu hình này
                     $still_needed = $count_needed - count($mine);
                     if ($still_needed > 0) {
                        $added = array_slice($free_pool, $free_idx, $still_needed);
                        foreach ($added as $it) {
                           if (strtolower(trim($it['loai_linhkien'] ?? '')) !== 'imei') {
                              $it['so_serial'] = ''; // Không hiển thị serial linh kiện nếu chưa gán chính thức
                           }
                           $grouped_configs[$pc]['machines'][$m][] = $it;
                        }
                        $free_idx += count($added);
                     }
                  }
               }
            }
         }

         // BƯỚC 3: Lấy thông tin người đang làm (MỚI)
         $active_locks = [];
         $stmt_locks = $pdo->prepare("SELECT t.config_name, t.so_may, u.fullname 
                                      FROM trang_thai_lap_may t 
                                      JOIN users u ON t.user_id = u.id 
                                      WHERE t.id_donhang = ?");
         $stmt_locks->execute([$order_id]);
         while ($l = $stmt_locks->fetch(PDO::FETCH_ASSOC)) {
            $active_locks[mb_strtolower($l['config_name'], 'UTF-8')][$l['so_may']] = $l['fullname'];
         }
      }
   } catch (PDOException $e) {
   }
}

// BƯỚC 4: Lấy máy đang làm của user hiện tại (để scroll tới)
$my_locked_config = null;
$my_locked_machine = null;
$current_user_id = $_SESSION['user_id'] ?? null;
if ($pdo && $current_user_id) {
   try {
      $stmt_my = $pdo->prepare("SELECT config_name, so_may FROM trang_thai_lap_may WHERE id_donhang = ? AND user_id = ? LIMIT 1");
      $stmt_my->execute([$order_id, $current_user_id]);
      $my_lock = $stmt_my->fetch(PDO::FETCH_ASSOC);
      if ($my_lock) {
         $my_locked_config = mb_strtolower($my_lock['config_name'], 'UTF-8');
         $my_locked_machine = (int) $my_lock['so_may'];
      }
   } catch (PDOException $e) {}
}
?>

<link rel="stylesheet" href="./css/kho-hang.css">
<main class="main-content-order">
    <nav class="breadcrumb-nav">
        <a href="dashboard-ky-thuat.php">Trang chủ</a>
        <span class="bc-sep">›</span>
        <span class="bc-active">NHẬP SERIAL</span>
    </nav>

    <?php if ($order): ?>
    <div class="order-banner">
        <div class="banner-content">
            <h1 class="banner-title">Đơn hàng: <span><?php echo htmlspecialchars($order['ma_don_hang']); ?></span></h1>

        </div>
    </div>

    <div class="order-controls">
        <div class="note-input-wrapper">
            <div class="input-with-icon">
                <i class="fa-solid fa-clipboard-list"></i>
                <input id="note" type="text" placeholder="Không có ghi chú...."
                    value="<?php echo htmlspecialchars($order['ghi_chu'] ?? ''); ?>" readonly>
            </div>
        </div>

        <form id="exportFormOrder" method="post" action="xuat-file.php" class="export-form">
            <input type="hidden" name="id_donhang" value="<?php echo $order_id; ?>">
            <input type="hidden" name="export_excel" value="1">
            <button type="button" class="btn-export-premium"
                onclick="exportWithFilePicker(document.getElementById('exportFormOrder'), this, 'xuat-file.php')">
                <div class="btn-content">
                    <i class="fa-solid fa-file-excel"></i>
                    <span>Xuất Excel</span>
                </div>
                <div class="btn-shimmer"></div>
            </button>
        </form>

        <a href="import-excel.php?id=<?php echo $order_id; ?>" class="btn-export-premium" style="text-decoration:none;">
            <div class="btn-content">
                <i class="fa-solid fa-file-circle-check"></i>
                <span>Kiểm Tra Excel</span>
            </div>
            <div class="btn-shimmer"></div>
        </a>
    </div>

    <div class="filter-controls">
        <div class="status-tabs">
            <div class="tab active" data-tab="all">Tất cả <span class="tab-count" id="count-all">0</span></div>
            <div class="tab" data-tab="empty">Chưa nhập <span class="tab-count" id="count-empty">0</span></div>
            <div class="tab" data-tab="partial">Đang nhập <span class="tab-count" id="count-partial">0</span></div>
            <div class="tab" data-tab="done">Hoàn tất <span class="tab-count" id="count-done">0</span></div>
        </div>
        <div class="search-input-wrap">
            <input type="text" id="machineSearch" placeholder="Tìm số máy, IMEI...">
        </div>
    </div>
    <div class="config-section">
        <?php foreach ($grouped_configs as $l_key => $data):
            $qty = $config_qtys[$l_key] ?? 1;
            $dName = $data['display_name'] ?? $l_key;
         ?>
        <div class="config-card">
            <div class="config-card-header">
                <div class="config-header-left">
                    <span class="config-label"><?php echo htmlspecialchars($dName); ?></span>
                    <span class="config-qty">Số lượng <?php echo $qty; ?></span>
                </div>
                <?php
                  $machines_finished = 0;
                  for ($m_check = 1; $m_check <= $qty; $m_check++) {
                     $m_items_check = $data['machines'][$m_check] ?? [];
                     if (empty($m_items_check))
                        continue;

                     $m_is_finished = true;
                     foreach ($m_items_check as $mi) {
                        $l_type = strtolower(trim($mi['loai_linhkien'] ?? ''));
                        if ($l_type === 'imei' || $l_type === 'imer') continue; // [MỚI] Bỏ qua IMEI khi xét trạng thái hoàn tất máy

                        $has_s = !empty(trim((string) ($mi['so_serial'] ?? '')));
                        $is_v = !empty($mi['user_id_save']); // Kỹ thuật viên đã lưu
                        $assigned_cfg = mb_strtolower(trim($mi['linhkien_chon'] ?? ''), 'UTF-8');
                        if (!$has_s || !$is_v || (string) $assigned_cfg !== (string) $l_key || (int) $mi['so_may'] !== (int) $m_check) {
                           $m_is_finished = false;
                           break;
                        }
                     }
                     if ($m_is_finished)
                        $machines_finished++;
                  }
                  ?>
                <div class="config-header-right">
                    <?php if ($machines_finished === $qty && $qty > 0): ?>
                    <span class="config-qty"
                        style="background:#10B981; color:#fff; box-shadow: 0 4px 12px rgba(16,185,129,0.4);">
                        <i class="fa-solid fa-circle-check"></i> Đã nhập
                        <?php echo $machines_finished; ?>/<?php echo $qty; ?>
                        máy
                    </span>
                    <?php else: ?>
                    <span class="config-qty"
                        style="background:#F59E0B; color:#fff; box-shadow:0 4px 12px rgba(245,158,11,0.4);">
                        <i class="fa-solid fa-spinner fa-spin-pulse"></i> Đã nhập
                        <?php echo $machines_finished; ?>/<?php echo $qty; ?> máy
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <table class="config-table">
                <thead>
                    <tr>
                        <th>Máy</th>
                        <th>Linh kiện</th>
                        <th class="th-action">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 1; $i <= $qty; $i++):
                        $m_items = $data['machines'][$i] ?? [];
                        $has_ram_m = false;
                        foreach ($m_items as $mi_m) {
                           if (strtolower(trim($mi_m['loai_linhkien'] ?? '')) === 'ram') {
                              $has_ram_m = true;
                              break;
                           }
                        }
                        usort($m_items, function ($a, $b) {
                           $o = ['cpu' => 1, 'main' => 2, 'ram' => 3, 'ssd' => 4, 'vga' => 5, 'psu' => 6, 'fan' => 7];
                           $pA = $o[strtolower(trim($a['loai_linhkien']))] ?? 99;
                           $pB = $o[strtolower(trim($b['loai_linhkien']))] ?? 99;
                           return ($pA !== $pB) ? ($pA <=> $pB) : ($a['id_ct'] <=> $b['id_ct']);
                        });
                        $filled = 0;
                        $total = 0;
                        foreach ($m_items as $mi) {
                           $l_type = strtolower(trim($mi['loai_linhkien'] ?? ''));
                           if ($l_type === 'imei' || $l_type === 'imer') continue; // [MỚI] Không tính IMEI vào tiến độ vì là mã định danh

                           $total++;
                           $has_s = !empty(trim((string) ($mi['so_serial'] ?? '')));
                           $is_v = !empty($mi['user_id_save']);
                           $assigned_cfg = mb_strtolower(trim($mi['linhkien_chon'] ?? ''), 'UTF-8');
                           if ($has_s && $is_v && (string) $assigned_cfg == (string) $l_key && (int) $mi['so_may'] == (int) $i)
                              $filled++;
                        }
                        $imei_values = [];
                        foreach ($m_items as $mi_imei) {
                           $loai_imei = strtolower(trim($mi_imei['loai_linhkien'] ?? ''));
                           $imei_text = trim((string) ($mi_imei['so_serial'] ?? ''));
                           if (($loai_imei === 'imei' || $loai_imei === 'imer') && $imei_text !== '') {
                              $imei_values[] = $imei_text;
                           }
                        }
                        $imei_values = array_values(array_unique($imei_values));
                        $imei_display = implode(' / ', $imei_values);
                     ?>
                    <tr data-filled="<?php echo $filled; ?>" data-total="<?php echo $total; ?>"
                        data-machine-num="<?php echo $i; ?>"
                        data-imei="<?php echo htmlspecialchars(strtolower($imei_display)); ?>"
                        data-config="<?php echo htmlspecialchars(strtolower($l_key)); ?>">
                        <td class="td-may">
                            <div class="machine-info-col">
                                <div class="machine-title">Máy số <?php echo $i; ?></div>
                                <div
                                    class="machine-imei-box <?php echo $imei_display !== '' ? 'has-imei' : 'no-imei'; ?>">
                                    <span
                                        class="imei-value"><?php echo $imei_display !== '' ? htmlspecialchars($imei_display) : '---'; ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="td-linhkien">
                            <div class="component-list">
                                <?php foreach ($m_items as $mi):
                                    $loai_lower = strtolower(trim($mi['loai_linhkien'] ?? ''));
                                    if ($loai_lower === 'imei' || $loai_lower === 'imer')
                                       continue; // ẨN imei/imer KHỎI DANH SÁCH LINH KIỆN BÊN PHẢI

                                    $has_s = !empty(trim((string) ($mi['so_serial'] ?? '')));
                                    $is_v = !empty($mi['user_id_save']);
                                    $assigned_cfg = mb_strtolower(trim($mi['linhkien_chon'] ?? ''), 'UTF-8');
                                    $is_assigned = ($has_s && $is_v && (string) $assigned_cfg == (string) $l_key && (int) $mi['so_may'] == (int) $i);
                                 ?>
                                <div class="component-item">
                                    <strong><?php echo strtoupper($mi['loai_linhkien']); ?>:</strong>
                                    <?php echo htmlspecialchars($mi['ten_linhkien']); ?>
                                    <?php if ($is_assigned): ?> <i class="fa-solid fa-circle-check"
                                        style="color:#30d81d;"></i>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="td-action">
                            <a href="javascript:void(0)"
                                onclick="checkAndEnterMachine(<?php echo $order_id; ?>, '<?php echo addslashes($dName); ?>', <?php echo $i; ?>)"
                                class="btn-nhap-serial-link <?php echo ($total > 0 && $filled === $total) ? 'finished' : ''; ?>">
                                <span><?php echo ($total > 0 && $filled === $total) ? 'ĐÃ XONG' : 'NHẬP SERIAL'; ?>
                                    <b><?php echo $filled . '/' . $total; ?></b></span>
                            </a>
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<!-- Scroll to My Machine Button -->
<?php if ($my_locked_config && $my_locked_machine): ?>
<button id="scrollToMyMachine" class="scroll-to-my-machine" title="Cuộn tới máy của bạn">
    <i class="fa-solid fa-user-gear"></i>
</button>
<?php endif; ?>

<!-- Back to Top Button -->
<button id="backToTop" class="back-to-top" title="Cuộn lên đầu trang">
    <i class="fa-solid fa-arrow-up"></i>
</button>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="./js/export-helper.js"></script>
<script>
function checkAndEnterMachine(orderId, configName, machineIdx) {
    const formData = new FormData();
    formData.append('action', 'check');
    formData.append('order_id', orderId);
    formData.append('config_name', configName);
    formData.append('machine_idx', machineIdx);

    fetch('ajax-handle-lock.php', {
            method: 'POST',
            body: formData
        })
        .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(data => {
            if (data.status === 'busy') {
                Swal.fire({
                    title: 'Máy Chưa Hoàn thiện!',
                    text: data.message,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Đồng ý',
                    cancelButtonText: 'Không, ở lại'
                }).then((result) => {
                    if (result.isConfirmed) {
                        lockAndEnter(orderId, configName, machineIdx, true);
                    }
                });
            } else if (data.status === 'done') {
                // Nếu máy đã xong, cho phép vào thẳng luôn để sửa (theo yêu cầu)
                lockAndEnter(orderId, configName, machineIdx, true);
            } else if (data.status === 'locked') {
                Swal.fire({
                    title: 'Máy Đang Được Xử Lý ',
                    text: data.message,
                    icon: 'error'
                });
            } else if (data.status === 'my_lock') {
                // Nếu là máy chính mình đang làm, vào luôn không cần hỏi
                lockAndEnter(orderId, configName, machineIdx, false);
            } else {
                // Trường hợp máy trống và user cũng không bận máy khác: Vào luôn không cần hỏi
                lockAndEnter(orderId, configName, machineIdx, false);
            }
        })
        .catch(err => {
            console.error('[checkAndEnterMachine] Lỗi:', err);
            Swal.fire('Lỗi kết nối', 'Không thể kiểm tra trạng thái máy. Vui lòng thử lại!\n(' + err.message + ')',
                'error');
        });

}

function lockAndEnter(orderId, configName, machineIdx, force) {
    const formData = new FormData();
    formData.append('action', 'lock');
    formData.append('order_id', orderId);
    formData.append('config_name', configName);
    formData.append('machine_idx', machineIdx);
    if (force) formData.append('force', '1');

    fetch('ajax-handle-lock.php', {
            method: 'POST',
            body: formData
        })
        .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(data => {
            if (data.success) {
                window.location.href =
                    `kho-import-serial.php?id=${orderId}&config=${encodeURIComponent(configName)}&m=${machineIdx}`;
            } else {
                Swal.fire('Lỗi', data.message, 'error');
            }
        })
        .catch(err => {
            console.error('[lockAndEnter] Lỗi:', err);
            Swal.fire('Lỗi kết nối', 'Không thể khóa máy. Vui lòng thử lại!\n(' + err.message + ')', 'error');
        });
}

const currentOrderId = <?php echo json_encode($order_id); ?>;
const myLockedConfig = <?php echo json_encode($my_locked_config); ?>;
const myLockedMachine = <?php echo json_encode($my_locked_machine); ?>;

// ===== SCROLL BUTTONS =====
(function() {
    const backToTopBtn = document.getElementById('backToTop');
    const scrollToMyBtn = document.getElementById('scrollToMyMachine');

    // Hiện/ẩn nút back-to-top khi scroll
    window.addEventListener('scroll', () => {
        if (window.scrollY > 300) {
            backToTopBtn && backToTopBtn.classList.add('visible');
        } else {
            backToTopBtn && backToTopBtn.classList.remove('visible');
        }
        // Nút scroll-to-my-machine luôn hiện (nếu tồn tại)
        if (scrollToMyBtn) scrollToMyBtn.classList.add('visible');
    });

    // Back to top
    if (backToTopBtn) {
        backToTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }

    // Scroll tới máy của user
    if (scrollToMyBtn && myLockedConfig && myLockedMachine) {
        scrollToMyBtn.addEventListener('click', () => {
            // Tìm row theo data-config và data-machine-num
            const rows = document.querySelectorAll('tbody tr[data-config][data-machine-num]');
            let targetRow = null;
            rows.forEach(row => {
                if (row.dataset.config === myLockedConfig &&
                    parseInt(row.dataset.machineNum) === myLockedMachine) {
                    targetRow = row;
                }
            });
            if (targetRow) {
                targetRow.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                // Highlight nhấp nháy
                targetRow.classList.add('highlight-my-machine');
                setTimeout(() => targetRow.classList.remove('highlight-my-machine'), 2000);
            }
        });

        // Hiện nút ngay khi trang load (vì user đang làm máy)
        scrollToMyBtn.classList.add('visible');
    }
})();

// ===== FILTER BAR =====
(function() {
    let activeTab = 'all';

    function getRows() {
        return Array.from(document.querySelectorAll('tbody tr[data-filled]'));
    }

    function countByTab() {
        const rows = getRows();
        let all = rows.length,
            empty = 0,
            partial = 0,
            done = 0;
        rows.forEach(r => {
            const f = parseInt(r.dataset.filled),
                t = parseInt(r.dataset.total);
            if (f === 0) empty++;
            else if (f >= t && t > 0) done++;
            else partial++;
        });
        document.getElementById('count-all').textContent = all;
        document.getElementById('count-empty').textContent = empty;
        document.getElementById('count-partial').textContent = partial;
        document.getElementById('count-done').textContent = done;
    }

    function applyFilter() {
        const savedY = window.scrollY;
        const query = document.getElementById('machineSearch').value.toLowerCase().trim();
        getRows().forEach(row => {
            const f = parseInt(row.dataset.filled),
                t = parseInt(row.dataset.total);
            let tabOk = true;
            if (activeTab === 'empty') tabOk = f === 0;
            else if (activeTab === 'partial') tabOk = f > 0 && f < t;
            else if (activeTab === 'done') tabOk = t > 0 && f >= t;

            let searchOk = true;
            if (query) {
                const machineNum = (row.dataset.machineNum || '');
                const imei = (row.dataset.imei || '');
                const isNumeric = /^\d+$/.test(query);
                if (isNumeric) {
                    // Gõ số thuần: khớp đúng số máy HOẶC IMEI có chứa chuỗi đó
                    searchOk = machineNum === query || imei.includes(query);
                } else {
                    // Gõ chữ: tìm theo cấu hình, tên linh kiện, tiêu đề máy
                    const config = (row.dataset.config || '');
                    const compText = row.querySelector('.td-linhkien') ? row.querySelector('.td-linhkien')
                        .textContent.toLowerCase() : '';
                    const mayText = row.querySelector('.td-may') ? row.querySelector('.td-may').textContent
                        .toLowerCase() : '';
                    searchOk = config.includes(query) || compText.includes(query) || mayText.includes(
                        query);
                }
            }

            row.style.display = (tabOk && searchOk) ? '' : 'none';
        });

        document.querySelectorAll('.config-card').forEach(card => {
            const visible = card.querySelectorAll('tbody tr[data-filled]');
            let hasVisible = false;
            visible.forEach(r => {
                if (r.style.display !== 'none') hasVisible = true;
            });
            card.style.display = hasVisible ? '' : 'none';
        });

        // Giữ nguyên vị trí scroll, không để trang nhảy
        requestAnimationFrame(() => window.scrollTo({
            top: savedY,
            behavior: 'instant'
        }));
    }

    document.querySelectorAll('.tab').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeTab = btn.dataset.tab;
            applyFilter();
        });
    });

    document.getElementById('machineSearch').addEventListener('input', applyFilter);

    countByTab();
})();
</script>