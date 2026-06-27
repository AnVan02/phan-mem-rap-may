<?php
error_reporting(0);
ini_set('display_errors', 0);

// Polyfill TOÀN BỘ mbstring - phủ hết tất cả hàm cần thiết
if (!function_exists('mb_internal_encoding')) {
   function mb_internal_encoding($encoding = null)
   {
      return $encoding ? true : 'UTF-8';
   }
}
if (!function_exists('mb_regex_encoding')) {
   function mb_regex_encoding($encoding = null)
   {
      return $encoding ? true : 'UTF-8';
   }
}
if (!function_exists('mb_substr')) {
   function mb_substr($str, $start, $length = null, $encoding = null)
   {
      return $length === null ? substr($str, $start) : substr($str, $start, $length);
   }
}
if (!function_exists('mb_strlen')) {
   function mb_strlen($str, $encoding = null)
   {
      return strlen($str);
   }
}
if (!function_exists('mb_strpos')) {
   function mb_strpos($haystack, $needle, $offset = 0, $encoding = null)
   {
      return strpos($haystack, $needle, $offset);
   }
}
if (!function_exists('mb_strrpos')) {
   function mb_strrpos($haystack, $needle, $offset = 0, $encoding = null)
   {
      return strrpos($haystack, $needle, $offset);
   }
}
if (!function_exists('mb_stripos')) {
   function mb_stripos($haystack, $needle, $offset = 0, $encoding = null)
   {
      return stripos($haystack, $needle, $offset);
   }
}
if (!function_exists('mb_strripos')) {
   function mb_strripos($haystack, $needle, $offset = 0, $encoding = null)
   {
      return strripos($haystack, $needle, $offset);
   }
}
if (!function_exists('mb_strstr')) {
   function mb_strstr($haystack, $needle, $before_needle = false, $encoding = null)
   {
      return strstr($haystack, $needle, $before_needle);
   }
}
if (!function_exists('mb_strtolower')) {
   function mb_strtolower($str, $encoding = null)
   {
      return strtolower($str);
   }
}
if (!function_exists('mb_strtoupper')) {
   function mb_strtoupper($str, $encoding = null)
   {
      return strtoupper($str);
   }
}
if (!defined('MB_CASE_UPPER'))
   define('MB_CASE_UPPER', 0);
if (!defined('MB_CASE_LOWER'))
   define('MB_CASE_LOWER', 1);
if (!defined('MB_CASE_TITLE'))
   define('MB_CASE_TITLE', 2);
if (!defined('MB_CASE_FOLD'))
   define('MB_CASE_FOLD', 80);
if (!function_exists('mb_convert_case')) {
   function mb_convert_case($string, $mode, $encoding = null)
   {
      if ($mode === MB_CASE_UPPER)
         return strtoupper($string);
      if ($mode === MB_CASE_LOWER || $mode === MB_CASE_FOLD)
         return strtolower($string);
      if ($mode === MB_CASE_TITLE)
         return ucwords(strtolower($string));
      return $string;
   }
}
if (!function_exists('mb_convert_encoding')) {
   function mb_convert_encoding($string, $to_encoding, $from_encoding = null)
   {
      return $string;
   }
}
if (!function_exists('mb_detect_encoding')) {
   function mb_detect_encoding($string, $encodings = null, $strict = false)
   {
      return 'UTF-8';
   }
}
if (!function_exists('mb_substitute_character')) {
   function mb_substitute_character($char = null)
   {
      return true;
   }
}
if (!function_exists('mb_check_encoding')) {
   function mb_check_encoding($value = null, $encoding = null)
   {
      return true;
   }
}
if (!function_exists('mb_ord')) {
   function mb_ord($str, $encoding = null)
   {
      return ord($str[0]);
   }
}
if (!function_exists('mb_chr')) {
   function mb_chr($codepoint, $encoding = null)
   {
      return chr($codepoint);
   }
}
if (!function_exists('mb_substr_count')) {
   function mb_substr_count($haystack, $needle, $encoding = null)
   {
      return substr_count($haystack, $needle);
   }
}
if (!function_exists('mb_str_split')) {
   function mb_str_split($string, $length = 1, $encoding = null)
   {
      return str_split($string, $length);
   }
}
if (!function_exists('mb_encode_numericentity')) {
   function mb_encode_numericentity($str, $convmap, $encoding = null, $is_hex = false)
   {
      return htmlspecialchars($str);
   }
}
if (!function_exists('mb_decode_numericentity')) {
   function mb_decode_numericentity($str, $convmap, $encoding = null)
   {
      return html_entity_decode($str);
   }
}

require "vendor/autoload.php";


// Nhập các thư viện PhpSpreadsheet để tạo file Excel
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;

/**
 * Hàm lấy danh sách linh kiện đã được phân bổ theo từng máy (pooling)
 * - Nếu truyền $order_id thì chỉ lấy 1 đơn hàng cụ thể
 * - Nếu không truyền thì lấy tất cả đơn hàng
 */
function get_pooled_components($pdo, $order_id = null)
{
   // Xây dựng câu lệnh SQL lọc theo đơn hàng nếu có
   $where_clause = "";
   $params = [];
   if ($order_id) {
      $where_clause = " WHERE c.id_donhang = ? ";
      $params[] = $order_id;
   }

   // Kiểm tra và tự động thêm cột imei vào bảng donhang nếu chưa có (để tránh lỗi SQL Unknown column 'd.imei')
   $columnCheck = $pdo->query("SHOW COLUMNS FROM donhang LIKE 'imei'")->fetch(PDO::FETCH_ASSOC);
   if (!$columnCheck) {
      $pdo->exec("ALTER TABLE donhang ADD COLUMN imei LONGTEXT NULL");
   }

   // Lấy toàn bộ chi tiết đơn hàng kèm thông tin khách hàng
   $sql = "SELECT c.*, d.ten_khach_hang, d.ngay_tao, d.imei as order_imei
           FROM chitiet_donhang c 
           JOIN donhang d ON c.id_donhang = d.id_donhang
           " . $where_clause . "
           ORDER BY c.id_donhang DESC, c.id_ct ASC";

   $stmt = $pdo->prepare($sql);
   $stmt->execute($params);
   $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

   // Nhóm các dòng theo từng đơn hàng
   $orders = [];
   foreach ($all_rows as $row) {
      $orders[$row['id_donhang']][] = $row;
   }

   $final_results = [];

   // Xử lý từng đơn hàng
   foreach ($orders as $oid => $items_in_order) {
      // -------------------------------------------------------
      // BƯỚC 1: XÁC ĐỊNH SỐ LƯỢNG MÁY CHO TỪNG CẤU HÌNH
      // Dùng "Space Hack" - đếm số khoảng trắng cuối tên cấu hình
      // để xác định cấu hình chủ sở hữu thực sự của linh kiện
      // -------------------------------------------------------
      $config_qtys = [];
      $temp_all_configs = [];

      foreach ($items_in_order as $row) {
         $trimmed = rtrim($row['ten_cauhinh'], ' ');
         $spaces = strlen($row['ten_cauhinh']) - strlen($trimmed); // Số khoảng trắng = chỉ số cấu hình chủ
         $parts = array_map('trim', explode(',', $trimmed));
         $owner = mb_strtolower($parts[$spaces] ?? $parts[0], 'UTF-8');
         $temp_all_configs[$owner][] = $row;
      }

      // Đếm số máy của mỗi cấu hình dựa theo loại linh kiện ưu tiên (CPU > MAIN > VGA > ...)
      foreach ($temp_all_configs as $k => $items) {
         $preferred = ['cpu', 'main', 'mainboard', 'vga', 'ssd', 'psu', 'fan', 'case', 'win'];
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

      // -------------------------------------------------------
      // BƯỚC 2: PHÂN BỔ LINH KIỆN VÀO TỪNG MÁY (POOLING)
      // Ưu tiên linh kiện đã được gán (có serial + config + so_may)
      // Phần còn lại lấy từ "free pool" theo thứ tự
      // -------------------------------------------------------
      $global_type_groups = [];
      foreach ($items_in_order as $item) {
         // Chuẩn hóa tên cấu hình về chữ thường để nhóm linh kiện cùng loại
         $config_normalized = array_map(function ($p) {
            return mb_strtolower(trim($p), 'UTF-8');
         }, explode(',', $item['ten_cauhinh']));
         $type_key = mb_strtolower(trim($item['loai_linhkien']), 'UTF-8') . '|' . mb_strtolower(trim((string) $item['ten_linhkien']), 'UTF-8') . '|' . implode(',', $config_normalized);
         $global_type_groups[$type_key][] = $item;
      }

      foreach ($global_type_groups as $type_key => $sublist) {
         // Lấy danh sách cấu hình mà nhóm linh kiện này thuộc về (có thể dùng chung)
         $pool_configs = array_map(function ($p) {
            return mb_strtolower(trim($p), 'UTF-8');
         }, explode(',', $sublist[0]['ten_cauhinh']));
         $pool_configs = array_values(array_unique(array_map('trim', $pool_configs)));

         // Tổng số máy đang dùng chung nhóm linh kiện này
         $total_m_sharing = 0;
         foreach ($pool_configs as $pc) {
            $total_m_sharing += ($config_qtys[$pc] ?? 0);
         }

         if ($total_m_sharing > 0) {
            // Tách linh kiện đã được gán chính thức và linh kiện tự do (chưa gán)
            $already_assigned = []; // [config][so_may][] = item đã gán
            $free_pool = [];        // Linh kiện chưa gán, phân bổ tuần tự

            // Đếm số linh kiện thực tế thuộc mỗi cấu hình (dựa theo Space Hack)
            $items_by_config = [];
            foreach ($sublist as $it) {
               $t_owner = rtrim($it['ten_cauhinh'], ' ');
               $s_owner = strlen($it['ten_cauhinh']) - strlen($t_owner);
               $p_owner = array_map('trim', explode(',', $t_owner));
               $owner_name = mb_strtolower($p_owner[$s_owner] ?? $p_owner[0], 'UTF-8');
               $items_by_config[$owner_name] = ($items_by_config[$owner_name] ?? 0) + 1;

               // Kiểm tra linh kiện đã được gán chính thức chưa (có đủ serial + config + so_may)
               $c_chon = mb_strtolower(trim($it['linhkien_chon'] ?? ''), 'UTF-8');
               $m_chon = (int) ($it['so_may'] ?? 0);
               if (!empty($it['so_serial']) && $c_chon !== '' && $m_chon > 0) {
                  $already_assigned[$c_chon][$m_chon][] = $it;
               } else {
                  $free_pool[] = $it;
               }
            }

            // Phân bổ linh kiện vào từng máy của từng cấu hình
            $free_idx = 0;
            foreach ($pool_configs as $pc) {
               $pc_qty = $config_qtys[$pc] ?? 0;
               if ($pc_qty <= 0)
                  continue;

               $pc_total_items = $items_by_config[$pc] ?? 0;
               $pc_base = floor($pc_total_items / $pc_qty);   // Số linh kiện tối thiểu mỗi máy
               $pc_rem = $pc_total_items % $pc_qty;           // Số máy được thêm 1 linh kiện dư

               for ($m = 1; $m <= $pc_qty; $m++) {
                  // Các máy đầu tiên nhận thêm 1 linh kiện nếu có số dư
                  $count_needed = $pc_base + ($m <= $pc_rem ? 1 : 0);

                  // 1. Ưu tiên linh kiện đã được gán chính thức cho máy này
                  $mine = $already_assigned[$pc][$m] ?? [];
                  foreach ($mine as $it) {
                     $it['so_may_fix'] = $m;
                     $it['linhkien_chon_fix'] = $pc;
                     $final_results[] = $it;
                  }

                  // 2. Lấy thêm từ free pool nếu chưa đủ số lượng cần thiết
                  $still_needed = $count_needed - count($mine);
                  if ($still_needed > 0) {
                     $added = array_slice($free_pool, $free_idx, $still_needed);
                     foreach ($added as $it) {
                        $it['so_may_fix'] = $m;
                        $it['linhkien_chon_fix'] = $pc;

                        // Chỉ giữ serial cho IMEI/IMER để hiện ở Header máy. 
                        // Các linh kiện khác nếu chưa gán đích danh (nằm trong free pool) thì ẩn serial.
                        $type_tmp = strtoupper($it['loai_linhkien'] ?? '');
                        if ($type_tmp !== 'IMEI' && $type_tmp !== 'IMER') {
                           $it['so_serial'] = '';
                        }

                        $final_results[] = $it;
                     }
                     $free_idx += count($added);
                  }
               }
            }
         }
      }
   }

   // -------------------------------------------------------
   // BƯỚC 3: SẮP XẾP KẾT QUẢ
   // Thứ tự: Đơn hàng mới nhất → Cấu hình theo thứ tự DB → Số máy → Loại linh kiện
   // -------------------------------------------------------
   $config_first_id = [];
   foreach ($final_results as $res) {
      $c = $res['linhkien_chon_fix'];
      if (!isset($config_first_id[$c])) {
         $config_first_id[$c] = $res['id_ct']; // Lưu id_ct đầu tiên của mỗi cấu hình để giữ thứ tự DB
      }
   }

   usort($final_results, function ($a, $b) use ($config_first_id) {
      // Ưu tiên đơn hàng mới hơn
      if ($a['id_donhang'] != $b['id_donhang'])
         return $b['id_donhang'] <=> $a['id_donhang'];

      // Sắp xếp theo thứ tự cấu hình trong DB
      $orderA = $config_first_id[$a['linhkien_chon_fix']] ?? 0;
      $orderB = $config_first_id[$b['linhkien_chon_fix']] ?? 0;
      if ($orderA != $orderB)
         return $orderA <=> $orderB;

      // Sắp xếp theo số máy
      if ($a['so_may_fix'] != $b['so_may_fix'])
         return $a['so_may_fix'] <=> $b['so_may_fix'];

      // Sắp xếp theo loại linh kiện (CPU → Main → Fan → RAM → SSD → VGA → Nguồn → ...)
      $orderMap = [
         'cpu' => 1,
         'main' => 2,
         'mainboard' => 2,
         'fan' => 3,
         'tản' => 3,
         'ram' => 4,
         'ssd' => 5,
         'vga' => 6,
         'đồ họa' => 6,
         'nguồn' => 7,
         'psu' => 7,
         'case' => 8,
         'phím' => 9,
         'key' => 9,
         'chuột' => 10,
         'mouse' => 10,
         'win' => 11,
         'hệ điều hành' => 11,
         'phần mềm' => 12,
         'màn' => 13,
         'lcd' => 13
      ];

      $getScore = function ($type) use ($orderMap) {
         $t = mb_strtolower($type, 'UTF-8');
         foreach ($orderMap as $k => $v) {
            if (strpos($t, $k) !== false)
               return $v;
         }
         return 14; // Loại không xác định thì xếp cuối
      };

      $scoreA = $getScore($a['loai_linhkien']);
      $scoreB = $getScore($b['loai_linhkien']);

      return $scoreA <=> $scoreB;
   });

   return $final_results;
}

// -------------------------------------------------------
// XỬ LÝ XUẤT FILE EXCEL KHI NGƯỜI DÙNG NHẤN NÚT
// -------------------------------------------------------
if (isset($_POST['export_excel'])) {
   require "config.php";

   $order_id = isset($_POST['id_donhang']) ? (int) $_POST['id_donhang'] : 0;
   $customer_name = "Khach_Hang";

   // Lấy tên khách hàng để đặt tên file
   if ($order_id > 0) {
      $stmt = $pdo->prepare("
         SELECT ten_khach_hang
         FROM donhang
         WHERE id_donhang = ?
      ");
      $stmt->execute([$order_id]);
      $customer_name = $stmt->fetchColumn() ?: "Khach_Hang";
   }

   // Tạo tên file: "Cau Hinh ROSA-TenKhach-ngaythang.xlsx"
   $date_str = date('dmy');

   // Chuyển tiếng Việt có dấu → không dấu để tránh lỗi encoding tên file
   function remove_vietnamese_accents($str) {
      $accents = [
         'à','á','â','ã','ä','å','ā','ắ','ặ','ặ','ằ','ẳ','ẵ','ấ','ầ','ẩ','ẫ','ậ',
         'è','é','ê','ë','ē','ế','ề','ể','ễ','ệ',
         'ì','í','î','ï','ī',
         'ò','ó','ô','õ','ö','ō','ố','ồ','ổ','ỗ','ộ','ớ','ờ','ở','ỡ','ợ',
         'ù','ú','û','ü','ū','ứ','ừ','ử','ữ','ự',
         'ý','ỳ','ỷ','ỹ','ỵ',
         'đ',
         'À','Á','Â','Ã','Ä','Å','Ā','Ắ','Ặ','Ằ','Ẳ','Ẵ','Ấ','Ầ','Ẩ','Ẫ','Ậ',
         'È','É','Ê','Ë','Ē','Ế','Ề','Ể','Ễ','Ệ',
         'Ì','Í','Î','Ï','Ī',
         'Ò','Ó','Ô','Õ','Ö','Ō','Ố','Ồ','Ổ','Ỗ','Ộ','Ớ','Ờ','Ở','Ỡ','Ợ',
         'Ù','Ú','Û','Ü','Ū','Ứ','Ừ','Ử','Ữ','Ự',
         'Ý','Ỳ','Ỷ','Ỹ','Ỵ',
         'Đ',
      ];
      $noAccents = [
         'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
         'e','e','e','e','e','e','e','e','e','e',
         'i','i','i','i','i',
         'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
         'u','u','u','u','u','u','u','u','u','u',
         'y','y','y','y','y',
         'd',
         'A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A',
         'E','E','E','E','E','E','E','E','E','E',
         'I','I','I','I','I',
         'O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O',
         'U','U','U','U','U','U','U','U','U','U',
         'Y','Y','Y','Y','Y',
         'D',
      ];
      return str_replace($accents, $noAccents, $str);
   }

$clean_customer = remove_vietnamese_accents($customer_name);
$clean_customer = str_replace([' ', '/', '\\'], '_', $clean_customer);
$filename = "Cau Hinh ROSA-" . $clean_customer . "-" . $date_str . ".xlsx";

   try {
      // Lấy dữ liệu đã được phân bổ theo máy
      $results = get_pooled_components($pdo, $order_id);

      // Nhóm kết quả theo cấu hình và số máy để render Excel
      $configs = [];
      $config_machine_counts = [];
      $all_cfg_names = []; // Giữ thứ tự cấu hình theo DB

      // Lấy danh sách IMEI của đơn hàng (từ cột JSON trong bảng donhang)
      $order_imeis_json = !empty($results) ? ($results[0]['order_imei'] ?? '') : '';
      $order_imeis = !empty($order_imeis_json) ? json_decode($order_imeis_json, true) : [];

      foreach ($results as $row) {
         $oid = $row['id_donhang'];
         $may = $row['so_may_fix'];
         $cfg_name = $row['linhkien_chon_fix'] ?? ($row['ten_cauhinh'] ?: 'Cấu hình');
         $cfg_key = $oid . '_' . md5($cfg_name); // Key duy nhất cho mỗi cấu hình trong đơn

         // Ghi nhớ thứ tự cấu hình theo DB
         if (!in_array($cfg_name, $all_cfg_names)) {
            $all_cfg_names[] = $cfg_name;
         }

         // Khởi tạo dữ liệu cho cấu hình nếu chưa có
         if (!isset($configs[$cfg_key])) {
            $configs[$cfg_key] = [
               'cfg_name' => mb_strtoupper($cfg_name, 'UTF-8'),
               'raw_name' => $cfg_name,
               'oid' => $oid,
               'machines' => []
            ];
         }

         // Khởi tạo dữ liệu cho từng máy trong cấu hình
         if (!isset($configs[$cfg_key]['machines'][$may])) {
            $configs[$cfg_key]['machines'][$may] = [
               'may_num' => $may,
               'imei' => '',
               'items' => []
            ];
         }

         // Cập nhật số máy tối đa của cấu hình này (dùng để tính chỉ số IMEI toàn cục)
         if (!isset($config_machine_counts[$cfg_name]) || $may > $config_machine_counts[$cfg_name]) {
            $config_machine_counts[$cfg_name] = $may;
         }

         $type = $row['loai_linhkien'];
         $type_display = $type;

         // Xử lý linh kiện IMEI/IMER: lưu riêng vào 'imei' của máy, không thêm vào danh sách linh kiện
         if (stripos($type, 'IMER') !== false || stripos($type, 'IMEI') !== false) {
            $val = trim((string) ($row['so_serial'] ?? ''));
            if (!empty($val) && !preg_match('/^S[ỐOÔ]\s*(IMEI|EMEI)/ui', $val)) {
               $configs[$cfg_key]['machines'][$may]['imei'] = $val;
            } else {
               // Nếu chưa nhập thì để trống, không lấy số thứ tự máy làm IMER
               $configs[$cfg_key]['machines'][$may]['imei'] = '';
            }
            continue;
         }

         // Chuẩn hóa tên hiển thị của loại linh kiện cho Excel
         elseif (stripos($type, 'CPU') !== false) {
            $type_display = 'CPU';
         } elseif (stripos($type, 'MAIN') !== false) {
            $type_display = 'Mainboard';
         } elseif (stripos($type, 'RAM') !== false) {
            $type_display = 'Ram';
         } elseif (stripos($type, 'SSD') !== false) {
            $type_display = 'SSD';
         } elseif (stripos($type, 'VGA') !== false || stripos($type, 'ĐỒ HỌA') !== false) {
            $type_display = 'Đồ họa';
         } elseif (stripos($type, 'PSU') !== false || stripos($type, 'NGUỒN') !== false) {
            $type_display = 'Nguồn';
         } elseif (stripos($type, 'CASE') !== false) {
            $type_display = 'Case';
         } elseif (stripos($type, 'KEY') !== false || stripos($type, 'PHÍM') !== false) {
            $type_display = 'Key board';
         } elseif (stripos($type, 'MOUSE') !== false || stripos($type, 'CHUỘT') !== false) {
            $type_display = 'Mouse';
         } elseif (stripos($type, 'WIN') !== false || stripos($type, 'HỆ ĐIỀU HÀNH') !== false) {
            $type_display = 'Hệ Điều Hành';
         } elseif (stripos($type, 'SOFTWARE') !== false || stripos($type, 'PHẦN MỀM') !== false) {
            $type_display = 'Phần Mềm';
         } elseif (stripos($type, 'LCD') !== false || stripos($type, 'MÀN') !== false) {
            $type_display = 'LCD';
         } elseif (stripos($type, 'FAN') !== false || stripos($type, 'TẢN') !== false) {
            $type_display = 'Tản';
         }

         // Thêm linh kiện vào danh sách của máy này
         $configs[$cfg_key]['machines'][$may]['items'][] = [
            'sn' => $row['so_serial'] ?: '', // Serial number
            'tp' => $type_display,            // Loại linh kiện (đã chuẩn hóa)
            'nm' => $row['ten_linhkien']       // Tên linh kiện
         ];
      }

      // -------------------------------------------------------
      // TẠO FILE EXCEL VỚI PHPSPREADSHEET
      // -------------------------------------------------------
      $spreadsheet = new Spreadsheet();
      $sheet = $spreadsheet->getActiveSheet();
      $sheet->setTitle('Export');

      // Căn giữa mặc định cho toàn bộ sheet
      $spreadsheet->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
      $spreadsheet->getDefaultStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
      $sheet->getDefaultRowDimension()->setRowHeight(25);

      // Mỗi cấu hình chiếm 4 cột + 1 cột trống ngăn cách
      $colOffset = 0;
      foreach ($configs as $cfg_data) {
         $rowIdx = 1;

         // Xác định tên cột Excel cho cấu hình hiện tại (A, B, C, D hoặc F, G, H, I, ...)
         $colA = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colOffset + 1);
         $colB = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colOffset + 2);
         $colC = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colOffset + 3);
         $colD = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colOffset + 4);

         // Độ rộng cột
         $sheet->getColumnDimension($colA)->setWidth(20);
         $sheet->getColumnDimension($colB)->setWidth(40);
         $sheet->getColumnDimension($colC)->setWidth(40);
         $sheet->getColumnDimension($colD)->setWidth(10);

         foreach ($cfg_data['machines'] as $m_data) {
            // Tính chỉ số máy toàn cục để lấy đúng IMEI từ mảng JSON
            $global_idx = 0;
            foreach ($all_cfg_names as $cn) {
               if ($cn === ($cfg_data['raw_name'] ?? $cfg_data['cfg_name'])) {
                  $global_idx += $m_data['may_num'];
                  break;
               }
               $global_idx += $config_machine_counts[$cn];
            }

            // Ưu tiên IMEI từ linh kiện, nếu không có thì lấy từ mảng IMEI của đơn hàng
            $display_imei = $m_data['imei'];
            if (empty($display_imei) && !empty($order_imeis)) {
               $display_imei = $order_imeis[$global_idx - 1] ?? '';
            }

            // Lọc bỏ IMEI placeholder
            if (preg_match('/^S[ỐOÔ]\s*(IMEI|EMEI)/ui', $display_imei)) {
               $display_imei = '';
            }

            // --- DÒNG TIÊU ĐỀ 1: Số máy | Tên cấu hình | IMEI ---
            $sheet->setCellValue($colA . $rowIdx, 'Máy ' . $m_data['may_num']);
            $sheet->setCellValue($colB . $rowIdx, $cfg_data['cfg_name']);
            // Hiển thị Số IMER của máy ở cột C
            $display_val = !empty($display_imei) ? ' ' . $display_imei : '';
            $sheet->setCellValueExplicit($colC . $rowIdx, (string) $display_val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $headerStyle = [
               'font' => ['bold' => true],
               'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
               'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
            ];
            $sheet->getStyle("$colA$rowIdx:$colD$rowIdx")->applyFromArray($headerStyle);
            $sheet->getStyle("$colA$rowIdx:$colD$rowIdx")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFFFF');
            $rowIdx++;

            // --- DÒNG TIÊU ĐỀ 2: Sub-header (Thành Phần | Mã SP | Số Serial / IMER | SLUONG) ---
            $sheet->setCellValue($colA . $rowIdx, 'Thành Phần');
            $sheet->setCellValue($colB . $rowIdx, 'Mã SP');
            // $sheet->setCellValue($colC . $rowIdx, 'Số Serial / IMER');
            $sheet->setCellValue($colD . $rowIdx, 'SLƯỢNG');

            $subHeaderStyle = [
               'font' => ['bold' => true, 'color' => ['rgb' => 'FF0000'], 'font-family' => 'Aptos narrow'],
               'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
               'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ];
            $sheet->getStyle("$colA$rowIdx:$colD$rowIdx")->applyFromArray($subHeaderStyle);
            $rowIdx++;

            // --- CÁC DÒNG LINH KIỆN ---
            $items = $m_data['items'];
            $processed_until = -1; // Theo dõi linh kiện đã được merge ô

            for ($i = 0; $i < count($items); $i++) {
               $it = $items[$i];

               // Cột D: Số lượng luôn là 1
               $sheet->setCellValue($colD . $rowIdx, 1);
               $sheet->getStyle($colD . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

               // Cột C: Serial number (luôn là string để tránh Excel tự chuyển sang số)
               $sheet->setCellValueExplicit($colC . $rowIdx, (string) $it['sn'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

               // Style cho cột serial
               $snStyle = [
                  'font' => ['bold' => true],
                  'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
               ];
               // CPU: tô đen serial 
               if ($it['tp'] == 'CPU') {
                  $snStyle['font']['color'] = ['rgb' => '000000'];
               }
               // RAM: tô đỏ serial
               if ($it['tp'] == 'Ram') {
                  $snStyle['font']['color'] = ['rgb' => 'EF4444'];
               }
               // Hệ điều hành / Phần mềm: tô nền xanh lá nếu có serial
               if (($it['tp'] == 'Hệ Điều Hành' || $it['tp'] == 'Phần Mềm') && !empty($it['sn'])) {
                  $sheet->getStyle($colC . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCFCE7');
               }
               $sheet->getStyle($colC . $rowIdx)->applyFromArray($snStyle);

               // Merge ô cột A và B nếu nhiều dòng liên tiếp cùng loại và cùng tên linh kiện
               if ($i > $processed_until) {
                  $span = 1;
                  for ($j = $i + 1; $j < count($items); $j++) {
                     if ($items[$j]['tp'] === $it['tp'] && $items[$j]['nm'] === $it['nm']) {
                        $span++;
                     } else {
                        break;
                     }
                  }
                  if ($span > 1) {
                     $sheet->mergeCells($colA . $rowIdx . ':' . $colA . ($rowIdx + $span - 1));
                     $sheet->mergeCells($colB . $rowIdx . ':' . $colB . ($rowIdx + $span - 1));
                  }

                  // Ghi tên loại và tên linh kiện
                  $sheet->setCellValue($colA . $rowIdx, $it['tp']);
                  $sheet->setCellValue($colB . $rowIdx, $it['nm']);

                  $sheet->getStyle($colA . $rowIdx)->getFont()->setBold(true);
                  $sheet->getStyle($colA . $rowIdx)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                  $sheet->getStyle($colA . $rowIdx)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

                  $sheet->getStyle($colB . $rowIdx)->getAlignment()->setWrapText(true);
                  $sheet->getStyle($colB . $rowIdx)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

                  $processed_until = $i + $span - 1;
               }

               // Viền cho toàn bộ dòng
               $sheet->getStyle("$colA$rowIdx:$colD$rowIdx")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
               $sheet->getStyle("$colA$rowIdx:$colD$rowIdx")->getBorders()->getAllBorders()->getColor()->setRGB('000000');

               $rowIdx++;
            }

            $rowIdx += 1; // Dòng trống ngăn cách giữa các máy
         }
         $colOffset += 5; // Chuyển sang khối cột tiếp theo (4 cột dữ liệu + 1 cột trống)
      }

      // Xuất file ra trình duyệt
      if (ob_get_length()) {
         ob_end_clean();
      }
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment;filename="' . $filename . '"');
      header('Cache-Control: max-age=0');

      $writer = new Xlsx($spreadsheet);
      $writer->save('php://output');
      exit;
   } catch (Throwable $e) {
      die("Lỗi xuất Excel: " . $e->getMessage() . " trong file " . $e->getFile() . " dòng " . $e->getLine());
   }
}
?>
<?php require "thanh-dieu-huong.php"; ?>
<link rel="stylesheet" href="./css/xuat-file.css">
<link rel="stylesheet" href="./css/thanh-dieu-huong.css">

<div class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title">Xuất Dữ Liệu Serial</h1>
            <p class="subtitle">Dữ liệu tách biệt hoàn toàn theo từng máy</p>
        </div>

        <form method="post" action="">
            <button type="submit" name="export_excel" class="btn-export">
                <i class="fas fa-file-excel"></i> Xuất File Excel
            </button>
        </form>
    </div>
    <?php
   require_once "config.php";

   // Đếm tổng số serial đã nhập trong hệ thống
   $total_stmt = $pdo->query("SELECT COUNT(*) as total FROM chitiet_donhang");
   $total_count = $total_stmt->fetch(PDO::FETCH_ASSOC)['total'];

   // Lấy 3 đơn hàng gần nhất để xem trước
   $latest_orders_stmt = $pdo->query("SELECT DISTINCT id_donhang FROM chitiet_donhang ORDER BY id_donhang DESC LIMIT 3");
   $latest_order_ids = $latest_orders_stmt->fetchAll(PDO::FETCH_COLUMN);
   $preview_results = [];
   foreach ($latest_order_ids as $oid) {
      $res = get_pooled_components($pdo, $oid);
      $preview_results = array_merge($preview_results, $res);
   }

   // Giới hạn 50 dòng xem trước
   $preview_results = array_slice($preview_results, 0, 50);
   ?>
    <div class="stats-container">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-barcode"></i></div>
            <div class="stat-info">
                <h3>Tổng số Serial</h3>
                <p>
                    <?php echo number_format($total_count); ?>
                </p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: rgba(0, 123, 255, 0.1); color: #007bff;"><i
                    class="fas fa-shopping-cart"></i></div>
            <div class="stat-info">
                <h3>Đơn liên quan</h3>
                <p>
                    <?php
               // Đếm số đơn hàng có linh kiện trong hệ thống
               $oc_stmt = $pdo->query("SELECT COUNT(DISTINCT id_donhang) as total FROM chitiet_donhang");
               echo $oc_stmt->fetch(PDO::FETCH_ASSOC)['total'];
               ?>
                </p>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-eye"></i> Xem trước dữ liệu (Tách biệt theo máy)</h3>
        </div>
        <div class="table-responsive">
            <table class="preview-table">
                <thead>
                    <tr>
                        <th>SERIAL / MÃ MÁY</th>
                        <th style="text-align:center;">LOẠI</th>
                        <th>TÊN LINH KIỆN</th>
                        <!-- <th style="text-align:center;">SỐ IMEI</th> -->
                    </tr>
                </thead>
                <tbody>
                    <?php
               if (count($preview_results) > 0) {
                  // Nhóm lại theo từng máy để hiển thị trực quan
                  $prev_grouped = [];
                  foreach ($preview_results as $row) {
                     $may = $row['so_may_fix'];
                     $cfg = $row['linhkien_chon_fix'] ?? ($row['ten_cauhinh'] ?: 'Cấu hình');
                     $key = $row['id_donhang'] . '_' . md5($cfg) . '_MAY_' . $may;
                     if (!isset($prev_grouped[$key])) {
                        $prev_grouped[$key] = [
                           'title' => "MÁY " . $may . " (Đơn #" . $row['id_donhang'] . ")",
                           'cfg' => mb_strtoupper($cfg, 'UTF-8'),
                           'items' => []
                        ];
                     }
                     $prev_grouped[$key]['items'][] = $row;
                  }

                  // Render từng nhóm máy
                  foreach ($prev_grouped as $g) {
                     // Lấy IMEI của máy này từ items
                     $imei_display = '';
                     foreach ($g['items'] as $item) {
                        $loai = strtolower(trim($item['loai_linhkien'] ?? ''));
                        if (($loai === 'imei' || $loai === 'imer') && !empty(trim((string) ($item['so_serial'] ?? '')))) {
                           $imei_display = trim((string) $item['so_serial']);
                           break;
                        }
                     }

                     echo '<tr style="background:#1e3a8a; color:white; font-weight:bold;">
                  <td colspan="3" style="padding:12px 15px;">
                     <i class="fas fa-desktop"></i> ' . strtoupper($g['title']) . ' - ' . $g['cfg'] . '
                  </td>
            <td style="text-align:center; color:#facc15;">'
                        . ($imei_display ?: '<span style="opacity:0.5;">---</span>') .
                        '</td>
            </tr>';

                     foreach ($g['items'] as $item) {
                        $t = strtoupper($item['loai_linhkien']);
                        if (strpos($t, 'MAIN') !== false)
                           $t = 'MB';
                        // Ẩn dòng IMEI/IMER khỏi danh sách linh kiện vì đã hiển thị ở header
                        if ($t === 'IMEI' || $t === 'IMER')
                           continue;

                        echo '<tr>
                <td><code style="font-weight:bold; color:#059669; font-size:1.1rem;">'
                           . ($item['so_serial'] ?: '<span style="color:#cbd5e1;">(Trống)</span>') .
                           '</code></td>
                <td style="text-align:center; font-weight:bold; background:#f8fafc; color:#475569;">' . $t . '</td>
                <td>' . $item['ten_linhkien'] . '</td>
                <td></td>
              </tr>';
                     }
                     echo '<tr style="height:50px; background:white;"><td colspan="4" style="border:none;"></td></tr>';
                  }
               }
               ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
</body>

</html>