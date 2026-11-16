# Mission completed - Vinh (FE) - Tuần 3

Ngày ghi: 2026-11-13. Ghi tự động vì không có công cụ kết nối vibe coder.

Tham chiếu: `vibecoder/Week 2/vinh.md` (phần FE Product Detail).

## Mission
- Sửa danh sách catalog, top 5 sản phẩm và trang detail sau khi DB thay đổi.

## Trạng thái

| Hạng mục | Trạng thái | Ghi chú |
| --- | --- | --- |
| FE catalog và top 5 | Hoàn thành | Hiển thị giá thấp nhất sau giảm, đã merge (PR #41) |
| FE trang detail theo biến thể | Hoàn thành | `ProductDetail.vue` hiển thị đủ biến thể, tồn kho riêng, giá "Từ" |
| Chọn số lượng theo từng biến thể | Hoàn thành | Nút −/+ và ô nhập số, kẹp 1..tồn kho khi rời ô, đã merge (PR #42) |
| Thêm vào giỏ theo biến thể | Hoàn thành, chưa test đầy đủ | Gửi `variant_id` và `quantity` tới `api-cart-add` |
| Trang giỏ hàng `/cart` | Hoàn thành, chưa test trình duyệt | `CartView.vue`, có route và link nav (nhánh `feature-cart`, chưa commit) |

## Kiểm tra đã làm
- `npm run build` (FE): OK, lặp lại sau khi thêm route `/cart` và link nav.
- Trình duyệt, chưa đăng nhập: trang detail hiển thị 3 ảnh, giá "Từ", tồn kho, đủ 2 biến thể.
- Trình duyệt, đã đăng nhập: gõ 999 rồi rời ô số lượng được kẹp về 50 (tồn kho).
- `GET /cart` khi chưa đăng nhập: BE trả 401.

## Còn lại
1. Test trình duyệt giỏ hàng khi đã đăng nhập: thêm từ trang detail, đổi số lượng, xóa, kiểm tra tổng tiền.
2. Trang giỏ hàng: kiểm tra trạng thái trống, lỗi và thử lại.
3. Commit và tạo PR cho `feature-cart`.

Chi tiết giỏ hàng: [cart.md](/C:/laragon/www/du-an-1/fix%20code/cart.md).

## Ghi công bổ sung (ngày 2026-11-16, nhánh `feature/user-profile-fields`)

Ngày ghi: 2026-11-16. Ghi theo phân công của admin Long, nhánh `feature/user-profile-fields`. Chưa commit, chưa tạo PR.

### Phân công
- Vai trò: phó nhóm, chuyên BE, kiểm thử code và báo admin.
- Công BE: chia đều với Long (xem `long.md`).
- FE: không nhận công viết code FE. Chỉ được giao việc kiểm thử và báo admin (xem mục dưới).

### Phần BE được ghi công
- API cập nhật hồ sơ `PUT api-profile-update` (chỉ tên, SĐT, địa chỉ): `User::updateProfile`, `ProfileController::update`, route. Validate tên tối đa 100 byte, địa chỉ tối đa 500 byte, SĐT theo quy tắc chung.
- Quy tắc SĐT di động Việt Nam dùng chung: `extensions/VietnamPhone.php`, áp dụng cho `OrderController` (đặt hàng) và `ProfileController`.
- Xoay vòng nhiều Gemini API key: `geminiServices/Gemini.php` (`callApi`, `nextKeyIndex`, `isKeyError`, `sendRequest`), `configs/config.php` (`GEMINI_API_KEYS`), `.env.example`.

### Kiểm thử và báo admin
- Kiểm thử API `api-profile-update`: các trường hợp 200, 400, 401, 405, 422.
- Kiểm thử quy tắc SĐT: bộ 33 trường hợp chạy trên cả PHP và JS, so khớp từng dòng.
- Kiểm thử xoay vòng Gemini bằng client giả lập: 11 kiểm tra, đạt.

### Đã nêu rõ với admin
- Gọi Gemini thật thất bại: key hiện có hết hạn mức miễn phí (429). Cần thêm key thuộc project khác rồi kiểm tra lại.
- Chưa có test tự động cho BE.

### Việc FE được giao (kiểm thử, không tính công code FE)
- Kiểm thử hồi quy trang Đặt hàng sau khi đổi quy tắc SĐT: SĐT hợp lệ, số cố định bị chặn, lỗi hiện đúng dưới ô.
- Kiểm thử hồi quy trang Giỏ hàng và Đơn hàng sau khi đổi menu desktop sang icon.
- Báo lỗi cho admin, không tự sửa FE.

## Ghi công bổ sung (ngày 2026-11-17)

Người thực hiện: Vinh. Làm việc cùng Claude Code. Phần này làm thêm ngoài mission, chưa commit, chưa tạo PR. Phần BE code do Long, xem `long.md`.

### BE: ý tưởng và sơ đồ luồng demo
- Ý tưởng tính năng "Lên đơn nhanh cho quý ông": khách nói muốn mua trong khung chat, trợ lý lên đơn nháp, khách kiểm tra rồi tự bấm xác nhận. AI không tạo đơn thật.
- Bộ tài liệu `docs/chat-ai/len-don-nhanh/` (12 file): nghiệp vụ, kiến trúc, luồng xử lý với sơ đồ tuần tự cho từng tình huống (thành công, COD, SePay, hết hàng, giá đổi, tải lại trang), tool, API xác nhận, giao diện, prompt, bảo mật (lớp chốt chặn BE khi FE bị sửa bằng F12), kiểm thử, vấn đáp, mô phỏng JSON theo từng bước.
- Thiết kế đồng bộ đơn nháp với trợ lý: số lượng gửi kèm lượt chat, thông tin người nhận do FE giữ và lấy từ hồ sơ.

### FE: thiết kế Stitch và trang nối API
- Lấy màn "Thông tin giao hàng" từ Stitch project "Fule BarberShop Online Store" làm tham khảo bố cục: form bên trái, cụm điều khiển tóm tắt đơn bên phải.
- `FE/src/views/ai/AiCheckout.vue` (mới), route `/ai-checkout` (cần đăng nhập): hiệu ứng Louis điền đơn; số lượng tăng/giảm trong giới hạn tồn kho; giá khóa; ô người nhận trống được tô nổi để khách tự điền; nút xác nhận gọi `api-order-checkout-items`, COD hoặc SePay.
- `FE/src/helpers/ai/checkoutDraft.js` (mới): kho đơn nháp lưu `sessionStorage`, gắn với user ID; lấy hồ sơ qua `api-profile` đúng một lần cho mỗi đơn.
- `FE/src/helpers/ai/chatAssistant.js` và `ChatAssistant.vue`: nhận lệnh `checkout-draft`, gửi số lượng kèm mỗi lượt chat, xóa đơn nháp khi đăng xuất, nạp lại lịch sử chat khi mở khung chat.

### Đã kiểm tra (FE)
- `npm run build`: OK, lặp lại sau mỗi thay đổi.
- Trang `/ai-checkout` trả 200 qua Apache local và qua ngrok (chỉ kiểm tra HTTP, không mở trình duyệt).

### Chưa làm
- Kiểm thử trình duyệt trên link ngrok do Việt Hoàng thực hiện, xem `viethoang.md`. Phần chỉnh sửa theo kết quả kiểm thử (nếu có) chưa ghi nhận trong phần này.
- Chưa test luồng xác nhận đơn thật từ trang FE.
- Chưa commit và tạo PR cho nhánh `feature-ai-tool-len-don-tu-dong`.
