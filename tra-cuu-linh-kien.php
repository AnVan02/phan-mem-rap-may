<?php
require "config.php";
require "thanh-dieu-huong.php";

$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = [];

if ($search_query !== '') {
    // Thêm id_ct để sort IMEI rows theo thứ tự tạo (= thứ tự máy)
    $stmt = $pdo->prepare("
        SELECT c.id_ct, c.id_donhang, c.so_may, c.linhkien_chon, c.ten_cauhinh,
               d.ma_don_hang, d.ngay_tao, d.ten_khach_hang, c.ten_linhkien, c.loai_linhkien, c.so_serial
        FROM chitiet_donhang c
        JOIN donhang d ON c.id_donhang = d.id_donhang
        WHERE c.so_serial = :search OR d.ma_don_hang = :search
        ORDER BY c.id_ct ASC
    ");
    $stmt->execute(['search' => $search_query]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    function get_owner_config_name($ten_cauhinh) {
        $tc = (string)($ten_cauhinh ?? '');
        if (strpos($tc, ',') === false) return trim($tc);
        $trailing = strlen($tc) - strlen(rtrim($tc));
        $cfgs = array_map('trim', explode(',', $tc));
        return $cfgs[$trailing] ?? trim($cfgs[0] ?? '');
    }

    // Tách IMEI rows ra riêng, nhóm theo (id_donhang, config_lower), giữ thứ tự id_ct
    // Máy 1 = phần tử [0], máy 2 = phần tử [1], ... (giống kho-hang.php)
    $imei_pool = [];
    $regular_matches = [];
    foreach ($matches as $m) {
        $lt = strtoupper(trim($m['loai_linhkien'] ?? ''));
        if (in_array($lt, ['IMEI', 'IMER'])) {
            $cfg_l = mb_strtolower(trim($m['ten_cauhinh'] ?? ''), 'UTF-8');
            $imei_pool[$m['id_donhang']][$cfg_l][] = $m['so_serial'];
        } else {
            $regular_matches[] = $m;
        }
    }

    // Nhóm linh kiện thường theo (id_donhang + config + so_may)
    $grouped = [];
    foreach ($regular_matches as $match) {
        $row_cfg = !empty($match['linhkien_chon'])
            ? mb_strtolower(trim($match['linhkien_chon']), 'UTF-8')
            : mb_strtolower(get_owner_config_name($match['ten_cauhinh']), 'UTF-8');
        $key = $match['id_donhang'] . '_' . $row_cfg . '_' . $match['so_may'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = $match;
            $grouped[$key]['matched_components'] = [];
            $grouped[$key]['_row_cfg'] = $row_cfg;
        } elseif (empty($grouped[$key]['linhkien_chon']) && !empty($match['linhkien_chon'])) {
            $saved = $grouped[$key]['matched_components'];
            $saved_cfg = $grouped[$key]['_row_cfg'];
            $grouped[$key] = $match;
            $grouped[$key]['matched_components'] = $saved;
            $grouped[$key]['_row_cfg'] = $saved_cfg;
        }
        $grouped[$key]['matched_components'][] = $match['loai_linhkien'] . ' - ' . $match['ten_linhkien'];
    }

    foreach ($grouped as $key => $match) {
        $id_donhang = $match['id_donhang'];
        $so_may     = (int)($match['so_may'] ?? 0);
        $owner_config = get_owner_config_name($match['ten_cauhinh']);
        $row_cfg    = $match['_row_cfg'] ?? mb_strtolower($owner_config, 'UTF-8');

        $imei_val = '';

        if ($so_may > 0) {
            // Ưu tiên 1: từ imei_pool (đã có khi tìm theo mã đơn hàng)
            $pool = $imei_pool[$id_donhang][$row_cfg] ?? [];
            $candidate = trim((string)($pool[$so_may - 1] ?? ''));
            if ($candidate !== '') $imei_val = $candidate;

            // Ưu tiên 2: query DB theo thứ tự id_ct (không filter so_may vì có thể = NULL)
            if ($imei_val === '') {
                $stmt_imei = $pdo->prepare("
                    SELECT so_serial FROM chitiet_donhang
                    WHERE id_donhang = :id
                      AND LOWER(ten_cauhinh) = LOWER(:tc)
                      AND UPPER(loai_linhkien) IN ('IMEI', 'IMER')
                      AND so_serial IS NOT NULL AND so_serial != ''
                    ORDER BY id_ct ASC
                ");
                $stmt_imei->execute(['id' => $id_donhang, 'tc' => $owner_config]);
                $all_imei = $stmt_imei->fetchAll(PDO::FETCH_COLUMN);
                $candidate = trim((string)($all_imei[$so_may - 1] ?? ''));
                if ($candidate !== '') $imei_val = $candidate;
            }

            // Fallback: donhang.imei JSON
            if ($imei_val === '') {
                try {
                    $stmt_cfgs = $pdo->prepare("
                        SELECT ten_cauhinh, COUNT(*) as cnt
                        FROM chitiet_donhang
                        WHERE id_donhang = ? AND UPPER(loai_linhkien) IN ('IMEI', 'IMER')
                        GROUP BY ten_cauhinh ORDER BY MIN(id_ct) ASC
                    ");
                    $stmt_cfgs->execute([$id_donhang]);
                    $cfgs = $stmt_cfgs->fetchAll(PDO::FETCH_ASSOC);
                    $global_idx = 0;
                    foreach ($cfgs as $cfg) {
                        if (mb_strtolower(trim($cfg['ten_cauhinh'])) === mb_strtolower($owner_config)) {
                            $global_idx += $so_may;
                            break;
                        }
                        $global_idx += (int)$cfg['cnt'];
                    }
                    if ($global_idx > 0) {
                        $stmt_oi = $pdo->prepare("SELECT imei FROM donhang WHERE id_donhang = ?");
                        $stmt_oi->execute([$id_donhang]);
                        $imei_json = $stmt_oi->fetchColumn();
                        if ($imei_json) {
                            $order_imeis = json_decode($imei_json, true) ?? [];
                            $c2 = trim((string)($order_imeis[$global_idx - 1] ?? ''));
                            if ($c2 !== '' && !preg_match('/^S[ỐOÔ]\s*(IMEI|EMEI)/ui', $c2))
                                $imei_val = $c2;
                        }
                    }
                } catch (PDOException $e) {}
            }
        }

        $match['imei_may']    = $imei_val !== '' ? $imei_val : 'Chưa có';
        $match['owner_config'] = $owner_config;
        unset($match['_row_cfg']);
        $results[] = $match;
    }

    // Tìm kiếm theo IMEI: khi search term là serial IMEI/IMER, regular_matches rỗng
    // → cần xác định số máy từ vị trí của IMEI trong danh sách id_ct
    if (empty($results)) {
        foreach ($matches as $m) {
            $lt = strtoupper(trim($m['loai_linhkien'] ?? ''));
            if (!in_array($lt, ['IMEI', 'IMER'])) continue;

            $id_donhang   = $m['id_donhang'];
            $owner_config = get_owner_config_name($m['ten_cauhinh']);

            // Lấy tất cả id_ct của IMEI cùng đơn + cấu hình theo thứ tự tạo
            $stmt_pos = $pdo->prepare("
                SELECT id_ct FROM chitiet_donhang
                WHERE id_donhang = :id
                  AND LOWER(ten_cauhinh) = LOWER(:tc)
                  AND UPPER(loai_linhkien) IN ('IMEI', 'IMER')
                  AND so_serial IS NOT NULL AND so_serial != ''
                ORDER BY id_ct ASC
            ");
            $stmt_pos->execute(['id' => $id_donhang, 'tc' => $owner_config]);
            $all_id_cts = $stmt_pos->fetchAll(PDO::FETCH_COLUMN);

            $pos = array_search((string)$m['id_ct'], array_map('strval', $all_id_cts));
            if ($pos === false) continue;
            $so_may = $pos + 1;

            // Lấy thông tin đơn hàng
            $stmt_dh = $pdo->prepare("SELECT ma_don_hang, ngay_tao, ten_khach_hang FROM donhang WHERE id_donhang = ?");
            $stmt_dh->execute([$id_donhang]);
            $dh = $stmt_dh->fetch(PDO::FETCH_ASSOC);

            // Lấy linhkien_chon cho máy này (nếu đã phân bổ)
            $stmt_lk = $pdo->prepare("
                SELECT linhkien_chon FROM chitiet_donhang
                WHERE id_donhang = ? AND so_may = ? AND linhkien_chon IS NOT NULL AND linhkien_chon != ''
                LIMIT 1
            ");
            $stmt_lk->execute([$id_donhang, $so_may]);
            $lk_chon = $stmt_lk->fetchColumn() ?: $owner_config;

            $results[] = [
                'id_donhang'        => $id_donhang,
                'so_may'            => $so_may,
                'linhkien_chon'     => $lk_chon,
                'ten_cauhinh'       => $m['ten_cauhinh'],
                'ma_don_hang'       => $dh['ma_don_hang'] ?? '',
                'ngay_tao'          => $dh['ngay_tao'] ?? '',
                'ten_khach_hang'    => $dh['ten_khach_hang'] ?? '',
                'imei_may'          => $m['so_serial'],
                'owner_config'      => $owner_config,
                'matched_components'=> [$lt . ' - ' . ($m['ten_linhkien'] ?? '')],
            ];
        }
    }

    // Phân trang
    $items_per_page = 10;
    $total_items = count($results);
    $total_pages = ceil($total_items / $items_per_page);

    $current_page = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
    if ($current_page > $total_pages && $total_pages > 0)
        $current_page = $total_pages;

    $offset = ($current_page - 1) * $items_per_page;
    $paginated_results = array_slice($results, $offset, $items_per_page);
}

?>

<link rel="stylesheet" href="./css/tra-cuu-linh-kien.css">
<style>
    /* CSS Premium Design cho trang Tìm kiếm / Check Quality */
    :root {
        --bg-primary: #f8fafc;
        --bg-surface: #ffffff;
        --text-primary: #1e293b;
        --text-secondary: #64748b;
        --accent-color: #3b82f6;
        --accent-hover: #2563eb;
        --border-color: #e2e8f0;
        --success-color: #10b981;
        --radius-md: 12px;
        --radius-lg: 16px;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }

    body {
        background-color: var(--bg-primary);
        font-family: Montserrat;
        color: var(--text-primary);
        margin: 0;
    }

    .main-content-order {
        padding: 28px 32px;
        /* max-width: 1300px; */
        margin: 0 auto;
        animation: fadeIn 0.4s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Breadcrumb */l,
    .breadcrumb-nav {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 15px;
        margin-bottom: 24px;
        color: var(--text-secondary);
    }

    .breadcrumb-nav a {
        color: var(--text-primary);
        text-decoration: none;
        font-weight: 500;
        transition: color 0.2s;
    }

    .breadcrumb-nav a:hover {
        color: var(--accent-hover);
    }

    .bc-sep {
        font-size: 16px;
    }

    .bc-active {
        font-weight: 600;
        color: var(--accent-color);
    }
    .bc-active-p {
        display: flex;
        align-items: center;
        gap: 0.1rem;
        font-size: 17px;
        font-weight: 600;
        color: #1E40AF;
        margin-bottom: 1.8rem;
    }

    /* Search Box Container */
    .search-container {
        background: var(--bg-surface);
        padding: 40px 32px 36px;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
        margin-bottom: 32px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 24px;
    }

    .search-brand {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .search-logo {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: #EEF2FF;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .search-brand-text {
        font-size: 26px;
        font-weight: 800;
        color: var(--text-primary);
        letter-spacing: -0.5px;
    }

    .search-form {
        width: 100%;
        max-width: 740px;
    }

    .search-input-wrapper {
        display: flex;
        align-items: center;
        border: 2px solid var(--border-color);
        border-radius: 50px;
        background: #fff;
        padding: 0 8px 0 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.07);
        transition: all 0.3s ease;
        gap: 8px;
    }

    .search-input-wrapper:focus-within {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
    }

    .search-input-icon {
        color: #94a3b8;
        font-size: 15px;
        flex-shrink: 0;
    }

    .search-input {
        flex: 1;
        padding: 14px 4px;
        font-size: 15px;
        border: none;
        outline: none;
        background: transparent;
        color: var(--text-primary);
        min-width: 0;
    }

    .search-btn-inside {
        padding: 9px 22px;
        font-size: 14px;
        font-weight: 600;
        color: white;
        background: linear-gradient(135deg, #EE0000, #c00000);
        border: none;
        border-radius: 50px;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
        flex-shrink: 0;
    }

    .search-btn-inside:hover {
        opacity: 0.9;
        box-shadow: 0 4px 12px rgba(220, 0, 0, 0.35);
    }

    /* Results Section */
    .results-container {
        background: var(--bg-surface);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        overflow: hidden;
        max-width: 860px;
        margin: 0 auto;
    }

    .results-header {
        padding: 24px 32px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f8fafc;
    }

    .results-header h3 {
        margin: 0;
        font-size: 18px;
        color: var(--text-primary);
    }

    .results-count {
        background: #DCFCE7;
        color: #15803D;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 15px;
        font-weight: 600;
    }
    

    /* Cards Layout for Results */
    .results-grid {
        display: flex;
        flex-direction: column;
        gap: 16px;
        padding: 20px 24px;
        background: #f1f5f9;
    }
    .result-card {
        background: var(--bg-surface);
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-md);
        padding: 24px;
        border: 1px solid var(--border-color);
        transition: transform 0.2s, box-shadow 0.2s;
        display: flex;
        flex-direction: column;
        gap: 16px;
        cursor: pointer;
    }

    .result-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-lg);
        border-color: #cbd5e1;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px dashed var(--border-color);
        padding-bottom: 12px;
    }

    .order-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #eff6ff;
        color: var(--accent-hover);
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 14px;
        text-decoration: none;
        transition: background 0.2s;
    }

    .order-badge:hover {
        background: #dbeafe;
    }

    .date-badge {
        font-size: 13px;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .card-body {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .info-row {
        display: flex;
        align-items: center;
        gap: 13px;
    }

    .info-label {
        font-size: 14px;
        color: var(--text-secondary);
        font-weight: 500;
        width: 110px;
        flex-shrink: 0;
    }

    .info-value {
        font-size: 15px;
        color: var(--text-primary);
        font-weight: 600;
    }
    .imei-highlight {
        font-family: Montserrat;
        font-size: 15px;
        font-weight: 500;
        color: #2563EB;        /* xanh dương */
        background: #DBEAFE;   /* nền xanh nhạt */
        padding: 4px 10px;
        border-radius: 6px;
    }

    .no-results {
        padding: 64px 32px;
        text-align: center;
        color: var(--text-secondary);
    }

    .no-results i {
        font-size: 48px;
        margin-bottom: 16px;
        color: #cbd5e1;
    }

    .no-results p {
        font-size: 16px;
        margin: 0;
    }

    .btn-export-mini {
        background: #DCFCE7;
        color: #15803D;
        border: none;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: background 0.2s;
        
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 32px;
        padding-bottom: 16px;
    }

    .page-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        height: 36px;
        padding: 0 10px;
        border-radius: 8px;
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
    }

    .page-btn:hover:not(.disabled) {
        border-color: var(--accent-color);
        color: var(--accent-color);
    }

    .page-btn.active {
        background: var(--accent-color);
        color: white;
        border-color: var(--accent-color);
    }

    .page-btn.disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Responsive - Tablet */
    @media (max-width: 768px) {
        .main-content-order {
            padding: 12px 16px;
        }

        .search-container {
            padding: 24px 16px 20px;
            gap: 16px;
        }

        .search-brand-text {
            font-size: 20px;
        }

        .search-logo {
            width: 36px;
            height: 36px;
        }

        .search-logo i {
            font-size: 16px !important;
        }

        .search-form {
            max-width: 100%;
        }

        .search-input-wrapper {
            padding: 0 6px 0 14px;
        }

        .search-input {
            font-size: 14px;
            padding: 12px 4px;
        }

        .search-btn-inside {
            padding: 8px 14px;
            font-size: 13px;
        }

        .results-container {
            border-radius: 12px;
        }

        .results-header {
            padding: 16px 20px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .results-header h3 {
            font-size: 15px;
        }

        .results-grid {
            padding: 14px 16px;
            gap: 12px;
        }

        .result-card {
            padding: 16px;
            gap: 12px;
        }

        .info-label {
            width: 90px;
            font-size: 12px;
        }

        .info-value {
            font-size: 14px;
        }

        .imei-value {
            font-size: 14px;
        }

        .imei-highlight {
            font-size: 13px;
            padding: 3px 8px;
        }

        .breadcrumb-nav {
            font-size: 12px;
            margin-bottom: 14px;
        }
    }

    /* Responsive - Mobile nhỏ */
    @media (max-width: 480px) {
        .main-content-order {
            padding: 10px 12px;
        }

        .search-container {
            padding: 20px 14px 18px;
            gap: 14px;
            border-radius: 12px;
        }

        .search-brand {
            gap: 8px;
        }

        .search-brand-text {
            font-size: 17px;
        }

        .search-input-wrapper {
            padding: 0 5px 0 12px;
            gap: 6px;
        }

        .search-input {
            font-size: 13px;
            padding: 11px 2px;
        }

        .search-input-icon {
            font-size: 13px;
        }

        .search-btn-inside {
            padding: 7px 12px;
            font-size: 12px;
        }

        .results-header {
            padding: 12px 16px;
        }

        .results-grid {
            padding: 10px 12px;
            gap: 10px;
        }

        .result-card {
            padding: 14px 12px;
        }

        .card-body {
            gap: 10px;
        }

        .info-row {
            flex-wrap: wrap;
            gap: 4px;
        }

        .info-label {
            width: 100%;
            font-size: 11px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 0;
        }

        .info-value {
            font-size: 14px;
        }

        .imei-value {
            font-size: 15px;
        }

        .card-footer {
            padding-top: 10px !important;
        }

        .btn-export-mini {
            width: 100%;
            justify-content: center;
            padding: 10px;
        }

        .pagination {
            gap: 5px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .page-btn {
            min-width: 32px;
            height: 32px;
            font-size: 13px;
        }
    }

    .machine-imei-box {
        display: flex;
        flex-direction: column;
        padding: 4px 0;
        transition: all 0.3s ease;
    }
    .machine-imei-box.has-imei {
        background: transparent;
        border: none;
    }
    .machine-imei-box.no-imei {
        background: transparent;
        border: none;
        opacity: 0.5;
    }
    .imei-value {
        font-size: 16px;
        font-weight: 800;
        color: #ef4444;
        font-family: Montserrat;
    }
</style>

<main class="main-content-order">
    <nav class="breadcrumb-nav">
        <a href="dashboard-ky-thuat.php">Trang Chủ</a>
        <span class="bc-sep">›</span>
        <span class="bc-active">TRA CỨU LINH KIỆN</span> <br>
    </nav>
        <p class="bc-active-p">Hệ thống hỗ trợ tìm kiếm đơn hàng trực tiếp qua số IMEI hoặc Serial sản phẩm.</p>


    <div class="search-container">
        <div class="search-brand">
            <div class="search-logo">
                <i class="fa-solid fa-magnifying-glass" style="color:#1152D4; font-size:22px;"></i>
            </div>
            <span class="search-brand-text">Tra Cứu Máy Bằng Serial <span style="color:#EE0000;">Linh Kiện / IMEI</span>
        </div>
        <form method="get" action="tra-cuu-linh-kien.php" class="search-form">
            <div class="search-input-wrapper">
                <i class="fa-solid fa-magnifying-glass search-input-icon"></i>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>"
                    placeholder="Nhập IMEI, Serial linh kiện hoặc mã đơn hàng..." class="search-input" required
                    autocomplete="off" autofocus>
                <button type="submit" class="search-btn-inside">Tìm kiếm</button>
            </div>
        </form>
    </div>

    <?php if ($search_query !== ''): ?>
        <div class="results-container">
            <div class="results-header">
                <h3>Kết quả tìm kiếm cho: <strong style="color:#CC0000">"<?php echo htmlspecialchars($search_query); ?>"</strong></h3>
                <span class="results-count"><?php echo count($results); ?> kết quả</span>
            </div>

            <?php if (count($results) > 0): ?>
                <div class="results-grid">
                    <?php foreach ($paginated_results as $row): ?>
                        <?php
                            $r_id = $row['id_donhang'];
                            $r_cfg = addslashes($row['owner_config']);
                            $r_may = (int)($row['so_may'] ?? 0);
                            $imei_display = ($row['imei_may'] !== 'Chưa có') ? $row['imei_may'] : '';
                        ?>
                        <div class="result-card" onclick="enterMachine(<?php echo $r_id; ?>, '<?php echo $r_cfg; ?>', <?php echo $r_may; ?>)">
                            <!-- <div class="card-header">
                                <a href="nhap-serial.php?id=<?php echo $r_id; ?>" class="order-badge"
                                    onclick="event.stopPropagation()">
                                    <i class="fa-solid fa-file-invoice"></i>
                                    <?php echo htmlspecialchars($row['ma_don_hang']); ?>
                                </a>
                                <span class="date-badge">
                                    <i class="fa-regular fa-clock"></i>
                                    <?php echo date('d/m/Y', strtotime($row['ngay_tao'])); ?>
                                </span>
                            </div> -->
                            <div class="card-body">
                                <div class="info-row">
                                    <span class="info-label">Tên đơn hàng</span>
                                    <span class="info-value"><?php echo htmlspecialchars($row['ten_khach_hang'] ?? '---'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Tên cấu hình</span>
                                    <span class="info-value"><?php echo htmlspecialchars($row['owner_config'] ?: 'Chưa phân bổ'); ?></span>
                                </div>
                                <!-- <div class="info-row">
                                    <span class="info-label">Số máy</span>
                                    <span class="info-value">Máy <?php echo htmlspecialchars($row['so_may'] ?? '-'); ?></span>
                                </div> -->
                                <div class="info-row">
                                    <span class="info-label">Số IMEI</span>
                                    <div class="machine-imei-box <?php echo $imei_display !== '' ? 'has-imei' : 'no-imei'; ?>">
                                        <span class="imei-value"><?php echo $imei_display !== '' ? htmlspecialchars($imei_display) : '---'; ?></span>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Ngày tạo </span>
                                    <span class="imei-highlight">
                                        <i class="fa-regular fa-clock" style="margin-right: 4px; font-size: 13px;"></i>
                                       <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($row['ngay_tao']))); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="card-footer" style="display:flex; justify-content:center; padding-top:12px; border-top:1px dashed var(--border-color); margin-top:4px;">
                                <button class="btn-export-mini" onclick="event.stopPropagation(); enterMachine(<?php echo $r_id; ?>, '<?php echo $r_cfg; ?>', <?php echo $r_may; ?>)">
                                    Xem Chi Tiết Linh Kiện
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($current_page > 1): ?>
                            <a href="?q=<?php echo urlencode($search_query); ?>&p=<?php echo $current_page - 1; ?>" class="page-btn"
                                title="Trang trước"><i class="fa-solid fa-chevron-left"></i></a>
                        <?php else: ?>
                            <span class="page-btn disabled"><i class="fa-solid fa-chevron-left"></i></span>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?q=<?php echo urlencode($search_query); ?>&p=<?php echo $i; ?>"
                                class="page-btn <?php echo $i === $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a href="?q=<?php echo urlencode($search_query); ?>&p=<?php echo $current_page + 1; ?>" class="page-btn"
                                title="Trang sau"><i class="fa-solid fa-chevron-right"></i></a>
                        <?php else: ?>
                            <span class="page-btn disabled"><i class="fa-solid fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="no-results">
                    <i class="fa-solid fa-box-open"></i>
                    <p>Không tìm thấy máy nào chứa mã Serial / IMEI
                        "<strong><?php echo htmlspecialchars($search_query); ?></strong>".</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<script>
function enterMachine(orderId, configName, machineIdx) {
    if (!machineIdx) {
        // Máy chưa được gán → vào kho-hang để xem tổng quan
        window.location.href = 'kho-import-serial.php?id=' + orderId;
        return;
    }
    const formData = new FormData();
    formData.append('action', 'lock');
    formData.append('order_id', orderId);
    formData.append('config_name', configName);
    formData.append('machine_idx', machineIdx);

    fetch('ajax-handle-lock.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = `kho-import-serial.php?id=${orderId}&config=${encodeURIComponent(configName)}&m=${machineIdx}`;
            } else {
                alert(data.message || 'Không thể vào máy này.');
            }
        })
        .catch(() => alert('Lỗi kết nối server.'));
}
</script>

</body>

</html>