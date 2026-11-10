# Task Tuần 3 - Tuấn Anh

Tuần: 3
Ngày giao task này: 10/11/2026
Phạm vi: chỉ hoàn thiện giao diện và luồng gọi API trên FE. Tuấn Anh không được sửa bất kỳ file nào trong `BE/`, không đổi API contract, schema hoặc migration.

## Tạo Branch Trước Khi Làm

- Trước khi code, yêu cầu AI trong VS Code kiểm tra `git status`, fetch code mới nhất từ remote `origin`, rồi tạo branch riêng cho task này từ `origin/dev` mới nhất. Không code trực tiếp trên `dev` hoặc branch của người khác.
- Branch đề xuất: `feature/week3-tuananh-cart-card`. Ví dụ: `git fetch origin` rồi `git switch -c feature/week3-tuananh-cart-card origin/dev`.
- Nếu working tree không sạch hoặc branch đề xuất đã tồn tại, dừng và báo admin để thống nhất; không bỏ thay đổi hiện có, không dùng chung branch với Văn Hoàng.

## Điều Kiện Bắt Đầu

- Trước khi code, Tuấn Anh phải chạy file SQL đầy đủ được cung cấp để chuẩn bị database local/dev và xác nhận dữ liệu cần cho task dùng được.
- Nếu không tìm thấy file SQL đầy đủ, file hiện có thiếu bảng/dữ liệu cần thiết, hoặc chạy SQL thất bại: dừng task và báo admin để xin file SQL đầy đủ. Không tự tạo/chỉnh SQL, không sửa BE để né lỗi database.
- Chỉ chạy trên database local/dev đã được chỉ định; không chạy script có thể xóa dữ liệu trên môi trường dùng chung/production.

## Mục tiêu

Hoàn thiện thao tác thêm giỏ nhanh quantity 1 trên hai product card. Vì phần code này nhẹ hơn, Tuấn Anh đồng thời phụ trách kiểm thử tích hợp cho cả task của mình và task ProductDetail của Văn Hoàng sau khi được bàn giao.

## Hiện trạng đã rà soát

- `FE/src/components/ProductCardCatalog.vue` và `FE/src/components/ProductCardTop5.vue` có nút thêm giỏ dạng giao diện, chưa gọi API; phần code này thuộc Tuấn Anh.
- `FE/src/views/products/ProductDetail.vue` có luồng bình luận và stepper/giỏ dạng demo; phần code thuộc Văn Hoàng, còn kiểm thử tích hợp sau handoff thuộc Tuấn Anh.
- BE đã có route xác thực `api-cart-add`; danh tính user được lấy từ Bearer JWT ở middleware, không nhận user ID từ FE.
- `FE/src/services/api.js` đã phụ trách cấu hình request/interceptor; không sửa file này. Dùng `FE/src/services/toast.js` để báo thành công/lỗi.

## Phạm Vi Thực Hiện

### Trạng Thái, Thông Báo Và Validate

- Bao phủ các trạng thái phù hợp cho từng luồng: đang tải/đang gửi, thành công, lỗi, rỗng và thử lại khi có thể; không để lỗi API trông như danh sách rỗng hoặc thành công giả.
- Mọi thao tác gửi form/thêm giỏ phải có alert/toast thành công hoặc lỗi dùng `FE/src/services/toast.js`; khóa nút khi đang xử lý để tránh gửi lặp.
- Validate phía FE và hiện lỗi thân thiện ngay dưới đúng trường nhập. Với lỗi validation trả từ BE, không ánh xạ thành lỗi từng trường; hiển thị thông báo chung từ response qua toast.
- Tham khảo cách validate và hiển thị lỗi trong các trang auth FE hiện có; validation FE chỉ hỗ trợ trải nghiệm, BE vẫn là nơi kiểm tra dữ liệu cuối cùng.

### Thêm Giỏ Nhanh Từ Product Cards

- Chỉ nối nút thêm giỏ trong `FE/src/components/ProductCardCatalog.vue` và `FE/src/components/ProductCardTop5.vue` với `POST /?action=api-cart-add`; mỗi card gửi quantity bằng 1.
- Khách chưa đăng nhập không gọi API và được điều hướng/nhắc đăng nhập theo flow hiện có.
- Khóa nút của card đang gửi để tránh request lặp; báo thành công/lỗi qua `FE/src/services/toast.js`. Xử lý HTTP 409 hết hàng/vượt tồn kho, không báo thành công giả.
- Giữ nút vô hiệu hóa khi sản phẩm hết hàng theo `stock`. Không tự cập nhật stock phía client; BE vẫn là nơi quyết định cuối cùng.
- Không sửa `ProductDetail.vue` hoặc phần UI bình luận/giỏ trang chi tiết; đây là phần code của Văn Hoàng.

### Kiểm Thử Tích Hợp Cả Hai Task (Owner: Tuấn Anh)

- Chỉ bắt đầu test `ProductDetail.vue` sau khi Văn Hoàng hoàn tất, kiểm tra và bàn giao; xác nhận file không còn thay đổi đồng thời. Tuấn Anh là người chạy build/browser/API test cuối cho cả hai task.
- Chạy test task mình: quick add từ cả hai card, quantity đúng 1, guest, success, loading/khóa nút, stock 0, lỗi 401/404/409 và lỗi mạng.
- Chạy test task Văn Hoàng theo checklist trong `vibecoder/week-3/vanhoang.md`: tạo comment, sửa/xóa comment của mình, guest/owner, validation, stepper/quantity và thêm giỏ trên ProductDetail.
- Để test sửa/xóa comment đang public, dùng comment của chính tài khoản test trên DB local/dev. Ghi lại `comment_id`, `user_id`, `status` ban đầu; chỉ đặt `status = 1` cho đúng comment của mình. Sau test xóa fixture hoặc khôi phục status ban đầu. Không sửa comment người khác hoặc dữ liệu dùng chung/production.
- Nếu không truy cập DB được để chuẩn bị fixture, báo admin hỗ trợ; nếu phát hiện lỗi code của ProductDetail, gửi bằng chứng cho Văn Hoàng sửa rồi Tuấn Anh chạy lại đúng ca test. Tuấn Anh không sửa file của Văn Hoàng.
- Không test bằng việc sửa BE/schema/migration. Nếu contract API lỗi, lưu request/response và báo admin/owner BE.

## Contract BE Đã Xác Nhận

### Thêm Vào Giỏ

- `POST /?action=api-cart-add`, yêu cầu Bearer JWT; body `{ "product_id": <integer>, "quantity": 1 }`.
- Thành công HTTP 201: `status: "success"`, `cart: { items, total_amount }`; sản phẩm đã có trong giỏ sẽ được cộng số lượng.
- Lỗi chính: 401 chưa đăng nhập/token lỗi; 404 sản phẩm không tồn tại; 405 sai method; 409 hết hàng/vượt tồn kho; 422 dữ liệu không hợp lệ.

Không đổi action, method, field, response shape, yêu cầu đăng nhập hoặc schema. Tuấn Anh không sửa BE. Nếu kiểm thử cho thấy BE không khớp contract trên, ghi lại request/response thực tế và báo admin/owner BE để xử lý.

## Tiêu Chí Nghiệm Thu

- Hai product card gửi đúng `product_id`, quantity 1; success toast chỉ hiện sau response thành công.
- Guest không gọi API và nhận CTA/điều hướng đăng nhập; lỗi 401, 404, 409 và lỗi mạng hiển thị phù hợp.
- Sau khi Văn Hoàng bàn giao, Tuấn Anh xác nhận các tiêu chí nghiệm thu của cả hai task trong `vanhoang.md` và file này, bao gồm create/update/delete comment, quyền owner, validate, stepper và thêm giỏ ProductDetail.
- `npm run build` và kiểm thử browser/API tích hợp cho cả hai task phải pass. Nếu lỗi thuộc ProductDetail thì chuyển Văn Hoàng sửa rồi retest; nếu contract BE lỗi thì báo admin/owner BE, không sửa PHP hoặc `BE/test-api.md`.
- `git diff --check` pass; không sửa `FE/src/services/api.js`, không thêm endpoint hoặc dependency ngoài phạm vi.

## File Dự Kiến

- Code FE của Tuấn Anh: `FE/src/components/ProductCardCatalog.vue`, `FE/src/components/ProductCardTop5.vue`.
- File ProductDetail chỉ đọc/kiểm thử sau handoff; code do Văn Hoàng sở hữu.
- Có thể đọc contract/log BE để đối chiếu, nhưng không sửa file nào trong `BE/`.

## Ghi Chú Phối Hợp

- Trước khi bắt đầu code, chạy SQL đầy đủ theo phần Điều Kiện Bắt Đầu và kiểm tra `git status`; nếu database chưa sẵn sàng thì dừng, báo admin.
- Ưu tiên dùng API và service hiện có; không gửi user ID/role từ client.
- Tuấn Anh là owner code của hai product card và owner kiểm thử tích hợp cuối cho cả hai task.
- Văn Hoàng là owner code duy nhất của `FE/src/views/products/ProductDetail.vue`; Tuấn Anh không sửa file này. Chỉ test sau khi Văn Hoàng báo handoff rõ ràng.
- Không sửa BE dưới bất kỳ hình thức nào; nếu phát hiện lỗi contract/BE thì báo admin/owner BE kèm bằng chứng.
- Sau khi hoàn tất task, ghi đè nội dung giao việc trong chính file này bằng báo cáo hoàn thành thực tế trên branch: file FE đã đổi, hành vi đã làm, kết quả build/browser, lỗi còn tồn tại và vấn đề đã báo admin. Không ghi nhận hạng mục chưa kiểm chứng là đã xong.

## Chuẩn Bị MCP Thiết Kế Cho Task Sau

- Task hiện tại không cần tham khảo Stitch/Figma; không để việc cấu hình MCP làm chậm hoặc chặn code/test.
- Chuẩn bị cho task UI tiếp theo: dùng cấu hình mẫu `.vscode/mcp.example.json` để kết nối Stitch MCP trong VS Code. Tạo/cấu hình bản local `.vscode/mcp.json` theo cách VS Code hỗ trợ; file này đã bị Git ignore, không commit cấu hình local hoặc API key.
- Project Stitch của team: [Sharp Barber](https://stitch.google.com/projects/6021813646882729612).
- API key Stitch chỉ nhập qua secure prompt `stitch_api_key` như cấu hình mẫu yêu cầu; không ghi key vào source, task log, terminal output hoặc Git.
- Sau khi kết nối, kiểm tra MCP có thể truy cập project/screen thiết kế Sharp Barber của team. Nếu thiếu key hoặc quyền truy cập, báo admin để được cấp quyền/key theo quy trình an toàn; không tự tìm hoặc chia sẻ secret.
- Nếu thiết kế team chỉ có trong Zalo, nhờ người gửi/admin cung cấp file hoặc ảnh gốc được phép sử dụng, đưa thiết kế vào Figma của team rồi dùng Figma MCP đã được cấu hình trong VS Code hoặc Cursor để đọc frame làm tài liệu tham khảo cho task UI sau.
- Chưa có quyền truy cập Stitch/Figma thì ghi rõ blocker và yêu cầu hỗ trợ; không tự giả định chi tiết thiết kế. Phần chuẩn bị MCP này không mở rộng phạm vi task hiện tại sang BE.