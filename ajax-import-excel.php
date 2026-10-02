    <?php
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ob_start();

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false,
                'message' => 'Lỗi PHP: ' . $err['message'] . ' (dòng ' . $err['line'] . ')'],
                JSON_UNESCAPED_UNICODE);
        }
    });

    session_start();
    require "config.php";
    header('Content-Type: application/json; charset=utf-8');

    @ini_set('memory_limit', '-1');
    @set_time_limit(0);

    function jimport_exit(array $data): void {
        if (ob_get_level()) ob_end_clean();
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_SESSION['user_id'])) jimport_exit(['success' => false, 'message' => 'Chưa đăng nhập']);
    $session_user_id = (int)$_SESSION['user_id'];

    $order_id = (int)($_POST['order_id'] ?? 0);
    if ($order_id <= 0) jimport_exit(['success' => false, 'message' => 'Thiếu ID đơn hàng']);

    // File không bắt buộc khi có cache — kiểm tra ở phía sau
    if (!file_exists('vendor/autoload.php'))
        jimport_exit(['success' => false, 'message' => 'Thiếu thư viện PhpSpreadsheet']);
    require_once 'vendor/autoload.php';
    use PhpOffice\PhpSpreadsheet\IOFactory;

    function readTxtOrCsvFile($filePath) {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("Không thể đọc nội dung file.");
        }
        
        // Kiểm tra và chuyển đổi bảng mã UTF-16 (Excel hay dùng khi xuất Unicode Text)
        if (substr($content, 0, 2) === "\xFF\xFE") {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (substr($content, 0, 2) === "\xFE\xFF") {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        } elseif (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3); // Bỏ UTF-8 BOM
        }
        
        // Tách các dòng (hỗ trợ cả \r\n, \r, \n)
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        // Tự động nhận diện dấu phân cách bằng cách đếm số lần xuất hiện ở 10 dòng đầu
        $delimiter = "\t"; // Mặc định là Tab
        $possibleDelimiters = ["\t", ",", ";"];
        $counts = ["\t" => 0, "," => 0, ";" => 0];
        
        $sampleLines = array_slice($lines, 0, 10);
        foreach ($sampleLines as $line) {
            foreach ($possibleDelimiters as $delim) {
                $counts[$delim] += substr_count($line, $delim);
            }
        }
        
        arsort($counts);
        $detected = array_key_first($counts);
        if ($counts[$detected] > 0) {
            $delimiter = $detected;
        }
        
        $rows = [];
        $maxCols = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            
            // str_getcsv giúp handle cả các trường có dấu nháy bao bọc
            $cells = str_getcsv($line, $delimiter);
            
            $cells = array_map(function($val) {
                return $val !== null ? trim($val) : '';
            }, $cells);
            
            $rows[] = $cells;
            if (count($cells) > $maxCols) {
                $maxCols = count($cells);
            }
        }
        
        // Điền thêm các cột trống để đảm bảo các dòng đều có độ dài bằng cột dài nhất
        foreach ($rows as &$row) {
            if (count($row) < $maxCols) {
                $row = array_pad($row, $maxCols, '');
            }
        }
        
        return $rows;
    }

    // -------------------------------------------------------
    // ĐỌC FILE EXCEL (giống ajax-check-import-excel.php)
    // Ưu tiên: đọc từ cache file (do ajax-check-import-excel.php lưu lại)
    //           không cần upload & parse Excel lần 2 nỳa!
    // -------------------------------------------------------
    $machineBlocks = [];
    $_cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'excel_import_' . session_id() . '_' . $order_id . '.json';
    $_usedCache = false;

    if (file_exists($_cacheFile) && (time() - filemtime($_cacheFile)) < 1800) {
        // Cache còn hần (< 30 phút) — dùng luôn, bỏ qua phần đọc Excel
        $machineBlocks = json_decode(file_get_contents($_cacheFile), true) ?? [];
        @unlink($_cacheFile); // Xoá cache sau khi dùng (one-time use)
        $_usedCache = true;
    }

    if (!$_usedCache) {
        // Fallback: không có cache — đọc lại từ file Excel hoặc TXT/CSV
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK)
            jimport_exit(['success' => false, 'message' => 'Không có file hoặc lỗi khi tải lên (và không tìm thấy cache). Vui lòng thử lại.']);

        $fileName = $_FILES['excel_file']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext === 'txt' || $ext === 'csv') {
            try {
                $allRows = readTxtOrCsvFile($_FILES['excel_file']['tmp_name']);
            } catch (Throwable $e) {
                jimport_exit(['success' => false, 'message' => 'Không đọc được file TXT/CSV: ' . $e->getMessage()]);
            }
        } else {
            try {
                $reader = IOFactory::createReaderForFile($_FILES['excel_file']['tmp_name']);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($_FILES['excel_file']['tmp_name']);
                $sheet       = $spreadsheet->getActiveSheet();
                
                $highestRow = $sheet->getHighestDataRow();
                $highestColumn = $sheet->getHighestDataColumn();
                $allRows = $sheet->rangeToArray('A1:' . $highestColumn . $highestRow, null, false, false, false);
            } catch (Throwable $e) {
                jimport_exit(['success' => false, 'message' => 'Không đọc được file Excel: ' . $e->getMessage()]);
            }
        }

        array_walk_recursive($allRows, function (&$v) {
            if (is_float($v) && $v == (int)$v && abs($v) < 9.0e15) {
                $v = (string)(int)$v;
            } elseif (is_int($v)) {
                $v = (string)$v;
            }
        });

        if (empty($allRows)) jimport_exit(['success' => false, 'message' => 'File rỗng']);

        // parse machineBlocks từ $allRows (giống ajax-check-import-excel.php)
        foreach ($allRows as $rIdx => $row) {
            foreach ($row as $cIdx => $cellVal) {
                if (preg_match('/^máy\s*(\d+)$/ui', trim((string)($cellVal ?? '')), $m)) {
                    $machineBlocks[] = [
                        'col'      => $cIdx,
                        'row'      => $rIdx,
                        'so_may'   => (int)$m[1],
                        'cfg_name' => trim((string)($row[$cIdx + 1] ?? '')),
                        'imei'     => ltrim(trim((string)($row[$cIdx + 2] ?? '')), " \t"),
                        'items'    => [],
                    ];
                }
            }
        }
        if (empty($machineBlocks))
            jimport_exit(['success' => false, 'message' => 'Không tìm thấy dòng "Máy X" trong file']);

        $blocksByCol = [];
        foreach ($machineBlocks as $bIdx => $b) $blocksByCol[$b['col']][] = $bIdx;
        foreach ($blocksByCol as $col => $bIdxList) {
            usort($bIdxList, fn($a, $b) => $machineBlocks[$a]['row'] <=> $machineBlocks[$b]['row']);
            for ($bi = 0; $bi < count($bIdxList); $bi++) {
                $bIdx  = $bIdxList[$bi];
                $block = &$machineBlocks[$bIdx];
                $startRow = $block['row'] + 2;
                $endRow   = ($bi + 1 < count($bIdxList)) ? $machineBlocks[$bIdxList[$bi + 1]]['row'] : count($allRows);
                $lastType = $lastModel = '';
                for ($r = $startRow; $r < $endRow; $r++) {
                    $row = $allRows[$r] ?? [];
                    $typeCell   = trim((string)($row[$col]     ?? ''));
                    $modelCell  = trim((string)($row[$col + 1] ?? ''));
                    $serialCell = trim((string)($row[$col + 2] ?? ''));
                    
                    if ($typeCell === '' && $modelCell === '' && $serialCell === '') {
                        break;
                    }
                    if ($typeCell !== '' && $typeCell !== $lastType) $lastModel = '';
                    if ($typeCell  !== '') $lastType  = $typeCell;
                    if ($modelCell !== '') $lastModel = $modelCell;
                    $type = $lastType;
                    if ($type === '' || mb_strtolower($type, 'UTF-8') === 'thành phần') continue;
                    $hasData = false;
                    foreach ($row as $v) { if (trim((string)($v ?? '')) !== '') { $hasData = true; break; } }
                    if (!$hasData) { $lastType = $lastModel = ''; break; }
                    $block['items'][] = ['type' => $type, 'model' => $lastModel, 'model_fresh' => ($modelCell !== ''), 'serial' => $serialCell];
                }
                unset($block);
            }
        }
    }

    // -------------------------------------------------------
    // LẤY DỮ LIỆU DB (IMEI)
    // -------------------------------------------------------
    $orderImeis = [];
    try {
        $s = $pdo->prepare("SELECT imei FROM donhang WHERE id_donhang = ?");
        $s->execute([$order_id]);
        $raw = $s->fetchColumn();
        if ($raw) {
            $dec = json_decode($raw, true);
            if (is_array($dec)) $orderImeis = array_map(fn($v) => mb_strtolower(trim((string)$v), 'UTF-8'), $dec);
        }
    } catch (PDOException $e) {}

    // IMEI từ chitiet_donhang — theo so_may VÀ toàn đơn hàng (fallback)
    $dbImeiRows = []; // so_may → [serial_lower, ...]
    $allOrderImeiSerials = []; // tất cả IMEI/IMER của đơn, không phân biệt so_may
    try {
        $s2 = $pdo->prepare("SELECT so_may, so_serial FROM chitiet_donhang WHERE id_donhang = ? AND UPPER(loai_linhkien) IN ('IMEI','IMER') AND so_serial IS NOT NULL AND so_serial <> ''");
        $s2->execute([$order_id]);
        foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sl = mb_strtolower(trim($r['so_serial']), 'UTF-8');
            $dbImeiRows[(int)$r['so_may']][] = $sl;
            $allOrderImeiSerials[] = $sl;
        }
    } catch (PDOException $e) {}

    // -------------------------------------------------------
    // MAP TÊN HIỂN THỊ → LOẠI DB
    // -------------------------------------------------------
    function getTypeKeywordsForImport(string $displayType): array {
        $map = [
            'cpu'          => ['cpu'],
            'mainboard'    => ['main', 'mainboard'],
            'main'         => ['main', 'mainboard'],
            'ram'          => ['ram'],
            'ssd'          => ['ssd'],
            'hdd'          => ['hdd'],
            'ổ cứng'       => ['hdd'],
            'hard disk'    => ['hdd'],
            'harddisk'     => ['hdd'],
            'đồ họa'       => ['vga'],
            'vga'          => ['vga'],
            'nguồn'        => ['psu'],
            'psu'          => ['psu'],
            'case'         => ['case'],
            'tản'          => ['fan'],
            'fan'          => ['fan'],
            'hệ điều hành' => ['win', 'windows'],
            'phần mềm'     => ['win', 'software'],
            'win'          => ['win'],
            'windows'      => ['win'],
            'key board'    => ['key'],
            'mouse'        => ['mouse'],
            'lcd'          => ['lcd'],
        ];
        $key = mb_strtolower(trim($displayType), 'UTF-8');
        return $map[$key] ?? [$key];
    }
    function isSerialRequired(string $type): bool {
        $type = mb_strtolower(trim($type), 'UTF-8');
        $keywords = getTypeKeywordsForImport($type);
        $noSerialTypes = ['case', 'vỏ case', 'vo case', 'fan', 'tản', 'nguồn', 'psu', 'hệ điều hành', 'phần mềm', 'win', 'windows'];

        foreach ($keywords as $kw) {
            if (in_array($kw, $noSerialTypes, true)) {
                return false;
            }
        }

        return true;
    }
    function isOsType(string $type): bool {
        $type = mb_strtolower(trim($type), 'UTF-8');
        return in_array($type, ['hệ điều hành', 'phần mềm', 'win', 'windows'], true);
    }

    // Số lượng "chuẩn" của 1 linh kiện (theo từ khoá loại + tên model + cấu hình) trên mỗi máy,
    // suy ra từ các máy đã có dữ liệu (đa số/mode). Trả về 0 nếu chưa đủ dữ liệu tham chiếu
    // (< 2 máy đã gán) -> không giới hạn. Dùng để tránh 1 máy "hút" nhầm slot của máy khác khi
    // file Excel bị dư dòng (double-scan, copy nhầm...).
    function get_expected_qty_excel($pdo, $order_id, $ph, $kw_likes, $modelName, $owner, &$cache)
    {
        $cache_key = $ph . '|' . implode(',', $kw_likes) . '|' . mb_strtolower($modelName, 'UTF-8') . '|' . $owner;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }
        $sql = "SELECT ten_cauhinh, so_may FROM chitiet_donhang
                WHERE id_donhang = ? AND ($ph) AND LOWER(TRIM(ten_linhkien)) = LOWER(TRIM(?)) AND so_may > 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$order_id], $kw_likes, [$modelName]));
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t_owner = rtrim($r['ten_cauhinh'], ' ');
            $s_owner = strlen($r['ten_cauhinh']) - strlen($t_owner);
            $p_owner = array_map('trim', explode(',', $t_owner));
            $owner_name = mb_strtolower($p_owner[$s_owner] ?? $p_owner[0], 'UTF-8');
            if ($owner_name !== $owner)
                continue;
            $m = (int) $r['so_may'];
            $counts[$m] = ($counts[$m] ?? 0) + 1;
        }
        if (count($counts) < 2) {
            $cache[$cache_key] = 0;
            return 0;
        }
        $freq = array_count_values($counts);
        arsort($freq);
        $cache[$cache_key] = (int) array_key_first($freq);
        return $cache[$cache_key];
    }

    // Hàm tìm và cập nhật serial cho 1 linh kiện
    function importOneSerial(PDO $pdo, int $order_id, int $so_may, string $cfgNorm,
                            string $displayType, string $modelName, string $serial): string
    {
        $keywords = getTypeKeywordsForImport($displayType);
        $kw_likes = array_map(fn($k) => '%' . $k . '%', $keywords);
        $placeholders = implode(' OR ', array_fill(0, count($keywords), 'LOWER(loai_linhkien) LIKE ?'));

        // Chiến lược 1: khớp so_may + ten_linhkien
        $sql = "SELECT id_ct FROM chitiet_donhang
                WHERE id_donhang = ? AND so_may = ?  AND ($placeholders) AND ten_linhkien = ?
                ORDER BY id_ct ASC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$order_id, $so_may], $kw_likes, [$modelName]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Chiến lược 2: khớp so_may, không cần ten_linhkien
        if (!$row) {
            $sql2 = "SELECT id_ct FROM chitiet_donhang
                    WHERE id_donhang = ? AND so_may = ? AND ($placeholders)
                    ORDER BY id_ct ASC LIMIT 1";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute(array_merge([$order_id, $so_may], $kw_likes));
            $row = $stmt2->fetch(PDO::FETCH_ASSOC);
        }

        // Chiến lược 3: hàng chưa gán (so_may NULL/0), khớp ten_linhkien
        if (!$row) {
            $sql3 = "SELECT id_ct FROM chitiet_donhang
                    WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($placeholders) AND ten_linhkien = ?
                    ORDER BY id_ct ASC LIMIT 1";
            $stmt3 = $pdo->prepare($sql3);
            $stmt3->execute(array_merge([$order_id], $kw_likes, [$modelName]));
            $row = $stmt3->fetch(PDO::FETCH_ASSOC);
        }

        // Chiến lược 4: hàng chưa gán, không cần ten_linhkien
        if (!$row) {
            $sql4 = "SELECT id_ct FROM chitiet_donhang
                    WHERE id_donhang = ? AND (so_may IS NULL OR so_may = 0) AND ($placeholders)
                    ORDER BY id_ct ASC LIMIT 1";
            $stmt4 = $pdo->prepare($sql4);
            $stmt4->execute(array_merge([$order_id], $kw_likes));
            $row = $stmt4->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) return 'not_found';

        // Cập nhật serial + so_may + linhkien_chon (giống luu-serial-db.php)
        $upd = $pdo->prepare("UPDATE chitiet_donhang
                            SET so_serial = ?, so_may = ?, linhkien_chon = ?, user_id = NULL, user_id_save = NULL
                            WHERE id_ct = ?");
        $upd->execute([$serial, $so_may, $cfgNorm, $row['id_ct']]);
        return 'ok';
    }

    // -------------------------------------------------------
    // THỰC HIỆN NHẬP
    // -------------------------------------------------------
    $results       = [];
    $totalImported = 0;
    $totalSkipped  = 0;
    $totalNotFound = 0;

    // Nhóm theo cấu hình và số máy
    $byMachine = [];
    foreach ($machineBlocks as $b) {
        $mayKey = mb_strtolower(trim($b['cfg_name']), 'UTF-8') . '_' . $b['so_may'];
        if (!isset($byMachine[$mayKey])) {
            $byMachine[$mayKey] = [
                'so_may'   => $b['so_may'], 
                'cfg_name' => $b['cfg_name'],
                'imei'     => $b['imei'], 
                'items'    => []
            ];
        }
        foreach ($b['items'] as $it) $byMachine[$mayKey]['items'][] = $it;
    }

    // -------------------------------------------------------
    // KIỂM TRA: tên linh kiện trong file Excel phải hợp lệ
    // -------------------------------------------------------
    $validImportTypes = [
        'cpu', 'mainboard', 'main', 'ram', 'ssd', 'hdd', 'ổ cứng', 'hard disk', 'harddisk',
        'đồ họa', 'vga', 'nguồn', 'psu', 'case',
        'tản', 'fan', 'hệ điều hành', 'phần mềm',
        'win', 'windows', 'key board', 'mouse', 'lcd',
    ];

    foreach ($byMachine as $machine) {
        foreach ($machine['items'] as $it) {
            $typeKey = mb_strtolower(trim($it['type']), 'UTF-8');
            if (!in_array($typeKey, $validImportTypes, true)) {
                jimport_exit(['success' => false,
                    'message' => "❌ Tên linh kiện \"" . $it['type'] . "\" tại Máy {$machine['so_may']} ({$machine['cfg_name']}) không được nhận dạng. Vui lòng kiểm tra lại tên trong cột \"Thành Phần\" của file Excel.\nCác tên hợp lệ: CPU, Mainboard, Main, RAM, SSD, HDD, Đồ họa, VGA, Nguồn, PSU, Case, Tản, Fan, Hệ điều hành, Phần mềm, Win, Windows, Key Board, Mouse, LCD."
                ]);
            }
        }
    }

    // -------------------------------------------------------
    // KIỂM TRA: serial trong file Excel phải khớp với DB
    // -------------------------------------------------------

    // Tải toàn bộ serial đã nhập cho đơn hàng này (từ nhap-serial.php), theo TỪNG máy + TỪNG loại linh kiện
    // (không dùng 1 tập phẳng chung cho cả đơn — nếu không, serial của CPU máy A có thể trùng ngẫu nhiên
    // với serial của RAM máy B và làm "khớp giả" dù thực chất sai linh kiện/sai máy)
    $dbSerialsByMachine = []; // [so_may][loai_linhkien_lower] => [ ['serial'=>.., 'cauhinh'=>..], ... ]  (so_may=0 = chưa gán máy)
    try {
        $stAll = $pdo->prepare(
            "SELECT so_may, LOWER(TRIM(loai_linhkien)) as loai, LOWER(TRIM(so_serial)) as serial,
                    LOWER(TRIM(ten_cauhinh)) as cauhinh
            FROM chitiet_donhang
            WHERE id_donhang = ? AND so_serial IS NOT NULL AND so_serial <> ''"
        );
        $stAll->execute([$order_id]);
        foreach ($stAll->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dbSerialsByMachine[(int)($r['so_may'] ?? 0)][$r['loai']][] = ['serial' => $r['serial'], 'cauhinh' => $r['cauhinh']];
        }
    } catch (PDOException $e) {
        jimport_exit(['success' => false, 'message' => 'Lỗi tải dữ liệu: ' . $e->getMessage()]);
    }

    // $cfgName: tên cấu hình (từ Excel) — bắt buộc lọc theo đúng cấu hình, tránh 2 máy khác cấu hình
    // (nên khác linh kiện) nhưng cùng đang ở pool "chưa gán máy" (so_may=0) bị lẫn serial của nhau.
    function getDbSerialsForImport(array $dbSerialsByMachine, int $so_may, string $displayType, string $cfgName): array {
        $keywords = getTypeKeywordsForImport($displayType);
        $cfgNorm  = mb_strtolower(trim($cfgName), 'UTF-8');
        $result   = [];
        // Ưu tiên serial đã gán đúng máy này
        foreach ($keywords as $kw) {
            foreach ($dbSerialsByMachine[$so_may] ?? [] as $dbLoai => $entries) {
                if (!str_contains($dbLoai, $kw)) continue;
                foreach ($entries as $e) {
                    if (str_contains($e['cauhinh'], $cfgNorm)) $result[] = $e['serial'];
                }
            }
        }
        // Fallback: serial cùng loại linh kiện + đúng cấu hình nhưng chưa gán máy (so_may = 0/NULL)
        foreach ($keywords as $kw) {
            foreach ($dbSerialsByMachine[0] ?? [] as $dbLoai => $entries) {
                if (!str_contains($dbLoai, $kw)) continue;
                foreach ($entries as $e) {
                    if (str_contains($e['cauhinh'], $cfgNorm)) $result[] = $e['serial'];
                }
            }
        }
        return $result;
    }

    // Tải tên linh kiện (model) từ DB để kiểm tra khớp với Excel
    $dbTenLinhKien = []; // loai_lower => [ten_lower => true]
    try {
        $sTen = $pdo->prepare(
            "SELECT LOWER(TRIM(loai_linhkien)) as loai, LOWER(TRIM(ten_linhkien)) as ten
            FROM chitiet_donhang WHERE id_donhang = ?
            AND ten_linhkien IS NOT NULL AND ten_linhkien <> ''"
        );
        $sTen->execute([$order_id]);
        foreach ($sTen->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dbTenLinhKien[$r['loai']][$r['ten']] = true;
        }
    } catch (PDOException $e) {
        jimport_exit(['success' => false, 'message' => 'Lỗi tải dữ liệu: ' . $e->getMessage()]);
    }

    // Kiểm tra tên model linh kiện trong Excel phải khớp với DB của đơn hàng
    foreach ($byMachine as $machine) {
        foreach ($machine['items'] as $it) {
            $keywords  = getTypeKeywordsForImport($it['type']);
            $modelName = trim($it['model']);

            // Hàm kiểm tra loại linh kiện có tên model trong DB không
            $typeHasModels = false;
            foreach ($keywords as $kw) {
                foreach ($dbTenLinhKien as $dbLoai => $tenMap) {
                    if (str_contains($dbLoai, $kw) && !empty($tenMap)) {
                        $typeHasModels = true;
                        break 2;
                    }
                }
            }

            if ($modelName === '') {
                // Excel không có tên model — báo lỗi nếu DB yêu cầu model
                if ($typeHasModels) {
                    jimport_exit(['success' => false,
                        'message' => "❌ Thiếu tên linh kiện ({$it['type']}) tại Máy {$machine['so_may']} ({$machine['cfg_name']}). Vui lòng điền tên model trong file Excel."
                    ]);
                }
                continue;
            }

            // Model có giá trị — kiểm tra khớp với DB
            if (!$typeHasModels) continue; // DB không có model cho loại này, bỏ qua

            $modelLower = mb_strtolower($modelName, 'UTF-8');
            $foundInDb  = false;
            foreach ($keywords as $kw) {
                foreach ($dbTenLinhKien as $dbLoai => $tenMap) {
                    if (str_contains($dbLoai, $kw) && isset($tenMap[$modelLower])) {
                        $foundInDb = true;
                        break 2;
                    }
                }
            }

            if (!$foundInDb) {
                jimport_exit(['success' => false,
                    'message' => "❌ Tên linh kiện \"$modelName\" ({$it['type']}) tại Máy {$machine['so_may']} ({$machine['cfg_name']}) không khớp với đơn hàng #$order_id. Vui lòng kiểm tra lại tên model trong file Excel."
                ]);
            }
        }
    }

    // Kiểm tra trùng lặp serial trong chính file Excel
    $seenSerialsInExcel = [];

    foreach ($byMachine as $mayKey => $machine) {
        $may = $machine['so_may'];
        $cfg = $machine['cfg_name'];

        foreach ($machine['items'] as $it) {
            $sn = trim($it['serial']);
            if ($sn === '' || $sn === '-' || $sn === '—') continue;
            if (isOsType($it['type'])) continue;

            $snLower  = mb_strtolower($sn, 'UTF-8');
            $typeNorm = mb_strtolower($it['type'], 'UTF-8');

            // Kiểm tra trùng lặp serial trong file Excel (cùng loại linh kiện)
            if (isset($seenSerialsInExcel[$typeNorm][$snLower])) {
                $prev = $seenSerialsInExcel[$typeNorm][$snLower];
                jimport_exit(['success' => false,
                    'message' => "Lỗi trùng lặp Serial: Số Serial \"$sn\" bị nhập trùng ở Máy $may - $cfg và Máy {$prev['may']} - {$prev['cfg']} (Loại: {$it['type']}). Mỗi Serial chỉ được dùng cho một linh kiện duy nhất!"
                ]);
            }
            $seenSerialsInExcel[$typeNorm][$snLower] = ['may' => $may, 'cfg' => $cfg, 'type' => $it['type']];
        }
    }

    // Kiểm tra IMEI/IMER trong file phải khớp với DB của đơn hàng
    foreach ($byMachine as $mayKey => $machine) {
        $may = $machine['so_may'];
        $cfg = $machine['cfg_name'];
        $imeiExcel = $machine['imei'];
        if ($imeiExcel === '') {
            jimport_exit(['success' => false,
                'message' =>"⚠️ Không thể import Máy $may - $cfg vì chưa có số IMEI/IMER. Đây là thông tin bắt buộc để xác minh thiết bị."
            ]);
        }
        $imeiLower = mb_strtolower($imeiExcel, 'UTF-8');

        // 1. Kiểm tra IMEI trùng với linh kiện khác trong file Excel
        if (isset($seenSerialsInExcel['imei'][$imeiLower])) {
            $prev = $seenSerialsInExcel['imei'][$imeiLower];
            jimport_exit(['success' => false,
                'message' => "🔁 Trùng IMEI/IMER: Số \"$imeiExcel\" tại Máy $may - $cfg đã được sử dụng trước đó ở Máy {$prev['may']} - {$prev['cfg']}. Mỗi IMEI chỉ được dùng cho 1 máy duy nhất, vui lòng kiểm tra lại file Excel."
            ]);
        }
        $seenSerialsInExcel['imei'][$imeiLower] = ['may' => $may, 'cfg' => $cfg, 'type' => 'IMEI'];

        // 2. Kiểm tra IMEI tồn tại trong đơn hàng — ưu tiên đúng máy này, fallback pool chưa gán máy
        // Nếu trong DB chưa có bất kỳ số IMEI nào thì chấp nhận mọi IMEI từ Excel để lưu mới
        $dbImeiIsEmpty = true;
        foreach ($dbImeiRows as $m => $rows) {
            if (!empty($rows)) { $dbImeiIsEmpty = false; break; }
        }
        $jsonImei    = $orderImeis[$may - 1] ?? '';
        $imeiMatched = ($dbImeiIsEmpty && empty($orderImeis) && empty($allOrderImeiSerials))
            || in_array($imeiLower, $dbImeiRows[$may] ?? [], true)
            || in_array($imeiLower, $dbImeiRows[0] ?? [], true)
            || ($jsonImei !== '' && $imeiLower === $jsonImei)
            || in_array($imeiLower, $allOrderImeiSerials, true);
        if (!$imeiMatched) {
            jimport_exit(['success' => false,
                'message' =>"❗Số IMEI/IMER \"$imeiExcel\" (Máy $may - $cfg) không trùng khớp với đơn hàng #$order_id. Vui lòng kiểm tra lại file Excel."
            ]);
        }

    }

    // Kiểm tra tổng số máy trong file Excel phải khớp với DB
    $excelMachineCount = count($byMachine);
    $dbMachineCount = 0;
    try {
        // Luôn đếm tổng số máy bằng cách đếm CPU (hoặc MAIN)
        $stCpu = $pdo->prepare(
            "SELECT COUNT(*) FROM chitiet_donhang
            WHERE id_donhang = ? AND UPPER(loai_linhkien) = 'CPU'"
        );
        $stCpu->execute([$order_id]);
        $dbMachineCount = (int)$stCpu->fetchColumn();

        if ($dbMachineCount === 0) {
            $stMain = $pdo->prepare(
                "SELECT COUNT(*) FROM chitiet_donhang
                WHERE id_donhang = ? AND UPPER(loai_linhkien) IN ('MAIN','MAINBOARD')"
            );
            $stMain->execute([$order_id]);
            $dbMachineCount = (int)$stMain->fetchColumn();
        }
    } catch (PDOException $e) {}

    if ($dbMachineCount > 0 && $excelMachineCount !== $dbMachineCount) {
        jimport_exit(['success' => false,
            'message' => "Số lượng máy không khớp: File Excel có $excelMachineCount máy, nhưng đơn hàng #$order_id trong hệ thống có $dbMachineCount máy. Vui lòng kiểm tra lại file Excel cho khớp với số máy của đơn hàng."
        ]);
    }

    // Không cần kiểm tra may < 1 || may > dbMachineCount nữa vì mỗi cấu hình có số máy riêng

    // -------------------------------------------------------
    // THỰC HIỆN NHẬP (sau khi đã qua toàn bộ kiểm tra)
    // Tối ưu: tải toàn bộ chitiet_donhang 1 lần vào RAM,
    // match trong PHP, batch UPDATE cuối cùng → giảm 90%+ queries DB
    // -------------------------------------------------------

    // Load toàn bộ rows của đơn hàng 1 lần duy nhất
    $allCtRows = [];
    try {
        $stLoad = $pdo->prepare(
            "SELECT id_ct, so_may, LOWER(TRIM(loai_linhkien)) as loai,
                    LOWER(TRIM(ten_linhkien)) as ten_linhkien,
                    LOWER(TRIM(ten_cauhinh)) as ten_cauhinh,
                    linhkien_chon,
                    so_serial
            FROM chitiet_donhang
            WHERE id_donhang = ?
            ORDER BY id_ct ASC"
        );
        $stLoad->execute([$order_id]);
        $allCtRows = $stLoad->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        jimport_exit(['success' => false, 'message' => 'Lỗi tải dữ liệu: ' . $e->getMessage()]);
    }

    // Hàm kiểm tra 1 row DB có khớp loại linh kiện không
    function rowMatchesType(array $row, array $keywords): bool {
        foreach ($keywords as $kw) {
            if (str_contains($row['loai'], $kw)) return true;
        }
        return false;
    }

    // Hàm kiểm tra linhkien_chon có available không
    function rowAvailable(array $row, string $cfgNorm): bool {
        $lk = $row['linhkien_chon'] ?? '';
        return $lk === '' || $lk === null || mb_strtolower(trim($lk), 'UTF-8') === $cfgNorm;
    }

    // Hàm kiểm tra slot chưa có serial (có thể ghi vào)
    function rowEmpty(array $row): bool {
        $s = $row['so_serial'] ?? '';
        return $s === '' || $s === null;
    }

    // Hàm match id_ct từ $allCtRows theo các chiến lược ưu tiên (không query DB)
    // Hỗ trợ cả ghi đè (overwrite) serial cũ khi import lại
    function findMatchedRows(array $allCtRows, int $may, string $cfgNorm, array $keywords,
                             string $modelName, string $cfgName): array
    {
        $cfgNameLower = mb_strtolower(trim($cfgName), 'UTF-8');
        $modelLower   = mb_strtolower(trim($modelName), 'UTF-8');

        $candidates = [];

        foreach ($allCtRows as $row) {
            if (!rowMatchesType($row, $keywords)) continue;
            if (!str_contains($row['ten_cauhinh'], $cfgNameLower)) continue;
            if ($modelLower !== '' && $row['ten_linhkien'] !== $modelLower) continue;
            if (!rowAvailable($row, $cfgNorm)) continue;

            $rowMay = (int)($row['so_may'] ?? 0);
            $isEmpty = rowEmpty($row);

            if ($rowMay === $may) {
                $priority = $isEmpty ? 1 : 2;
            } elseif ($rowMay === 0) {
                $priority = $isEmpty ? 3 : 4;
            } else {
                continue;
            }

            $candidates[] = [
                'id_ct' => $row['id_ct'],
                'priority' => $priority
            ];
        }

        // Sắp xếp các ứng viên theo độ ưu tiên (1 là cao nhất, 4 là thấp nhất)
        usort($candidates, fn($a, $b) => $a['priority'] <=> $b['priority']);
        return array_column($candidates, 'id_ct');
    }

    // Tính expected_qty từ dữ liệu đã load (không query DB)
    function get_expected_qty_from_cache(array $allCtRows, int $order_id, array $keywords,
                                        string $modelName, string $cfgNorm, array &$cache): int
    {
        $cacheKey = implode(',', $keywords) . '|' . mb_strtolower($modelName, 'UTF-8') . '|' . $cfgNorm;
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        $modelLower = mb_strtolower(trim($modelName), 'UTF-8');
        $counts = [];
        foreach ($allCtRows as $row) {
            $rowMay = (int)($row['so_may'] ?? 0);
            if ($rowMay <= 0) continue;
            if (!rowMatchesType($row, $keywords)) continue;
            if ($modelLower !== '' && $row['ten_linhkien'] !== $modelLower) continue;
            // Kiểm tra ten_cauhinh chứa cfgNorm
            if (!str_contains($row['ten_cauhinh'], $cfgNorm)) continue;
            $counts[$rowMay] = ($counts[$rowMay] ?? 0) + 1;
        }
        if (count($counts) < 2) { $cache[$cacheKey] = 0; return 0; }
        $freq = array_count_values($counts);
        arsort($freq);
        $cache[$cacheKey] = (int)array_key_first($freq);
        return $cache[$cacheKey];
    }

    try {
        $pdo->beginTransaction();

        // 1. Tải danh sách IMEI hiện tại từ donhang để cập nhật
        $currentImeis = [];
        try {
            $sImei = $pdo->prepare("SELECT imei FROM donhang WHERE id_donhang = ?");
            $sImei->execute([$order_id]);
            $rawImei = $sImei->fetchColumn();
            if ($rawImei) {
                $decImei = json_decode($rawImei, true);
                if (is_array($decImei)) $currentImeis = $decImei;   
            }
        } catch (PDOException $e) {}

        // Cập nhật danh sách IMEI từ file Excel
        foreach ($byMachine as $machine) {
            $may = $machine['so_may'];
            if ($may > 0 && !empty($machine['imei'])) {
                $currentImeis[$may - 1] = $machine['imei'];
            }
        }

        // Lưu IMEI vào bảng donhang
        if (!empty($currentImeis)) {
            $columnCheck = $pdo->query("SHOW COLUMNS FROM donhang LIKE 'imei'")->fetch(PDO::FETCH_ASSOC);
            if (!$columnCheck) {
                $pdo->exec("ALTER TABLE donhang ADD COLUMN imei LONGTEXT NULL");
            }
            $imei_json = json_encode($currentImeis, JSON_UNESCAPED_UNICODE);
            $stmt_imei = $pdo->prepare("UPDATE donhang SET imei = ? WHERE id_donhang = ?");
            $stmt_imei->execute([$imei_json, $order_id]);
        }

        $qty_expected_cache = [];
        $totalQtyCapped = 0;

        // Gom tất cả UPDATE lại batch, thực hiện 1 lần sau vòng lặp
        $batchUpdates = []; // [[$serial, $may, $cfgNorm, $session_user_id, $id_ct], ...]

        // Đánh dấu id_ct đã được dùng trong lần import này (tránh 2 group dùng cùng slot)
        $usedIdCt = [];

        foreach ($byMachine as $mayKey => $machine) {
            $may       = $machine['so_may'];
            $imeiExcel = $machine['imei'];
            $cfgNorm   = mb_strtolower(trim($machine['cfg_name']), 'UTF-8');
            $serialDone = 0;
            $serialFail = 0;
            $details    = [];

            // 2. Cập nhật IMEI/IMER trong chitiet_donhang
            if ($imeiExcel !== '') {
                $imeiKeywords = ['imei', 'imer'];
                $matchedImeiRows = findMatchedRows($allCtRows, $may, $cfgNorm, $imeiKeywords, '', $machine['cfg_name']);
                $matchedImeiRows = array_values(array_filter($matchedImeiRows, fn($id) => !isset($usedIdCt[$id])));
                if (!empty($matchedImeiRows)) {
                    $idCtImei = $matchedImeiRows[0];
                    $batchUpdates[] = [$imeiExcel, $may, $cfgNorm, $session_user_id, $idCtImei];
                    $usedIdCt[$idCtImei] = true;
                    $serialDone++;
                }
            }

            // Gom theo type+model
            $groups = [];
            foreach ($machine['items'] as $it) {
                $gKey = mb_strtolower($it['type'], 'UTF-8') . '|||' . $it['model'];
                $groups[$gKey][] = $it['serial'];
            }

            foreach ($groups as $gKey => $serials) {
                [$typeNorm, $modelName] = explode('|||', $gKey, 2);
                $keywords = getTypeKeywordsForImport($typeNorm);

                // Giới hạn qty từ dữ liệu đã load (không query DB)
                $expected_qty = get_expected_qty_from_cache($allCtRows, $order_id, $keywords, $modelName, $cfgNorm, $qty_expected_cache);
                if ($expected_qty > 0 && count($serials) > $expected_qty) {
                    $numCapped = count($serials) - $expected_qty;
                    $serials   = array_slice($serials, 0, $expected_qty);
                    $totalQtyCapped += $numCapped;
                    $details[] = "Dư $numCapped serial $typeNorm ($modelName) so với số lượng chuẩn ($expected_qty/máy) - đã bỏ qua.";
                }

                // Match trong PHP — không query DB
                $matchedRows = findMatchedRows($allCtRows, $may, $cfgNorm, $keywords, $modelName, $machine['cfg_name']);

                // Loại bỏ các id_ct đã được dùng trong vòng lặp này
                $matchedRows = array_values(array_filter($matchedRows, fn($id) => !isset($usedIdCt[$id])));

                $slotPtr = 0; // con trỏ slot trong matchedRows
                foreach ($serials as $serial) {
                    if ($serial === '' || $serial === '-' || $serial === '—') continue;
                    if (!isset($matchedRows[$slotPtr])) { $serialFail++; $slotPtr++; continue; }
                    $idCt = $matchedRows[$slotPtr];
                    $batchUpdates[] = [$serial, $may, $cfgNorm, $session_user_id, $idCt];
                    $usedIdCt[$idCt] = true;
                    $serialDone++;
                    $slotPtr++;
                }
            }

            if ($serialFail > 0) {
                // Không rollback — chỉ ghi chú, vẫn import các serial tìm được
                $details[] = "⚠️ $serialFail serial không tìm được slot linh kiện phù hợp (có thể DB chưa tạo đủ slot).";
                $totalNotFound += $serialFail;
            }

            $results[] = [
                'so_may'      => $may,
                'cfg_name'    => $machine['cfg_name'],
                'imei'        => $imeiExcel,
                'status'      => $serialFail > 0 ? 'partial' : 'ok',
                'serial_done' => $serialDone,
                'serial_fail' => $serialFail,
                'note'        => implode(' ', $details),
            ];
            $totalImported++;
        }

        // Thực hiện tất cả UPDATE trong 1 lần (batch)
        if (!empty($batchUpdates)) {
            $upd = $pdo->prepare("UPDATE chitiet_donhang
                                SET so_serial = ?, so_may = ?, linhkien_chon = ?,
                                    user_id = NULL, user_id_save = ?
                                WHERE id_ct = ?");
            foreach ($batchUpdates as $params) {
                $upd->execute($params);
            }
        }

        $pdo->commit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        jimport_exit(['success' => false, 'message' => 'Lỗi DB khi nhập: ' . $e->getMessage()]);
    }

    ob_end_clean();
    if ($totalImported === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi: Không có máy nào được nhập (Serial chưa đầy đủ hoặc sai IMEI).',
            'results' => $results,
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success'         => true,
            'total_imported'  => $totalImported,
            'total_skipped'   => $totalSkipped,
            'total_not_found' => $totalNotFound,
            'total_qty_capped'=> $totalQtyCapped,
            'results'         => $results,
        ], JSON_UNESCAPED_UNICODE);
    }