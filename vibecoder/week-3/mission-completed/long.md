# Mission completed - Long (BE) - Tuần 3

Ngày ghi: 2026-11-13. Ghi tự động vì không có công cụ kết nối vibe coder.

Tham chiếu: `vibecoder/Week 2/Long.MD` (phần BE).

## Mission
- Đổi cấu trúc DB cho product thành có biến thể.
- Viết lại các API endpoint cần thiết cho catalog, detail, cart.
- Viết test kiểm thử.

## Trạng thái

| Hạng mục | Trạng thái | Ghi chú |
| --- | --- | --- |
| DB có biến thể (`product_variants`, `cart_items.variant_id`) | Hoàn thành | Migration 03 đã áp dụng |
| API catalog (`api-products`, top 5) | Hoàn thành | Đã sửa cho schema biến thể, đã merge (PR #41) |
| API detail (`api-product-detail`) | Hoàn thành | `Product::getBySlug`, `ProductController::detail`, đã merge (PR #42) |
| API cart (`api-cart`, add, update, delete) | Hoàn thành, chưa commit | Nhánh `feature-cart`. `Cart.php` và `CartController.php` đã viết lại theo `variant_id` |
| Checkout và hủy đơn theo biến thể | Chưa làm | `BE/models/Order.php` còn đọc `product_id`, `products.price/stock` |
| Test kiểm thử tự động | Chưa làm | Mới kiểm tra bằng `php -l` và curl |

## Kiểm tra đã làm (cart)
- `php -l` cho `Cart.php` và `CartController.php`: OK.
- `GET api-cart` không token: 401.
- Chưa test với JWT hợp lệ: thêm trùng biến thể, vượt tồn kho, item của user khác.

## Còn lại
1. Test API cart với JWT hợp lệ (201, 200, 404, 409, 422).
2. Commit và tạo PR cho `feature-cart`.
3. Sửa `Order.php` checkout và hủy đơn theo biến thể.
4. Viết test kiểm thử cho BE.

Chi tiết: [cart.md](/C:/laragon/www/du-an-1/fix%20code/cart.md).

## Ghi công bổ sung (ngày 2026-11-14)

Người thực hiện: Long. Làm việc cùng Claude Code. Phần này làm thêm ngoài mission ban đầu, chưa commit, chưa tạo PR.

### Frontend: giao diện theo thiết kế Stitch
- Đổi giao diện sáng Navy & Brass cho header, footer, toast, các trang auth, danh sách sản phẩm, chi tiết sản phẩm, giỏ hàng, thanh toán, kết quả thanh toán, đơn hàng, trang chủ và 404.
- Đổi font sang Inter và Playfair Display (có bộ ký tự tiếng Việt), thay DM Sans và DM Serif Display.
- Sửa toast (căn theo nội dung trang, không dính header), menu mobile (hover, badge giỏ hàng), trạng thái active của nút giỏ hàng, kích thước lưới Top 5, nút Xóa trong giỏ.
- Giữ nguyên phần script và binding trong template; đã kiểm tra bằng so sánh với bản commit.

### Validate form đăng nhập
- FE: đổi kiểm tra mật khẩu của form đăng nhập sang cùng quy tắc form đăng ký (8–72 byte).
- BE: `api-login` thêm kiểm tra độ dài mật khẩu, trả 422 cùng thông báo với đăng ký. Trước đó trả 401.
- Lưu ý: tài khoản có mật khẩu dưới 8 ký tự sẽ không đăng nhập được nữa.

### Chi tiết sản phẩm: tồn kho trừ số đã có trong giỏ
- Khi đã đăng nhập, trang đọc giỏ hiện tại (`api-cart`, không đổi API) để trừ số đã có trong giỏ khỏi tồn kho.
- Khi đủ số lượng: hiện "Đã đủ số lượng trong giỏ", khóa nút thêm giỏ.
- BE vẫn kiểm tra lại khi thêm. Giỏ hàng không giữ kho.

### Cấu hình local
- `BE/.env`: bỏ comment `APP_URL` (địa chỉ ngrok), để trống 3 biến `SEPAY_*_URL`. File này không commit.

## Đã kiểm tra
- `npm run build` (FE): OK sau mỗi đợt sửa.
- `php -l` cho `AuthController.php`: OK.
- Trình duyệt (Chrome, qua ngrok): trang chủ, danh sách, đăng nhập, chi tiết sản phẩm, đăng nhập tài khoản test, thêm sản phẩm vào giỏ, xem giỏ; trạng thái tồn kho trên trang chi tiết khi giỏ đã đủ.
- API: login mật khẩu 3 ký tự trả 422 đúng thông báo.

## Đã test

## Ghi công bổ sung (ngày 2026-11-15)

Người thực hiện: Long. Làm việc cùng Claude Code. Phần này làm thêm ngoài mission ban đầu.

### Backend: dựng khung service Gemini chat có tool call
- Tạo thư mục `BE/geminiServices/` và autoload cho thư mục này trong `BE/index.php`.
- `Gemini.php`: gọi Gemini API bằng cURL, vòng lặp tool call (tối đa 5 vòng), giữ và cắt lịch sử hội thoại, xử lý lỗi tool và trường hợp bị chặn nội dung.
- `GeminiPrompt.php`: file riêng cho system prompt của trợ lý FuLe BarberShop.
- `GeminiTool.php`: lớp cha cho tool nghiệp vụ (`name`, `description`, `parameters`, `execute`).
- `ChatController.php` + route `api-ai-chat` (POST, body `message` + `history`), tạm thời chưa bắt đăng nhập.
- Cấu hình: `GEMINI_API_KEY`, `GEMINI_MODEL` đọc từ `BE/.env`, thêm tên biến vào `.env.example`.
- Ban đầu tách 5 file (Config, Client, History, Tool, Gemini); sau đó gộp còn 3 file cho dễ đọc.
- Tài liệu: [huong-dan-gemini-chat-tools.md](../../../docs/huong-dan-gemini-chat-tools.md).

### Đã kiểm tra
- `php -l` cho các file trong `BE/geminiServices/`, `ChatController.php`, `config.php`, `routes/index.php`: OK.
- Client giả lập (không gọi Google): gọi nhiều tool một lượt, tool lỗi, tool không tồn tại, hỏi tiếp với history lưu qua JSON, quá 5 vòng, bị chặn nội dung, câu hỏi rỗng, prompt mặc định và prompt riêng: đúng.
- curl qua Laragon `api-ai-chat`: GET 405, body sai 400, message rỗng 422, history sai kiểu 400, chưa có key 500.
- Chưa test với Gemini thật vì chưa có `GEMINI_API_KEY`.

### Còn lại
1. Viết tool nghiệp vụ (`GeminiToolSearchProduct`, `GeminiToolPrepareOrder`).
2. Bắt đăng nhập cho `api-ai-chat`, lưu history ở server.
3. Tool điều hướng (`navigate` + trường `actions`), cần thống nhất contract với FE.
