<?php
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Nếu không phải kỹ thuật, hoặc đã xác thực rồi thì cho qua luôn
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'kythuat' || (isset($_SESSION['scan_verified']) && $_SESSION['scan_verified'] === true)) {
    $target = $_GET['redirect'] ?? 'dashboard-ky-thuat.php';
    header("Location: " . $target);
    exit();
}

$error = '';
$redirect = $_GET['redirect'] ?? 'nhap-serial.php';
$api_login_url = 'https://scanninh.rosaoffice.com/auth/login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Vui lòng nhập đầy đủ tài khoản và mật khẩu Scanner';
    } else {
        // GỬI YÊU CẦU XÁC THỰC ĐẾN SCANNER API (Theo mẫu quet-ma.js)
        $ch = curl_init($api_login_url);
        $payload = json_encode(['username' => $username, 'password' => $password]);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Localhost bypass
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Kiểm tra mã phản hồi từ API (200 OK là thành công)
        if ($http_code === 200) {
            $_SESSION['scan_verified'] = true;
            $_SESSION['scanner_user'] = $username; // Lưu lại user máy quét nếu cần
            header("Location: " . $redirect);
            exit();
        } else {
            // Thử giải mã lỗi từ API nếu có
            $res_data = json_decode($response, true);
            $error = $res_data['detail'] ?? 'Tài khoản hoặc mật khẩu Scanner không đúng';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xác thực Scanner API | Kỹ thuật</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0050D2;
            --bg: #f8fafc;
        }

        body {
            font-family: Montserrat;
            background-color: var(--bg);
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .verify-card {
            background: white;
            padding: 35px;
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.05);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .icon-box {
            width: 70px;
            height: 70px;
            background: #FFF7ED;
            color: #EA580C;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 20px;
        }

        h1 {
            font-size: 20px;
            color: #1e293b;
            margin-bottom: 8px;
        }

        p {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .input-group {
            position: relative;
            margin-bottom: 15px;
            text-align: left;
        }

        .input-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .input-group input {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            outline: none;
            box-sizing: border-box;
            transition: all 0.2s;
        }

        .input-group input:focus {
            border-color: #EA580C;
            box-shadow: 0 0 0 4px rgba(234, 88, 12, 0.1);
        }

        .btn-verify {
            width: 100%;
            padding: 16px;
            background: #EA580C;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
        }

        .btn-verify:hover {
            background: #C2410C;
            transform: translateY(-2px);
        }

        .error-msg {
            background: #fee2e2;
            color: #ef4444;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 15px;
            font-weight: 500;
            border: 1px solid #fecaca;
        }

        .scan-action-btn {
            position: absolute;
            right: 10px;
            top: 32px;
            background: #fed7aa;
            border: none;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            color: #EA580C;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Scanner Modal */
        .scanner-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.85);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }

        .scanner-container {
            background: white;
            width: 100%;
            max-width: 450px;
            border-radius: 20px;
            overflow: hidden;
        }

        .scanner-header {
            padding: 15px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .scanner-body {
            padding: 20px;
            text-align: center;
        }

        .preview-area {
            width: 100%;
            aspect-ratio: 16/9;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            margin-bottom: 15px;
            cursor: pointer;
        }

        .preview-area img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
    </style>
</head>

<body>

    <div class="verify-card">
        <div class="icon-box"><i class="fa-solid fa-user-check"></i></div>
        <h1>Xác thực dịch vụ quét</h1>
        <p>Vui lòng đăng nhập tài khoản
            <strong>Scanner</strong> để tiếp tục thao tác.
        </p>

        <?php if ($error): ?>
            <div class="error-msg"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" id="verifyForm">
            <div class="input-group">
                <label>Tài khoản Scanner</label>
                <input type="text" name="username" id="username" placeholder="Nhập username..." required autofocus
                    autocomplete="off">
                <button type="button" class="scan-action-btn" id="btnOpenScanner" title="Quét tài khoản">
                    <i class="fa-solid fa-camera"></i>
                </button>
            </div>
            <div class="input-group">
                <label>Mật khẩu Scanner</label>
                <input type="password" name="password" id="password" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn-verify">XÁC THỰC DỊCH VỤ QUÉT</button>
        </form>

        <a href="dashboard-ky-thuat.php"
            style="display:inline-block; margin-top:20px; color:#94a3b8; text-decoration:none; font-size:12px;">
            <i class="fa-solid fa-arrow-left"></i> Hủy và quay lại
        </a>
    </div>

    <div class="scanner-modal" id="scannerModal">
        <div class="scanner-container">
            <div class="scanner-header"><span style="font-weight:700">Quét thẻ Scanner</span><button type="button"
                    id="btnCloseScanner"
                    style="border:none; background:none; font-size:24px; cursor:pointer">&times;</button></div>
            <div class="scanner-body">
                <div class="preview-area" id="previewArea">
                    <div id="placeholder"
                        style="color:#94a3b8; display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%">
                        <i class="fa-solid fa-camera" style="font-size:35px; margin-bottom:10px"></i><span>Chạm để quét
                            mã</span>
                    </div>
                    <img id="previewImg" style="display:none">
                </div>
                <div id="scannerStatus" style="font-size:13px; color:#64748b">Đang chờ quét...</div>
                <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display:none">
            </div>
        </div>
    </div>

    <script>
        const PROXY_URL = 'scanner-proxy.php?path=';
        const btnOpenScanner = document.getElementById('btnOpenScanner');
        const scannerModal = document.getElementById('scannerModal');
        const btnCloseScanner = document.getElementById('btnCloseScanner');
        const cameraInput = document.getElementById('cameraInput');
        const previewArea = document.getElementById('previewArea');
        const previewImg = document.getElementById('previewImg');
        const placeholder = document.getElementById('placeholder');
        const scannerStatus = document.getElementById('scannerStatus');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const verifyForm = document.getElementById('verifyForm');

        btnOpenScanner.addEventListener('click', () => scannerModal.style.display = 'flex');
        btnCloseScanner.addEventListener('click', () => scannerModal.style.display = 'none');
        previewArea.addEventListener('click', () => cameraInput.click());

        cameraInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (event) => {
                previewImg.src = event.target.result;
                previewImg.style.display = 'block';
                placeholder.style.display = 'none';
            };
            reader.readAsDataURL(file);

            scannerStatus.textContent = "⌛ Đang nhận diện...";
            const formData = new FormData();
            formData.append('file', file);

            try {
                const res = await fetch(PROXY_URL + 'scan', {
                    method: 'POST',
                    body: formData
                });
                const result = await res.json();

                if (result.success && result.results && result.results.length > 0) {
                    const data = result.results[0].data;
                    if (data.includes('|')) {
                        const parts = data.split('|');
                        usernameInput.value = parts[0];
                        passwordInput.value = parts[1];
                    } else {
                        usernameInput.value = data;
                    }
                    scannerStatus.textContent = "✓ Đã nhận diện!";
                    setTimeout(() => {
                        scannerModal.style.display = 'none';
                        if (usernameInput.value && passwordInput.value) verifyForm.submit();
                    }, 600);
                } else {
                    scannerStatus.textContent = "❌ Không tìm thấy mã hợp lệ";
                }
            } catch (err) {
                scannerStatus.textContent = "❌ Lỗi kết nối API";
            }
        });
    </script>

</body>

</html>