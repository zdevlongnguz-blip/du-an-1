# Công việc BE-FE - Long

Branch gốc: `feature/FE/profile+quenmk+doimatkhau`
Branch cập nhật ngày 2026-11-07: `feature/design-master-layout+auth-ui`

## Nền tảng
- Thiết lập cấu trúc dự án BE PHP MVC và FE Vue 3/Vite, Vue Router, Axios, Bootstrap; tích hợp build FE vào BE.

## Backend features
- Xây dựng các API cho hệ thống, bài viết, sản phẩm, danh mục và dữ liệu demo.
- Hoàn thiện luồng xác thực: đăng ký, đăng nhập, đăng xuất, xác minh email, quên/đặt lại mật khẩu và đổi mật khẩu.
- Tích hợp email BE cho xác minh tài khoản và reset mật khẩu; thêm trang cầu nối cyberpunk cho link reset.
- Tạo các model dùng chung và model người dùng, sản phẩm.
- Thiết lập database cho ứng dụng và bổ sung migration cần thiết.

## Frontend features
- Dựng layout salon, trang chủ, trang test và danh mục sản phẩm.
- Dựng UI/UX auth cơ bản cho Login, Register, Profile, Forgot/Reset Password và Change Password.
- Thêm validation cho các form, toast thông báo dùng chung và trang 404.
- Tích hợp API client, lưu trạng thái đăng nhập và cập nhật menu theo trạng thái người dùng.

## Cập nhật nhánh `feature/design-master-layout+auth-ui` - 2026-11-07

### Giao diện Sharp Barber
- Đối chiếu thiết kế Stitch Sharp Barber (App Shell, Login, Register) và áp dụng nhận diện sáng, typography, navbar, footer cho FE.
- Thay trang chủ demo bằng layout Sharp Barber; để vùng banner và Top 5 products trống, không tạo dữ liệu sản phẩm giả.
- Tạo `AuthPage.vue` dùng chung cho Login, Register, Forgot/Reset Password, Profile và Change Password.
- Responsive theo breakpoint Bootstrap: navbar desktop đầy đủ; dưới 992px dùng hamburger và menu dọc; auth cards dùng `col-12 col-md-6 col-lg-4`.
- Desktop/tablet có viền auth card; mobile bỏ viền, input tối thiểu 44px và font 16px; footer xếp dọc dưới 768px.
- Đánh dấu route Login/Register/Profile đang hoạt động trong menu; giữ menu tự đóng sau điều hướng.
- Ghim navbar cùng toast/banner hệ thống trong một sticky stack. Bỏ banner Welcome riêng; lời chào Welcome dùng toast service và không ghi đè thông báo khác đã có.
- Hiển thị nhãn “Thông báo” dạng label trong toast, thay cho tiền tố ngoặc vuông.

### Auth và validation FE
- Giữ nguyên endpoint, payload và contract BE; không thêm trường Full name vì API/DB hiện chưa hỗ trợ.
- Thêm lỗi FE ngay dưới từng trường cho Login, Register, Forgot Password, Reset Password và Change Password; bổ sung `aria-invalid`/`aria-describedby`.
- Lỗi validation FE chặn submit trước khi gọi API; lỗi BE tiếp tục hiển thị trong toast hệ thống.
- Đối chiếu độ dài mật khẩu: BE dùng `strlen()` giới hạn 8-72 byte; FE dùng `TextEncoder` đo byte UTF-8 cùng giới hạn. Sửa nội dung lỗi FE để nói rõ đơn vị byte UTF-8.
- Đưa link Forgot Password xuống dòng riêng ngay dưới ô Password.

### Kiểm thử
- `npm run build` trong `FE/`: pass ở lượt kiểm thử cuối.
- Browser kiểm tra Login/Register tại 1200px, 768px và 375px: active route đúng khi test với localStorage sạch; không tràn ngang.
- Register submit rỗng hiện lỗi dưới 3 trường và không gửi API request.
- Mock lỗi HTTP 422 từ login vẫn hiện message BE trong toast “Thông báo”.
- Kiểm tra sticky sau khi cuộn 600px: sticky stack ở `top: 0`; toast vẫn nằm trong stack.
- Border auth card có ở desktop; mobile bỏ border theo thiết kế.

## Cập nhật nhánh `long/feature-update-database+BE-api` - 2026-11-08

### Database và sản phẩm
- Tạo migration bổ sung `created_at`/`updated_at` còn thiếu cho `categories`, `products`, `banners`; tạo bảng `product_images` cho album; thêm `view_count` và `sales_count` cho `products`.
- Tạo seeder sản phẩm: đặt lượt xem/lượt bán mẫu cho 24 sản phẩm hiện có và thêm ảnh thumbnail làm ảnh slide đầu tiên trong album của từng sản phẩm; seeder chạy lặp không nhân bản ảnh.
- Chạy migrations và seeder trên database hiện có, không chạy lại `create_database.sql`. Xác nhận 24/24 sản phẩm có bản ghi album, tổng lượt xem mẫu 68.350 và tổng lượt bán mẫu 4.258.
- Cập nhật README hướng dẫn chạy migration và seeder trên database đã khởi tạo.

### API chi tiết sản phẩm
- Thêm endpoint public `GET ?action=api-product-detail&slug=<slug>`; trả sản phẩm theo slug, `category_name`, thumbnail `image`, bộ đếm và mảng `images` được sắp theo `sort_order`.
- Slug thiếu/không hợp lệ trả HTTP 400; sản phẩm không tồn tại trả HTTP 404. Giữ nguyên endpoint `api-products`; endpoint này không lấy album.
- Gọi thử với slug `sap-vuot-toc-hanz-de-fuko-claymation`: response thành công, có `category_name`, `view_count`, `sales_count` và một ảnh album.

### Kiểm tra và lưu ý
- PHP lint pass cho router, `ProductController` và model `Product`; `git diff --check` pass.
- Thư mục `BE/assets/uploads/` hiện chỉ có `default.jpg`; các ảnh sản phẩm/thumbnail đang là đường dẫn trong database, cần thêm file ảnh thực để hiển thị.

## Cập nhật nhánh `long/feature-comments-be` - 2026-11-08

### Database và migration runner
- Tạo migration `20261108_create_comments_table.sql`; comment liên kết với product và user bằng khóa ngoại `ON DELETE CASCADE`, có timestamp và index phục vụ truy vấn theo sản phẩm.
- Tạo seeder comments mẫu có kiểm tra trùng để có thể chạy lặp; giữ nguyên `create_database.sql` vì database đã được khởi tạo trước đó.
- Chạy migration và seeder trên database local `du-an-1`; xác nhận có 4 comments mẫu, chạy lại vẫn giữ 4 dòng và cả hai khóa ngoại đều cascade.
- Thêm `BE/database/run.php` để chạy migrations theo thứ tự tên file, ghi nhận migration đã áp dụng; seeder chỉ chạy khi truyền `--seed`. Đã kiểm tra cú pháp PHP, chưa chạy runner này trên database.

### API comments
- Thêm model `Comment`, controller `CommentController` và endpoint public `GET ?action=api-product-comments&slug=<slug>&page=<page>&per_page=<per_page>`.
- Endpoint trả comments mới nhất trước, có metadata phân trang; join `users` để trả thêm `email` và `role`. Sản phẩm không tồn tại trả HTTP 404; tham số không hợp lệ trả HTTP 400.
- Gọi thử với slug `sap-vuot-toc-hanz-de-fuko-claymation`: HTTP 200, trả đúng comment và pagination. Lưu ý endpoint public hiện trả email người bình luận.

### FE ProductDetail
- Thêm helper `FE/src/helpers/dd.js` để định dạng response thành JSON dễ đọc.
- Thêm route `/product-detail/:slug` và trang `ProductDetail.vue`, gọi hai endpoint chi tiết sản phẩm/comments rồi in nguyên response; chưa dựng UI sản phẩm.
- `npm --prefix FE run build` pass; PHP lint pass cho model/controller/router comments.

## Cập nhật công việc BE - 2026-11-09

### API top 5 sản phẩm
- Thêm endpoint public `GET ?action=api-products-top&sort_by=views|sales`, trả tối đa 5 sản phẩm xếp giảm dần theo `view_count` hoặc `sales_count`.
- Nếu không truyền `sort_by`, mặc định chọn `sales`; response thành công có `sort_by` để client biết tiêu chí đang dùng. Giá trị không hợp lệ trả HTTP 400.
- Thêm `Product::getTop($sortBy = 'sales')`; tên cột ORDER BY giới hạn bằng allowlist.
- Kiểm tra PHP lint và gọi thử API local: cả hai tiêu chí trả đúng 5 sản phẩm theo thứ tự; đầu vào sai trả HTTP 400.

### Mặc định phân trang comments
- Đặt mặc định trực tiếp trong `Comment::getByProductSlug`: `page = 1`, `perPage = 10`, đồng bộ với API controller.
- PHP lint cho `Comment.php` pass.

## Cập nhật công việc BE - nhánh `luongviethoang/feature/update-ui-catalog` - 2026-11-09

### Lọc sản phẩm theo slug danh mục
- Đổi tham số lọc của `GET ?action=api-products` từ `category_id` sang `category`; giá trị `category` là slug danh mục. Giá trị không phải chuỗi hoặc dài hơn 255 ký tự trả HTTP 400.
- Đổi `Product::getFiltered()` để lọc theo `categories.slug`; bổ sung JOIN vào truy vấn đếm để số lượng và phân trang khớp với danh sách sản phẩm.
- Giữ nguyên cấu trúc `products.data` và `products.pagination`; trường trong `filters` đổi thành `category`.
- Cột `categories.slug` đã có trong schema nên không cần migration. FE và link banner dùng cùng tham số `category`.

### Kiểm tra
- PHP lint pass cho `ProductController.php` và `Product.php`; `git diff --check` pass.
- Chạy lại `BE/database/seed/banners.sql` trên database local `du-an-1`; xác nhận hai CTA catalog dùng `category=`, mỗi link có đúng một bản ghi và không còn CTA dùng `category_slug`.
- Chưa gọi API với database thật để kiểm thử tích hợp.
