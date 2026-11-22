<?php

/**
 * Service gọi dịch vụ kiểm duyệt bình luận bằng AI (Comment Guard, chạy trên Google Colab).
 * Controller chỉ cần gọi analyze() hoặc analyzeBatch(); việc gửi HTTP và kiểm tra kết quả nằm ở đây.
 */

/**
 * Dịch vụ bên ngoài có 2 endpoint:
 * - POST /analyze        body { text }    -> 1 kết quả
 * - POST /analyze-batch  body { texts }   -> { results: [kết quả, ...] }
 *
 * Mỗi kết quả có dạng:
 * {
 *   text, status: 'VISIBLE'|'HIDDEN', reason: 'CLEAN'|'HATE'|...,
 *   toxic_prob: 0..1 (xác suất độc hại), needs_review: true|false (AI không chắc, nên để admin xem),
 *   sentiment: 'POS'|'NEG'|... hoặc null, sentiment_score: 0..1 hoặc null
 * }
 */
class CommentGuard
{
    // Số bình luận tối đa gửi trong một lần chấm chùm, tránh request quá lớn làm Colab chạy lâu.
    const MAX_BATCH = 50;

    /** @var string Địa chỉ gốc của dịch vụ, không có dấu / ở cuối */
    private $baseUrl;

    /** @var string API key gửi qua header x-api-key (rỗng = không gửi) */
    private $apiKey;

    /** @var int Số giây tối đa chờ một request */
    private $timeout;

    /**
     * Khởi tạo service. Không truyền gì thì lấy cấu hình từ BE/.env (qua config.php).
     *
     * @param string|null $baseUrl Địa chỉ gốc riêng (null = COMMENT_GUARD_URL)
     * @param string|null $apiKey  Key riêng (null = COMMENT_GUARD_KEY)
     * @param int|null    $timeout Số giây chờ riêng (null = COMMENT_GUARD_TIMEOUT)
     */
    public function __construct($baseUrl = null, $apiKey = null, $timeout = null)
    {
        $this->baseUrl = rtrim(is_string($baseUrl) ? trim($baseUrl) : COMMENT_GUARD_URL, '/');
        $this->apiKey = is_string($apiKey) ? trim($apiKey) : COMMENT_GUARD_KEY;
        $this->timeout = (is_int($timeout) && $timeout > 0) ? $timeout : COMMENT_GUARD_TIMEOUT;
    }

    /**
     * Chấm một bình luận.
     *
     * @param string $text Nội dung bình luận (không rỗng)
     * @return array Kết quả đã chuẩn hóa (xem normalizeResult)
     * @throws InvalidArgumentException Khi text rỗng
     * @throws RuntimeException Khi chưa cấu hình URL, lỗi mạng hoặc dịch vụ trả dữ liệu sai
     */
    public function analyze($text = '')
    {
        if (!is_string($text) || trim($text) === '') {
            throw new InvalidArgumentException('Nội dung cần kiểm duyệt không được rỗng.');
        }

        $data = $this->post('/analyze', ['text' => $text]);

        return $this->normalizeResult($data);
    }

    /**
     * Chấm nhiều bình luận trong một lần gọi (nhanh hơn gọi analyze() nhiều lần).
     *
     * @param string[] $texts Danh sách nội dung, 1 đến MAX_BATCH phần tử, mỗi phần tử không rỗng
     * @return array Danh sách kết quả theo đúng thứ tự $texts
     * @throws InvalidArgumentException Khi danh sách rỗng, quá dài hoặc có phần tử rỗng
     * @throws RuntimeException Khi chưa cấu hình URL, lỗi mạng hoặc dịch vụ trả dữ liệu sai
     */
    public function analyzeBatch($texts = [])
    {
        if (!is_array($texts) || count($texts) === 0 || count($texts) > self::MAX_BATCH) {
            throw new InvalidArgumentException('Danh sách cần kiểm duyệt phải có từ 1 đến ' . self::MAX_BATCH . ' phần tử.');
        }
        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '') {
                throw new InvalidArgumentException('Mỗi nội dung cần kiểm duyệt phải là chuỗi không rỗng.');
            }
        }

        // array_values: bỏ key lạ (ví dụ ["a" => ...]) để JSON gửi đi luôn là mảng [...], không phải object {...}.
        $data = $this->post('/analyze-batch', ['texts' => array_values($texts)]);

        if (!isset($data['results']) || !is_array($data['results']) || count($data['results']) !== count($texts)) {
            throw new RuntimeException('Dịch vụ kiểm duyệt trả về số kết quả không khớp số bình luận gửi đi.');
        }

        return array_map([$this, 'normalizeResult'], $data['results']);
    }

    /**
     * Gửi request POST JSON tới dịch vụ bằng cURL.
     *
     * cURL: thư viện có sẵn trong PHP để server gửi request HTTP tới server khác.
     * Để protected để khi thử nghiệm có thể viết class con giả lập (không gọi mạng thật).
     *
     * @param string $path Đường dẫn endpoint, ví dụ '/analyze'
     * @param array  $body Dữ liệu gửi đi, sẽ được mã hóa JSON
     * @return array Dữ liệu JSON dịch vụ trả về, đã giải mã thành mảng
     * @throws RuntimeException Khi chưa cấu hình URL, lỗi mạng, HTTP khác 200 hoặc không phải JSON
     */
    protected function post($path = '', $body = [])
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('Chưa cấu hình COMMENT_GUARD_URL trong BE/.env.');
        }

        // JSON_UNESCAPED_UNICODE: giữ nguyên chữ tiếng Việt có dấu thay vì đổi thành \uXXXX.
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Không mã hóa được nội dung sang JSON (có thể sai bảng mã UTF-8).');
        }

        $headers = ['Content-Type: application/json; charset=utf-8'];
        // Chỉ gửi key khi có cấu hình. Gửi qua header thay vì query string để key không lọt vào log URL.
        if ($this->apiKey !== '') {
            $headers[] = 'x-api-key: ' . $this->apiKey;
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            // RETURNTRANSFER: lấy response thành chuỗi thay vì in thẳng ra màn hình.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Không đưa URL hay key vào message lỗi: message có thể bị log hoặc trả ra cho client.
        if ($raw === false) {
            throw new RuntimeException('Không kết nối được dịch vụ kiểm duyệt: ' . $curlError);
        }
        if ($httpCode !== 200) {
            throw new RuntimeException('Dịch vụ kiểm duyệt trả về lỗi HTTP ' . $httpCode . '.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Dịch vụ kiểm duyệt trả về dữ liệu không phải JSON.');
        }

        return $data;
    }

    /**
     * Chuẩn hóa một kết quả: ép đúng kiểu, thiếu trường thì báo lỗi.
     * Vì sao cần: dữ liệu đến từ dịch vụ bên ngoài, không được tin là luôn đúng định dạng.
     *
     * @param mixed $item Một kết quả dịch vụ trả về
     * @return array { text, status, reason, toxic_prob, needs_review, sentiment, sentiment_score }
     * @throws RuntimeException Khi thiếu status/toxic_prob hoặc sai kiểu
     */
    private function normalizeResult($item = null)
    {
        if (!is_array($item) || !isset($item['status']) || !is_string($item['status'])
            || !isset($item['toxic_prob']) || !is_numeric($item['toxic_prob'])) {
            throw new RuntimeException('Dịch vụ kiểm duyệt trả về kết quả sai định dạng.');
        }

        return [
            'text' => isset($item['text']) && is_string($item['text']) ? $item['text'] : '',
            'status' => $item['status'],
            'reason' => isset($item['reason']) && is_string($item['reason']) ? $item['reason'] : null,
            'toxic_prob' => (float) $item['toxic_prob'],
            'needs_review' => !empty($item['needs_review']),
            'sentiment' => isset($item['sentiment']) && is_string($item['sentiment']) ? $item['sentiment'] : null,
            'sentiment_score' => isset($item['sentiment_score']) && is_numeric($item['sentiment_score'])
                ? (float) $item['sentiment_score']
                : null,
        ];
    }
}
