# API Documentation — QR & Barcode Scanner

**Base URL:** `http://localhost:9003`
**Interactive Docs:** `http://localhost:9003/docs`

---

## Xác thực (Authentication)

API dùng hệ thống **dual-token** lưu trong **httponly cookie** — frontend không cần tự quản lý token, trình duyệt tự gửi cookie theo mỗi request.

| Cookie          | Loại          | Thời hạn | Mô tả                        |
| --------------- | ------------- | -------- | ---------------------------- |
| `access_token`  | JWT           | 30 phút  | Dùng để xác thực mỗi request |
| `refresh_token` | Random string | 30 ngày  | Dùng để lấy access_token mới |

> **Lưu ý:** Mọi request đều phải kèm `credentials: "include"` (fetch) hoặc `withCredentials: true` (axios).

---

## AUTH ENDPOINTS

### POST `/auth/register`

Đăng ký tài khoản mới.

**Request body (JSON):**

```json
{
  "username": "rosa",
  "email": "rosa@example.com",
  "password": "matkhau123"
}
```

**Response `201`:**

```json
{
  "id": 1,
  "username": "rosa",
  "email": "rosa@example.com",
  "is_active": true,
  "created_at": "2026-03-23T10:00:00"
}
```

**Lỗi:**
| Status | Mô tả |
|---|---|
| `400` | `"Username đã được sử dụng"` |
| `400` | `"Email đã được sử dụng"` |

---

### POST `/auth/login`

Đăng nhập. Server tự set cookie `access_token` + `refresh_token`.

**Request body (JSON):**

```json
{
  "username": "rosa",
  "password": "matkhau123"
}
```

**Response `200`:**

```json
{
  "message": "Đăng nhập thành công",
  "username": "rosa"
}
```

**Lỗi:**
| Status | Mô tả |
|---|---|
| `401` | `"Sai username hoặc mật khẩu"` |
| `400` | `"Tài khoản đã bị vô hiệu hóa"` |

---

### POST `/auth/refresh`

Lấy `access_token` mới khi hết hạn. Dùng `refresh_token` cookie hiện có.

> Gọi endpoint này khi nhận được lỗi `401` từ các API khác.

**Request:** Không cần body. Cookie `refresh_token` được gửi tự động.

**Response `200`:**

```json
{
  "message": "Token đã được làm mới"
}
```

**Lỗi:**
| Status | Mô tả |
|---|---|
| `401` | `"Không có refresh token"` |
| `401` | `"Refresh token không hợp lệ hoặc đã hết hạn"` → redirect về trang login |

---

### POST `/auth/logout`

Đăng xuất. Server xóa cookie và vô hiệu hóa refresh token.

**Request:** Không cần body.

**Response `200`:**

```json
{
  "message": "Đã đăng xuất"
}
```

---

### GET `/auth/me`

Lấy thông tin user đang đăng nhập.
**Yêu cầu:** Đã đăng nhập (có cookie hợp lệ).

**Response `200`:**

```json
{
  "id": 1,
  "username": "rosa",
  "email": "rosa@example.com",
  "is_active": true,
  "created_at": "2026-03-23T10:00:00"
}
```

**Lỗi:**
| Status | Mô tả |
|---|---|
| `401` | Chưa đăng nhập hoặc token hết hạn |

---

### DELETE `/auth/sessions`

Đăng xuất khỏi **tất cả thiết bị** — vô hiệu hóa toàn bộ refresh token.
**Yêu cầu:** Đã đăng nhập.

**Response `200`:**

```json
{
  "message": "Đã đăng xuất khỏi tất cả thiết bị"
}
```

---

## SCAN ENDPOINT

### POST `/scan`

Upload ảnh để quét QR code / barcode.
**Yêu cầu:** Đã đăng nhập.

**Request:** `multipart/form-data`

| Field  | Type         | Mô tả                                         |
| ------ | ------------ | --------------------------------------------- |
| `file` | File (image) | Ảnh chứa QR/barcode. Hỗ trợ JPG, PNG, WebP... |

**Response `200` — Quét thành công:**

```json
{
  "success": true,
  "results": [
    {
      "type": "QRCODE",
      "data": "https://example.com"
    }
  ]
}
```

**Response `200` — Không tìm thấy mã:**

```json
{
  "success": false,
  "message": "Không tìm thấy mã. Thử chụp gần hơn để mã chiếm phần lớn khung hình."
}
```

**Trường `type` có thể là:**

```
QRCODE, EAN13, EAN8, CODE128, CODE39, UPCA, UPCE,
PDF417, AZTEC, DATAMATRIX, ITF, ...
```

**Lỗi:**
| Status | Mô tả |
|---|---|
| `401` | Chưa đăng nhập |
| `200` `success: false` | Không decode được ảnh |

---

## Hướng dẫn tích hợp Frontend

### Cấu hình cơ bản (fetch)

```javascript
// Luôn kèm credentials để gửi cookie
const api = (path, options = {}) =>
  fetch(`http://localhost:9003${path}`, {
    credentials: "include", // BẮT BUỘC
    headers: { "Content-Type": "application/json", ...options.headers },
    ...options,
  });
```

### Đăng ký

```javascript
const res = await api("/auth/register", {
  method: "POST",
  body: JSON.stringify({ username: "rosa", email: "a@b.com", password: "123" }),
});
const data = await res.json();
```

### Đăng nhập

```javascript
const res = await api("/auth/login", {
  method: "POST",
  body: JSON.stringify({ username: "rosa", password: "123" }),
});
// Cookie tự động được set bởi trình duyệt
```

### Upload ảnh scan

```javascript
const formData = new FormData();
formData.append("file", fileInput.files[0]);

const res = await fetch("http://localhost:9003/scan", {
  method: "POST",
  credentials: "include", // BẮT BUỘC
  body: formData, // KHÔNG set Content-Type khi dùng FormData
});
const result = await res.json();

if (result.success) {
  console.log(result.results); // [{ type: "QRCODE", data: "..." }]
} else {
  console.log(result.message);
}
```

### Xử lý token hết hạn (401)

```javascript
async function fetchWithRefresh(path, options = {}) {
  let res = await api(path, options);

  if (res.status === 401) {
    // Thử refresh token
    const refreshRes = await api("/auth/refresh", { method: "POST" });
    if (refreshRes.ok) {
      res = await api(path, options); // thử lại request gốc
    } else {
      window.location.href = "/login"; // hết hạn hẳn → về login
    }
  }
  return res;
}
```

---

## Tóm tắt nhanh

| Method | Endpoint         | Auth   | Mô tả                     |
| ------ | ---------------- | ------ | ------------------------- |
| POST   | `/auth/register` | Không  | Đăng ký                   |
| POST   | `/auth/login`    | Không  | Đăng nhập                 |
| POST   | `/auth/refresh`  | Cookie | Làm mới token             |
| POST   | `/auth/logout`   | Cookie | Đăng xuất                 |
| GET    | `/auth/me`       | Có     | Thông tin user            |
| DELETE | `/auth/sessions` | Có     | Đăng xuất tất cả thiết bị |
| POST   | `/scan`          | Có     | Quét QR / Barcode         |
