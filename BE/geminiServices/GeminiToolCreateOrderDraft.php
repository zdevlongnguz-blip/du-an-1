<?php

/**
 * Tool "create_order_draft": cho Gemini lên ĐƠN NHÁP giúp khách (tầng service).
 * Tool KHÔNG tạo đơn thật. Nó chỉ tra giá, tồn kho của các món rồi gửi đơn nháp cho FE mở trang /ai-checkout;
 * khách tự kiểm tra và bấm "Xác nhận đặt hàng" thì FE mới gọi API api-order-checkout-items để tạo đơn.
 */

/**
 * Vì sao chỉ lên đơn nháp: AI có thể hiểu sai (nhầm phiên bản, số lượng) hoặc bị khách gài câu lệnh,
 * mà tạo đơn là trừ tồn kho ngay. Để khách bấm xác nhận thì mọi đơn đều do chính khách quyết định.
 *
 * Tool CHỈ lo món hàng. Thông tin người nhận (tên, SĐT, địa chỉ) tách hẳn khỏi AI và tool:
 * FE tự lấy từ hồ sơ qua api-profile (hoặc giữ thông tin khách đã sửa trên trang) để điền form.
 * Nhờ vậy luồng AI không bao giờ đụng tới thông tin cá nhân.
 *
 * Thiết kế LỆNH (command): AI không gửi "đơn mới trông thế nào" mà gửi "khách muốn đổi gì",
 * ví dụ [{op: add, sku: X, quantity: 5}]. Server lấy đơn nháp khách đang thấy trên trang làm gốc
 * rồi áp từng lệnh. Vì sao: nếu bắt AI tự dựng lại cả đơn, AI hay lấy nhầm số cũ trong lịch sử chat
 * (trang 5 hộp, khách nói "thêm 5", AI gửi 6 = 1 cũ + 5) hoặc quên món khách không nhắc tới.
 * AI giỏi hiểu ý câu nói; còn cộng trừ và giữ trạng thái là việc server làm chính xác hơn.
 */
class GeminiToolCreateOrderDraft extends GeminiTool
{
    // Số lệnh tối đa trong một lần gọi, chặn AI gửi danh sách quá dài.
    const MAX_OPS = 20;

    // Các loại lệnh hợp lệ (allowlist). Lệnh khác bị từ chối.
    const OPS = ['add', 'set', 'subtract', 'remove'];

    /**
     * Đơn LÀM VIỆC của lượt chat hiện tại: null = chưa có lần gọi nào thành công, dùng đơn trên trang làm gốc.
     *
     * Vì sao cần: trong một lượt, Gemini có thể gọi tool nhiều lần (gọi song song "thêm B", "thêm C",
     * hoặc gọi lại sau khi nhận lỗi). Nếu lần nào cũng tính lại từ đơn trên trang thì lần sau xóa mất
     * kết quả lần trước (chỉ đơn của lần cuối được gửi cho FE). Nên mỗi lần gọi THÀNH CÔNG thì ghi nhận
     * vào đây, lần sau áp lệnh tiếp lên đơn này. Gọi lỗi thì không ghi nhận gì.
     *
     * An toàn: ChatController tạo tool mới cho mỗi request, nên đơn này chỉ sống trong một lượt của một khách.
     *
     * @var array|null Danh sách ['sku', 'name', 'quantity'] cùng dạng với $context['checkout_draft']
     */
    private $workingDraft = null;

    // Đã báo AI về các món trên trang bị loại (ngừng bán) chưa. Chỉ báo một lần mỗi lượt.
    private $droppedReported = false;

    /**
     * Tên tool. Gemini dùng tên này khi yêu cầu gọi.
     * Giữ tên cũ để lịch sử chat và tài liệu không phải đổi theo.
     *
     * @return string
     */
    public function name()
    {
        return 'create_order_draft';
    }

    /**
     * Mô tả để Gemini biết lúc nào nên gọi tool và gửi lệnh gì.
     *
     * @return string
     */
    public function description()
    {
        return 'Lên đơn nháp hoặc sửa đơn nháp đang mở, khi khách muốn mua, đặt hàng, thêm/bớt/đổi/bỏ món. '
            . 'Gửi danh sách LỆNH mô tả thay đổi khách vừa yêu cầu, KHÔNG gửi lại cả đơn và KHÔNG tự cộng trừ: '
            . 'add = mua/thêm N (website cộng vào số đang có), set = đổi thành đúng N, subtract = bớt N, remove = bỏ món. '
            . 'Món khách không nhắc tới thì không cần gửi, website tự giữ nguyên. '
            . 'Cần SKU của từng phiên bản (lấy từ search_product hoặc đơn nháp đang mở). '
            . 'Thông tin nhận hàng do website tự điền, AI không được hỏi trong chat. '
            . 'Tool KHÔNG tạo đơn thật: website mở trang để khách kiểm tra và tự bấm xác nhận.';
    }

    /**
     * Khai báo tham số theo JSON Schema: một danh sách lệnh.
     *
     * Cố ý KHÔNG có tham số tên, SĐT, địa chỉ, user: AI không có đường chuyển thông tin cá nhân vào đơn
     * và không chọn được khách khác.
     *
     * @return array
     */
    public function parameters()
    {
        return [
            'type' => 'object',
            'properties' => [
                'ops' => [
                    'type' => 'array',
                    'description' => 'Các thay đổi khách vừa yêu cầu, theo đúng thứ tự khách nói',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'op' => [
                                'type' => 'string',
                                'enum' => self::OPS,
                                'description' => 'add: mua/thêm N. set: đổi thành đúng N. subtract: bớt N. remove: bỏ món',
                            ],
                            'sku' => ['type' => 'string', 'description' => 'SKU của phiên bản'],
                            'quantity' => [
                                'type' => 'integer',
                                'description' => 'Số khách nói (N). add không nói số thì 1. remove không cần',
                            ],
                        ],
                        'required' => ['op', 'sku'],
                    ],
                ],
            ],
            'required' => ['ops'],
        ];
    }

    /**
     * Áp các lệnh của AI vào đơn nháp trên trang, kiểm tra món hàng (SKU, đang bán, đủ tồn kho) rồi trả đơn nháp mới.
     *
     * Hai kết quả có thể có:
     * - Món hàng có vấn đề (sai SKU, hết hàng, không đủ số lượng): ready=false + problems, không mở trang.
     * - Món hàng ổn: ready=true + tóm tắt đơn cho AI, kèm _action 'checkout-draft' (chỉ có món và tổng tiền) cho FE.
     *
     * @param array $args    Tham số Gemini gửi lên: ops
     * @param array $context Ngữ cảnh server. 'user_id' (lấy từ JWT, không lấy từ AI) để chắc chắn tool chỉ chạy
     *                       cho người đã đăng nhập. 'checkout_draft' (không bắt buộc): các món ['sku', 'name', 'quantity']
     *                       khách đang thấy trên trang, ChatController đã kiểm tra với DB; không có thì gốc là đơn rỗng.
     *                       'checkout_draft_dropped' (không bắt buộc): số món trên trang bị loại vì ngừng bán/không tìm thấy
     * @return array Kết quả cho Gemini (và _action cho FE khi đơn đã sẵn sàng)
     * @throws InvalidArgumentException Khi lệnh sai, bớt quá số đang có, đơn rỗng hoặc vượt giới hạn
     * @throws RuntimeException Khi thiếu user_id trong context (lỗi lập trình, không phải lỗi của khách)
     */
    public function execute($args = [], $context = [])
    {
        $args = is_array($args) ? $args : [];
        // User ID chỉ lấy từ $context do ChatController truyền xuống (claim sub của JWT), không bao giờ lấy từ AI.
        $userId = (int)($context['user_id'] ?? 0);
        if ($userId < 1) {
            throw new RuntimeException('Thiếu user_id trong context của tool create_order_draft.');
        }

        // Gốc để áp lệnh: đơn làm việc của lượt này nếu đã có lần gọi thành công,
        // ngược lại là đơn nháp khách đang thấy trên trang (rỗng nếu chưa có).
        $baseDraft = $this->workingDraft;
        if ($baseDraft === null) {
            $baseDraft = is_array($context['checkout_draft'] ?? null) ? $context['checkout_draft'] : [];
        }
        $applied = $this->applyOps($this->readOps($args['ops'] ?? null), $baseDraft);
        $items = $applied['items'];

        // Tra giá và tồn kho thật từ DB. Map theo SKU viết thường vì cột sku không phân biệt hoa thường.
        $variants = [];
        foreach ((new Product())->getActiveVariantsBySkus(array_column($items, 'sku')) as $row) {
            $variants[mb_strtolower((string)$row['sku'], 'UTF-8')] = $row;
        }

        $problems = [];
        $draftItems = [];
        $totalCents = 0;
        foreach ($items as $item) {
            $variant = $variants[mb_strtolower($item['sku'], 'UTF-8')] ?? null;
            if ($variant === null) {
                $problems[] = ['sku' => $item['sku'], 'reason' => 'Không tìm thấy hoặc đã ngừng bán'];
                continue;
            }
            $stock = (int)$variant['stock'];
            if ($stock < $item['quantity']) {
                $problems[] = [
                    'sku' => $variant['sku'],
                    'name' => $variant['product_name'] . ' - ' . $variant['variant_name'],
                    'reason' => $stock < 1 ? 'Hết hàng' : 'Không đủ số lượng, chỉ còn ' . $stock,
                ];
                continue;
            }

            $price = (float)$variant['final_price'];
            $draftItems[] = [
                'sku' => $variant['sku'],
                'name' => $variant['product_name'],
                'variant_name' => $variant['variant_name'],
                'image' => $variant['image'],
                'quantity' => $item['quantity'],
                'price' => $price,
                // Giá niêm yết chỉ gửi khi đang giảm giá, để FE gạch giá cũ.
                'original_price' => $variant['sale_price'] !== null ? (float)$variant['price'] : null,
                // Tồn kho lúc lên đơn: FE dùng làm giới hạn khi khách tăng số lượng. Lúc xác nhận BE kiểm tra lại.
                'stock' => min($stock, Order::MAX_ITEM_QUANTITY),
            ];
            // Cộng theo đơn vị xu (số nguyên) để không bị sai số khi cộng số thập phân.
            $totalCents += (int)round($price * 100) * $item['quantity'];
        }

        if ($problems !== []) {
            return [
                'ready' => false,
                'problems' => $problems,
                'message' => 'Chưa lên được đơn nháp vì có món gặp vấn đề. Báo khách từng món và gợi ý chọn phiên bản khác hoặc giảm số lượng.',
            ];
        }

        $totalAmount = $totalCents / 100;

        // Phần gửi cho AI: chỉ tóm tắt món hàng và tổng tiền (đã là số cuối cùng, AI đọc lại cho khách).
        $summary = [];
        foreach ($draftItems as $draftItem) {
            $summary[] = [
                'name' => $draftItem['name'] . ' - ' . $draftItem['variant_name'],
                'quantity' => $draftItem['quantity'],
                'line_total' => $draftItem['price'] * $draftItem['quantity'],
            ];
        }

        // Ghi nhận đơn làm việc: lần gọi tool sau trong cùng lượt sẽ áp lệnh tiếp lên đơn này.
        // Chỉ ghi nhận khi lên đơn thành công (đã qua kiểm tra kho), lỗi thì giữ nguyên gốc cũ.
        $this->workingDraft = [];
        foreach ($draftItems as $draftItem) {
            $this->workingDraft[] = [
                'sku' => $draftItem['sku'],
                'name' => $draftItem['name'] . ' - ' . $draftItem['variant_name'],
                'quantity' => $draftItem['quantity'],
            ];
        }

        // Món trên trang bị loại (ngừng bán hoặc không tìm thấy) thì không có trong đơn mới.
        // Không để món biến mất âm thầm: báo AI để AI nói lại với khách. Chỉ báo SỐ LƯỢNG món,
        // không chép SKU do client gửi vào kết quả (tránh khách chèn câu lệnh qua trường sku).
        $dropped = (int)($context['checkout_draft_dropped'] ?? 0);
        if ($dropped > 0 && !$this->droppedReported) {
            array_unshift($applied['notes'], $dropped . ' món trong đơn nháp trên trang đã ngừng bán hoặc không còn tìm thấy, '
                . 'nên không có trong đơn mới. Báo khách biết và gợi ý chọn sản phẩm khác.');
            $this->droppedReported = true;
        }

        $result = [
            'ready' => true,
            'items' => $summary,
            'total_amount' => $totalAmount,
            'message' => 'Đơn nháp đã mở trên website với ĐÚNG các món và số lượng trong items (báo khách theo số này). '
                . 'Website tự điền thông tin nhận hàng từ hồ sơ của khách; khách kiểm tra, điền ô còn thiếu nếu có, '
                . 'có thể chỉnh số lượng, chọn cách thanh toán rồi tự bấm "Xác nhận đặt hàng". '
                . 'Đơn CHƯA được tạo. Không hỏi thông tin cá nhân trong chat.',
        ];
        if ($applied['notes'] !== []) {
            // Ghi chú cho AI: món bị loại vì ngừng bán, lệnh bị bỏ qua (bỏ món không có trong đơn)...
            $result['notes'] = $applied['notes'];
        }
        $result[Gemini::ACTION_KEY] = [
            'type' => 'checkout-draft',
            // Chỉ có món và tổng tiền. Người nhận do FE tự lấy (api-profile) hoặc giữ từ trang.
            'draft' => [
                'items' => $draftItems,
                'total_amount' => $totalAmount,
            ],
        ];

        return $result;
    }

    /**
     * Kiểm tra danh sách lệnh AI gửi lên: đúng kiểu, loại lệnh trong allowlist, SKU và số lượng hợp lệ.
     * Chỉ kiểm tra từng lệnh; việc áp lệnh vào đơn do applyOps làm.
     *
     * @param mixed $rawOps Giá trị 'ops' từ AI
     * @return array Danh sách ['op' => string, 'sku' => string, 'quantity' => int] (remove có quantity = 0)
     * @throws InvalidArgumentException Khi dữ liệu sai
     */
    private function readOps($rawOps = null)
    {
        if (!is_array($rawOps) || $rawOps === []) {
            throw new InvalidArgumentException('Thiếu danh sách lệnh (ops). Mỗi lệnh gồm op (add, set, subtract, remove), sku, quantity.');
        }
        if (count($rawOps) > self::MAX_OPS) {
            throw new InvalidArgumentException('Tối đa ' . self::MAX_OPS . ' lệnh mỗi lần.');
        }

        $ops = [];
        foreach ($rawOps as $rawOp) {
            $rawOp = is_array($rawOp) ? $rawOp : [];

            $op = $rawOp['op'] ?? null;
            if (!in_array($op, self::OPS, true)) {
                throw new InvalidArgumentException('op chỉ nhận add, set, subtract hoặc remove.');
            }

            $sku = is_string($rawOp['sku'] ?? null) ? trim($rawOp['sku']) : '';
            if ($sku === '' || strlen($sku) > 100) {
                throw new InvalidArgumentException('SKU không hợp lệ. Dùng đúng SKU trong kết quả search_product hoặc trong đơn nháp đang mở.');
            }

            $quantity = 0;
            if ($op !== 'remove') {
                // "mua Claymation" không nói số thì hiểu là 1. set/subtract bắt buộc có số.
                $rawQuantity = $rawOp['quantity'] ?? ($op === 'add' ? 1 : null);
                // Gemini đôi khi gửi số dạng 2.0 (float); chỉ nhận khi là số nguyên đúng nghĩa.
                $quantity = is_int($rawQuantity) ? $rawQuantity
                    : (is_float($rawQuantity) && floor($rawQuantity) === $rawQuantity ? (int)$rawQuantity : 0);
                if ($quantity < 1 || $quantity > Order::MAX_ITEM_QUANTITY) {
                    throw new InvalidArgumentException('Lệnh ' . $op . ' cần quantity là số nguyên từ 1 đến ' . Order::MAX_ITEM_QUANTITY . '.');
                }
            }

            $ops[] = ['op' => $op, 'sku' => $sku, 'quantity' => $quantity];
        }

        return $ops;
    }

    /**
     * Áp lần lượt từng lệnh vào đơn nháp trên trang, ra danh sách món cuối cùng.
     *
     * Ví dụ trang có [Claymation 5, Vinh Râu 3]:
     * - [add Claymation 5]                 → Claymation 10, Vinh Râu 3 (món không nhắc tới giữ nguyên)
     * - [remove Vinh Râu, set Claymation 2] → Claymation 2
     *
     * @param array $ops       Kết quả readOps
     * @param array $pageDraft Các món trên trang ['sku', 'name', 'quantity'] (rỗng nếu chưa có đơn nháp)
     * @return array ['items' => [['sku', 'quantity']], 'notes' => [chuỗi ghi chú cho AI]]
     * @throws InvalidArgumentException Khi bớt quá số đang có, đơn không còn món, quá số món hoặc quá 99 mỗi món
     */
    private function applyOps($ops = [], $pageDraft = [])
    {
        // Đơn đang làm việc, key là SKU viết thường (cột sku không phân biệt hoa thường).
        // Mảng PHP giữ thứ tự thêm vào, nên món cũ giữ chỗ cũ, món mới nằm cuối.
        $draft = [];
        foreach ($pageDraft as $line) {
            if (is_array($line) && is_string($line['sku'] ?? null) && (int)($line['quantity'] ?? 0) > 0) {
                $draft[mb_strtolower($line['sku'], 'UTF-8')] = ['sku' => $line['sku'], 'quantity' => (int)$line['quantity']];
            }
        }

        $notes = [];
        // Thứ tự quan trọng: áp đúng thứ tự khách nói, ví dụ "bỏ hết Claymation rồi lấy lại 2" là remove rồi add.
        foreach ($ops as $op) {
            $key = mb_strtolower($op['sku'], 'UTF-8');
            $current = $draft[$key]['quantity'] ?? 0;

            if ($op['op'] === 'remove') {
                if ($current === 0) {
                    $notes[] = 'SKU ' . $op['sku'] . ' không có trong đơn nên không cần bỏ.';
                }
                unset($draft[$key]);
                continue;
            }

            if ($op['op'] === 'add') {
                $quantity = $current + $op['quantity'];
            } elseif ($op['op'] === 'set') {
                $quantity = $op['quantity'];
            } else { // subtract
                if ($current === 0) {
                    throw new InvalidArgumentException('SKU ' . $op['sku'] . ' không có trong đơn nháp nên không bớt được.');
                }
                $quantity = $current - $op['quantity'];
                // Bớt QUÁ số đang có (có 5 mà "bớt 7") thường là khách nói nhầm: báo lỗi để AI hỏi lại.
                if ($quantity < 0) {
                    throw new InvalidArgumentException('SKU ' . $op['sku'] . ': đơn chỉ có ' . $current . ', không bớt được '
                        . $op['quantity'] . '. Hỏi lại khách muốn bớt bao nhiêu.');
                }
                // Bớt ĐÚNG bằng số đang có ("có 2, bớt 2") nghĩa là hết món: bỏ món, giống lệnh remove.
                if ($quantity === 0) {
                    unset($draft[$key]);
                    continue;
                }
            }

            if ($quantity > Order::MAX_ITEM_QUANTITY) {
                throw new InvalidArgumentException('SKU ' . $op['sku'] . ': tổng số lượng ' . $quantity
                    . ' vượt giới hạn ' . Order::MAX_ITEM_QUANTITY . ' mỗi món. Báo khách giảm bớt.');
            }
            // Giữ cách viết SKU của đơn trên trang (lấy từ DB); món mới thì dùng SKU AI gửi, DB sẽ tra lại.
            $draft[$key] = ['sku' => $draft[$key]['sku'] ?? $op['sku'], 'quantity' => $quantity];
        }

        if ($draft === []) {
            throw new InvalidArgumentException('Sau khi áp lệnh, đơn không còn món nào. Không gọi lại tool; '
                . 'hướng dẫn khách bấm nút "Bỏ đơn nháp" trên trang nếu muốn bỏ hẳn.');
        }
        if (count($draft) > Order::MAX_DIRECT_ITEMS) {
            throw new InvalidArgumentException('Một đơn tối đa ' . Order::MAX_DIRECT_ITEMS . ' món.');
        }

        return ['items' => array_values($draft), 'notes' => $notes];
    }
}
