[12/11/2026 22:29:59] Lường Văn Hoàng: # Task 3 – ProductDetail – Văn Hoàng

## 1. Thông tin nhiệm vụ

- Người thực hiện: Văn Hoàng
- Tuần: 3
- Ngày thực hiện: 12/11/2026
- Task: Hoàn thiện chức năng trang chi tiết sản phẩm.
- Pull Request: #38
- Nhánh: Nhánh Task 3 – ProductDetail
- Trạng thái: Đã push code và tạo Pull Request, chờ Admin review.

## 2. File đã chỉnh sửa

- `FE/src/views/products/ProductDetail.vue`

Không chủ động chỉnh sửa Backend, API client, module auth/toast dùng chung hoặc component do Tuấn Anh phụ trách.

## 3. Nội dung đã triển khai

### Bình luận
- Kết nối API lấy danh sách bình luận.
- Gửi bình luận mới bằng API.
- Kiểm tra nội dung rỗng, chỉ chứa khoảng trắng và giới hạn 2.000 ký tự.
- Thông báo bình luận mới đang chờ duyệt.
- Không tự thêm bình luận đang chờ duyệt vào danh sách công khai.
- Chỉ hiển thị nút sửa/xóa đối với bình luận của tài khoản hiện tại.
- Sửa bình luận trực tiếp trên giao diện.
- Xóa bình luận có xác nhận.
- Tải lại danh sách và cập nhật phân trang sau khi sửa/xóa.

### Giỏ hàng
- Kết nối API thêm sản phẩm vào giỏ hàng.
- Cho phép tăng/giảm số lượng.
- Kiểm tra số lượng không vượt quá tồn kho.
- Khóa nút khi đang gửi yêu cầu.
- Hiển thị thông báo thành công hoặc lỗi.

### Giao diện và trạng thái
- Hỗ trợ trạng thái loading, lỗi, rỗng và thử lại.
- Hiển thị thông báo qua toast.
- Khách chưa đăng nhập được hướng dẫn đăng nhập.
- Giữ lại nội dung bình luận khi gửi thất bại.

## 4. Hướng dẫn kiểm thử và bàn giao

Các luồng cần kiểm thử:

1. Đăng nhập và gửi bình luận hợp lệ.
2. Kiểm tra bình luận mới ở trạng thái chờ duyệt.
3. Thử gửi bình luận rỗng và bình luận vượt quá 2.000 ký tự.
4. Sửa và xóa bình luận thuộc tài khoản hiện tại.
5. Kiểm tra không thể sửa/xóa bình luận của người khác.
6. Thêm sản phẩm vào giỏ với số lượng khác nhau.
7. Kiểm tra tồn kho bằng 0 và trường hợp API trả lỗi 409.
8. Kiểm tra khách chưa đăng nhập.

## 5. Trạng thái kiểm thử

kiểm thử luồng thêm mới bình luận trên trình duyệt:
* không nhập gì -> ấn gửi -> hiện thông báo "cần nhập nội dung bình luận" : ĐẠT
* nhập comment chuẩn -> ấn gửi -> hiện thông báo đã thêm bình luận thành công từ server Backend : ĐẠT (nhưng cần A Long Backend xem xét lại cách thông báo, phải là "Thêm bình luận thành công, đang chờ Admin duyệt!")
* các trạng thái loading... lỗi.... hiển thị rõ ràng -> ĐẠT
Sửa bình luận:

ĐẠT nhưng sửa xong không đợi Admin duyệt đã hiển thị ngay: Lỗi BE bên anh Long, cần anh Long xem lại
Xóa bình luận: ĐẠT
*chưa đăng nhập -> bắt buộc đăng nhập mới bình luận được -> ĐẠT
*chưa test các trường hợp lỗi bảo mật hoặc bị tấn công bảo mật để can thiệp vào bình luận của user khác vì đang tạm tin tưởng BE bên anh Long
- Đã commit và push nhánh lên GitHub.
- Đã tạo Pull Request #38 hướng vào nhánh `dev`.
- Chưa xác nhận `npm run build`.
- Chưa xác nhận kiểm thử tích hợp Browser/API.
- Chờ Tuấn Anh thực hiện kiểm thử tích hợp theo phân công.

## 6. Vấn đề còn lại

- Chờ Admin review và duyệt Pull Request.
- Chờ kiểm thử tích hợp các API bình luận và giỏ hàng.
- Nếu phát hiện lỗi thuộc ProductDetail, Văn Hoàng sẽ tiếp nhận sửa và bàn giao lại để kiểm thử.

---
[12/11/2026 22:46:32] Y A Cờ Long: Sửa bình luận:
ĐẠT nhưng sửa xong không đợi Admin duyệt đã hiển thị ngay: Lỗi BE bên anh Long, cần anh Long xem lại
[12/11/2026 22:46:59] Y A Cờ Long: Xóa bình luận: ĐẠT
[12/11/2026 22:49:05] Y A Cờ Long: *chưa đăng nhập -> bắt buộc đăng nhập mới bình luận được -> ĐẠT
*chưa test các trường hợp lỗi bảo mật hoặc bị tấn công bảo mật để can thiệp vào bình luận của user khác vì đang tạm tin tưởng BE bên anh Long

## Ghi công bổ sung (ngày 2026-11-23)

Người thực hiện: Văn Hoàng (FE + kiểm thử trên trình duyệt). Làm việc cùng Claude Code, nhánh `feature/fule-product-filter`. Phần kế hoạch, BE và database do Long, xem `long.md`.

### FE
- `FE/src/views/products/catalog.vue`:
  - Khung lọc đầy đủ: tìm kiếm, danh mục, khoảng giá, độ giữ nếp, độ bóng, tag (gợi ý tag phổ biến, nhập nhiều tag), sắp xếp mới nhất / cũ nhất.
  - Mọi bộ lọc nằm trên URL (chia sẻ link được, trợ lý Fule mở đúng trang đã lọc); đổi trang / đổi sắp xếp không làm mất chữ đang nhập dở.
  - Danh sách sắp xếp, thang mức, giới hạn lấy từ API `api-product-filters`, không viết cứng ở FE.
  - Câu dẫn kết quả: "Tìm thấy N sản phẩm khớp với …".
  - Sửa lỗi sidebar bộ lọc cao hơn màn hình bị che: giới hạn chiều cao, cuộn bên trong.
- `FE/src/components/admin/AdminVariantManager.vue`: ô chọn độ giữ nếp, độ bóng khi admin tạo / sửa biến thể.
- `FE/src/components/chat/ChatAssistant.vue`: chip "trợ lý đã làm gì" cho mọi tool (tra cứu kèm bộ lọc, đơn nháp, chọn bài hát).
- `FE/src/helpers/ai/chatAssistant.js`: cho phép trợ lý mở catalog kèm đủ 8 tham số lọc.

### Kiểm thử trên trình duyệt (Chrome qua ngrok)
- `npm run build` trong `FE/`: OK.
- Các luồng đã kiểm thử: lọc từng điều kiện và kết hợp nhiều điều kiện, nhập tag, sắp xếp, phân trang, xóa bộ lọc, báo lỗi giá; sidebar trên màn hình thấp và trên điện thoại; trang admin biến thể; chat với Fule (mở catalog đã lọc, chip tool).
