# Mission completed - Tôn Đức Long (FE + BE nhỏ) - Tuần 3

Ngày ghi: 2026-11-24. Ghi theo yêu cầu của chính Tôn Đức Long. Nhánh `feature/week3-duclong-top5-related-draft` (tạo từ `dev` d6f5c23). Chưa commit, chưa tạo PR.

## Phân công
- Task giao: `vibecoder/week-3/task/ngocdung.md`, mục 1 (lưới Top 5 trang chủ) và mục 2 (khối "Cùng danh mục bán chạy" ở trang chi tiết), kèm vài việc nhỏ ngoài task.
- Kiến nghị từ admin Long (ủy thác): đổi thời gian chờ SePay và bắt buộc chọn phương thức thanh toán.

## Phần FE đã làm
- `FE/src/views/HomeView.vue`: thiết kế lại lưới Top 5. Trên desktop, hạng 1 chiếm khối 2x2 bên trái. Trên điện thoại, hạng 1 rộng hết màn, 4 thẻ còn lại 2 cột. Trạng thái tải / lỗi / rỗng chuyển sang `StateBlock`.
- `FE/src/components/ProductCardTop5.vue`: thẻ tự thu gọn theo bề rộng của chính nó (container query), dùng được cả ở lưới trang chủ lẫn khối cùng danh mục.
- `FE/src/components/ProductRelated.vue` (mới): khối "Cùng danh mục bán chạy". Gọi `api-products-top` với `category_id` và `exclude_id`, tối đa 5 sản phẩm, có chống kết quả cũ ghi đè khi bấm sang sản phẩm khác.
- `FE/src/views/products/ProductDetail.vue`: gắn khối trên vào trang chi tiết, ngay trên phần bình luận. Không đổi logic có sẵn.
- `FE/src/views/ai/AiCheckout.vue`: dời con dấu "Đã duyệt" sang khoảng trống giữa dòng "Tạm tính", không che số tiền; chữ trong dấu không xuống dòng. Chỉ sửa CSS, đã được admin Long cho phép.
- `FE/src/views/ai/AiCheckout.vue` và `FE/src/views/payment/CheckoutView.vue`: phương thức thanh toán để trống, khách phải chọn. Bấm đặt hàng khi chưa chọn thì hiện lỗi ngay dưới khối chọn, không gửi API.
- `FE/src/views/orders/OrderDetail.vue`, `FE/src/views/payment/PaymentResult.vue`: đổi chữ "30 phút" thành "2 phút" theo thời gian chờ SePay mới.

## Phần BE đã làm (theo kiến nghị admin Long)
- `BE/configs/config.php`: `SEPAY_PAYMENT_TIMEOUT_MINUTES` từ 30 xuống 2.
- `BE/controllers/OrderController.php`: `api-order-checkout` và `api-order-checkout-items` bắt buộc có `payment_method`. Thiếu hoặc rỗng thì trả 422 "Vui lòng chọn phương thức thanh toán". Đây là thay đổi contract: trước đây thiếu thì mặc định COD.

## Tài liệu
- Đổi "30 phút" thành "2 phút" ở `docs/huong-dan-build-va-ngrok.md`, `docs/trang-thai-du-an.md`, `docs/don-hang/dong-bo-ton-kho-va-gia.md`, `docs/chat-ai/len-don-nhanh/03-luong-xu-ly.md`, `docs/chat-ai/len-don-nhanh/08-bao-mat.md`, `BE/test-api.md`.

## Đã kiểm tra
- `npm run build` trong `FE/`: OK (lặp lại nhiều lần khi sửa).
- `php -l` cho `OrderController.php` và `config.php`: OK.
- Chụp màn hình trên Chrome (headless) ở 400px, 820px, 1366px: lưới Top 5 trang chủ, khối cùng danh mục, con dấu trên trang đơn nháp không che giá. Đã thử bấm thẻ trong khối: sang đúng sản phẩm, khối tự tải lại, sản phẩm đang xem không còn trong khối.
- Giả lập API lỗi: khối cùng danh mục hiện "Không tải được sản phẩm cùng danh mục." kèm nút Thử lại. Giả lập danh sách rỗng: hiện "Danh mục này chưa có sản phẩm nào khác."
- API đặt hàng: thiếu `payment_method` trả 422 đúng thông báo (cả hai endpoint).
- Hủy đơn SePay sau 2 phút: đơn tạo, lùi thời gian 3 phút, gọi giỏ hàng để kích hoạt kiểm tra. Đơn chuyển `cancelled` và tồn kho trả lại (29 → 30). Đơn test là `#92` trên DB local.
- Trang đơn nháp AI: không chọn phương thức, bấm xác nhận thì hiện lỗi đỏ, không gửi đơn.

## Chưa kiểm tra
- Luồng đặt hàng đầy đủ trên trình duyệt (đặt thành công, COD, SePay lỗi, 409 giá đổi, thiếu kho, ngừng bán, đơn nháp AI tra lại kho) chưa được test tay trên ngrok. Đây là việc còn lại trước khi gộp.
- Trang checkout tay (`/checkout`) chưa chụp màn hình trạng thái chưa chọn phương thức.

## Lưu ý
- Chưa commit: tác giả commit sẽ do Tôn Đức Long chọn khi git đăng nhập.
- Trước khi gộp, cần admin Long xác nhận lại thời gian 2 phút, vì khách quét QR chậm có thể bị hủy đơn. Chưa có code xử lý trường hợp SePay báo tiền về sau khi đơn đã hủy.
- Hai trang đặt hàng là vùng bị khóa trong FE/CLAUDE.md. Đã sửa theo ủy thác của admin Long, cần admin xác nhận trực tiếp trong phần tổng kết.
