<?php

// Nạp các giá trị bí mật từ BE/.env; biến môi trường của máy chạy được ưu tiên.
$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    $envLines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($envLines === false) {
        throw new RuntimeException('Không đọc được file cấu hình BE/.env.');
    }

    foreach ($envLines as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || $envLine[0] === '#' || strpos($envLine, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $envLine, 2);
        $name = trim($name);
        $value = trim($value);
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $name)) {
            continue;
        }

        if (getenv($name) !== false) {
            continue;
        }

        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

$dbUsername = getenv('DB_USERNAME');
$dbPassword = getenv('DB_PASSWORD');
$jwtSecret = getenv('JWT_SECRET');
if ($dbUsername === false || $dbPassword === false || $jwtSecret === false || $jwtSecret === '') {
    throw new RuntimeException('Thiếu DB_USERNAME, DB_PASSWORD hoặc JWT_SECRET trong BE/.env.');
}

// Tự xác định URL theo request để tạo liên kết tuyệt đối trong email.
$_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_base = rtrim(dirname($_script), '/\\') . '/';
define('BASE_URL', $_protocol . '://' . $_host . $_base);
unset($_protocol, $_host, $_script, $_base);

define('PATH_ROOT', __DIR__ . '/../');
define('PATH_VIEW', PATH_ROOT . 'views/');
define('PATH_VIEW_MAIN', PATH_ROOT . 'views/main.php');
define('BASE_ASSETS_UPLOADS', BASE_URL . 'assets/uploads');
define('PATH_ASSETS_UPLOADS', PATH_ROOT . 'assets/uploads');
define('PATH_MODEL', PATH_ROOT . 'models/');
define('PATH_CONTROLLER', PATH_ROOT . 'controllers/');
define('BASE_ASSETS_JS', BASE_URL . 'assets/js/');
define('PATH_EXTENSION', PATH_ROOT . 'extensions/');

// Các thiết lập không phải bí mật được giữ trực tiếp trong file cấu hình.
define('APP_ENV', 'development');
define('DB_HOST', 'localhost');
define('DB_NAME', 'du-an-1');
define('DB_PORT', '3306');
define('DB_USERNAME', $dbUsername);
define('DB_PASSWORD', $dbPassword);
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

define('JWT_SECRET', $jwtSecret);
define('JWT_TTL', 3600);
define('EMAIL_VERIFICATION_TTL', 3600);
define('PASSWORD_RESET_TTL', 3600);
define('FRONTEND_URL', 'http://localhost:5173');

// Mailer và phần tích hợp SePay chỉ đọc cấu hình từ biến môi trường.
$mailPort = getenv('MAIL_PORT');
define('MAIL_HOST', getenv('MAIL_HOST') ?: '');
define('MAIL_PORT', $mailPort !== false && ctype_digit($mailPort) ? (int) $mailPort : 0);
define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') ?: '');
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: '');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: '');
define('MAIL_ENCRYPTION', getenv('MAIL_ENCRYPTION') ?: '');

define('SEPAY_ENVIRONMENT', getenv('SEPAY_ENVIRONMENT') ?: 'sandbox');
define('SEPAY_MERCHANT_ID', getenv('SEPAY_MERCHANT_ID') ?: '');
define('SEPAY_SECRET_KEY', getenv('SEPAY_SECRET_KEY') ?: '');
define('SEPAY_SUCCESS_URL', getenv('SEPAY_SUCCESS_URL') ?: rtrim(FRONTEND_URL, '/') . '/payment/success');
define('SEPAY_ERROR_URL', getenv('SEPAY_ERROR_URL') ?: rtrim(FRONTEND_URL, '/') . '/payment/error');
define('SEPAY_CANCEL_URL', getenv('SEPAY_CANCEL_URL') ?: rtrim(FRONTEND_URL, '/') . '/payment/cancel');
define('SEPAY_IPN_SECRET', getenv('SEPAY_IPN_SECRET') ?: '');