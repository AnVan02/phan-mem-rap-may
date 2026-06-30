<?php
session_start();
// Tắt mọi thông báo lỗi hiển thị trực tiếp để không làm hỏng JSON
error_reporting(0);
ini_set('display_errors', 0);

require "config.php";

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;

// Hàm trả về lỗi nhanh
function respondError($message)
{
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!$pdo) {
    respondError('Không có kết nối Database.');
}

// Nhận dữ liệu JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['groups'])) {
    respondError('Dữ liệu gửi lên không đúng định dạng.');
}

try {
    $pdo->beginTransaction();

    $note = trim($data['customer']['note'] ?? '');

    // 1. Lưu đơn hàng tổng quát
    // Nếu chưa có cột ghi_chu trên bảng donhang thì tự động thêm.
    $columnCheck = $pdo->query("SHOW COLUMNS FROM donhang LIKE 'ghi_chu'")->fetch(PDO::FETCH_ASSOC);
    if (!$columnCheck) {
        $pdo->exec("ALTER TABLE donhang ADD COLUMN ghi_chu TEXT NULL AFTER ten_khach_hang");
    }

    // Tự động thêm cột co_serial nếu chưa có
    $colSerial = $pdo->query("SHOW COLUMNS FROM chitiet_donhang LIKE 'co_serial'")->fetch(PDO::FETCH_ASSOC);
    if (!$colSerial) {
        $pdo->exec("ALTER TABLE chitiet_donhang ADD COLUMN co_serial TINYINT(1) NOT NULL DEFAULT 1 AFTER so_may");
    }

    $stmt = $pdo->prepare("INSERT INTO donhang (ma_don_hang, ten_khach_hang, so_luong_may, user_id, ghi_chu) VALUES (?, ?, ?, ?, ?)");

    $custom_code = trim($data['customer']['code'] ?? '');
    $ten_kh = !empty($data['customer']['name']) ? $data['customer']['name'] : 'Khách lẻ';

    $tong_so_may_don_hang = 0;
    foreach ($data['groups'] as $group) {
        $tong_so_may_don_hang += (int) $group['quantity'];
    }

    // Insert tạm với placeholder, sẽ cập nhật sau khi có ID
    $ma_don_tmp = $custom_code ?: ('__TMP__' . time());
    $stmt->execute([$ma_don_tmp, $ten_kh, $tong_so_may_don_hang, $user_id, $note]);
    $id_donhang_vua_tao = $pdo->lastInsertId();

    // Sinh mã đơn hàng chuẩn ROSA-YYMMDD-{id} nếu không có mã tùy chỉnh
    if (empty($custom_code)) {
        $ma_don = 'ROSA-' . date('ymd') . '-' . str_pad($id_donhang_vua_tao, 4, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE donhang SET ma_don_hang = ? WHERE id_donhang = ?")->execute([$ma_don, $id_donhang_vua_tao]);
    } else {
        $ma_don = $custom_code;
    }

    // 2. Xây dựng bảng tra cứu: linh kiện => danh sách nhóm cấu hình sử dụng
    // Tự gộp linh kiện giống nhau giữa các cấu hình (bao gồm RAM nếu giống nhau).
    $component_groups = []; // key: "cleaned_name|loai|qty" => array of tên nhóm
    foreach ($data['groups'] as $g_idx => $group) {
        // Giữ đúng tên cấu hình do người dùng đặt (vd: h12, 222...) để SQL hiển thị đúng như yêu cầu.
        // Nếu không có tên thì fallback "Cấu hình X".
        $ten_nhom = !empty($group['name']) ? $group['name'] : 'Cấu hình ' . ($g_idx + 1);

        foreach ($group['rows'] as $row) {
            $loai_raw = strtoupper(trim($row['label']));
            $loai = ($loai_raw === 'MAINBOARD') ? 'MAIN' : $loai_raw;

            foreach ($row['mainInputs'] as $idx => $ten_linh_kien) {
                $raw_lk = trim($ten_linh_kien);
                if ($raw_lk !== '') {
                    $q_mult = 1;
                    $clean_lk = $raw_lk;
                    if (preg_match('/^(\d+)x(.+)$/i', $raw_lk, $matches)) {
                        $q_mult = (int) $matches[1];
                        $clean_lk = trim($matches[2]);
                    }
                    $tong_so_luong_lk = (int) ($row['qtyInputs'][$idx] ?? 1) * $q_mult;


                    // Gộp linh kiện giữa các cấu hình.
                    // Riêng RAM: nhiều khi tên linh kiện bị nhập như mã nội bộ (vd: 111/2222) nhưng cùng dung lượng 8G.
                    // Nên gộp RAM theo "dấu hiệu dung lượng" nếu có (8g/16g...), fallback về tên.
                    $ram_sig = '';
                    if ($loai === 'RAM') {
                        if (preg_match('/(\d+)\s*g\b/i', $raw_lk, $mRam) || preg_match('/(\d+)\s*g\b/i', $clean_lk, $mRam)) {
                            $ram_sig = strtolower($mRam[1] . 'g');
                        } else {
                            $ram_sig = strtolower($clean_lk);
                        }
                    }
                    // Để gộp được RAM của tất cả cấu hình trong đơn: Bỏ tên cấu hình ra khỏi key gộp
                    $key = ($loai === 'RAM')
                        ? ($ram_sig . '|' . $loai)
                        : (strtolower($clean_lk) . '|' . $loai . '|' . $tong_so_luong_lk);
                    if (!isset($component_groups[$key])) {
                        $component_groups[$key] = [];
                    }
                    if (!in_array($ten_nhom, $component_groups[$key])) {
                        $component_groups[$key][] = $ten_nhom;
                    }
                }
            }
        }
    }

    // 3. Lưu chi tiết linh kiện
    // Thứ tự sắp xếp loại linh kiện (CPU trước, WIN sau cùng)
    $loai_order = ['CPU' => 1, 'MAIN' => 2, 'RAM' => 3, 'SSD' => 4, 'VGA' => 5, 'PSU' => 6, 'FAN' => 7, 'CASE' =>9, 'WIN' => 9, 'IMEI' => 10];

    // Gom tất cả dòng cần insert vào mảng trước
    $all_rows = [];
    $seq = 0;

    foreach ($data['groups'] as $g_idx => $group) {
        // Giữ đúng tên cấu hình do người dùng đặt để map linhkien_chon = "<tên cấu hình> | Máy Y"
        $ten_nhom_don_hang = !empty($group['name']) ? $group['name'] : 'Cấu hình ' . ($g_idx + 1);
        $so_luong_may_trong_nhom = (int) $group['quantity'];


        foreach ($group['rows'] as $row) {
            $loai_raw = strtoupper(trim($row['label']));
            $loai = ($loai_raw === 'MAINBOARD') ? 'MAIN' : $loai_raw;

            foreach ($row['mainInputs'] as $idx => $ten_linh_kien) {
                $raw_lk = trim($ten_linh_kien);

                if ($raw_lk !== '') {
                    $q_mult = 1;
                    $clean_lk = $raw_lk;
                    if (preg_match('/^(\d+)x(.+)$/i', $raw_lk, $matches)) {
                        $q_mult = (int) $matches[1];
                        $clean_lk = trim($matches[2]);
                    }
                    $tong_so_luong_lk = (int) ($row['qtyInputs'][$idx] ?? 1) * $q_mult;

                    // Phải dùng cùng key-rule như bước (2) ở trên
                    $ram_sig = '';
                    if ($loai === 'RAM') {
                        if (preg_match('/(\d+)\s*g\b/i', $raw_lk, $mRam) || preg_match('/(\d+)\s*g\b/i', $clean_lk, $mRam)) {
                            $ram_sig = strtolower($mRam[1] . 'g');
                        } else {
                            $ram_sig = strtolower($clean_lk);
                        }
                    }

                    $key = ($loai === 'RAM')
                        ? ($ram_sig . '|' . $loai)
                        : (strtolower($clean_lk) . '|' . $loai . '|' . $tong_so_luong_lk);
                    // Mới: Sắp xếp danh sách cấu hình và thêm khoảng trắng ẩn ở cuối để phân biệt "chủ sở hữu"
                    // Điều này giúp database hiện giống hệt nhau nhưng code vẫn biết linh kiện thuộc về ai
                    $all_cfg_names = isset($component_groups[$key]) ? $component_groups[$key] : [$ten_nhom_don_hang];
                    // sort($all_cfg_names); // Tắt sắp xếp Alphabet để giữ đúng thứ tự từ file JS truyền sang
                    $sorted_list_string = implode(', ', $all_cfg_names);
                    $owner_idx = array_search($ten_nhom_don_hang, $all_cfg_names);
                    $ten_cauhinh = $sorted_list_string . str_repeat(' ', (int) $owner_idx);

                    $co_serial = isset($row['hasSerial']) ? (int)$row['hasSerial'] : 1;
                    for ($m = 1; $m <= $so_luong_may_trong_nhom; $m++) {
                        $so_luong_lk_trong_1_may = $tong_so_luong_lk;
                        for ($q = 1; $q <= $so_luong_lk_trong_1_may; $q++) {
                            $all_rows[] = [
                                'sort' => $loai_order[$loai] ?? 99,
                                'machine' => $m,
                                'input_idx' => $idx,
                                'seq' => $seq++,
                                'ten_donhang' => $ten_kh,
                                'ten_cauhinh' => $ten_cauhinh,
                                'linhkien_chon' => null,
                                'ten_linhkien' => mb_strtoupper($clean_lk, 'UTF-8'),
                                'loai' => $loai,
                                'so_may' => $m,
                                'co_serial' => $co_serial,
                            ];
                        }
                    }
                }
            }
        }
        // --- TỰ ĐỘNG THÊM MÃ MÁY (IMEI) CHO TỪNG MÁY (NẰM CUỐI CÙNG) ---
        // Mỗi máy trong đơn hàng sẽ luôn có 1 slot để nhập IMEI
        for ($m = 1; $m <= $so_luong_may_trong_nhom; $m++) {
            $all_rows[] = [
                'sort' => $loai_order['IMEI'] ?? 99,
                'machine' => $m,
                'input_idx' => 999,
                'seq' => $seq++,
                'ten_donhang' => $ten_kh,
                'ten_cauhinh' => $ten_nhom_don_hang,
                'linhkien_chon' => null,
                'ten_linhkien' => 'MÃ MÁY (IMEI)',
                'loai' => 'IMEI',
                'so_may' => $m,
                'co_serial' => 1,
            ];
        }
    }

    // Sắp xếp theo loại linh kiện: CPU → MAIN → RAM → SSD → VGA → PSU → FAN → WIN
    usort($all_rows, function ($a, $b) {
        $c = $a['sort'] <=> $b['sort'];
        if ($c !== 0)
            return $c;
        // Giữ thứ tự ổn định theo máy và thứ tự input để RAM/linh kiện không bị trộn (usort không stable)
        $c = ($a['machine'] ?? 0) <=> ($b['machine'] ?? 0);
        if ($c !== 0)
            return $c;
        $c = ($a['input_idx'] ?? 0) <=> ($b['input_idx'] ?? 0);
        if ($c !== 0)
            return $c;
        return ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0);
    });

    // Insert tất cả dòng đã sắp xếp
    $stmt_detail = $pdo->prepare("INSERT INTO chitiet_donhang (id_donhang, ten_donhang, ten_cauhinh, ten_linhkien, loai_linhkien, linhkien_chon, user_id, user_id_save, so_may, co_serial) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($all_rows as $r) {
        $stmt_detail->execute([
            $id_donhang_vua_tao,
            $r['ten_donhang'],
            $r['ten_cauhinh'],
            $r['ten_linhkien'],
            $r['loai'],
            null,
            null,
            null,
            $r['so_may'] ?? null,
            $r['co_serial'] ?? 1,
        ]);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'order_id' => $id_donhang_vua_tao]);
} catch (Exception $e) {
    if ($pdo)
        $pdo->rollBack();
    respondError('Lỗi SQL: ' . $e->getMessage());
}
