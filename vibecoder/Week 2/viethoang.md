# Nhật ký công việc - Viethoang

Ngày: 2026-11-09
Branch: `luongviethoang/feature/top5sp`

## Frontend - Top 5 sản phẩm
- Nối trang chủ `HomeView.vue` với API `api-products-top`; mặc định hiển thị sản phẩm bán chạy, có thể chuyển giữa Most viewed và Best-selling.
- Dùng `sort_by` và response `sort_by`/`products` từ API để hiển thị đúng tiêu chí và dữ liệu.
- Tạo component `ProductCardTop5.vue` theo thiết kế Stitch Sharp Barber: Bento grid bất đối xứng, hiệu ứng hover, thứ hạng, giá và chỉ số lượt xem/lượt bán.
- Thêm trạng thái Out of stock từ trường `stock`; nút Add to cart được disable khi hết hàng. Nút hiện chỉ mang tính giao diện, chưa xử lý giỏ hàng.
- Bổ sung loading, lỗi/thử lại và empty state cho khu vực Top 5; giữ nguyên chiều ngang banner và trang chủ.
- Bỏ View all khỏi tiêu đề Top 5; thêm Catalog vào menu desktop/mobile với trạng thái active riêng.
- Tinh chỉnh active Catalog desktop thành gạch chân; giữ nền active trên mobile và bổ sung padding cho mục active Login/Register/Profile.

## Kiểm tra
- `npm run build` trong `FE/`: pass.
- Browser kiểm tra đổi chế độ xếp hạng, layout desktop/mobile, active Catalog và nút Add to cart chỉ hiển thị.
- Giả lập `stock = 0`: badge Out of stock hiển thị và nút bị disable; dữ liệu thật hiện không có sản phẩm hết hàng.
- `git diff --check`: pass.
- Dev server chạy tại `http://localhost:5174/`.

## Cập nhật nhánh `luongviethoang/feature/update-ui-catalog` - 2026-11-09

### Frontend - Catalog sản phẩm
- Thiết kế lại trang Catalog theo giao diện Sharp Barber: thanh tìm kiếm/lọc danh mục, lưới sản phẩm responsive và phân trang.
- Thêm trạng thái loading dạng skeleton, lỗi có nút thử lại, trạng thái rỗng có thể xóa bộ lọc; tải danh mục riêng để lỗi danh mục không làm mất danh sách sản phẩm.
- Tạo `ProductCardCatalog.vue` hiển thị ảnh, tên, danh mục, giá, lượt xem/lượt bán và tồn kho; có fallback khi ảnh lỗi, link sang chi tiết sản phẩm và nút giỏ hàng chỉ mang tính giao diện.
- Đổi bộ lọc FE sang query param `category` có giá trị là `category.slug`; đồng bộ URL và request `api-products`.

### Frontend - CTA thẻ Top 5
- Thay CTA dạng chữ bằng hai nút icon xem chi tiết và giỏ hàng; nút xem mở trang chi tiết theo slug.
- Gom CTA vào cùng cụm, hiện khi hover/focus và luôn hiện trên thiết bị không có hover; nút giỏ bị vô hiệu hóa khi sản phẩm hết hàng. Chức năng thêm giỏ chưa được triển khai.

### Kiểm tra
- `npm run build` trong `FE/` và `git diff --check` pass.
- Chưa kiểm tra browser cho giao diện Catalog/CTA sau các thay đổi của nhánh này.

## Sửa lỗi hiển thị thống kê Product Card - 2026-11-09

### Frontend - ProductCardCatalog và ProductCardTop5
- Xác nhận API danh sách và top sản phẩm trả đúng hai trường `view_count` và `sales_count`; lỗi nằm ở bố cục CSS.
- Đưa lượt xem/lượt bán ra khỏi vùng `.details` bị thu gọn bằng `max-height: 0`, để thống kê luôn hiển thị kể cả khi không hover.
- Xếp số và nhãn thành hai hàng trong từng cột để dễ đọc trên card hẹp; bổ sung `line-clamp` chuẩn song song với khai báo WebKit trong Catalog.
- Gỡ rule `.top5-card:focus-visible` không hoạt động vì `<article>` không nhận focus.
- Rà `FE/src/style.css`: không có selector ghi đè hai card. `ProductCard.vue` không còn được sử dụng nhưng giữ nguyên vì file đang có thay đổi cục bộ chưa commit.

### Kiểm tra
- `npm run build` trong `FE/`: pass; diagnostics hai component không có lỗi.
- Browser kiểm tra trang Catalog và trang chủ: lượt xem/lượt bán đều hiện khi không hover.
- Dev server chạy tại `http://127.0.0.1:5173/`.
