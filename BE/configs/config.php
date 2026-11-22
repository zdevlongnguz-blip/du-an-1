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

// URL công khai (ví dụ link ngrok hoặc domain deploy) lấy từ APP_URL trong BE/.env.
// Để trống thì suy ra từ request; không tin header X-Forwarded-* vì client có thể giả mạo.
$_publicUrl = rtrim(trim((string) getenv('APP_URL')), '/');
if ($_publicUrl !== '' && !preg_match('#^https?://[^/\s]+$#i', $_publicUrl)) {
    throw new RuntimeException('APP_URL trong BE/.env phải có dạng https://ten-mien (không có đường dẫn).');
}

// Tự xác định URL theo request để tạo liên kết tuyệt đối trong email.
$_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_base = rtrim(dirname($_script), '/\\') . '/';
define('BASE_URL', ($_publicUrl !== '' ? $_publicUrl : $_protocol . '://' . $_host) . $_base);
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
define('PATH_GEMINI_SERVICE', PATH_ROOT . 'geminiServices/');
// Thư mục chứa service gọi AI kiểm duyệt bình luận (Comment Guard).
define('PATH_COMMENT_GUARD_SERVICE', PATH_ROOT . 'commentGuardServices/');

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
// FRONTEND_URL: ưu tiên biến môi trường; có APP_URL thì FE cùng origin với BE; mặc định là Vite dev.
define('FRONTEND_URL', rtrim(getenv('FRONTEND_URL') ?: ($_publicUrl !== '' ? $_publicUrl : 'http://localhost:5173'), '/'));
unset($_publicUrl);

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
// Số phút tối đa chờ thanh toán online; quá hạn thì đơn sepay pending/unpaid tự hủy và hoàn tồn kho.
define('SEPAY_PAYMENT_TIMEOUT_MINUTES', 30);

// Gemini (AI của Google): API key là bí mật nên đọc từ BE/.env; để trống thì service Gemini báo lỗi khi được gọi.
// GEMINI_API_KEY có thể chứa 1 key hoặc nhiều key cách nhau bằng dấu phẩy (key1,key2,key3)
// để dùng luân phiên nhiều key miễn phí. File .env không có kiểu mảng nên phải tự tách chuỗi.
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
// Danh sách key đã tách: bỏ khoảng trắng, bỏ phần tử rỗng (ví dụ dấu phẩy thừa ở cuối), bỏ key trùng.
// array_values: đánh lại chỉ số 0, 1, 2... sau khi lọc để service chọn key theo vị trí.
define('GEMINI_API_KEYS', array_values(array_unique(array_filter(
    array_map('trim', explode(',', GEMINI_API_KEY)),
    static fn($key) => $key !== ''
))));
// Tên model Gemini dùng mặc định, đổi được qua GEMINI_MODEL trong BE/.env mà không phải sửa code.
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash');

// Bật/tắt AI kiểm duyệt bình luận mà không phải sửa code (ví dụ khi Colab bị tắt hoặc chạy quá chậm).
// Nhận 1/0, true/false, on/off, yes/no. Để trống hoặc ghi sai thì mặc định BẬT.
// Khi tắt: bình luận được lưu ngay với status 0 (chờ admin duyệt), không gọi Colab.
// Phải xử lý chuỗi rỗng riêng: getenv() trả false khi chưa khai báo biến, và filter_var coi cả false lẫn ''
// là "tắt", nên nếu không kiểm tra trước thì quên khai báo biến sẽ vô tình tắt kiểm duyệt.
// FILTER_NULL_ON_FAILURE: giá trị không nhận ra được (ví dụ "abc") trả về null thay vì false, để còn dùng mặc định.
$_commentGuardEnabledRaw = trim((string) getenv('COMMENT_GUARD_ENABLED'));
$_commentGuardEnabled = $_commentGuardEnabledRaw === ''
    ? null
    : filter_var($_commentGuardEnabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
define('COMMENT_GUARD_ENABLED', $_commentGuardEnabled === null ? true : $_commentGuardEnabled);

// Comment Guard: dịch vụ AI bên ngoài (chạy trên Google Colab) chấm bình luận có độc hại hay không.
// URL và key đọc từ BE/.env vì URL Colab đổi mỗi lần chạy lại và key là bí mật.
// URL để trống thì service CommentGuard báo lỗi khi được gọi.
define('COMMENT_GUARD_URL', rtrim(trim((string) getenv('COMMENT_GUARD_URL')), '/'));
define('COMMENT_GUARD_KEY', trim((string) getenv('COMMENT_GUARD_KEY')));
// Số giây tối đa chờ dịch vụ trả lời; sai định dạng hoặc ngoài 1-60 thì dùng 15 giây.
$_commentGuardTimeout = (int) getenv('COMMENT_GUARD_TIMEOUT');
define('COMMENT_GUARD_TIMEOUT', ($_commentGuardTimeout >= 1 && $_commentGuardTimeout <= 60) ? $_commentGuardTimeout : 15);