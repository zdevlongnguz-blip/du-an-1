# Task Tuần 3 - Văn Hoàng

Tuần: 3
Ngày giao task này: 10/11/2026
Phạm vi: phần FE khó hơn trên trang chi tiết sản phẩm: gửi/sửa/xóa bình luận của user đang đăng nhập và thêm giỏ theo số lượng. Không sửa bất kỳ file nào trong `BE/`, không đổi API contract, schema hoặc migration.

## Tạo Branch Trước Khi Làm

- Trước khi code, yêu cầu AI trong VS Code kiểm tra `git status`, fetch code mới nhất từ remote `origin`, rồi tạo branch riêng cho task này từ `origin/dev` mới nhất. Không code trực tiếp trên `dev` hoặc branch của người khác.
- Branch đề xuất: `feature/week3-vanhoang-product-detail`. Ví dụ: `git fetch origin` rồi `git switch -c feature/week3-vanhoang-product-detail origin/dev`.
- Nếu working tree không sạch hoặc branch đề xuất đã tồn tại, dừng và báo admin để thống nhất; không bỏ thay đổi hiện có, không dùng chung branch với Tuấn Anh.

## Điều Kiện Bắt Đầu

- Trước khi code, Văn Hoàng phải chạy file SQL đầy đủ được cung cấp để chuẩn bị database local/dev và xác nhận dữ liệu cần cho task dùng được.
- Nếu không tìm thấy file SQL đầy đủ, file hiện có thiếu bảng/dữ liệu cần thiết, hoặc chạy SQL thất bại: dừng task và báo admin để xin file SQL đầy đủ. Không tự tạo/chỉnh SQL, không sửa BE để né lỗi database.
- Chỉ chạy trên database local/dev đã được chỉ định; không chạy script có thể xóa dữ liệu trên môi trường dùng chung/production.

## Mục Tiêu

Hoàn thiện các tương tác trên `ProductDetail.vue`: thành viên có thể gửi bình luận mới, sửa/xóa bình luận đã duyệt của chính mình và thêm sản phẩm vào giỏ với số lượng chọn được. Khách chưa đăng nhập được hướng dẫn đăng nhập; mọi thao tác có trạng thái và thông báo phù hợp.

## Hiện Trạng Đã Rà Soát

- `FE/src/views/products/ProductDetail.vue` đang tải sản phẩm và bình luận; form gửi comment, nút sửa/xóa và stepper/nút thêm giỏ vẫn là giao diện demo.
- Danh sách bình luận public chỉ trả comment `status = 1`; comment mới tạo bởi API được lưu `status = 0` chờ duyệt.
- API `api-comment-create`, `api-comment-update`, `api-comment-delete` và `api-cart-add` đã có; BE kiểm tra Bearer JWT và quyền sở hữu ở server.
- `api.js`, auth session và toast service hiện có phải được tái sử dụng, không sửa cơ chế dùng chung.
- Tuấn Anh sở hữu `ProductCardCatalog.vue` và `ProductCardTop5.vue` để thêm giỏ nhanh. Không sửa các file đó hoặc thay đổi markup của chúng.

## Phạm Vi Thực Hiện

### Trạng Thái, Thông Báo Và Validate

- Bao phủ các trạng thái phù hợp: loading/đang gửi, thành công, lỗi, rỗng và thử lại; không báo thành công giả hoặc nhầm lỗi API thành danh sách rỗng.
- Dùng `FE/src/helpers/ui/toast.js` cho thông báo chung thành công/lỗi; khóa nút khi request đang chạy để tránh gửi lặp.
- Validate phía FE và hiện lỗi thân thiện ngay dưới trường nhập. Lỗi validation từ BE hiển thị bằng toast chung từ `response.data.message`, không ánh xạ thành lỗi từng trường.
- Tham khảo pattern form/validation của các trang auth hiện có. Validation FE hỗ trợ trải nghiệm, BE vẫn quyết định dữ liệu cuối cùng.

### Gửi Bình Luận

- Hoàn thiện textarea/form trong `ProductDetail.vue`: bind nội dung, từ chối rỗng/chỉ khoảng trắng và giới hạn tối đa 2.000 ký tự; hiện lỗi dưới trường và khóa nút khi đang gửi.
- Gửi `POST /?action=api-comment-create` với JSON `{ "product_id": product.id, "content": content }`. Không gửi user ID; BE lấy danh tính từ JWT.
- Thành công: xóa nội dung form và báo bình luận đang chờ duyệt. Không tự chèn comment `status = 0` vào danh sách public hoặc tăng count public.
- Lỗi: giữ nội dung người dùng đã nhập và báo lỗi BE bằng toast chung.

### Sửa/Xóa Bình Luận Của Mình

- Chỉ hiện Sửa/Xóa cho comment có `comment.user_id` khớp ID user hiện tại lấy từ `getUser()` trong `FE/src/helpers/auth/session.js`. Đây chỉ là điều kiện hiển thị UI; không gửi user ID lên API và không thay thế kiểm tra quyền của BE.
- Sửa inline với Hủy/Lưu; validate nội dung ở FE không rỗng, tối đa 2.000 ký tự, lỗi dưới trường. Gọi `PUT /?action=api-comment-update&id=<commentId>` với `{ "content": content }`.
- Xóa cần xác nhận trước khi gọi `DELETE /?action=api-comment-delete&id=<commentId>`; không gửi body. Khóa nút khi đang xử lý.
- Sau sửa/xóa, tải lại trang comment hiện tại, cập nhật count/phân trang. Nếu xóa item cuối của trang thì lùi trang phù hợp; lỗi 401/404 không được giả thành thành công.
- BE là nơi xác minh ownership. Không hiển thị thao tác cho comment người khác và không dựa vào UI làm biện pháp bảo mật.

### Hỗ Trợ Chuẩn Bị Dữ Liệu Test

- Tuấn Anh phụ trách chạy test tích hợp. Để test sửa/xóa, cần comment của chính tài khoản test với `status = 1`, vì API public chỉ trả comment đã duyệt.
- Khi bàn giao, cung cấp cho Tuấn Anh thông tin sản phẩm phù hợp và xác nhận luồng comment mới đang chờ duyệt (`status = 0`); không tự sửa dữ liệu người khác.
- Việc tạo/duyệt comment fixture trên DB local/dev và cleanup sau test do Tuấn Anh thực hiện theo task `vibecoder/week-3/task/tuananh.md`. Nếu cần quyền DB/admin, báo admin; không sửa BE.

### Thêm Giỏ Từ Trang Chi Tiết

- Nối stepper và nút thêm giỏ trong `ProductDetail.vue` với `POST /?action=api-cart-add`, body `{ "product_id": product.id, "quantity": quantity }`.
- Stepper chỉ cho số nguyên dương và không vượt stock hiện có; hết hàng thì quantity bằng 0 và nút bị khóa. Không tự sửa stock phía client.
- Thành viên chưa đăng nhập không gọi API; giữ CTA đăng nhập hiện có. Thành viên đã đăng nhập nhận toast phù hợp cho thành công/lỗi; xử lý HTTP 409 hết hàng/vượt kho.
- Khóa nút trong lúc gửi; nếu API lỗi, không xóa hoặc âm thầm thay quantity người dùng đã chọn.
- Không triển khai Mua ngay, trang giỏ, checkout, cập nhật/xóa item trong giỏ hoặc badge toàn site.

## Contract BE Đã Xác Nhận

### Tạo Bình Luận

- `POST /?action=api-comment-create`, yêu cầu Bearer JWT; body `{ "product_id": <integer>, "content": <string> }`.
- Thành công HTTP 201: `status: "success"`, `comment: { id, product_id, content }`; comment mới ở `status = 0` chờ duyệt.
- Lỗi chính: 401 chưa đăng nhập/token lỗi; 404 sản phẩm không tồn tại; 405 sai method; 422 ID/nội dung không hợp lệ.

### Sửa Bình Luận

- `PUT /?action=api-comment-update&id=<commentId>`, yêu cầu Bearer JWT; body `{ "content": <string> }`.
- Thành công HTTP 200: `status: "success"`, `comment: { id, content }`.
- Lỗi chính: 401 chưa đăng nhập/token lỗi; 404 comment không tồn tại/không thuộc user; 405 sai method; 422 nội dung không hợp lệ.

### Xóa Bình Luận

- `DELETE /?action=api-comment-delete&id=<commentId>`, yêu cầu Bearer JWT, không cần body.
- Thành công HTTP 200: `status: "success"` kèm message.
- Lỗi chính: 401 chưa đăng nhập/token lỗi; 404 comment không tồn tại/không thuộc user; 405 sai method.

### Thêm Vào Giỏ

- `POST /?action=api-cart-add`, yêu cầu Bearer JWT; body `{ "product_id": <integer>, "quantity": <integer> }`.
- Thành công HTTP 201: `status: "success"`, `cart: { items, total_amount }`; sản phẩm trùng được cộng quantity.
- Lỗi chính: 401 chưa đăng nhập/token lỗi; 404 sản phẩm không tồn tại; 405 sai method; 409 hết hàng/vượt stock; 422 dữ liệu không hợp lệ.

Không đổi action, method, field, response shape, yêu cầu đăng nhập hoặc schema. Văn Hoàng không sửa BE. Nếu API thực tế không khớp contract, ghi request/response và báo admin/owner BE.

## Tiêu Chí Nghiệm Thu

- Comment hợp lệ gửi HTTP 201, form được xóa và có thông báo chờ duyệt; comment pending không xuất hiện nhầm trong danh sách public.
- Comment rỗng/chỉ khoảng trắng/quá 2.000 ký tự bị chặn tại FE với lỗi đúng trường; lỗi BE hiển thị toast chung và không làm mất nội dung đang nhập.
- Chỉ comment của user hiện tại có nút Sửa/Xóa; sửa thành công cập nhật nội dung, xóa có xác nhận và cập nhật đúng count/phân trang.
- Comment người khác không có nút thao tác; request sai quyền nhận lỗi API phù hợp. FE không gửi user ID.
- Ca sửa/xóa dùng comment của chính user với `status = 1` trên DB local/dev; sau test fixture được xóa hoặc khôi phục status ban đầu.
- Guest không thể gọi API bình luận/giỏ từ UI và được hướng dẫn đăng nhập.
- Thêm giỏ gửi đúng product ID và quantity; chặn quantity sai/vượt stock, khóa nút khi request, và hiển thị lỗi 409 mà không báo thành công giả.
- Văn Hoàng bàn giao code và hướng dẫn/repro các điểm đã triển khai; không nhận quyền owner kiểm thử tích hợp. Tuấn Anh chạy `npm run build` và kiểm thử browser/API cho cả hai task theo `vibecoder/week-3/task/tuananh.md`.
- Nếu Tuấn Anh phát hiện lỗi trong phần ProductDetail, Văn Hoàng sửa đúng file mình sở hữu rồi bàn giao lại để Tuấn Anh retest. Lỗi BE/API được báo admin/owner BE; không sửa PHP hoặc `BE/test-api.md`.
- `git diff --check` pass; không sửa nội dung `FE/src/helpers/api/client.js`, không thêm endpoint hoặc dependency.

## Phạm Vi File Và Ownership

- Owner code Văn Hoàng: chỉ `FE/src/views/products/ProductDetail.vue`.
- Owner code Tuấn Anh: `FE/src/components/ProductCardCatalog.vue` và `FE/src/components/ProductCardTop5.vue`; Tuấn Anh cũng là owner kiểm thử tích hợp cuối cho cả hai task.
- Tuấn Anh chỉ bắt đầu test ProductDetail sau khi Văn Hoàng báo handoff; không sửa ProductDetail. Nếu có lỗi, giao lại Văn Hoàng sửa và Tuấn Anh retest.
- Không sửa file BE, nội dung `FE/src/helpers/api/client.js`, module auth/toast dùng chung hoặc component của Tuấn Anh. Nếu thấy cần sửa file dùng chung, dừng và báo admin để phân công owner.

## Ghi Chú Phối Hợp

- Trước khi code, chạy SQL đầy đủ theo Điều Kiện Bắt Đầu và kiểm tra `git status`; database chưa sẵn sàng thì dừng, báo admin.
- Không gửi user ID/role từ client; quyền sửa/xóa do BE xác minh.
- Không sửa hoặc ghi đè task brief này. Sau khi code xong, ghi báo cáo implementation/handoff vào `vibecoder/week-3/mission-completed/vanhoang.md`; nếu folder chưa tồn tại trên branch thì tạo folder trước. Log gồm branch, file đã sửa, hành vi, các bước tái hiện/điểm cần test, lỗi còn lại và vấn đề đã báo admin. Không ghi build/browser/API test là pass; Tuấn Anh cập nhật kết quả kiểm thử tích hợp trong log của mình sau khi chạy.

## Chuẩn Bị MCP Thiết Kế Cho Task Sau

- Task hiện tại không cần tham khảo Stitch/Figma; không để cấu hình MCP làm chậm hoặc chặn phần comment/giỏ.
- Chuẩn bị cho task UI tiếp theo bằng cấu hình mẫu `.vscode/mcp.example.json` trong VS Code. Bản local `.vscode/mcp.json` bị Git ignore; không commit cấu hình local hoặc API key.
- Project Stitch của team: [Sharp Barber](https://stitch.google.com/projects/6021813646882729612).
- API key Stitch chỉ nhập qua secure prompt `stitch_api_key`; không ghi key vào source, task log, terminal output hoặc Git.
- Xác minh MCP truy cập được project/screen team. Thiếu key/quyền thì báo admin theo quy trình an toàn.
- Nếu thiết kế chỉ có trong Zalo, nhờ người gửi/admin cung cấp file/ảnh gốc được phép dùng, đưa vào Figma team rồi tham khảo frame qua Figma MCP trong VS Code hoặc Cursor.
- Chưa có quyền truy cập thì ghi blocker, không tự đoán thiết kế. Phần MCP dành cho task sau, không mở rộng task hiện tại sang BE.