<?php

/**
 * Ghi log cho MỌI API (tầng extension): mỗi request một khối gồm ngày giờ, route, kết quả
 * (THÀNH CÔNG / LỖI), thời gian xử lý, dữ liệu FE gửi lên và dữ liệu BE trả về.
 *
 * Cách hoạt động: index.php gọi ApiLogger::start() ngay đầu luồng để bắt đầu ghi đệm đầu ra và đọc body,
 * rồi đăng ký ApiLogger::finish() chạy khi script kết thúc (kể cả khi response_json() gọi exit).
 * Log ghi vào BE/storage/logs/api-YYYY-MM-DD.log, mỗi ngày một file.
 *
 * Bảo mật: mật khẩu, token, khóa API, số điện thoại, email, địa chỉ được che trước khi ghi (xem SENSITIVE_KEYS).
 * Không bao giờ ghi header Authorization; chỉ ghi có hay không có.
 */
class ApiLogger
{
    // Các khóa luôn bị che giá trị, không phân biệt hoa thường (ở mọi tầng lồng nhau của JSON).
    const SECRET_KEYS = [
        'password', 'password_confirmation', 'new_password', 'current_password',
        'token', 'access_token', 'refresh_token', 'authorization', 'api_key', 'secret', 'jwt',
    ];
    // Thông tin cá nhân: SĐT hiện 3 số cuối, email hiện đầu và đuôi, địa chỉ che hoàn toàn.
    const PHONE_KEYS = ['phone', 'recipient_phone'];
    const EMAIL_KEYS = ['email'];
    const ADDRESS_KEYS = ['address', 'shipping_address'];

    // Giới hạn độ dài mỗi phần để một request lớn không làm file log quá to.
    const MAX_BODY_CHARS = 4000;

    /** @var float Thời điểm bắt đầu xử lý request (giây, có phần thập phân) */
    private static $startedAt = 0.0;
    /** @var string Nội dung body FE gửi lên (đã đọc, chưa che) */
    private static $requestBody = '';
    /** @var array Thông tin request: method, route, query, có token hay không, content-type */
    private static $request = [];
    /** @var array Ghi chú do code nghiệp vụ gắn thêm trong request này: [['level' => 'INFO'|'WARN', 'message' => ...]] */
    private static $notes = [];

    /**
     * Gắn thêm một ghi chú vào khối log của request hiện tại.
     * Dùng cho chuyện xảy ra bên trong mà response không thể hiện, ví dụ dịch vụ AI lỗi
     * nhưng API vẫn trả 201 vì bình luận đã được lưu ở trạng thái chờ duyệt.
     * Ghi chú WARN nâng mức của cả khối log từ INFO lên WARN để dễ lọc.
     * Không đưa mật khẩu, token, key hay URL dịch vụ vào message.
     *
     * @param string $message Nội dung ghi chú
     * @param string $level   'INFO' hoặc 'WARN' (giá trị khác được coi là INFO)
     * @return void
     */
    public static function note(string $message = '', string $level = 'INFO'): void
    {
        if (trim($message) === '') {
            return;
        }
        self::$notes[] = [
            'level' => $level === 'WARN' ? 'WARN' : 'INFO',
            'message' => self::truncate($message, 500),
        ];
    }

    /**
     * Bắt đầu ghi log: đo thời gian, lưu body và thông tin request, bật bộ đệm để đọc được response.
     * Gọi 1 lần, đầu index.php, sau khi đã xử lý yêu cầu OPTIONS (CORS preflight không cần log).
     *
     * @return void
     */
    public static function start(): void
    {
        self::$startedAt = microtime(true);
        // php://input đọc được nhiều lần, nên đọc ở đây không làm mất body cho controller.
        self::$requestBody = (string)file_get_contents('php://input');
        self::$request = [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'action' => $_GET['action'] ?? '',
            'query' => self::safeQuery($_GET),
            // Cùng cách đọc header với requireBearerToken() trong helper.php, để log khớp với việc xác thực thật.
            'has_token' => self::authorizationHeader() !== '',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        ];
        ob_start();
    }

    /**
     * Ghi khối log của request vừa xong. Được PHP gọi tự động khi script kết thúc.
     * Không tự xuất bộ đệm: PHP sẽ gửi response về trình duyệt sau khi hàm này chạy xong.
     *
     * @return void
     */
    public static function finish(): void
    {
        $status = http_response_code();
        $status = $status === false ? 200 : (int)$status;
        $responseBody = (string)ob_get_contents();
        $durationMs = (int)round((microtime(true) - self::$startedAt) * 1000);

        $isError = $status >= 400;
        $outcome = $isError ? 'LỖI' : 'THÀNH CÔNG';
        $level = $status >= 500 ? 'ERROR' : ($isError ? 'WARN' : 'INFO');
        // Request thành công nhưng có ghi chú cảnh báo (ví dụ dịch vụ AI lỗi) thì nâng lên WARN.
        if ($level === 'INFO' && in_array('WARN', array_column(self::$notes, 'level'), true)) {
            $level = 'WARN';
        }

        $lines = [];
        $lines[] = sprintf(
            '[%s] %s | %s %s | HTTP %d | %s | %d ms | %s',
            self::now()->format('Y-m-d H:i:s P'),
            $level,
            self::$request['method'],
            // Ghi rõ đây là API endpoint, ví dụ: POST API endpoint "api-ai-chat" (tên lấy từ ?action=)
            self::$request['action'] !== '' ? 'API endpoint "' . self::$request['action'] . '"' : '(không có action)',
            $status,
            $outcome,
            $durationMs,
            self::$request['has_token'] ? 'có token' : 'không token'
        );
        // Tham số trên URL (phần sau dấu ? của đường dẫn), không phải nội dung gửi lên trong body
        $lines[] = '  Tham số URL: ' . self::encode(self::$request['query']);
        $lines[] = '  FE gửi: ' . self::describeBody(self::$requestBody, self::$request['content_type']);
        $lines[] = '  BE trả: ' . self::describeBody($responseBody, 'application/json');
        if ($isError) {
            $lines[] = '  Thông báo: ' . self::errorMessage($responseBody);
        }
        foreach (self::$notes as $note) {
            $lines[] = '  Ghi chú (' . $note['level'] . '): ' . $note['message'];
        }

        self::write(implode("\n", $lines) . "\n\n");
    }

    /**
     * Đọc body để ghi: nếu là JSON thì che dữ liệu nhạy cảm rồi in gọn; không phải JSON thì ghi kích thước.
     *
     * @param string $body        Nội dung thô
     * @param string $contentType Kiểu nội dung, dùng để biết có nên thử đọc JSON không
     * @return string Chuỗi đã che và cắt bớt để ghi
     */
    private static function describeBody(string $body, string $contentType): string
    {
        if (trim($body) === '') {
            return '(trống)';
        }
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return self::truncate(self::encode(self::redact($decoded)));
        }
        // Không phải JSON (ví dụ form SePay, ảnh): không ghi nội dung, chỉ ghi loại và kích thước.
        return sprintf('(không phải JSON, %s, %d byte)', $contentType !== '' ? $contentType : 'không rõ kiểu', strlen($body));
    }

    /**
     * Lấy thông báo lỗi từ response JSON của BE (trường message), để khối log có ngay nguyên nhân.
     *
     * @param string $body Nội dung response
     * @return string Thông báo, hoặc '(không có)'
     */
    private static function errorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            return self::truncate($decoded['message'], 300);
        }
        return '(không có)';
    }

    /**
     * Che dữ liệu nhạy cảm ở mọi tầng lồng nhau của mảng.
     * Khóa không nằm trong danh sách thì giữ nguyên; giá trị không phải chuỗi cũng giữ nguyên.
     *
     * @param mixed $data Dữ liệu đã decode từ JSON
     * @return mixed Dữ liệu sau khi che
     */
    public static function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            $lower = is_string($key) ? strtolower($key) : '';
            if ($lower !== '' && in_array($lower, self::SECRET_KEYS, true)) {
                $out[$key] = '***';
            } elseif ($lower !== '' && in_array($lower, self::PHONE_KEYS, true) && is_string($value)) {
                $out[$key] = self::maskPhone($value);
            } elseif ($lower !== '' && in_array($lower, self::EMAIL_KEYS, true) && is_string($value)) {
                $out[$key] = self::maskEmail($value);
            } elseif ($lower !== '' && in_array($lower, self::ADDRESS_KEYS, true) && is_string($value)) {
                $out[$key] = $value === '' ? '' : '***';
            } elseif (is_string($value)) {
                // Chuỗi tự do (ví dụ tin nhắn chat): che mọi số điện thoại di động gõ trong nội dung.
                $out[$key] = self::maskPhonesInText($value);
            } else {
                $out[$key] = self::redact($value);
            }
        }
        return $out;
    }

    /**
     * Tìm số di động Việt Nam (10 số, đầu 03/05/07/08/09, có thể có khoảng trắng hoặc dấu chấm/gạch)
     * trong chuỗi tự do rồi che như SĐT, ví dụ "gọi 0912 345 678" -> "gọi ***678".
     */
    private static function maskPhonesInText(string $text): string
    {
        $masked = preg_replace_callback(
            '/(?<!\d)(?:\+?84|0)[\s.\-]?[35789](?:[\s.\-]?\d){8}(?!\d)/u',
            static function (array $m): string {
                $digits = preg_replace('/\D/', '', $m[0]);
                return '***' . substr($digits, -3);
            },
            $text
        );
        return $masked ?? $text;
    }

    /** SĐT: giữ 3 số cuối, ví dụ 0912345678 -> ***678 */
    private static function maskPhone(string $phone): string
    {
        return $phone === '' ? '' : '***' . substr($phone, -3);
    }

    /** Email: giữ ký tự đầu và tên miền, ví dụ hoangnam@gmail.com -> h***@gmail.com */
    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /**
     * Lấy giá trị header Authorization theo đúng thứ tự mà requireBearerToken() dùng.
     * Chỉ dùng để biết có token hay không; giá trị token không bao giờ được ghi vào log.
     *
     * @return string Header Authorization, hoặc chuỗi rỗng
     */
    private static function authorizationHeader(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        return (string)$header;
    }

    /** Query string: che các khóa nhạy cảm nếu có (ví dụ token trên URL). */
    private static function safeQuery(array $query): array
    {
        return self::redact($query);
    }

    /**
     * Thời điểm hiện tại theo múi giờ Việt Nam. Dùng riêng cho log, không đổi múi giờ của cả ứng dụng
     * (PHP mặc định là UTC, nên nếu không đặt ở đây thì log và tên file sẽ lệch 7 tiếng so với giờ thực tế).
     */
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    }

    /** Chuyển mảng thành JSON một dòng, giữ tiếng Việt đọc được. */
    private static function encode($data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '(không mã hóa được)' : $json;
    }

    /** Cắt chuỗi quá dài để file log không phình to. */
    private static function truncate(string $text, int $max = self::MAX_BODY_CHARS): string
    {
        return mb_strlen($text, 'UTF-8') > $max
            ? mb_substr($text, 0, $max, 'UTF-8') . ' ...(cắt bớt)'
            : $text;
    }

    /**
     * Ghi nội dung vào file log của ngày hôm nay. Lỗi ghi file không được làm hỏng request,
     * nên chỉ báo qua error_log của PHP.
     *
     * @param string $text Khối log đã định dạng
     * @return void
     */
    private static function write(string $text): void
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('[ApiLogger] Không tạo được thư mục log: ' . $dir);
            return;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'api-' . self::now()->format('Y-m-d') . '.log';
        if (@file_put_contents($file, $text, FILE_APPEND | LOCK_EX) === false) {
            error_log('[ApiLogger] Không ghi được log: ' . $file);
        }
    }
}
