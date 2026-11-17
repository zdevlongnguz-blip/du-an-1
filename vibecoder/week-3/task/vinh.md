# Task Tuần 3 - Vinh: Sản Phẩm Cùng Danh Mục Trên Trang Chi Tiết

Tuần: 3
Ngày giao task này: 17/11/2026
Phạm vi: hiển thị danh sách sản phẩm cùng danh mục (có giới hạn số lượng) ở cuối trang chi tiết sản phẩm. Task này cần một thay đổi nhỏ ở BE. Phần BE do owner BE làm riêng, Vinh không sửa `BE/`.

## Tạo Branch Trước Khi Làm

- Kiểm tra `git status`, fetch `origin`, rồi tạo branch từ `origin/dev` mới nhất.
- Branch đề xuất: `feature/week3-vinh-related-products`.
- Không code trực tiếp trên `dev` hoặc branch của người khác.

## Điều Kiện Bắt Đầu

- Chạy file SQL đầy đủ theo quy trình của team để chuẩn bị database local/dev, và xác nhận có sản phẩm cùng danh mục để test. Thiếu file hoặc lỗi thì dừng và báo admin.
- Không chạy script có thể xóa dữ liệu trên môi trường dùng chung/production.

## Hiện Trạng Đã Rà Soát

- `api-product-detail` (`BE/controllers/ProductController.php`) trả `category_id` và `category_name`, **không trả slug danh mục**.
- `api-products` lọc theo `category` là **slug** (`categories.slug`), không lọc được theo `category_id`. Vì vậy FE không thể dùng trực tiếp `api-products` với dữ liệu trang chi tiết hiện có.
- `api-products` có `per_page` nhưng không có tham số loại trừ sản phẩm đang xem, và không giới hạn trần cho `per_page`.
- Kết luận: cần một thay đổi nhỏ ở BE. Cách làm đề xuất bên dưới, chờ owner BE xác nhận trước khi FE code.

## Xung Đột Quyền Sở Hữu Cần Giải Quyết Trước

- `FE/src/views/products/ProductDetail.vue` là file đang do **Văn Hoàng** sở hữu trong task tuần 3 (xem `vanhoang.md`).
- Vinh chỉ được sửa file này sau khi Văn Hoàng bàn giao, hoặc admin xác nhận cho phép sửa song song.
- Để giảm xung đột: phần UI đặt trong component mới `FE/src/components/ProductRelated.vue`. Trong `ProductDetail.vue` chỉ thêm đúng một dòng gọi component, đặt ở cuối trang.

## Contract Đề Xuất (chờ owner BE xác nhận)

### Phương án đề xuất: endpoint mới

- `GET /?action=api-product-related&slug=<slug>&limit=<n>`, không yêu cầu đăng nhập (giống `api-product-detail`).
- Logic: lấy `category_id` của sản phẩm có `slug` đó; trả sản phẩm cùng `category_id`, **loại trừ chính sản phẩm đó**, sắp theo lượt xem giảm dần hoặc id giảm dần (owner BE chọn và ghi rõ).
- `limit`: mặc định 4, tối thiểu 1, tối đa 8. Ngoài khoảng thì trả 422 hoặc kẹp về giới hạn (owner BE chọn và ghi rõ).
- Thành công HTTP 200: `{ "status": "success", "products": [ ...các item cùng dạng với api-products... ] }`.
- Lỗi: 400 khi thiếu/sai slug; 404 khi slug không tồn tại; 405 sai method.
- Mỗi item dùng cùng shape với `formatProductListItem` của `api-products` để FE tái sử dụng `ProductCard`.

### Phương án dự phòng (nếu không muốn endpoint mới)

- Thêm `category_slug` vào response `api-product-detail` (thay đổi cộng thêm, không phá contract cũ).
- FE gọi `api-products?category=<slug>&per_page=<limit+1>`, rồi tự loại sản phẩm đang xem. Nhược điểm: lấy dư dữ liệu và không có giới hạn trần.

Không được tự dựng contract. Owner BE phải xác nhận trước khi Vinh code phần gọi API.

## Phạm Vi Thực Hiện (FE)

- Tạo `FE/src/components/ProductRelated.vue`: nhận `slug` (hoặc danh sách sản phẩm), gọi API, có trạng thái đang tải, lỗi kèm nút thử lại, và rỗng (ẩn cả khối nếu không có sản phẩm nào).
- Dùng lại `ProductCard`/`ProductCardCatalog` hiện có để hiển thị; không tạo card mới nếu không cần.
- Trong `ProductDetail.vue` (sau khi có quyền sửa): thêm đúng một dòng gọi `<ProductRelated>` ở cuối trang. Không thay đổi phần khác.
- Chỉ dùng `api` từ `@/helpers/api/client`. Không dùng `fetch`, không `axios.create`, không hardcode URL.
- Comment tiếng Việt theo mục 8 của `FE/CLAUDE.md`. Đầu `<script setup>` của component mới có 1-3 dòng mô tả.
- Không đổi `router/index.js`, `App.vue`, `style.css`, `client.js`.

## Tiêu Chí Nghiệm Thu

- Trang chi tiết hiển thị tối đa `limit` sản phẩm cùng danh mục; không có sản phẩm nào là chính trang đang xem.
- Có trạng thái loading, lỗi (có nút thử lại), rỗng (ẩn khối).
- Lỗi API không hiển thị thành "không có sản phẩm liên quan".
- Click vào sản phẩm liên quan chuyển sang trang chi tiết đúng slug.
- `npm run build` trong `FE/` pass; ghi kết quả thật.
- Kiểm tra trình duyệt ở ~400px và desktop; ghi rõ phần nào chưa kiểm tra.
- `git diff`: chỉ có `ProductRelated.vue` mới và một dòng trong `ProductDetail.vue` (sau handoff). Không có thay đổi trong `BE/`.

## Phần BE (giao riêng, owner: Long)

- Viết endpoint `api-product-related` (hoặc thêm `category_slug` theo phương án dự phòng) theo contract đã xác nhận.
- Thêm vào `BE/routes/index.php` và `BE/controllers/ProductController.php`, theo đúng pattern của `api-products-top`.
- Kiểm tra bằng curl: đúng sản phẩm cùng danh mục, không có chính nó, `limit` ngoài khoảng, slug sai (400), slug không có (404).
- Cập nhật `BE/test-api.md` (chỉ phần BE do Long phụ trách).

## Thứ Tự Thực Hiện

1. Owner BE xác nhận contract (phương án chính hoặc dự phòng).
2. Long làm phần BE và báo Vinh khi xong.
3. Admin xác nhận quyền sửa `ProductDetail.vue` (sau handoff của Văn Hoàng).
4. Vinh code `ProductRelated.vue` và gắn vào trang chi tiết.
5. Kiểm thử và báo cáo.

## Ghi Chú Phối Hợp

- Không sửa BE. Nếu phát hiện lỗi BE khi test, ghi request/response thực tế và báo owner BE.
- Báo cáo sau khi xong: ghi vào `vibecoder/week-3/mission-completed/vinh.md`, mục riêng cho task này. Chỉ ghi điều đã kiểm chứng.
