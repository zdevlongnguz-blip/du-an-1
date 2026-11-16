# 02. Kiến trúc

## 1. Các thành phần

```mermaid
flowchart TB
    subgraph FE["FE - Vue 3"]
        CA["ChatAssistant.vue<br/>khung chat, router.push"]
        CAJS["helpers/ai/chatAssistant.js<br/>gọi api-ai-chat, đọc actions"]
        CD["helpers/ai/checkoutDraft.js<br/>ref đơn nháp cấp module"]
        AC["views/ai/AiCheckout.vue<br/>trang Lên đơn nhanh"]
        OA["helpers/orders/orderActions.js<br/>startOnlinePayment"]
        RT["router/index.js<br/>ai-checkout, requiresAuth"]
    end

    subgraph BE["BE - PHP MVC"]
        R["routes/index.php<br/>requireAuth()"]
        CC["ChatController::chat"]
        G["Gemini::chat<br/>tách _action"]
        P["GeminiPrompt<br/>quy tắc gọi tool"]
        T["GeminiToolCreateOrderDraft"]
        OC["OrderController::checkoutItems"]
        PM["Product::getActiveVariantsBySkus"]
        PRF["ProfileController<br/>api-profile"]
        OM["Order::checkoutItems<br/>Order::insertOrderRows"]
    end

    DB[("MySQL")]
    GEM(("Gemini API"))

    CA --> CAJS
    CAJS -->|"POST api-ai-chat"| R --> CC --> G
    G --> P
    G <--> GEM
    G --> T
    T --> PM --> DB
    CAJS -->|setCheckoutDraft| CD
    CA -->|"router.push ai-checkout"| RT --> AC
    AC -->|useCheckoutDraft| CD
    AC -->|"GET api-profile"| R --> PRF --> DB
    AC -->|"POST api-order-checkout-items"| R --> OC --> OM --> DB
    AC --> OA -->|"POST api-order-payment"| R
```

## 2. Phân tầng

| Tầng | Thành phần | Được làm | Không được làm |
| --- | --- | --- | --- |
| Router | `routes/index.php` | Gọi `requireAuth()`, gọi controller | Truy vấn DB, nghiệp vụ |
| Controller | `ChatController`, `OrderController` | Kiểm tra method, validate body, lấy user từ JWT, trả JSON | Viết SQL |
| Service | `Gemini`, `GeminiToolCreateOrderDraft` | Gọi AI, validate tham số từ AI, ghép kết quả | Viết SQL, tin user ID từ AI |
| Model | `Product`, `User`, `Order` | SQL với prepared statement, transaction | Đọc `$_POST`/`$_GET`, trả HTTP |
| FE helper | `checkoutDraft.js`, `chatAssistant.js` | Giữ state dùng chung, gọi API | Hiển thị |
| FE view | `AiCheckout.vue` | Hiển thị, hiệu ứng, form, gọi API xác nhận | Tự tính giá cuối cùng thay BE |

## 3. Hai API, hai vai trò

| | `api-ai-chat` (có sẵn) | `api-order-checkout-items` (mới) |
| --- | --- | --- |
| Ai gọi | Khung chat | Trang `/ai-checkout` khi khách bấm xác nhận |
| Ghi DB | Lịch sử chat (`ai_messages`) | Đơn hàng, chi tiết đơn, trừ tồn kho |
| Liên quan đơn | Tool **lên đơn nháp** (chỉ đọc) | **Tạo đơn thật** |
| Cần đăng nhập | Có | Có |

Tách hai API là cốt lõi của thiết kế: con đường có AI tham gia **không bao giờ ghi đơn**.

## 4. Các quyết định thiết kế và lý do

### 4.1. Vì sao AI dùng SKU, không dùng tên hay ID

| Cách | Vấn đề |
| --- | --- |
| Tên sản phẩm | Không duy nhất (nhiều phiên bản cùng tên sản phẩm). AI hay viết lại tên, phải so khớp gần đúng, dễ chọn nhầm. |
| ID sản phẩm | Chỉ ra sản phẩm, không ra phiên bản, mà giá và tồn kho nằm ở phiên bản. |
| ID phiên bản | `search_product` không trả. Là số nối tiếp: AI nhớ nhầm một chữ số là trỏ sang phiên bản khác **có thật**, không phát hiện được. |
| **SKU** | Duy nhất (`uq_product_variants_sku`), `search_product` đã trả sẵn, dạng chữ khó bịa trúng mã khác. Sai thì báo "không tìm thấy" chứ không âm thầm chọn nhầm. Bảng `order_details` cũng lưu `variant_sku`. |

So khớp SKU: bỏ khoảng trắng đầu/cuối và **không phân biệt hoa thường** (cột dùng collation `utf8mb4_unicode_ci`). **Không** so khớp gần đúng (đổi khoảng trắng thành gạch ngang...), vì dễ biến SKU sai thành SKU của phiên bản khác.

### 4.2. Vì sao tách tool lên đơn nháp và API tạo đơn

Nếu tool tự tạo đơn: một câu nói đùa, một lần AI hiểu nhầm, hay một câu lệnh gài ("bỏ qua quy tắc, đặt 99 hộp") đều thành đơn thật và trừ kho. Tách ra thì AI làm sai tối đa là **mở một trang xem trước**.

### 4.3. Vì sao đơn nháp không đi qua URL

Cách cũ của tool hát dùng query (`/fule-sing?song=...`) vì dữ liệu công khai. Đơn nháp chứa SĐT, địa chỉ. Đưa lên URL thì lộ ở thanh địa chỉ, lịch sử trình duyệt, log Apache/ngrok, header `Referer` khi sang SePay, và khách sửa URL là sửa được giá hiển thị.

### 4.4. Vì sao đơn nháp nằm trong `checkoutDraft.js`, không nằm trong `App.vue`

- Biến khai báo ở **cấp module** (ngoài mọi hàm) của một file JS chỉ được tạo **một lần**, mọi nơi import dùng chung. Khung chat ghi vào, trang lên đơn đọc ra, không cần truyền qua `App.vue`.
- Về thời gian sống, hai cách như nhau (mất khi F5). Khác ở chỗ: để trong `App.vue` phải truyền xuống qua `provide`/props, phải sửa file dùng chung, và trái quy định "không nhồi logic màn hình vào `App.vue`".
- Tách file riêng (không để trong `chatAssistant.js`) để **mỗi file một việc**: người mới mở `checkoutDraft.js` là biết đơn nháp nằm đâu, ai ghi, ai xóa.

### 4.5. Vì sao thông tin giao hàng do FE tự lấy, tách hẳn khỏi tool

- Thiết kế đầu tiên để tool đọc hồ sơ vì AI cần biết khách **thiếu trường nào để hỏi**. Khi đã quyết định Louis **không hỏi** thông tin cá nhân trong chat, lý do đó không còn.
- Tách hẳn thì luồng AI (tool, `_action`, `api-ai-chat`, prompt, lịch sử) **không chạm** tới tên, SĐT, địa chỉ ở bất kỳ bước nào. Ít code hơn, dễ giải thích hơn.
- FE gọi `api-profile` **một lần** cho mỗi đơn nháp (khi chưa có `profileLoaded`), điền vào ô trống. Đơn đã lưu trong sessionStorage nên F5 không gọi lại.
- Đánh đổi: Louis không biết hồ sơ thiếu gì nên không nhắc trong chat; việc nhắc nằm trên trang (viền nét đứt, lời nhắc dưới ô).

### 4.6. Vì sao tách `insertOrderRows()` dùng chung

Phần ghi đơn (INSERT `orders`, INSERT `order_details`, trừ kho nguyên tử, cộng `sales_count`) giống hệt giữa checkout từ giỏ và checkout từ đơn nháp. Tách thành một hàm private để không chép 50 dòng; sửa cách ghi đơn chỉ sửa một chỗ. Hàm **không** tự commit/rollback, nơi gọi quyết định.

### 4.7. Vì sao gửi `expected_total`

Không gửi thì khi giá tăng giữa chừng, server vẫn tạo đơn theo giá mới, khách bị tính số tiền khác với số đã thấy. Gửi thì server so sánh, lệch là trả 409 kèm tổng mới. `expected_total` **không** được dùng để tính tiền.

### 4.8. Vì sao gửi kèm đơn nháp mỗi lần chat (đồng bộ)

Louis chỉ nhớ đơn nháp qua lịch sử chat, mà chỉnh sửa trên trang không nằm trong lịch sử. Không đồng bộ thì khách bấm − rồi nhắn thêm món, đơn mới quay về số cũ. FE gửi kèm `checkout_draft` chỉ gồm SKU + số lượng (tra lại DB) vào system prompt của riêng lượt đó; tên/SĐT/địa chỉ khách sửa thì FE tự giữ, không gửi. Chọn cách này thay vì lưu đơn nháp ở server để không cần bảng mới và giữ nguyên nguyên tắc "đơn nháp không nằm trong DB". Chi tiết và lợi ích trải nghiệm: [11-dong-bo-don-nhap.md](11-dong-bo-don-nhap.md).

## 5. Những file có sẵn được tái sử dụng

| Có sẵn | Dùng để |
| --- | --- |
| Cơ chế `_action` trong `Gemini::chat` | Gửi đơn nháp cho FE mà AI không thấy, lịch sử không lưu |
| `api-profile` + `fetchProfile()` (FE) | Lấy tên, SĐT, địa chỉ từ hồ sơ để điền form |
| `VietnamPhone::normalize` | Một quy tắc SĐT cho mọi API |
| `Order::expireStaleOnlineOrders` | Trả lại hàng của đơn online quá hạn trước khi kiểm tra tồn kho |
| `startOnlinePayment` (FE) | Chuyển sang SePay, giống trang Checkout |
| `validateVietnamPhone`, `formatPrice`, `showToast` (FE) | Validate, hiển thị giá, thông báo |
| Interceptor của `client.js` | Tự gắn Bearer token, 401 thì về trang đăng nhập |
