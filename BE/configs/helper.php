<?php

/**
 * Helper chung của ứng dụng.
 *
 * Chứa các tiện ích hỗ trợ:
 * - debug dữ liệu trong môi trường phát triển
 * - xử lý lỗi toàn cục
 * - validate và lưu trữ file upload
 * - tạo URL và trả về JSON response
 */

/**
 * In ra dữ liệu debug theo kiểu hiển thị đẹp, dùng khi dev đang test code.
 *
 * Hàm này sẽ dừng request ngay lập tức sau khi in dữ liệu ra màn hình. Dùng
 * cho việc debug nhanh ở môi trường development.
 *
 * @param mixed ...$vars Các giá trị cần dump
 * @return void
 */
if (!function_exists('dd')) {
    function dd(...$vars)
    {
        // GIAO DIỆN KIỂM TRA DỮ LIỆU PHONG CÁCH CYBERPUNK ĐỘC QUYỀN
        echo '<div style="background: #060713; color: #39ff14; padding: 25px; font-family: \'Courier New\', monospace; border: 2px solid #00f0ff; box-shadow: 0 0 20px #00f0ff; margin: 20px; border-radius: 4px; z-index: 99999; position: relative;">';
        echo '<h3 style="color: #ff007f; margin-top: 0; text-shadow: 0 0 8px #ff007f; letter-spacing: 2px;">⚡ [CYBERPUNK DUMP INSPECTOR] ⚡</h3>';
        foreach ($vars as $v) {
            echo '<pre style="background: #0c0e22; padding: 15px; border-left: 4px solid #ff007f; overflow-x: auto; color: #00f0ff; font-size: 14px;">';
            var_dump($v);
            echo '</pre>';
        }
        echo '</div>';
        die();
    }
}

/**
 * In ra dữ liệu debug dạng text đơn giản.
 *
 * Khác với dd(), hàm này không có giao diện fancy, chỉ in ra dữ liệu và dừng
 * request ngay. Thường dùng khi cần xem nhanh cấu trúc array/object.
 *
 * @param mixed $data Dữ liệu cần debug
 * @return void
 */
if (!function_exists('debug')) {
    function debug($data) {
        echo '<pre>';
        print_r($data);
        echo '</pre>';
        die;
    }
}

/**
 * Xử lý lỗi toàn cục của ứng dụng.
 *
 * Ghi log chi tiết vào file hệ thống, sau đó hiển thị giao diện lỗi phù hợp
 * với môi trường đang chạy (production hoặc development).
 *
 * @param Throwable $e Đối tượng exception hoặc error cần xử lý
 * @return void
 */
function handleGlobalError(Throwable $e) {
    // 1. Ghi log chi tiết để dễ debug khi phát triển
    error_log($e->getMessage() . " in " . $e->getFile() . " at " . $e->getLine());

    // 2. Nếu đang ở môi trường Production thì hiển thị giao diện meme nhẹ nhàng
    // Nếu đang ở môi trường Development thì show chi tiết lỗi để fix nhanh hơn
    if (getenv('APP_ENV') === 'production') {
        renderMemeError("Server đã bị hack hoặc code của bạn có vấn đề!");
    } else {
        // Hiển thị thông tin lỗi cho dev
        echo "<h1>[DEV_ERROR_LOG]</h1>";
        echo "<pre>" . $e->getMessage() . "<br>File: " . $e->getFile() . ":" . $e->getLine() . "</pre>";
        renderMemeError('Lỗi rồi kìa, fix hoặc khóc đi thằng nguuu!');
    }
    die();
}

/**
 * Hiển thị giao diện lỗi dạng meme và cung cấp nút quay về trang chủ.
 *
 * Dùng khi cần ngắt luồng request nhưng vẫn giữ giao diện người dùng thân thiện.
 *
 * @param string $message Thông điệp lỗi cần hiển thị
 * @return void
 */
function renderMemeError($message) {
    $homeUrl = url('/');
    // Render trực tiếp HTML riêng, không qua layout main.php để tránh vòng lặp lỗi
    echo "
    <style>body{background:#000;color:#0f0;font-family:monospace;text-align:center;padding:50px;}</style>
    <h1>SYSTEM_FAILURE</h1>
    <p>{$message}</p>
    <img src='https://media0.giphy.com/media/v1.Y2lkPTc5MGI3NjExZ3o0am91aWIyNmJhb2ltOGFnZTFjOHlycHpndGJzYTRhbHhtMTJwdSZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/oFTCaycpJYdMVOBD6A/giphy.gif' />
    <br><a href='$homeUrl' style='color:#fff'>Trở về thế giới cũ</a>";
}

/**
 * 1. Validate file upload trước khi lưu vào server.
 *
 * Hàm kiểm tra:
 * - file có lỗi không
 * - file có thực sự được upload từ client không
 * - dung lượng không vượt quá giới hạn
 * - MIME type hợp lệ
 * - extension file nằm trong whitelist
 *
 * @param array $file Mảng dữ liệu file từ $_FILES
 * @param array $allowedExtensions Danh sách extension cho phép
 * @param array $allowedMimeTypes Danh sách MIME type cho phép
 * @param int $maxSize Dung lượng tối đa tính bằng bytes
 * @return bool Trả về true nếu file hợp lệ
 * @throws Exception Nếu file không hợp lệ
 */
function validate_upload(
    array $file, 
    array $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'],
    array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    int $maxSize = 2097152 // Mặc định 2MB
): bool {
    // Kiểm tra lỗi hệ thống từ PHP
    $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new Exception("Lỗi tải lên hệ thống (Mã lỗi: {$errorCode})");
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if (empty($tmpPath) || !is_uploaded_file($tmpPath)) {
        throw new Exception("File tạm thời không hợp lệ hoặc không tồn tại!");
    }

    // Kiểm tra dung lượng
    if ($file['size'] > $maxSize) {
        throw new Exception("Kích thước file vượt quá giới hạn " . round($maxSize / 1024 / 1024, 2) . "MB");
    }

    // Deep Scan MIME type để tránh giả mạo đuôi file
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!in_array($realMimeType, $allowedMimeTypes)) {
        throw new Exception("Định dạng file không được phép (MIME: {$realMimeType})");
    }

    // Kiểm tra đuôi file
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedExtensions)) {
        throw new Exception("Đuôi file [{$fileExt}] không được hỗ trợ!");
    }

    return true;
}

/**
 * 2. Lưu file vào thư mục upload của hệ thống.
 *
 * Hàm sẽ:
 * - tạo thư mục đích nếu chưa tồn tại
 * - tạo tên file mới ngẫu nhiên để tránh trùng lặp
 * - move file từ tmp sang thư mục upload
 *
 * @param array $file Mảng dữ liệu file từ $_FILES
 * @param string $subFolder Thư mục con để lưu, ví dụ: 'products' hoặc 'avatars'
 * @return string Tên file đã lưu
 * @throws Exception Nếu không lưu được file
 */
function save_uploaded_file(array $file, string $subFolder = ''): string
{
    // Xác định đường dẫn gốc từ hằng số toàn cục
    $uploadDir = rtrim(PATH_ASSETS_UPLOADS, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    
    // Thêm subfolder nếu có
    if (!empty($subFolder)) {
        $uploadDir .= trim($subFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    // Tạo thư mục nếu chưa tồn tại
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new Exception("Không thể khởi tạo thư mục lưu trữ trên máy chủ!");
    }

    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Tạo tên file ngẫu nhiên để tránh trùng lặp và tấn công file injection
    $newFileName = 'cyber_' . bin2hex(random_bytes(6)) . '_' . time() . '.' . $fileExt;
    $destination = $uploadDir . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception("Không thể di chuyển file từ bộ nhớ tạm đến thư mục lưu trữ!");
    }

    return $newFileName;
}

/**
 * 3. Xóa file đã upload khỏi server.
 *
 * @param string $fileName Tên file cần xóa
 * @param string $subFolder Thư mục con chứa file nếu có
 * @return bool True nếu xóa thành công, false nếu không tồn tại hoặc lỗi
 */
function delete_uploaded_file(string $fileName, string $subFolder = ''): bool
{
    if (empty($fileName)) return false;

    $filePath = rtrim(PATH_ASSETS_UPLOADS, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!empty($subFolder)) {
        $filePath .= trim($subFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
    $filePath .= basename($fileName);

    if (file_exists($filePath) && is_file($filePath)) {
        return unlink($filePath);
    }
    
    return false;
}

/**
 * 4. Tạo URL tuyệt đối từ BASE_URL.
 *
 * Dùng khi cần tạo link như home, login, hoặc route trong PHP mà không muốn viết
 * thủ công từng lần. Ví dụ: url('products') sẽ ra http://localhost/app/products.
 *
 * @param string $path Đường dẫn mong muốn (ví dụ: 'login', 'products/add')
 * @return string URL đầy đủ
 */
function url(string $path = ''): string
{
    // Loại bỏ dấu / ở đầu để tránh bị trùng lặp
    $path = ltrim($path, '/\\');
    
    // Nối với hằng số BASE_URL đã định nghĩa trong file config
    return BASE_URL . $path;
}

/**
 * 5. Mã hóa chuỗi theo chuẩn Base64URL.
 *
 * JWT yêu cầu header và payload phải được encode theo chuẩn Base64URL, khác với
 * Base64 truyền thống ở chỗ bỏ dấu = và thay +/ bằng -_.
 *
 * @param string $data Chuỗi dữ liệu gốc
 * @return string Chuỗi mã hóa Base64URL
 */
if (!function_exists('jwt_base64url_encode')) {
    function jwt_base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/**
 * 6. Tạo JWT từ payload.
 *
 * JWT có 3 phần: header.payload.signature. Hàm này sẽ:
 * - tạo header với thuật toán HS256
 * - thêm iat và exp vào payload
 * - mã hóa header và payload theo Base64URL
 * - dùng secret key để sign bằng HMAC-SHA256
 *
 * @param array $payload Dữ liệu cần gắn vào token, ví dụ user id, email, role
 * @return string Chuỗi JWT đã ký
 */
if (!function_exists('jwt_sign')) {
    function jwt_sign(array $payload): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $headerEncoded = jwt_base64url_encode(json_encode($header, JSON_UNESCAPED_UNICODE));
        $payload['iat'] = time();
        $payload['exp'] = time() + (int) (JWT_TTL ?? 3600);
        $payloadEncoded = jwt_base64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));

        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
        $signatureEncoded = jwt_base64url_encode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }
}

/**
 * 7. Verify JWT và trả về payload đã giải mã.
 *
 * Quy trình:
 * - tách token thành 3 phần
 * - rebuild chữ ký dựa trên secret key
 * - so sánh chữ ký để đảm bảo token chưa bị chỉnh sửa
 * - decode payload JSON
 * - kiểm tra thời hạn exp
 *
 * @param string $token Chuỗi JWT cần verify
 * @return array Payload đã giải mã
 * @throws Exception Nếu token không hợp lệ hoặc hết hạn
 */
if (!function_exists('jwt_verify')) {
    function jwt_verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new Exception('Token không hợp lệ');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $expectedSignature = jwt_base64url_encode(
            hash_hmac('sha256', "$headerB64.$payloadB64", JWT_SECRET, true)
        );

        if (!hash_equals($expectedSignature, $signatureB64)) {
            throw new Exception('Chữ ký token không hợp lệ');
        }

        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);

        if (!is_array($payload)) {
            throw new Exception('Payload token không hợp lệ');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new Exception('Token đã hết hạn');
        }

        return $payload;
    }
}

/**
 * 8. Kiểm tra request có chứa Authorization: Bearer <token> hay không.
 *
 * Sau khi verify JWT, hàm đối chiếu user, trạng thái và token_version trong DB
 * để token cũ không tiếp tục dùng được sau logout hoặc khi tài khoản inactive.
 *
 * @return array Payload user từ token nếu hợp lệ
 */
if (!function_exists('requireBearerToken')) {
    function requireBearerToken(): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';

        if ($authHeader === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if ($authHeader === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            http_response_code(401);
            response_json([
                'status' => 'error',
                'message' => 'Unauthorized: thiếu Bearer token'
            ], 401);
            exit;
        }

        try {
            $payload = jwt_verify(trim($matches[1]));
        } catch (Exception $e) {
            http_response_code(401);
            response_json([
                'status' => 'error',
                'message' => 'Unauthorized: ' . $e->getMessage()
            ], 401);
            exit;
        }

        $userId = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        $tokenVersion = filter_var($payload['token_version'] ?? 0, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1 || $tokenVersion === false || $tokenVersion < 0) {
            response_json([
                'status' => 'error',
                'message' => 'Unauthorized: payload token không hợp lệ'
            ], 401);
        }

        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, DB_OPTIONS);
        $userStatement = $pdo->prepare(
            'SELECT `is_active`, `token_version`, `role` FROM `users` WHERE `id` = :user_id LIMIT 1'
        );
        $userStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $userStatement->execute();
        $user = $userStatement->fetch(PDO::FETCH_ASSOC);

        if ($user === false
            || (int) $user['is_active'] !== 1
            || (int) $user['token_version'] !== $tokenVersion
            || ($payload['role'] ?? '') !== $user['role']) {
            response_json([
                'status' => 'error',
                'message' => 'Unauthorized: token đã bị thu hồi hoặc tài khoản không hoạt động'
            ], 401);
        }

        return $payload;
    }
}

/**
 * 9. Hiển thị alert và redirect người dùng về một route nhất định.
 *
 * Dùng cho các flow cũ kiểu web MVC, khi muốn thông báo lỗi/success rồi điều
 * hướng người dùng. Hiện nay ít dùng trong API REST, nhưng vẫn tồn tại để tương
 * thích với code cũ.
 *
 * @param mixed $mess Nội dung thông báo
 * @return void
 */
if (!function_exists('throwMessageAndBack')) {
    function throwMessageAndBack($mess) {
        $safeMess = json_encode($mess);
        $safeUrl = json_encode(BASE_URL . '?action=products');
        echo "<script>alert({$safeMess}); window.location.href = {$safeUrl};</script>";
        exit;
    }
}

/**
 * Trả về phản hồi dạng JSON theo chuẩn HTTP.
 *
 * Hàm này sẽ xóa output buffer nếu có, thiết lập mã trạng thái HTTP,
 * đặt header JSON và dừng request ngay sau khi in dữ liệu ra.
 *
 * @param mixed $data Dữ liệu cần trả về (mảng, object, string,...)
 * @param int $status Mã trạng thái HTTP, mặc định là 200
 * @param int $flags Cờ json_encode hỗ trợ UTF-8 và không escape dấu gạch chéo
 * @return void
 */
if (!function_exists('response_json')) {
    /**
     * Trả về phản hồi dạng JSON kèm HTTP Status Code
     *
     * @param mixed $data Dữ liệu cần trả về (mảng hoặc object)
     * @param int $status Mã trạng thái HTTP (mặc định 200)
     * @param int $flags Cờ cấu hình cho json_encode (mặc định hỗ trợ tiếng Việt và không escape dấu gạch chéo)
     * @return void
     */
    function response_json($data, int $status = 200, int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): void
    {
        // Xóa các bộ đệm đầu ra nếu có để tránh lỗi rác HTML
        if (ob_get_level()) {
            ob_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        echo json_encode($data, $flags);
        exit;
    }
}

