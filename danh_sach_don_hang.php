<?php
require "config.php";
require "phan-quyen.php";

// ================== XỬ LÝ CẬP NHẬT ==================
// Phải xử lý trước khi thanh-dieu-huong.php in HTML ra, nếu không header() redirect sẽ lỗi.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save') {
    $id_donhang = (int) ($_POST['id_donhang'] ?? 0);
    $ma_don_hang = trim($_POST['ma_don_hang'] ?? '');
    $ten_khach_hang = trim($_POST['ten_khach_hang'] ?? '');
    $ghi_chu = trim($_POST['ghi_chu'] ?? '');
    $so_luong_may = max(1, (int) ($_POST['so_luong_may'] ?? 1));
    $user_id = isset($_POST['user_id']) && $_POST['user_id'] !== '' ? (int) $_POST['user_id'] : null;

    if ($id_donhang === 0) {
        die('Thiếu id_donhang, không thể cập nhật.');
    }
    if ($ma_don_hang === '') {
        die('Mã đơn hàng không được để trống.');
    }

    $stmt = $pdo->prepare("UPDATE donhang
        SET ma_don_hang = ?, ten_khach_hang = ?, ghi_chu = ?, so_luong_may = ?, user_id = ?
        WHERE id_donhang = ?");
    $stmt->execute([$ma_don_hang, $ten_khach_hang, $ghi_chu, $so_luong_may, $user_id, $id_donhang]);

    header('Location: ?msg=updated');
    exit;
}

require "thanh-dieu-huong.php";

// ================== DỮ LIỆU HIỂN THỊ ==================
$msg = $_GET['msg'] ?? '';

$donhang = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM donhang WHERE id_donhang = ?");
    $stmt->execute([(int) $_GET['id']]);
    $donhang = $stmt->fetch() ?: null;
}

$donhangs = $pdo->query("SELECT * FROM donhang ORDER BY ngay_tao DESC")->fetchAll();

// ================== NHÓM LINH KIỆN THEO CẤU HÌNH / MÁY (để sửa số lượng) ==================
// "Space Hack": ten_cauhinh có thể là "Cấu hình 1, Cấu hình 2" với khoảng trắng cuối chuỗi
// mã hoá index cấu hình sở hữu (0 khoảng trắng -> phần tử 0, ...). Xem CLAUDE.md.
function get_owner_config_ds($ten_cauhinh)
{
    $tc = (string) ($ten_cauhinh ?? '');
    if (strpos($tc, ',') === false) {
        return trim($tc);
    }
    $trailing = strlen($tc) - strlen(rtrim($tc));
    $cfgs = array_map('trim', explode(',', $tc));
    return $cfgs[$trailing] ?? trim($cfgs[0] ?? '');
}

$linhkien_groups = [];
if ($donhang) {
    $loai_order = ['CPU' => 1, 'MAIN' => 2, 'RAM' => 3, 'SSD' => 4, 'VGA' => 5, 'PSU' => 6, 'FAN' => 7, 'CASE' => 8, 'WIN' => 9];

    $stmt = $pdo->prepare("SELECT * FROM chitiet_donhang WHERE id_donhang = ? ORDER BY id_ct ASC");
    $stmt->execute([(int) $donhang['id_donhang']]);
    $ct_rows = $stmt->fetchAll();

    $tmp = [];
    foreach ($ct_rows as $r) {
        $loai = strtoupper(trim((string) ($r['loai_linhkien'] ?? '')));
        if ($loai === '' || $loai === 'IMEI' || $loai === 'IMER') {
            continue; // IMEI là 1 slot cố định/máy, không phải "số lượng linh kiện"
        }
        $owner = get_owner_config_ds($r['ten_cauhinh']);
        $so_may = (int) ($r['so_may'] ?? 0);
        $ten = (string) ($r['ten_linhkien'] ?? '');
        $key = $owner . '|' . $so_may . '|' . $loai . '|' . $ten;

        if (!isset($tmp[$key])) {
            $tmp[$key] = [
                'owner' => $owner,
                'so_may' => $so_may,
                'loai' => $loai,
                'ten' => $ten,
                'total' => 0,
                'entered' => 0,
                'sort' => $loai_order[$loai] ?? 99,
            ];
        }
        $tmp[$key]['total']++;
        if (!empty($r['so_serial'])) {
            $tmp[$key]['entered']++;
        }
    }

    usort($tmp, function ($a, $b) {
        $c = strcmp($a['owner'], $b['owner']);
        if ($c !== 0)
            return $c;
        $c = $a['so_may'] <=> $b['so_may'];
        if ($c !== 0)
            return $c;
        $c = $a['sort'] <=> $b['sort'];
        if ($c !== 0)
            return $c;
        return strcmp($a['ten'], $b['ten']);
    });

    foreach ($tmp as $item) {
        $linhkien_groups[$item['owner']][$item['so_may']][] = $item;
    }
}

// ================== SỐ LIỆU TỔNG HỢP (thẻ thống kê) ==================
$stat_total_may = (int) ($donhang['so_luong_may'] ?? 0);
$stat_types = [];
$stat_total_slot = 0;
$stat_entered_slot = 0;
foreach ($linhkien_groups as $machines) {
    foreach ($machines as $items) {
        foreach ($items as $it) {
            $stat_types[$it['loai'] . '|' . $it['ten']] = true;
            $stat_total_slot += $it['total'];
            $stat_entered_slot += $it['entered'];
        }
    }
}
$stat_type_count = count($stat_types);
$stat_percent = $stat_total_slot > 0 ? round($stat_entered_slot / $stat_total_slot * 100, 1) : 0;

?>

<link rel="stylesheet" href="./css/danh_sach_don_hang.css">

<main class="main-content-order-status">
    <header class="status-header">
        <div class="header-left">
            <div class="header-icon-box">
                <i class="fa-solid fa-list-check"></i>
            </div>
            <div class="header-titles">
                <h1>Danh Sách Đơn Hàng</h1>
                <p>Quản lý và theo dõi thông tin đơn hàng</p>
            </div>
        </div>
        <div class="header-right">
            <div class="search-input-wrap">
                <input type="text" id="searchInput" placeholder="Tìm mã đơn, khách hàng...">
            </div>
            <a href="ke-toan-tao-don.php" class="btn-add-order">
                <i class="fa-solid fa-plus"></i> Tạo đơn hàng
            </a>
        </div>
    </header>

    <?php if ($msg === 'updated'): ?>
    <div class="alert-success">
        <i class="fa-solid fa-circle-check"></i> Đã cập nhật đơn hàng.
    </div>
    <?php endif; ?>

    <div class="status-table-card">
        <div class="card-title-bar">
            <i class="fa-regular fa-rectangle-list"></i>
            <span>Danh sách đơn hàng</span>
        </div>
        <table class="status-table" id="ordersTable">
            <thead>
                <tr>
                    <th class="col-id">Mã đơn hàng</th>
                    <th class="col-customer">Khách hàng</th>
                    <th class="col-total">Số lượng máy</th>
                    <th class="col-date">Ngày tạo</th>
                    <th class="col-actions">Thao tác</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php if (count($donhangs) === 0): ?>
                <tr>
                    <td colspan="5" style="text-align:center; padding:2rem; color:#94a3b8;">Chưa có đơn hàng nào.</td>
                </tr>
                <?php else: ?>
                <tr id="noResultRow" style="display:none;">
                    <td colspan="5" style="text-align:center; padding:2rem; color:#94a3b8;">Không tìm thấy đơn hàng nào
                        phù hợp.</td>
                </tr>
                <?php endif; ?>
                <?php foreach ($donhangs as $d):
                    $search_str = mb_strtolower($d['ma_don_hang'] . ' ' . ($d['ten_khach_hang'] ?? ''), 'UTF-8');
                ?>
                <tr class="order-row<?= ($donhang && (int) $donhang['id_donhang'] === (int) $d['id_donhang']) ? ' row-editing' : '' ?>"
                    data-search="<?= htmlspecialchars($search_str) ?>" data-id="<?= (int) $d['id_donhang'] ?>">
                    <td class="col-id">
                        <a class="order-code-link" href="?id=<?= (int) $d['id_donhang'] ?>#form-don-hang">
                            <?= htmlspecialchars($d['ma_don_hang']) ?>
                        </a>
                    </td>
                    <td class="col-customer"><?= htmlspecialchars($d['ten_khach_hang'] ?? '') ?></td>
                    <td class="col-total"><?= (int) $d['so_luong_may'] ?> Máy</td>
                    <td class="col-date"><?= htmlspecialchars($d['ngay_tao']) ?></td>
                    <td class="col-actions">
                        <div class="actions">
                            <a class="btn-icon-edit" href="?id=<?= (int) $d['id_donhang'] ?>#form-don-hang"
                                title="Sửa đơn hàng">
                                <i class="fa-regular fa-pen-to-square"></i>
                            </a>
                            <button type="button" class="btn-icon-delete" title="Xóa đơn hàng"
                                data-id="<?= (int) $d['id_donhang'] ?>"
                                data-code="<?= htmlspecialchars($d['ma_don_hang']) ?>">
                                <i class="fa-regular fa-trash-can"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (count($donhangs) > 0): ?>
        <div class="table-footer">
            <div class="pagination-info">
                Hiển thị <strong id="visibleCount">0</strong> trong <strong><?= count($donhangs) ?></strong> đơn hàng
            </div>
            <div class="pagination" id="paginationControls"></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="edit-card<?= $donhang ? '' : ' empty' ?>" id="form-don-hang">
        <?php if (!$donhang): ?>
        <div class="empty-state">
            <i class="fa-regular fa-hand-pointer"></i>
            Chọn một đơn hàng ở bảng trên để sửa.
        </div>
        <?php else: ?>
        <div class="card-title-bar">
            <i class="fa-regular fa-rectangle-list"></i>
            <span>Thông tin đơn hàng</span>
        </div>

        <div class="edit-card-grid">
            <form method="post" class="edit-form">
                <input type="hidden" name="form_action" value="save">
                <input type="hidden" name="id_donhang" value="<?= (int) $donhang['id_donhang'] ?>">

                <div class="form-row-4">
                    <div class="form-group">
                        <label for="ma_don_hang">Mã đơn hàng <span class="required">*</span></label>
                        <input type="text" id="ma_don_hang" name="ma_don_hang"
                            value="<?= htmlspecialchars($donhang['ma_don_hang']) ?>" required maxlength="50">
                    </div>
                    <div class="form-group">
                        <label for="ten_khach_hang">Tên khách hàng <span class="required">*</span></label>
                        <input type="text" id="ten_khach_hang" name="ten_khach_hang"
                            value="<?= htmlspecialchars($donhang['ten_khach_hang'] ?? '') ?>" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label for="so_luong_may">Số lượng máy <span class="required">*</span></label>
                        <input type="number" id="so_luong_may" name="so_luong_may"
                            value="<?= (int) $donhang['so_luong_may'] ?>" min="1" step="1">
                    </div>
                    <div class="form-group">
                        <label for="user_id">Mã người phụ trách (user_id)</label>
                        <input type="number" id="user_id" name="user_id" placeholder="Nhập mã người phụ trách"
                            value="<?= htmlspecialchars($donhang['user_id'] ?? '') ?>" min="1" step="1">
                    </div>
                </div>

                <div class="form-group">
                    <label for="ghi_chu">Ghi chú</label>
                    <textarea id="ghi_chu" name="ghi_chu" placeholder="Nhập ghi chú (nếu có)..."
                        rows="4"><?= htmlspecialchars($donhang['ghi_chu'] ?? '') ?></textarea>
                </div>

                <div class="form-actions">
                    <a href="danh_sach_don_hang.php" class="btn-cancel">Hủy bỏ</a>
                    <button type="submit" class="btn-create-order">
                        <i class="fa-solid fa-floppy-disk"></i> Lưu thay đổi
                    </button>
                </div>
            </form>

            <div class="edit-illustration">
                <i class="fa-regular fa-clipboard-check"></i>
                <h3>Quản lý đơn hàng dễ dàng</h3>
                <p>Lưu trữ, theo dõi và quản lý thông tin đơn hàng nhanh chóng.</p>
            </div>
        </div>

        <?php if (!empty($linhkien_groups)): ?>
        <div class="linhkien-editor">
            <div class="linhkien-editor-header">
                <div class="linhkien-editor-title">
                    <div class="card-title-bar">
                        <i class="fa-solid fa-microchip"></i>
                        <span>Số lượng linh kiện theo từng máy</span>
                    </div>
                    <p class="linhkien-hint">Cấu hình và số lượng linh kiện cho từng máy trong đơn hàng</p>
                </div>
                <div class="linhkien-actions">
                    <button type="button" class="btn-outline" id="btnCopyConfig">
                        <i class="fa-regular fa-copy"></i> Sao chép cấu hình
                    </button>
                    <form id="exportFormOrder" method="post" action="xuat-file.php">
                        <input type="hidden" name="id_donhang" value="<?= (int) $donhang['id_donhang'] ?>">
                        <input type="hidden" name="export_excel" value="1">
                        <button type="submit" class="btn-export">
                            <i class="fa-regular fa-file-excel"></i> Xuất Excel
                        </button>
                    </form>
                </div>
            </div>

            <?php $cauhinh_index = 0;
            foreach ($linhkien_groups as $owner => $machines): ?>
            <div class="cauhinh-group<?= $cauhinh_index === 0 ? ' open' : '' ?>">
                <button type="button" class="cauhinh-toggle">
                    <i class="fa-solid fa-chevron-right"></i>
                    <span><?= htmlspecialchars($owner) ?></span>
                </button>
                <div class="cauhinh-body">
                    <div class="may-grid">
                        <?php $may_index = 0;
                        foreach ($machines as $so_may => $items): ?>
                        <div class="may-card may-card-<?= $may_index % 2 === 0 ? 'blue' : 'green' ?>">
                            <div class="may-card-header">
                                <i class="fa-solid fa-desktop"></i> Máy <?= (int) $so_may ?>
                            </div>
                            <table class="linhkien-table">
                                <thead>
                                    <tr>
                                        <th>Loại</th>
                                        <th>Tên linh kiện</th>
                                        <th>Đã nhập Serial</th>
                                        <th>Số lượng</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $it): ?>
                                    <tr class="linhkien-row" data-id-donhang="<?= (int) $donhang['id_donhang'] ?>"
                                        data-so-may="<?= (int) $so_may ?>" data-owner="<?= htmlspecialchars($owner) ?>"
                                        data-loai="<?= htmlspecialchars($it['loai']) ?>"
                                        data-ten="<?= htmlspecialchars($it['ten']) ?>">
                                        <td><?= htmlspecialchars($it['loai']) ?></td>
                                        <td><?= htmlspecialchars($it['ten']) ?></td>
                                        <td class="entered-count"><?= (int) $it['entered'] ?>/<?= (int) $it['total'] ?>
                                        </td>
                                        <td>
                                            <div class="qty-stepper">
                                                <button type="button" class="qty-btn qty-minus">-</button>
                                                <input type="number" class="qty-input" min="0" step="1"
                                                    value="<?= (int) $it['total'] ?>">
                                                <button type="button" class="qty-btn qty-plus">+</button>
                                            </div>
                                        </td>
                                        <td><button type="button" class="btn-save-qty" title="Lưu"><i
                                                    class="fa-solid fa-floppy-disk"></i></button></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php $may_index++;
                        endforeach; ?>
                    </div>
                </div>
            </div>
            <?php $cauhinh_index++;
            endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($donhang): ?>
    <div class="stats-row">
        <div class="stat-tile stat-blue">
            <div class="stat-icon"><i class="fa-regular fa-rectangle-list"></i></div>
            <div class="stat-info">
                <span class="stat-label">Tổng số máy</span>
                <span class="stat-value"><?= $stat_total_may ?> <small>máy</small></span>
            </div>
        </div>
        <div class="stat-tile stat-green">
            <div class="stat-icon"><i class="fa-solid fa-microchip"></i></div>
            <div class="stat-info">
                <span class="stat-label">Tổng loại linh kiện</span>
                <span class="stat-value"><?= $stat_type_count ?> <small>loại</small></span>
            </div>
        </div>
        <div class="stat-tile stat-orange">
            <div class="stat-icon"><i class="fa-solid fa-bolt"></i></div>
            <div class="stat-info">
                <span class="stat-label">Tổng số linh kiện</span>
                <span class="stat-value"><?= $stat_total_slot ?> <small>món</small></span>
            </div>
        </div>
        <div class="stat-tile stat-purple">
            <div class="stat-icon"><i class="fa-solid fa-barcode"></i></div>
            <div class="stat-info">
                <span class="stat-label">Serial đã nhập</span>
                <span class="stat-value"><?= $stat_entered_slot ?>/<?= $stat_total_slot ?>
                    <small><?= $stat_percent ?>%</small></span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const rows = document.querySelectorAll('.order-row');
    const visibleCountEl = document.getElementById('visibleCount');
    const noResultRow = document.getElementById('noResultRow');
    const paginationControls = document.getElementById('paginationControls');

    const itemsPerPage = 10;
    let currentPage = 1;

    function filterTable() {
        const searchText = (searchInput ? searchInput.value : '').toLowerCase().trim();

        const filteredRows = [];
        rows.forEach(row => {
            const matchesSearch = row.dataset.search.includes(searchText);
            if (matchesSearch) {
                filteredRows.push(row);
            } else {
                row.style.display = 'none';
            }
        });

        const totalFiltered = filteredRows.length;
        const totalPages = Math.ceil(totalFiltered / itemsPerPage) || 1;

        if (currentPage > totalPages) currentPage = 1;

        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;

        filteredRows.forEach((row, index) => {
            row.style.display = (index >= startIndex && index < endIndex) ? '' : 'none';
        });

        if (visibleCountEl) visibleCountEl.textContent = totalFiltered;
        if (noResultRow) {
            noResultRow.style.display = (totalFiltered === 0 && rows.length > 0) ? '' : 'none';
        }

        renderPagination(totalPages);
    }

    function renderPagination(totalPages) {
        if (!paginationControls) return;
        paginationControls.innerHTML = '';
        if (totalPages <= 1) return;

        const goTo = (page) => {
            currentPage = Math.min(Math.max(1, page), totalPages);
            filterTable();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        };

        const prevBtn = document.createElement('div');
        prevBtn.className = 'page-btn page-nav';
        prevBtn.innerHTML = '<i class="fa-solid fa-chevron-left"></i>';
        prevBtn.addEventListener('click', () => goTo(currentPage - 1));
        paginationControls.appendChild(prevBtn);

        for (let i = 1; i <= totalPages; i++) {
            const btn = document.createElement('div');
            btn.className = `page-btn ${i === currentPage ? 'active' : ''}`;
            btn.textContent = i;
            btn.addEventListener('click', () => goTo(i));
            paginationControls.appendChild(btn);
        }

        const nextBtn = document.createElement('div');
        nextBtn.className = 'page-btn page-nav';
        nextBtn.innerHTML = '<i class="fa-solid fa-chevron-right"></i>';
        nextBtn.addEventListener('click', () => goTo(currentPage + 1));
        paginationControls.appendChild(nextBtn);
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            currentPage = 1;
            filterTable();
        });
    }

    filterTable();

    // ================== XÓA ĐƠN HÀNG ==================
    document.querySelectorAll('.btn-icon-delete').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            const code = btn.dataset.code;
            if (!confirm(
                    `Xác nhận xóa đơn hàng "${code}"? Hành động này không thể hoàn tác.`)) {
                return;
            }
            btn.disabled = true;
            fetch('xoa-nhieu-don-hang.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        ids: [id]
                    })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        window.location.href = 'danh_sach_don_hang.php';
                    } else {
                        btn.disabled = false;
                        alert(res.message || 'Có lỗi xảy ra khi xóa.');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    alert('Lỗi kết nối server.');
                });
        });
    });

    // ================== SAO CHÉP CẤU HÌNH ==================
    const btnCopyConfig = document.getElementById('btnCopyConfig');
    if (btnCopyConfig) {
        btnCopyConfig.addEventListener('click', () => {
            const lines = [];
            document.querySelectorAll('.cauhinh-group').forEach(group => {
                const name = group.querySelector('.cauhinh-toggle span').textContent.trim();
                lines.push(name + ':');
                group.querySelectorAll('.linhkien-row').forEach(row => {
                    const loai = row.dataset.loai;
                    const ten = row.dataset.ten;
                    const qty = row.querySelector('.qty-input').value;
                    lines.push(`  - ${loai} ${ten} x${qty}`);
                });
            });
            navigator.clipboard.writeText(lines.join('\n')).then(() => {
                const originalHtml = btnCopyConfig.innerHTML;
                btnCopyConfig.innerHTML = '<i class="fa-solid fa-check"></i> Đã sao chép';
                setTimeout(() => {
                    btnCopyConfig.innerHTML = originalHtml;
                }, 1500);
            }).catch(() => alert('Không thể sao chép vào clipboard.'));
        });
    }

    // ================== SỬA SỐ LƯỢNG LINH KIỆN ==================
    document.querySelectorAll('.cauhinh-toggle').forEach(toggle => {
        toggle.addEventListener('click', () => {
            toggle.closest('.cauhinh-group').classList.toggle('open');
        });
    });

    document.querySelectorAll('.qty-minus').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.parentElement.querySelector('.qty-input');
            input.value = Math.max(0, (parseInt(input.value, 10) || 0) - 1);
        });
    });

    document.querySelectorAll('.qty-plus').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = btn.parentElement.querySelector('.qty-input');
            input.value = (parseInt(input.value, 10) || 0) + 1;
        });
    });

    document.querySelectorAll('.btn-save-qty').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('.linhkien-row');
            const qtyInput = row.querySelector('.qty-input');
            const newQty = parseInt(qtyInput.value, 10);

            if (isNaN(newQty) || newQty < 0) {
                alert('Số lượng không hợp lệ.');
                return;
            }

            const payload = {
                id_donhang: row.dataset.idDonhang,
                so_may: row.dataset.soMay,
                owner: row.dataset.owner,
                loai_linhkien: row.dataset.loai,
                ten_linhkien: row.dataset.ten,
                so_luong_moi: newQty
            };

            btn.disabled = true;
            fetch('ajax-sua-so-luong-linhkien.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                })
                .then(r => r.json())
                .then(res => {
                    btn.disabled = false;
                    if (res.success) {
                        row.querySelector('.entered-count').textContent = res.entered +
                            '/' + res.total;
                        qtyInput.value = res.total;
                        if (res.deleted_with_serial > 0) {
                            alert('Đã tự động xoá ' + res.deleted_with_serial +
                                ' serial do giảm số lượng.');
                        }
                    } else {
                        alert(res.message || 'Có lỗi xảy ra.');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    alert('Lỗi kết nối server.');
                });
        });
    });
});
</script>

<!-- Close tags opened in thanh-dieu-huong.php -->
</div> <!-- .app-body -->
</div> <!-- .app-container -->
</body>

</html>