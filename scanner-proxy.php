<?php

/**
 * Scanner Proxy - Giải quyết lỗi CORS và Cookie khi chạy localhost
 */
$target_base = 'https://scanninh.rosaoffice.com';

// Hàm polyfill cho getallheaders nếu không tồn tại (trong môi trường CGI/FastCGI)
if (!function_exists('getallheaders')) {
    function getallheaders()
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            } elseif ($name == 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
            } elseif ($name == 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }
        return $headers;
    }
}

// Lấy toàn bộ query string và xử lý lấy path riêng
$query_string = $_SERVER['QUERY_STRING'] ?? '';
parse_str($query_string, $query_params);

$path = isset($query_params['path']) ? $query_params['path'] : '';
unset($query_params['path']); // Xoá path khỏi params để còn lại các tham số khác

$url = $target_base . '/' . ltrim($path, '/');

// Nếu còn các tham số khác, nối lại vào URL
if (!empty($query_params)) {
    $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query_params);
}

$method = $_SERVER['REQUEST_METHOD'];
$headers = getallheaders();

// Các header cần loại bỏ để tránh bị server chặn (CORS/CSRF protection)
$exclude_headers = [
    'host',
    'content-length',
    'connection',
    'expect',
    'origin',
    'referer',
    'accept-encoding',
    'sec-fetch-dest',
    'sec-fetch-mode',
    'sec-fetch-site',
    'sec-fetch-user'
];

$curl_headers = [];
foreach ($headers as $key => $value) {
    if (in_array(strtolower($key), $exclude_headers))
        continue;

    // Giữ nguyên các header quan trọng khác (Cookie, Content-Type, v.v.)
    $curl_headers[] = "$key: $value";
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_ENCODING, "");

$body = '';
if ($method === 'POST') {
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
        $postData = $_POST;
        foreach ($_FILES as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $postData[$key] = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $body = "[Multipart Data]";
    } else {
        $body = file_get_contents('php://input');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
}

// Debug log (tự động xoá sau 100 lần để tránh quá nặng)
$log_entry = date('Y-m-d H:i:s') . " | $method | $url\n";
$log_entry .= "Headers: " . implode(", ", $curl_headers) . "\n";
$log_entry .= "Body: $body\n";
$log_entry .= "----------------------------------------\n";
file_put_contents('proxy_debug.txt', $log_entry, FILE_APPEND);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    $err_msg = 'cURL Error: ' . curl_error($ch);
    header('HTTP/1.1 500 External API Error');
    echo json_encode(['error' => $err_msg, 'url' => $url]);
    file_put_contents('proxy_debug.txt', "RESULT: ERROR | $err_msg\n----------------------------------------\n", FILE_APPEND);
    curl_close($ch);
    exit;
}

$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$resp_headers = substr($response, 0, $header_size);
$resp_body = substr($response, $header_size);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Debug kết quả phản hồi
file_put_contents('proxy_debug.txt', "RESULT: $http_code | $resp_body\n----------------------------------------\n", FILE_APPEND);

// Chuyển tiếp các header từ server thật
$header_lines = explode("\r\n", $resp_headers);
foreach ($header_lines as $line) {
    if (stripos($line, 'Set-Cookie:') === 0 || stripos($line, 'Content-Type:') === 0 || stripos($line, 'HTTP/') === 0) {
        header($line, false);
    }
}

echo $resp_body;
