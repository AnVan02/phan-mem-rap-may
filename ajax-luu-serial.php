<?php
// Đảm bảo session.save_path đúng TRƯỚC khi start
$_session_path = __DIR__ . '/sessions';
if (!is_dir($_session_path)) mkdir($_session_path, 0755, true);
ini_set('session.save_path', $_session_path);
session_start();
// --- [PHIÊN BẢN MỚI V10 - KIỂM ĐỊNH MỨC THEO CẤU HÌNH ĐÍCH (linhkien_chon), SỬA LỖI TRÙNG SỐ MÁY GIỮA 2 CẤU HÌNH] ---
$log_entry = date('[Y-m-d H:i:s] ') . "AJAX-LUU-SERIAL V10 CALLED" . PHP_EOL;
file_put_contents('debug_log.txt', $log_entry . "POST: " . json_encode($_POST) . PHP_EOL, FILE_APPEND);

require "config.php";
header('Content-Type: application/json');
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Lỗi kết nối']);
    exit;
}
$pdo->exec("SET NAMES utf8mb4");

// Đảm bảo cột co_serial tồn tại (dùng để đánh dấu linh kiện không cần nhập serial)
// Dùng try/catch thay vì SHOW COLUMNS để không chạy metadata query mỗi request
try { $pdo->query("SELECT co_serial FROM chitiet_donhang LIMIT 0"); }
catch (PDOException $e_col) {
    $pdo->exec("ALTER TABLE chitiet_donhang ADD COLUMN co_serial TINYINT(1) NOT NULL DEFAULT 1 AFTER so_may");
}

function extract_so_may($choice)
{
    if (preg_match('/M[áàảãạ]y\s*(\d+)/ui', $choice, $matches))
        return (int) $matches[1];
    return 1; // Mặc định máy 1 nếu không bóc tách được
}

// "Space Hack": xem CLAUDE.md / luu-serial-db.php::get_owner_config() - PHẢI giữ nguyên khoảng trắng cuối chuỗi
function get_owner_config_save($ten_cauhinh)
{
    $tc = (string) ($ten_cauhinh ?? '');
    if (strpos($tc, ',') === false) {
        return trim($tc);
    }
    $trailing = strlen($tc) - strlen(rtrim($tc));
    $cfgs = array_map('trim', explode(',', $tc));
    return $cfgs[$trailing] ?? trim($cfgs[0] ?? '');
}

// Số máy của từng cấu hình con (owner), suy từ số dòng của các loại linh kiện "đại diện"
// (CPU/MAIN/VGA/SSD/PSU/FAN - luôn đúng 1 cái/máy). Các dòng này tồn tại trong chitiet_donhang
// ngay từ lúc tạo đơn (kể toán tạo đơn), KHÔNG phụ thuộc việc đã gán so_may hay chưa.
function get_config_machine_counts_save($pdo, $order_id)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = $pdo->prepare("SELECT ten_cauhinh, loai_linhkien FROM chitiet_donhang WHERE id_donhang = ?");
    $stmt->execute([$order_id]);
    $owner_counts = [];
    $defining_types = ['CPU', 'MAIN', 'MAINBOARD', 'VGA', 'SSD', 'PSU', 'FAN'];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $owner = get_owner_config_save($r['ten_cauhinh']);
        $type = strtoupper(trim((string) $r['loai_linhkien']));
        if (in_array($type, $defining_types)) {
            $owner_counts[$owner][$type] = ($owner_counts[$owner][$type] ?? 0) + 1;
        }
    }
    $result = [];
    foreach ($owner_counts as $owner => $types) {
        $qty = 0;
        foreach ($defining_types as $t) {
            if (isset($types[$t])) {
                $qty = $types[$t];
                break;
            }
        }
        $result[$owner] = $qty > 0 ? $qty : 1;
    }
    $cache = $result;
    return $cache;
}

// Số lượng "chuẩn" của 1 loại linh kiện cho MỘT máy cụ thể = tổng số dòng ĐÃ TỒN TẠI cho loại đó
// (bất kể đã gán máy hay chưa) chia cho số máy của cấu hình - CHIA DƯ CHO CÁC MÁY ĐẦU TIÊN
// (so_may <= số dư), giống hệt cách kho-import-serial.php tính "count_needed" khi render ô nhập
// (xem $pc_base/$pc_rem ở đó). Hai nơi PHẢI khớp công thức, nếu không máy đầu tiên (được kho-import
// cho phép nhận thêm 1 đơn vị dư) sẽ bị ajax-luu-serial.php chặn nhầm vì tưởng đã đủ.
// Trả về 0 (không giới hạn) nếu chưa có đủ dòng tham chiếu để suy ra số máy hoặc tổng số dòng.
function get_expected_qty_per_may($pdo, $order_id, $type, $owner, $so_may, &$cache)
{
    $cache_key = $type . '|' . $owner;
    if (!isset($cache[$cache_key])) {
        $machine_counts = get_config_machine_counts_save($pdo, $order_id);
        $machine_count = $machine_counts[$owner] ?? 0;
        if ($machine_count <= 0) {
            $cache[$cache_key] = ['base' => 0, 'rem' => 0, 'count' => 0];
        } else {
            $stmt = $pdo->prepare("SELECT ten_cauhinh FROM chitiet_donhang WHERE id_donhang = ? AND loai_linhkien = ?");
            $stmt->execute([$order_id, $type]);
            $total = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (get_owner_config_save($r['ten_cauhinh']) === $owner) {
                    $total++;
                }
            }
            if ($total < $machine_count) {
                $cache[$cache_key] = ['base' => 0, 'rem' => 0, 'count' => 0];
            } else {
                $cache[$cache_key] = ['base' => intdiv($total, $machine_count), 'rem' => $total % $machine_count, 'count' => $machine_count];
            }
        }
    }
    $info = $cache[$cache_key];
    if ($info['count'] <= 0) {
        return 0;
    }
    return $info['base'] + (($so_may > 0 && $so_may <= $info['rem']) ? 1 : 0);
}

$order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$config_name = isset($_POST['config_name']) ? trim((string) $_POST['config_name']) : '';
$serials = $_POST['serials'] ?? null;

if ($order_id <= 0 || !is_array($serials) || $config_name === '') {
    echo json_encode(['success' => false, 'message' => 'Dữ liệu thiếu']);
    exit;
}
try {
    $pdo->beginTransaction();
    $so_may_t = isset($_POST['machine_idx']) ? (int) $_POST['machine_idx'] : extract_so_may($config_name);
    $ln_pure = $config_name;
    if (strpos($config_name, '|') !== false) {
        $parts = explode('|', $config_name);
        $ln_pure = mb_strtolower(trim($parts[0]), 'UTF-8');
    }

    if ($order_id <= 0 || empty($ln_pure)) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ (Thiếu ID đơn hàng hoặc Tên cấu hình)']);
        exit;
    }

    // BƯỚC 1: XÁC ĐỊNH LINH KIỆN NÀO THỰC SỰ ỨNG VỚI SERIAL QUÉT ĐƯỢC
    // Câu lệnh cập nhật dựa trên Serial có sẵn trong DB
    // BỎ BƯỚC GIẢI PHÓNG TOÀN BỘ MÁY (Đáp ứng: không phải máy không đổi cũng cập nhập)
    // $stmt_clear = $pdo->prepare("UPDATE chitiet_donhang SET linhkien_chon = NULL, so_may = 0 
    //                             WHERE id_donhang = ? AND linhkien_chon = ? AND so_may = ?");
    // $stmt_clear->execute([$order_id, $ln_pure, $so_may_t]);

    // Lấy ID người dùng từ Session
    $user_id = $_SESSION['user_id'] ?? null;

    // --- TRƯỜNG HỢP 4: KIỂM TRA QUYỀN SỞ HỮU TRƯỚC KHI LƯU ---
    // Dùng cùng cách normalize như ajax-handle-lock.php (preg_replace NFC/NFD safe)
    $clean_req_cfg = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($config_name), 'UTF-8'));
    $stmt_check_lock = $pdo->prepare("SELECT user_id, config_name FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ?");
    $stmt_check_lock->execute([$order_id, $so_may_t]);
    $lock_owner = false;
    foreach ($stmt_check_lock->fetchAll(PDO::FETCH_ASSOC) as $lrow) {
        $clean_db_cfg = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($lrow['config_name']), 'UTF-8'));
        if ($clean_db_cfg === $clean_req_cfg) {
            $lock_owner = $lrow['user_id'];
            break;
        }
    }

    if ($lock_owner === false || (int) $lock_owner !== (int) $user_id) {
        // Kiểm tra xem user này thực sự đang ở máy nào để báo lỗi chi tiết
        $stmt_where = $pdo->prepare("SELECT so_may, config_name FROM trang_thai_lap_may WHERE user_id = ? LIMIT 1");
        $stmt_where->execute([$user_id]);
        $where = $stmt_where->fetch(PDO::FETCH_ASSOC);

        $err_msg = "Phiên làm việc đã hết hạn hoặc bạn không có quyền cập nhật máy này. Vui lòng tải lại trang và thử lại.";

        if ($where) {
            $err_msg = "Hệ thống ghi nhận bạn đang làm việc ở Máy " . $where['so_may'] . " (" . $where['config_name'] . ").";
        }

        echo json_encode([
            'success' => false,
            'error_type' => 'auth_lock',
            'message' => $err_msg
        ]);
        exit;
    }
    // -------------------------------------------------------

    // BƯỚC 2: GÁN CÁC LINH KIỆN MỚI
    $stmt_update_by_id = $pdo->prepare("UPDATE chitiet_donhang 
                                           SET so_serial = ?, linhkien_chon = ?, so_may = ?, user_id = ?, user_id_save = ? 
                                            WHERE id_ct = ? AND id_donhang = ?");



    // Lấy thông tin hiện tại để đối chiếu (tránh cập nhật thừa)
    $stmt_get_current = $pdo->prepare("SELECT id_ct, so_serial, linhkien_chon, so_may, user_id, user_id_save, co_serial, ten_cauhinh, loai_linhkien FROM chitiet_donhang WHERE id_ct = ?");
    $qty_expected_cache = [];
    $qty_assigned_cache = [];
    $warnings = [];

    // TỰ ĐỘNG GIẢI PHÓNG DÒNG "KẸT" TỪ TRƯỚC: form phía JS luôn gửi lên TOÀN BỘ ô serial đang
    // hiển thị cho máy này (xem js/quet-ma.js) - nên bất kỳ dòng nào trong DB đang gán cho máy này
    // (cùng loại+cấu hình) mà KHÔNG nằm trong lần lưu này chắc chắn là dòng "vô hình" còn sót lại
    // từ một lần lưu lỗi trước (VD: máy từng bị gán thừa RAM do lỗi cũ). Nếu đang thừa so với định
    // mức/máy, giải phóng các dòng đó về kho tự do (so_may=0) để không tiếp tục chặn nhầm serial mới.
    if ($so_may_t > 0) {
        $submitted_ids = [];
        foreach ($serials as $it) {
            $iid = isset($it['id_ct']) ? (int) $it['id_ct'] : 0;
            if ($iid > 0)
                $submitted_ids[$iid] = true;
        }
        // QUAN TRỌNG: so_may là số THEO TỪNG CẤU HÌNH (config A và config B trong cùng đơn đều có
        // thể có "Máy 1" riêng) - PHẢI lọc thêm theo linhkien_chon = $ln_pure, nếu không sẽ gộp
        // nhầm 2 máy của 2 cấu hình khác nhau chỉ vì trùng số thứ tự.
        $stmt_existing_on_machine = $pdo->prepare("SELECT id_ct, ten_cauhinh, loai_linhkien FROM chitiet_donhang WHERE id_donhang = ? AND so_may = ? AND linhkien_chon = ?");
        $stmt_existing_on_machine->execute([$order_id, $so_may_t, $ln_pure]);
        $by_group = [];
        foreach ($stmt_existing_on_machine->fetchAll(PDO::FETCH_ASSOC) as $er) {
            $er_type = strtoupper(trim((string) $er['loai_linhkien']));
            if ($er_type === '' || in_array($er_type, ['IMEI', 'IMER']))
                continue;
            $by_group[$er_type][] = $er;
        }
        $stmt_release_orphan = $pdo->prepare("UPDATE chitiet_donhang SET linhkien_chon = NULL, so_may = 0, user_id = NULL, user_id_save = NULL WHERE id_ct = ? AND id_donhang = ?");
        foreach ($by_group as $g_type => $rows) {
            $g_owner = $ln_pure;
            $expected = get_expected_qty_per_may($pdo, $order_id, $g_type, $g_owner, $so_may_t, $qty_expected_cache);
            if ($expected <= 0)
                continue;
            $excess = count($rows) - $expected;
            if ($excess <= 0)
                continue;
            foreach ($rows as $r) {
                if ($excess <= 0)
                    break;
                if (isset($submitted_ids[(int) $r['id_ct']]))
                    continue; // dòng này đang được form gửi lên -> giữ nguyên, không đụng vào
                $stmt_release_orphan->execute([$r['id_ct'], $order_id]);
                $excess--;
            }
        }
    }

    // Giải phóng dòng CŨ nếu ô nhập đã được kiemtra.php gán sang một id_ct KHÁC
    // (VD: sửa serial "1" thành "2" khiến ô rebind từ dòng A sang dòng B) - nếu không,
    // dòng A vẫn giữ so_may/linhkien_chon cũ và bị tính là đã gán cho máy này song song với dòng B.
    $stmt_release_stale = $pdo->prepare("UPDATE chitiet_donhang
                                            SET linhkien_chon = NULL, so_may = 0, user_id = NULL, user_id_save = NULL
                                            WHERE id_ct = ? AND id_donhang = ? AND so_may = ? AND linhkien_chon = ?");

    $updated = 0;
    foreach ($serials as $item) {
        $val = isset($item['val']) ? strtoupper(trim((string) $item['val'])) : '';
        $id_ct = isset($item['id_ct']) ? (int) $item['id_ct'] : 0;
        $orig_id_ct = isset($item['orig_id_ct']) ? (int) $item['orig_id_ct'] : 0;
        $type = isset($item['type']) ? strtoupper(trim((string) $item['type'])) : '';

        if ($id_ct <= 0)
            continue;

        if ($orig_id_ct > 0 && $orig_id_ct !== $id_ct) {
            $stmt_release_stale->execute([$orig_id_ct, $order_id, $so_may_t, $ln_pure]);
        }

        // BƯỚC 1: Kiểm tra xem serial, cấu hình hoặc số máy có thay đổi không
        $stmt_get_current->execute([$id_ct]);
        $row = $stmt_get_current->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $co_serial = (int) ($row['co_serial'] ?? 1);
            // CASE/FAN vốn không có serial riêng nên luôn cho phép lưu rỗng, giống WIN/IMEI/IMER
            if ($val === '' && !in_array($type, ['WIN', 'IMEI', 'IMER', 'CASE', 'FAN']) && $co_serial !== 0) {
                // Khi người dùng xóa serial trên giao diện: GIỮ NGUYÊN so_serial gốc,
                // chỉ giải phóng liên kết máy (so_may = 0) để serial trở về "kho tự do"
                if ((int)$row['so_may'] > 0) {
                    $stmt_release = $pdo->prepare("UPDATE chitiet_donhang 
                                                    SET linhkien_chon = NULL, so_may = 0, user_id = NULL, user_id_save = NULL 
                                                    WHERE id_ct = ? AND id_donhang = ?");
                    $stmt_release->execute([$id_ct, $order_id]);
                    $updated++;
                }
                continue;
            }

            $current_sn = (string) ($row['so_serial'] ?? '');
            $current_cfg = (string) ($row['linhkien_chon'] ?? '');
            $current_m = (int) ($row['so_may'] ?? 0);
            $current_user_id = (int) ($row['user_id'] ?? 0);
            $current_user_save = (int) ($row['user_id_save'] ?? 0);

            // Cập nhật nếu: 
            // 1. Serial, cấu hình hoặc số máy có thay đổi
            // 2. HOẶC nếu user_id đang được gán (đang khóa) -> cần giải phóng (set NULL)
            // 3. HOẶC nếu user_id_save chưa được gán chính xác (để xác nhận ai là người lưu cuối)
            if (
                $current_sn !== $val ||
                $current_cfg !== (string) $ln_pure ||
                $current_m !== (int) $so_may_t ||
                $row['user_id'] !== null ||
                ($row['user_id_save'] === null && $user_id !== null) ||
                ($row['user_id_save'] !== null && (int) $row['user_id_save'] !== (int) $user_id)
            ) {
                // Đang chuyển dòng này sang MÁY KHÁC (kể cả từ kho chờ so_may=0) -> kiểm tra
                // máy đích đã đủ số lượng linh kiện loại này chưa, tránh gán thừa (VD: 3 RAM/máy
                // trong khi các máy khác chỉ có 2, hoặc 2 MAIN/máy trong khi chỉ cần 1).
                if ($current_m !== (int) $so_may_t && $so_may_t > 0 && $type !== '' && !in_array($type, ['IMEI', 'IMER'])) {
                    // Chủ sở hữu dùng để kiểm định mức PHẢI là cấu hình ĐÍCH đang lưu vào ($ln_pure),
                    // không phải nhãn ten_cauhinh tĩnh cũ của dòng này - vì dòng có thể là linh kiện
                    // dùng chung (Space Hack) giữa nhiều cấu hình, và so_may lặp lại giữa các cấu hình
                    // khác nhau trong cùng đơn (Máy 1 của cấu hình A khác Máy 1 của cấu hình B).
                    $owner_ct = $ln_pure;
                    $loai_ct = $type !== '' ? $type : strtoupper(trim((string) ($row['loai_linhkien'] ?? '')));
                    $expected = get_expected_qty_per_may($pdo, $order_id, $loai_ct, $owner_ct, $so_may_t, $qty_expected_cache);
                    if ($expected > 0) {
                        $count_key = $loai_ct . '|' . $owner_ct . '|' . $so_may_t;
                        if (!isset($qty_assigned_cache[$count_key])) {
                            $stmt_cnt = $pdo->prepare("SELECT id_ct FROM chitiet_donhang WHERE id_donhang = ? AND loai_linhkien = ? AND so_may = ? AND linhkien_chon = ?");
                            $stmt_cnt->execute([$order_id, $loai_ct, $so_may_t, $owner_ct]);
                            $c = 0;
                            foreach ($stmt_cnt->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                                if ((int) $cr['id_ct'] === $id_ct)
                                    continue;
                                $c++;
                            }
                            $qty_assigned_cache[$count_key] = $c;
                        }
                        if ($qty_assigned_cache[$count_key] >= $expected) {
                            $warnings[] = "Máy $so_may_t đã đủ $expected $loai_ct ($owner_ct), bỏ qua serial \"$val\".";
                            continue;
                        }
                        $qty_assigned_cache[$count_key]++;
                    }
                }

                $stmt_update_by_id->execute([$val, $ln_pure, $so_may_t, null, $user_id, $id_ct, $order_id]);
                $updated++;
            }
        }
    }
    $pdo->commit();

    // GIẢI PHÓNG KHÓA MÁY - Dùng PHP normalize để tránh lỗi NFC/NFD trên server Linux
    $stmt_get_locks = $pdo->prepare("SELECT config_name FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ?");
    $stmt_get_locks->execute([$order_id, $so_may_t]);
    $stmt_unlock = $pdo->prepare("DELETE FROM trang_thai_lap_may WHERE id_donhang = ? AND so_may = ? AND config_name = ?");
    foreach ($stmt_get_locks->fetchAll(PDO::FETCH_COLUMN) as $stored_cfg) {
        $clean_stored = preg_replace('/[^a-z0-9]/u', '', mb_strtolower(trim($stored_cfg), 'UTF-8'));
        if ($clean_stored === $clean_req_cfg) {
            $stmt_unlock->execute([$order_id, $so_may_t, $stored_cfg]);
            break;
        }
    }

    // Xóa dấu vết đang ở trong máy (Ngăn chặn F5 sau khi lưu)
    unset($_SESSION['LAST_MACHINE_ENTERED']);

    $msg = $updated > 0 ? "Đã lưu thành công cho $updated linh kiện!" : "Dữ liệu đã được đồng bộ!";
    if (!empty($warnings)) {
        $msg .= ' Cảnh báo: ' . implode(' ', $warnings);
    }
    echo json_encode(['success' => true, 'message' => $msg, 'updated' => $updated, 'warnings' => $warnings]);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
