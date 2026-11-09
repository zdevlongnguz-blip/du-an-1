# Nhật ký công việc - Tonduclong

Ngày: 2026-11-07
Branch: `tonduclong-feature-banner-slideshow`

## Đồng bộ nhánh và kiểm tra môi trường
- Kéo `origin/dev` vào branch hiện tại; Git báo `Already up to date`.
- Xác nhận `HEAD` ở commit `a018281`, cùng commit với `origin/dev` và `upstream/dev` tại thời điểm kiểm tra.
- Làm rõ trạng thái `ahead 8`: đây là chênh lệch so với remote-tracking của branch feature; các commit đã có trên `dev`, không phải tám commit riêng chưa push của người dùng.
- Kiểm tra `.vscode/mcp.json`: bị `.gitignore` bỏ qua, không được Git theo dõi và không bị pull từ `dev`.

## Kiểm tra thiết kế Stitch
- Kiểm tra project Stitch “Sharp Barber” và màn “Home Page Specification Suite”.
- Xác nhận thiết kế có slideshow banner, ba indicator, nút Previous/Next trên desktop/tablet; bản mobile cao khoảng 240px, ẩn mũi tên và giữ indicator.
- Ghi nhận dữ liệu API hiện trả ba banner, các trường `title`, `content`, `CTA_link` và `image: null`.

## Triển khai banner trang chủ
- Thay vùng banner trống trong `FE/src/views/HomeView.vue` bằng dữ liệu từ `/?action=api-banners` qua service API hiện có.
- Thêm trạng thái đang tải, lỗi kèm nút thử lại, và danh sách rỗng; giữ nguyên `FE/src/services/api.js` và contract BE.
- Tạo `FE/src/components/BannerSlideshow.vue` dùng Bootstrap Carousel JS với autoplay 5 giây, điều khiển Previous/Next, indicator, bàn phím, swipe, pause khi hover và vòng lặp.
- CTA dùng `RouterLink` với `CTA_link` từ API; query string được giữ nguyên khi điều hướng.
- Khi `image` là `null`, banner dùng nền dự phòng. Bố cục responsive theo mẫu Stitch: 240px mobile, 320px tablet, 400px desktop; mobile ẩn mô tả và mũi tên.
- Sửa lỗi các slide bị đè: chỉ slide active/đang chuyển mới dùng `display: flex`, để Bootstrap ẩn các slide còn lại.
- Tinh chỉnh nút điều hướng thành nút vuông bo nhẹ, viền mảnh, nền tối trong suốt; thay glyph bằng chevron CSS và căn icon đúng tâm.
- Dọn CSS banner placeholder cũ (`.home-banner-blank`) và transition Vue cũ (`.banner-fade`); không còn timer `setInterval` tự viết.

## Kiểm tra
- `npm --prefix FE run build`: thành công sau các thay đổi.
- Diagnostics của `HomeView.vue`, `BannerSlideshow.vue` và `style.css`: không có lỗi.
- Kiểm tra browser: API hiển thị ba banner; CTA đi tới đúng catalog/query; indicator và nút điều hướng hoạt động; autoplay chuyển slide; mobile cao 240px và ẩn mũi tên.
- Kiểm tra lỗi slide đè trên mobile: chỉ một trong ba `.carousel-item` hiển thị; chọn indicator thứ hai hiển thị đúng banner thứ hai.
- Kiểm tra căn nút desktop: hai nút 44x44px, tâm icon trùng tâm nút; Next chuyển đúng slide.
- Dev server được khởi động tại `http://127.0.0.1:5173/`.

## Mục chưa thực hiện
- Việc đổi tên `ProductCard.vue` được trao đổi nhưng tạm dừng theo yêu cầu; không đổi tên hoặc sửa component này trong phiên.

## Test API admin - 2026-11-09
- Chạy smoke test local Laragon lúc `22:24 +07:00`: **46/46 PASS**.
- Kiểm tra quyền: thiếu token trả 401; user thường gọi API admin trả 403; admin xem đơn trả 200; sai method trả 405.
- Kiểm tra CRUD danh mục, sản phẩm, banner; slug trùng trả 409; không xóa danh mục đang có sản phẩm.
- Kiểm tra upload ảnh sản phẩm, album và banner: DB trả filename, file truy cập được; thay/xóa ảnh dọn file cũ.
- Kiểm tra comment pending: tạo, lọc danh sách, duyệt và xóa.
- File sai MIME bị từ chối 422; JPEG hợp lệ upload thành công.
- Cleanup hoàn tất: xóa fixture category/product/banner/comment và ảnh tạm; `BE/assets/uploads/` chỉ còn `default.jpg`.
