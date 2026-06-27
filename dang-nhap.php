<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập hệ thống | ROSA TECHNICAL</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="css/dang-nhap.css">
</head>

<body>
    <div class="header-container">
        <div class="sidebar-logo">
            <img src="./image/logo.png" alt="">
        </div>
    </div>

    <div class="login-card">
        <div class="left-panel">
            <div class="left-panel-content">
                <h1>HỆ THỐNG QUẢN LÝ <br> LẮP RÁP MÁY TÍNH </h1>
                <p>Giải pháp tối ưu cho quy trình lắp ráp, kiểm kho và vận hành nội bộ chuyên nghiệp </p>
                <div class="feature-icons">
                    <div class="icon-item" title="Security"><i data-lucide="shield-check"></i></div>
                    <div class="icon-item" title="Speed"><i data-lucide="gauge"></i></div>
                    <div class="icon-item" title="Hardware"><i data-lucide="box"></i></div>
                </div>
            </div>
        </div>

        <div class="right-panel">
            <h2>Đăng nhập hệ thống</h2>
            <p class="welcome-text">Vui lòng nhập thông tin để truy cập nền tảng</p>

            <form id="loginForm" onsubmit="return false;">
                <div class="form-group">
                    <div class="label-wrap">
                        <label for="username">Tên đăng nhập</label>
                    </div>
                    <div class="input-relative">
                        <i data-lucide="user" class="field-icon"></i>
                        <input type="text" name="username" id="username" class="input-field"
                            placeholder="Vui lòng nhập tên đăng nhập" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <div class="label-wrap">
                        <label for="password">Mật khẩu</label>
                        <a href="#" class="forgot-pass">Quên mật khẩu?</a>
                    </div>
                    <div class="input-relative">
                        <i data-lucide="lock" class="field-icon"></i>
                        <input type="password" name="password" id="password" class="input-field" placeholder="••••••••"
                            required>
                        <!-- Nút bấm bao quanh icon để chắc chắn bắt được sự kiện click -->
                        <span class="password-toggle" id="togglePasswordBtn" style="cursor:pointer;">
                            <i data-lucide="eye" id="eyeIcon"></i>
                        </span>
                    </div>
                </div>

                <div class="remember-row">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Ghi nhớ đăng nhập</label>
                </div>

                <button type="submit" class="btn-submit" id="loginbtn">Đăng nhập</button>
            </form>

            <div id="loginMsg" class="msg"
                style="display: none; margin-top: 15px; padding: 10px; border-radius: 5px; text-align: center;"></div>
        </div>

    </div> <!-- Kết thúc login-card -->

    <div class="copyright-text">
        © 2026 ROSA - AI Computer
    </div>

    <script>
        function showMsg(text, type) {
            const el = document.getElementById('loginMsg');
            if (!el) return;
            el.textContent = text;
            el.className = `msg ${type}`;
            el.style.display = 'block';

            // Màu sắc cho thông báo
            if (type === 'error') {
                el.style.backgroundColor = '#fee2e2';
                el.style.color = '#dc2626';
                el.style.border = '1px solid #fecaca';
            } else {
                el.style.backgroundColor = '#f0fdf4';
                el.style.color = '#16a34a';
                el.style.border = '1px solid #bbf7d0';
            }
        }

        async function login() {
            const usernameInput = document.getElementById('username');
            const passwordInput = document.getElementById('password');
            const btn = document.getElementById('loginbtn');

            const username = usernameInput.value.trim();
            const password = passwordInput.value;

            if (!username || !password) {
                showMsg('Vui lòng nhập đầy đủ thông tin', 'error');
                return;
            }

            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Đang xử lý...';

            try {
                const res = await fetch('auth-login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        username,
                        password
                    })
                });

                if (!res.ok) {
                    throw new Error(`Server Error: ${res.status}`);
                }

                const data = await res.json();

                if (data.success) {
                    showMsg('Đăng nhập thành công! Đang chuyển hướng...', 'success');
                    setTimeout(() => {
                        if (data.role === 'kythuat') {
                            window.location.href = 'dashboard-ky-thuat.php';
                        } else {
                            window.location.href = 'dashboard-ke-toan.php';
                        }
                    }, 1000);
                } else {
                    showMsg(data.message || 'Đăng nhập thất bại', 'error');
                }
            } catch (err) {
                console.error(err);
                showMsg(`Lỗi kết nối: ${err.message}`, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = originalText;
            }
        }

        // Form submit
        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', (e) => {
                e.preventDefault();
                login();
            });
        }

        // Logic Ẩn/Hiện mật khẩu mới - Chắc chắn 100%
        const toggleBtn = document.querySelector('#togglePasswordBtn');
        const passwordField = document.querySelector('#password');

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                // Đổi kiểu input (password <-> text)
                const isPassword = passwordField.getAttribute('type') === 'password';
                passwordField.setAttribute('type', isPassword ? 'text' : 'password');

                // Đổi icon bên trong (Tìm theo thuộc tính data-lucide thay vì thẻ i)
                const icon = this.querySelector('[data-lucide]');
                const currentIcon = icon.getAttribute('data-lucide');
                icon.setAttribute('data-lucide', currentIcon === 'eye' ? 'eye-off' : 'eye');

                // Vẽ lại icon Lucide cho toàn bộ trang
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
        }

        // Lucide icons
        lucide.createIcons();
    </script>
</body>

</html>