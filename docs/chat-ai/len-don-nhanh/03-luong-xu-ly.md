# 03. Luồng xử lý

Mỗi mục là một tình huống, có sơ đồ tuần tự (sequence diagram). Muốn xem JSON thật ở từng bước, đọc [12-mo-phong-json.md](12-mo-phong-json.md). Ký hiệu chung:

| Tên trong sơ đồ | Là |
| --- | --- |
| K | Khách |
| Chat | `ChatAssistant.vue` + `chatAssistant.js` |
| Kho | `checkoutDraft.js` |
| Trang | `AiCheckout.vue` |
| RT | `routes/index.php` + `AuthMiddleware` |
| CC | `ChatController` |
| G | `Gemini::chat` |
| AI | Gemini API |
| Tool | `GeminiToolCreateOrderDraft` |
| OC | `OrderController::checkoutItems` |
| OM | `Order::checkoutItems` |
| DB | MySQL |

## 1. Lên đơn thành công

Tool chỉ lo món hàng. Người nhận do FE tự lấy từ hồ sơ (bước 23-25), không đi qua luồng AI.

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant Chat
    participant RT
    participant CC
    participant G
    participant AI
    participant Tool
    participant DB

    K->>Chat: "lấy cho anh 2 hũ Claymation"
    Chat->>RT: POST api-ai-chat {message, conversation_id} + Bearer
    RT->>RT: requireAuth() → payload.sub = 6
    RT->>CC: chat(payload)
    CC->>G: chat(message, history, {user_id: 6})
    G->>AI: câu hỏi + danh sách tool
    AI-->>G: functionCall search_product {keyword: "Claymation"}
    G->>DB: tìm sản phẩm + phiên bản
    G->>AI: functionResponse {products, variants có sku}
    AI-->>G: functionCall create_order_draft {items: [{sku: "CLAYMATION", quantity: 2}]}
    G->>Tool: execute(args, {user_id: 6})
    Tool->>DB: getActiveVariantsBySkus(["CLAYMATION"])
    Tool-->>G: {ready: true, items, total_amount, _action: {type: checkout-draft, draft: {items, total_amount}}}
    G->>G: tách _action ra khỏi kết quả
    G->>AI: functionResponse {ready, items, total_amount} (KHÔNG có _action)
    AI-->>G: "Louis đã lên đơn nháp 2 hũ... tổng 900.000đ..."
    G-->>CC: {text, actions: [checkout-draft], history}
    CC->>DB: lưu các lượt mới (không có _action)
    CC-->>Chat: {reply, actions, suggestions}
    Chat->>Chat: setCheckoutDraft(action.draft) → kiểm tra định dạng
    Chat->>Chat: router.push({name: 'ai-checkout'})
    Note over Chat: URL chỉ là /ai-checkout, không có query
    Chat->>Chat: Trang gọi ensureDraftRecipient(): điền tạm từ localStorage
    Chat->>RT: GET api-profile + Bearer (song song với màn chuẩn bị)
    RT-->>Chat: {user: {name, phone, address}} → điền form, chạy hiệu ứng gõ
```

Sau bước 22, trang `/ai-checkout` mở và chạy hiệu ứng (xem [06-frontend.md](06-frontend.md)). Từ đầu tới cuối, tên/SĐT/địa chỉ chỉ đi đường `api-profile` → FE → form → `api-order-checkout-items`.

## 2. Hồ sơ thiếu thông tin → trang vẫn mở, khách tự điền

Louis **không hỏi** tên, SĐT, địa chỉ trong chat, và cũng không biết hồ sơ thiếu gì (tool không đọc hồ sơ).

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant AI
    participant Tool
    participant Trang
    participant BE as api-profile

    K->>AI: "mua 1 chai Got2b"
    AI->>Tool: create_order_draft {items}
    Tool-->>AI: {ready: true, items, total_amount} + _action (chỉ món)
    AI-->>K: "Đơn nháp đã mở, quý ông kiểm tra thông tin nhận hàng rồi bấm Xác nhận"
    Trang->>BE: GET api-profile
    BE-->>Trang: {name: "Lê Văn An", phone: null, address: null}
    Trang->>K: hiệu ứng gõ tên, bỏ qua ô trống;<br/>ô trống viền đồng nét đứt + "Hồ sơ chưa có số điện thoại..."
    K->>Trang: tự điền SĐT, địa chỉ
    Note over Trang: Thông tin đi thẳng form → api-order-checkout-items,<br/>không qua Gemini
```

Nếu `api-profile` lỗi (mạng, server): trang vẫn chạy với dữ liệu tạm từ `localStorage` (hoặc ô trống), hiện toast vàng nhờ khách tự điền.

## 3. SĐT trong hồ sơ sai định dạng

```mermaid
sequenceDiagram
    autonumber
    participant Trang
    participant BE as api-profile
    actor K as Khách

    Trang->>BE: GET api-profile
    BE-->>Trang: {phone: "0123"}
    Trang->>Trang: normalizeVietnamPhone("0123") → null → ô SĐT để trống
    Trang->>K: ô SĐT trống, nhắc điền
    K->>Trang: nhập lại; validateVietnamPhone kiểm tra ngay dưới ô
```

Khách tự gõ SĐT vào chat: Louis không dùng, mời khách điền trên trang (prompt). Gemini vẫn đọc được câu đó vì là tin nhắn của khách, xem [08-bao-mat.md, mục 3](08-bao-mat.md).

## 4. Món có vấn đề (sai SKU, hết hàng, không đủ số lượng)

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant AI
    participant Tool
    participant DB

    K->>AI: "lấy 50 hũ Claymation"
    AI->>Tool: create_order_draft {items: [{sku: CLAYMATION, quantity: 50}]}
    Tool->>DB: getActiveVariantsBySkus → stock = 46
    Tool-->>AI: {ready: false, problems: [{sku, name, reason: "Không đủ số lượng, chỉ còn 46"}]}
    Note over Tool: Kiểm tra món TRƯỚC thông tin giao hàng,<br/>không mở trang
    AI-->>K: "Claymation hiện chỉ còn 46 hũ, quý ông lấy 46 hay chọn loại khác?"
```

## 5. Khách xác nhận, chọn COD

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant Trang
    participant Kho
    participant RT
    participant OC
    participant OM
    participant DB

    K->>Trang: chỉnh số lượng, chọn COD, bấm "Xác nhận đặt hàng"
    Trang->>Trang: validateForm() (tên, SĐT, địa chỉ)
    Trang->>Trang: submitting = true (khóa nút)
    Trang->>RT: POST api-order-checkout-items + Bearer<br/>{items:[{sku,quantity}], recipient_*, payment_method: cod, expected_total}
    RT->>RT: requireAuth()
    RT->>OC: checkoutItems(payload)
    OC->>OC: kiểm tra method, body, items, người nhận, SĐT, payment_method, expected_total
    OC->>OM: checkoutItems(userId từ sub, items, ...)
    OM->>DB: expireStaleOnlineOrders
    OM->>DB: BEGIN
    OM->>DB: SELECT ... WHERE sku IN (...) ORDER BY id FOR UPDATE
    OM->>OM: kiểm tra đang bán, đủ kho, tính tổng theo xu
    OM->>OM: so tổng với expected_total → khớp
    OM->>DB: insertOrderRows: INSERT orders, order_details,<br/>UPDATE stock (có điều kiện), UPDATE sales_count
    OM->>DB: COMMIT
    OM-->>OC: {status: ok, order_id, total_amount}
    OC-->>Trang: 201 {order: {id, ...}}
    Trang->>Kho: clearCheckoutDraft()
    Trang->>K: toast "Đặt hàng thành công"
    Trang->>Trang: router.push order-detail
```

## 6. Khách xác nhận, chọn SePay (có lỗi chuyển trang)

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant Trang
    participant Kho
    participant BE
    participant SP as SePay

    K->>Trang: chọn SePay, bấm Xác nhận
    Trang->>BE: POST api-order-checkout-items {payment_method: sepay, ...}
    BE-->>Trang: 201 {order: {id: 77}}
    Trang->>Trang: createdOrder = {id: 77, sepay}
    Trang->>Kho: clearCheckoutDraft()
    Trang->>BE: POST api-order-payment?id=77 (startOnlinePayment)
    alt Thành công
        BE-->>Trang: {checkout: {action, fields}}
        Trang->>SP: submit form POST → trình duyệt rời trang
    else Lỗi (mạng, cấu hình SePay)
        BE-->>Trang: lỗi
        Trang->>K: báo lỗi, nút chính thành "Thanh toán lại"
        K->>Trang: bấm "Thanh toán lại"
        Trang->>BE: POST api-order-payment?id=77 (KHÔNG tạo đơn lần hai)
    end
```

Đơn SePay chưa thanh toán sẽ được trả lại hàng sau `SEPAY_PAYMENT_TIMEOUT_MINUTES` (30 phút) bởi `expireStaleOnlineOrders`, giống luồng checkout từ giỏ.

## 7. Giá đổi hoặc hết hàng lúc xác nhận (409)

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant Trang
    participant OM
    participant DB

    Note over DB: Admin vừa đổi giá Claymation 450.000 → 480.000
    K->>Trang: bấm Xác nhận (expected_total = 900000)
    Trang->>OM: (qua OC) checkoutItems(..., expected_total "900000")
    OM->>DB: BEGIN, SELECT ... FOR UPDATE
    OM->>OM: tổng mới = 960000 ≠ 900000
    OM->>DB: ROLLBACK
    OM-->>Trang: 409 {message: "Giá sản phẩm vừa thay đổi. Tổng mới là 960.000đ...", total_amount}
    Trang->>K: khối lỗi đỏ + toast, form vẫn giữ nguyên
    K->>Trang: bấm "Nhờ Louis sửa đơn" → khung chat mở để lên đơn lại
```

Hết hàng giữa chừng đi đường tương tự, trả 409 "Có món đã hết hàng...".

## 8. Hai khách tranh nhau món cuối

```mermaid
sequenceDiagram
    autonumber
    participant A as Khách A
    participant B as Khách B
    participant DB

    Note over DB: CLAYMATION stock = 1
    A->>DB: BEGIN, SELECT ... FOR UPDATE (khóa dòng)
    B->>DB: BEGIN, SELECT ... FOR UPDATE
    Note over B,DB: B phải CHỜ vì dòng đang bị A khóa
    A->>DB: trừ kho → stock = 0, COMMIT (mở khóa)
    DB-->>B: đọc được stock = 0
    B->>DB: stock < quantity → ROLLBACK
    DB-->>B: 409 hết hàng
```

Khóa theo thứ tự `id` tăng dần ở mọi transaction để không bị deadlock (hai giao dịch chờ nhau mãi).

## 9. Khách tải lại trang, bỏ đơn nháp hoặc đăng xuất

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant Trang
    participant Kho
    participant Chat

    alt F5 khi đang có đơn nháp
        K->>Trang: tải lại /ai-checkout
        Kho->>Kho: đọc sessionStorage, userId khớp → giữ đơn nháp
        Trang->>K: hiện lại đơn và thông tin đã sửa (không gọi lại api-profile)
    else Mở link khi chưa có đơn nháp / đã đóng tab
        Trang->>K: màn "Chưa có đơn nháp" + nút "Trò chuyện với Louis"
    else Bấm "Bỏ đơn nháp"
        K->>Trang: bấm nút, chọn OK ở hộp thoại xác nhận
        Trang->>Kho: clearCheckoutDraft() (xóa cả sessionStorage)
        Trang->>K: toast "Đã bỏ đơn nháp." → chuyển sang trang sản phẩm
    else Đăng xuất
        K->>Chat: đăng xuất (authenticated = false)
        Chat->>Chat: forgetChat()
        Chat->>Kho: clearCheckoutDraft()
    end
```

## 10. Louis gửi đơn nháp mới khi khách đang ở trang lên đơn

Từ khi có đồng bộ, đơn mới được lên từ **đúng trạng thái trên trang**: số lượng gửi kèm cho Louis, người nhận khách đã sửa được FE tự chép sang đơn mới. Sơ đồ đầy đủ ở [11-dong-bo-don-nhap.md, mục 2](11-dong-bo-don-nhap.md).

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant Chat
    participant Kho
    participant Trang

    Note over Trang: đang hiển thị đơn nháp cũ
    K->>Chat: "đổi thành 3 hũ"
    Chat->>Kho: setCheckoutDraft(đơn mới)
    Chat->>Chat: router.push ai-checkout (cùng trang, không tải lại)
    Kho-->>Trang: watch(checkoutDraft) phát hiện đơn mới
    Trang->>Trang: play(đơn mới) → diễn lại hiệu ứng
```
