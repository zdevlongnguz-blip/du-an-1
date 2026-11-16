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
 */
class GeminiToolCreateOrderDraft extends GeminiTool
{
    /**
     * Tên tool. Gemini dùng tên này khi yêu cầu gọi.
     *
     * @return string
     */
    public function name()
    {
        return 'create_order_draft';
    }

    /**
     * Mô tả để Gemini biết lúc nào nên gọi tool.
     *
     * @return string
     */
    public function description()
    {
        return 'Lên đơn nháp cho khách khi khách muốn mua, đặt hàng hoặc chốt đơn. '
            . 'Cần SKU của từng phiên bản (lấy từ kết quả search_product) và số lượng. '
            . 'Thông tin nhận hàng (tên, số điện thoại, địa chỉ) do website tự điền trên trang từ hồ sơ của khách, '
            . 'tool không cần và AI không được hỏi các thông tin này trong chat. '
            . 'Tool KHÔNG tạo đơn thật: website mở trang để khách kiểm tra, điền thông tin còn thiếu và tự bấm xác nhận.';
    }

    /**
     * Khai báo tham số theo JSON Schema: chỉ có danh sách món.
     *
     * Cố ý KHÔNG có tham số tên, SĐT, địa chỉ: thông tin người nhận do FE tự lo, AI không có đường
     * chuyển thông tin cá nhân vào đơn nên cũng không có lý do để hỏi khách trong chat.
     *
     * @return array
     */
    public function parameters()
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'Các món khách muốn đặt, mỗi SKU chỉ xuất hiện một lần',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string', 'description' => 'SKU của phiên bản, lấy đúng từ search_product'],
                            'quantity' => ['type' => 'integer', 'description' => 'Số lượng, mặc định 1 nếu khách không nói'],
                        ],
                        'required' => ['sku', 'quantity'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    /**
     * Kiểm tra món hàng (SKU, đang bán, đủ tồn kho) rồi trả đơn nháp. Không đọc thông tin người nhận.
     *
     * Hai kết quả có thể có:
     * - Món hàng có vấn đề (sai SKU, hết hàng, không đủ số lượng): ready=false + problems, không mở trang.
     * - Món hàng ổn: ready=true + tóm tắt đơn cho AI, kèm _action 'checkout-draft' (chỉ có món và tổng tiền) cho FE.
     *
     * @param array $args    Tham số Gemini gửi lên: items
     * @param array $context Ngữ cảnh server, cần 'user_id' (lấy từ JWT, không lấy từ AI) để chắc chắn tool
     *                       chỉ chạy cho người đã đăng nhập
     * @return array Kết quả cho Gemini (và _action cho FE khi đơn đã sẵn sàng)
     * @throws InvalidArgumentException Khi tham số sai kiểu, rỗng hoặc vượt giới hạn
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

        $items = $this->readItems($args['items'] ?? null);

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

        // Phần gửi cho AI: chỉ tóm tắt món hàng và tổng tiền.
        $summary = [];
        foreach ($draftItems as $draftItem) {
            $summary[] = [
                'name' => $draftItem['name'] . ' - ' . $draftItem['variant_name'],
                'quantity' => $draftItem['quantity'],
                'line_total' => $draftItem['price'] * $draftItem['quantity'],
            ];
        }

        return [
            'ready' => true,
            'items' => $summary,
            'total_amount' => $totalAmount,
            'message' => 'Đơn nháp đã mở trên website. Website tự điền thông tin nhận hàng từ hồ sơ của khách; '
                . 'khách kiểm tra, điền ô còn thiếu nếu có, có thể chỉnh số lượng, chọn cách thanh toán rồi tự bấm '
                . '"Xác nhận đặt hàng". Đơn CHƯA được tạo. Không hỏi thông tin cá nhân trong chat.',
            Gemini::ACTION_KEY => [
                'type' => 'checkout-draft',
                // Chỉ có món và tổng tiền. Người nhận do FE tự lấy (api-profile) hoặc giữ từ trang.
                'draft' => [
                    'items' => $draftItems,
                    'total_amount' => $totalAmount,
                ],
            ],
        ];
    }

    /**
     * Kiểm tra danh sách món AI gửi lên: đúng kiểu, không rỗng, không trùng SKU, số lượng trong giới hạn.
     *
     * @param mixed $rawItems Giá trị 'items' từ AI
     * @return array Danh sách ['sku' => string, 'quantity' => int]
     * @throws InvalidArgumentException Khi dữ liệu sai
     */
    private function readItems($rawItems = null)
    {
        if (!is_array($rawItems) || $rawItems === []) {
            throw new InvalidArgumentException('Thiếu danh sách món (items). Hãy tìm sản phẩm bằng search_product để lấy SKU trước.');
        }
        if (count($rawItems) > Order::MAX_DIRECT_ITEMS) {
            throw new InvalidArgumentException('Một đơn tối đa ' . Order::MAX_DIRECT_ITEMS . ' món.');
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            $sku = is_array($rawItem) && is_string($rawItem['sku'] ?? null) ? trim($rawItem['sku']) : '';
            if ($sku === '' || strlen($sku) > 100) {
                throw new InvalidArgumentException('SKU không hợp lệ. Dùng đúng SKU trong kết quả search_product.');
            }

            // Gemini đôi khi gửi số dạng 2.0 (float); chỉ nhận khi là số nguyên đúng nghĩa.
            $rawQuantity = $rawItem['quantity'] ?? 1;
            $quantity = is_int($rawQuantity) ? $rawQuantity
                : (is_float($rawQuantity) && floor($rawQuantity) === $rawQuantity ? (int)$rawQuantity : 0);
            if ($quantity < 1 || $quantity > Order::MAX_ITEM_QUANTITY) {
                throw new InvalidArgumentException('Số lượng mỗi món phải từ 1 đến ' . Order::MAX_ITEM_QUANTITY . '.');
            }

            // Trùng SKU thì cộng dồn số lượng thay vì báo lỗi, vì khách có thể nói "thêm 1 hộp nữa".
            $key = mb_strtolower($sku, 'UTF-8');
            if (isset($items[$key])) {
                $items[$key]['quantity'] = min(Order::MAX_ITEM_QUANTITY, $items[$key]['quantity'] + $quantity);
            } else {
                $items[$key] = ['sku' => $sku, 'quantity' => $quantity];
            }
        }

        return array_values($items);
    }
}
