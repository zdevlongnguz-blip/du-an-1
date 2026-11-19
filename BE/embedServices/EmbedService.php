<?php

/**
 * Service gọi server Python embed-service để biến câu thành vector, và tính độ giống nhau giữa các vector (tầng service).
 * Controller chỉ cần gọi embed() rồi topK(); việc gọi HTTP và tính toán được xử lý ở đây.
 */

/**
 * Embedding (vector nhúng): một mảng 768 số thực đại diện cho "ý nghĩa" của một câu.
 * Hai câu gần nghĩa thì hai vector "chỉ về cùng một hướng".
 *
 * Model chạy bằng Python nên PHP không tự tạo vector được. PHP gửi câu sang server Python
 * (thư mục embed-service/ ở gốc repo, mặc định http://127.0.0.1:8001), nhận lại vector.
 * Server Python phải được bật trước, nếu không embed() sẽ ném RuntimeException.
 */
class EmbedService
{
    // Số giây tối đa chờ server Python trả lời, tránh treo PHP nếu server bị đơ.
    const TIMEOUT_SECONDS = 15;

    // Số giây tối đa để kết nối tới server Python. Server chưa bật thì báo lỗi nhanh, không đợi lâu.
    const CONNECT_TIMEOUT_SECONDS = 3;

    // Số câu tối đa mỗi lần gọi, khớp với MAX_TEXTS bên embed-service/main.py.
    const MAX_TEXTS = 64;

    // Địa chỉ gốc của server Python, ví dụ http://127.0.0.1:8001 (không có dấu / ở cuối).
    private $baseUrl;

    /**
     * Tạo service với địa chỉ server Python.
     *
     * @param string|null $baseUrl Địa chỉ server (null = lấy EMBED_SERVICE_URL trong config.php)
     */
    public function __construct($baseUrl = null)
    {
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            $baseUrl = defined('EMBED_SERVICE_URL') ? (string) EMBED_SERVICE_URL : '';
        }

        $this->baseUrl = rtrim(trim($baseUrl), '/');
    }

    /**
     * Biến danh sách câu thành danh sách vector, giữ đúng thứ tự: câu thứ i có vector thứ i.
     *
     * @param array $texts Mảng câu (chuỗi khác rỗng), tối đa MAX_TEXTS câu
     * @return array Mảng vector, mỗi vector là mảng số thực
     * @throws InvalidArgumentException Khi $texts rỗng, quá nhiều câu hoặc có phần tử không phải chuỗi
     * @throws RuntimeException Khi chưa cấu hình địa chỉ, server Python chưa bật hoặc trả dữ liệu sai
     */
    public function embed($texts = [])
    {
        if (!is_array($texts) || count($texts) === 0 || count($texts) > self::MAX_TEXTS) {
            throw new InvalidArgumentException('texts phải là mảng có từ 1 đến ' . self::MAX_TEXTS . ' câu.');
        }

        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '') {
                throw new InvalidArgumentException('Mỗi phần tử trong texts phải là chuỗi khác rỗng.');
            }
        }

        if ($this->baseUrl === '') {
            throw new RuntimeException('Thiếu EMBED_SERVICE_URL trong config.php.');
        }

        // array_values: đánh lại chỉ số 0, 1, 2... để json_encode ra mảng JSON [...] chứ không phải object {...}.
        $body = json_encode(['texts' => array_values($texts)], JSON_UNESCAPED_UNICODE);
        $data = $this->sendRequest($this->baseUrl . '/embed', $body);

        // Kiểm tra server trả đúng số vector như số câu đã gửi, tránh lệch thứ tự khi ghép kết quả.
        if (!isset($data['vectors']) || !is_array($data['vectors']) || count($data['vectors']) !== count($texts)) {
            throw new RuntimeException('embed-service trả về dữ liệu vector không hợp lệ.');
        }

        return $data['vectors'];
    }

    /**
     * Hỏi server Python còn sống không và đang dùng model nào.
     *
     * @return array ['status' => 'ok', 'model' => tên model, 'dim' => số chiều vector]
     * @throws RuntimeException Khi chưa cấu hình địa chỉ, server chưa bật hoặc trả dữ liệu sai
     */
    public function health()
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('Thiếu EMBED_SERVICE_URL trong config.php.');
        }

        $data = $this->sendRequest($this->baseUrl . '/health', null);
        if (!isset($data['model']) || !is_string($data['model']) || $data['model'] === '') {
            throw new RuntimeException('embed-service trả về thông tin model không hợp lệ.');
        }

        return $data;
    }

    /**
     * Tính cosine similarity (độ giống nhau theo góc) giữa hai vector.
     *
     * Công thức: cos = (a·b) / (|a| * |b|)
     * - a·b (tích vô hướng): cộng dồn a[i] * b[i].
     * - |a| (độ dài vector): căn bậc hai của tổng a[i]^2.
     * Kết quả từ -1 đến 1: càng gần 1 thì hai câu càng giống nghĩa. Chia cho độ dài để
     * chỉ so "hướng" của vector, câu dài hay ngắn không làm điểm cao hơn.
     *
     * @param array $a Vector thứ nhất
     * @param array $b Vector thứ hai, cùng số chiều với $a
     * @return float Điểm từ -1 đến 1 (trả 0.0 nếu vector rỗng, khác số chiều hoặc toàn số 0)
     */
    public static function cosineSimilarity($a = [], $b = [])
    {
        if (!is_array($a) || !is_array($b) || count($a) === 0 || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        // Một vòng lặp tính luôn cả 3 tổng để không phải duyệt mảng 768 phần tử ba lần.
        foreach ($a as $i => $valueA) {
            $valueA = (float) $valueA;
            $valueB = (float) ($b[$i] ?? 0);
            $dot += $valueA * $valueB;
            $normA += $valueA * $valueA;
            $normB += $valueB * $valueB;
        }

        // Vector toàn số 0 không có "hướng", chia cho 0 sẽ lỗi nên coi như không giống.
        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Tìm K phần tử giống vector câu hỏi nhất, xếp từ điểm cao xuống thấp.
     *
     * Cách làm "vét cạn": tính cosine với TỪNG phần tử rồi sắp xếp. Đủ nhanh với vài nghìn
     * phần tử; dữ liệu lớn hơn nhiều thì mới cần cơ sở dữ liệu vector chuyên dụng.
     *
     * @param array $queryVector Vector của câu hỏi
     * @param array $items       Mảng phần tử, mỗi phần tử có khóa 'vector' (các khóa khác giữ nguyên)
     * @param int   $k           Số kết quả muốn lấy, tối thiểu 1
     * @return array Tối đa $k phần tử, đã bỏ khóa 'vector' và thêm khóa 'score' (làm tròn 4 chữ số)
     */
    public static function topK($queryVector = [], $items = [], $k = 5)
    {
        if (!is_array($queryVector) || !is_array($items)) {
            return [];
        }

        $k = max(1, (int) $k);
        $scored = [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['vector'])) {
                continue;
            }

            $score = self::cosineSimilarity($queryVector, $item['vector']);
            // Bỏ vector (768 số) khỏi kết quả cho gọn response, chỉ giữ thông tin cần hiển thị.
            unset($item['vector']);
            $item['score'] = round($score, 4);
            $scored[] = $item;
        }

        // usort với hàm so sánh: trả số dương nghĩa là $y đứng trước $x, nên điểm cao lên đầu.
        // Toán tử <=> (spaceship) trả -1, 0 hoặc 1 tùy so sánh.
        usort($scored, static fn($x, $y) => $y['score'] <=> $x['score']);

        return array_slice($scored, 0, $k);
    }

    /**
     * Gửi request tới server Python và giải mã JSON trả về.
     * Có $body thì gửi POST JSON, $body = null thì gửi GET.
     *
     * @param string      $url  Địa chỉ đầy đủ của endpoint
     * @param string|null $body Chuỗi JSON gửi đi (null = GET, không có body)
     * @return array Dữ liệu JSON đã giải mã thành mảng
     * @throws RuntimeException Khi không kết nối được, HTTP khác 200 hoặc JSON hỏng
     */
    protected function sendRequest($url = '', $body = null)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            // Trả kết quả về biến thay vì in thẳng ra màn hình.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);
        if ($body !== null) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            ]);
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Không kết nối được: thường do chưa bật server Python. Không đưa chi tiết cURL ra message
        // để controller có thể hiện thẳng cho người dùng mà không lộ thông tin máy chủ.
        if ($raw === false || $curlError !== '') {
            throw new RuntimeException('Không kết nối được embed-service, kiểm tra server Python đã bật chưa.');
        }

        if ($httpCode !== 200) {
            throw new RuntimeException('embed-service trả về lỗi HTTP ' . $httpCode . '.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('embed-service trả về JSON không hợp lệ.');
        }

        return $data;
    }
}
