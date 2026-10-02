<?php
require_once 'config.php';
session_start();

header('Content-Type: application/json');

// Get input from JSON or POST
$data = json_decode(file_get_contents('php://input'), true);
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin']);
    exit;
}

try {
    // Tự động tạo bảng users nếu chưa tồn tại
    $checkTable = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount();
    if ($checkTable == 0) {
        $sql = "CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            fullname VARCHAR(100),
            role ENUM('ketoan', 'kythuat', 'admin') DEFAULT 'kythuat',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";
        $pdo->exec($sql);

        // Chèn tài khoản mẫu
        $users = [
            ['ketoan', password_hash('123456', PASSWORD_DEFAULT), 'Kế Toán', 'ketoan'],
            ['kythuat', password_hash('123456', PASSWORD_DEFAULT), 'Kỹ Thuật', 'kythuat'],
            ['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Quản Trị Viên', 'admin']
        ];
        $stmt = $pdo->prepare("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, ?)");
        foreach ($users as $user) {
            $stmt->execute($user);
        }
    }
    $stmt = $pdo->prepare("SELECT id, username, password, fullname, role FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Set sessions
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['user_role'] = $user['role']; // MUST: ketoan or kythuat or admin

        // Reset trạng thái xác thực quét mã khi đăng nhập mới
        unset($_SESSION['scan_verified']);

        echo json_encode(['success' => true, 'message' => 'Đăng nhập thành công', 'role' => $user['role']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Tên đăng nhập hoặc mật khẩu không đúng']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Lỗi kết nối database: ' . $e->getMessage()]);
}
