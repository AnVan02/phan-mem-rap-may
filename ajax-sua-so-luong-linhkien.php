<?php
session_start();
require "config.php";
header('Content-Type: application/json');

function respondError($message)
{
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    respondError('Chưa đăng nhập.');
}

if (!$pdo) {
    respondError('Không có kết nối Database.');
}

// "Space Hack": xem CLAUDE.md / luu-serial-db.php::get_owner_config()
function get_owner_config_qty($ten_cauhinh)
{
    $tc = (string) ($ten_cauhinh ?? '');
    if (strpos($tc, ',') === false) {
        return trim($tc);
    }
    $trailing = strlen($tc) - strlen(rtrim($tc));
    $cfgs = array_map('trim', explode(',', $tc));
    return $cfgs[$trailing] ?? trim($cfgs[0] ?? '');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$id_donhang = (int) ($input['id_donhang'] ?? 0);
$so_may = (int) ($input['so_may'] ?? 0);
$owner = trim((string) ($input['owner'] ?? ''));
$loai_linhkien = trim((string) ($input['loai_linhkien'] ?? ''));
$ten_linhkien = trim((string) ($input['ten_linhkien'] ?? ''));
$so_luong_moi = isset($input['so_luong_moi']) ? (int) $input['so_luong_moi'] : -1;

if ($id_donhang <= 0 || $so_may <= 0 || $owner === '' || $loai_linhkien === '' || $ten_linhkien === '') {
    respondError('Thiếu dữ liệu bắt buộc.');
}
if ($so_luong_moi < 0) {
    respondError('Số lượng không hợp lệ.');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM chitiet_donhang WHERE id_donhang = ? AND so_may = ? AND loai_linhkien = ? AND ten_linhkien = ? ORDER BY id_ct ASC");
    $stmt->execute([$id_donhang, $so_may, $loai_linhkien, $ten_linhkien]);
    $all_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $matched = array_values(array_filter($all_rows, function ($r) use ($owner) {
        return get_owner_config_qty($r['ten_cauhinh']) === $owner;
    }));

    $current = count($matched);
    $deleted_with_serial = 0;

    if ($so_luong_moi > $current) {
        $need = $so_luong_moi - $current;

        // Cần 1 dòng mẫu để copy nguyên văn ten_cauhinh (giữ đúng khoảng trắng "space hack"), ten_donhang, co_serial
        $template = $matched[0] ?? null;
        if (!$template) {
            $stmt2 = $pdo->prepare("SELECT * FROM chitiet_donhang WHERE id_donhang = ? AND loai_linhkien = ? AND ten_linhkien = ?");
            $stmt2->execute([$id_donhang, $loai_linhkien, $ten_linhkien]);
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (get_owner_config_qty($r['ten_cauhinh']) === $owner) {
                    $template = $r;
                    break;
                }
            }
        }
        if (!$template) {
            $pdo->rollBack();
            respondError('Không tìm thấy linh kiện mẫu để tạo thêm số lượng.');
        }

        $has_co_serial = array_key_exists('co_serial', $template);
        if ($has_co_serial) {
            $stmt_ins = $pdo->prepare("INSERT INTO chitiet_donhang (id_donhang, ten_donhang, ten_cauhinh, ten_linhkien, loai_linhkien, linhkien_chon, so_serial, so_may, user_id, user_id_save, co_serial) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, NULL, NULL, ?)");
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO chitiet_donhang (id_donhang, ten_donhang, ten_cauhinh, ten_linhkien, loai_linhkien, linhkien_chon, so_serial, so_may, user_id, user_id_save) VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, NULL, NULL)");
        }
        for ($i = 0; $i < $need; $i++) {
            $params = [
                $id_donhang,
                $template['ten_donhang'],
                $template['ten_cauhinh'],
                $ten_linhkien,
                $loai_linhkien,
                $so_may,
            ];
            if ($has_co_serial) {
                $params[] = $template['co_serial'];
            }
            $stmt_ins->execute($params);
        }
    } elseif ($so_luong_moi < $current) {
        $need_remove = $current - $so_luong_moi;

        $empty_rows = array_values(array_filter($matched, function ($r) {
            return empty($r['so_serial']);
        }));
        $filled_rows = array_values(array_filter($matched, function ($r) {
            return !empty($r['so_serial']);
        }));

        $to_delete = [];
        $from_empty = min($need_remove, count($empty_rows));
        for ($i = 0; $i < $from_empty; $i++) {
            $to_delete[] = $empty_rows[$i]['id_ct'];
        }
        $remaining = $need_remove - $from_empty;
        for ($i = 0; $i < $remaining; $i++) {
            $to_delete[] = $filled_rows[$i]['id_ct'];
            $deleted_with_serial++;
        }

        if (!empty($to_delete)) {
            $placeholders = implode(',', array_fill(0, count($to_delete), '?'));
            $pdo->prepare("DELETE FROM chitiet_donhang WHERE id_ct IN ($placeholders)")->execute($to_delete);
        }
    }

    $pdo->commit();

    // Đếm lại trạng thái mới nhất sau khi thay đổi
    $stmt3 = $pdo->prepare("SELECT so_serial, ten_cauhinh FROM chitiet_donhang WHERE id_donhang = ? AND so_may = ? AND loai_linhkien = ? AND ten_linhkien = ?");
    $stmt3->execute([$id_donhang, $so_may, $loai_linhkien, $ten_linhkien]);
    $final_matched = array_filter($stmt3->fetchAll(PDO::FETCH_ASSOC), function ($r) use ($owner) {
        return get_owner_config_qty($r['ten_cauhinh']) === $owner;
    });

    $entered = 0;
    foreach ($final_matched as $r) {
        if (!empty($r['so_serial'])) {
            $entered++;
        }
    }

    echo json_encode([
        'success' => true,
        'total' => count($final_matched),
        'entered' => $entered,
        'deleted_with_serial' => $deleted_with_serial,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respondError('Lỗi database: ' . $e->getMessage());
}
