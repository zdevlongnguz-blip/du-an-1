# Công việc FE - Vinh

> Đường dẫn log: `vibecoder/Week 2/Vinh.MD`. Yêu cầu ghi `vibecoder/week2/Vinh.md`, nhưng repo đang dùng thư mục `vibecoder/Week 2/` và file `Long.MD`, nên giữ theo convention hiện có để không tạo thư mục trùng do khác casing/khoảng trắng.

## Banner slideshow - Week 2

### Đã làm
- Tạo component tái sử dụng `FE/src/components/BannerSlider.vue`, gọi `?action=api-banners` qua `FE/src/services/api.js`.
- Nhúng `<BannerSlider />` vào `FE/src/App.vue`, ngay đầu `<main>` và phía trên `<RouterView />`, nên banner hiện trên mọi trang.
- Slideshow: tự chuyển mỗi 7 giây, nút trước/sau, chấm chỉ báo slide hiện tại, nút "Tạm dừng / Tự chuyển".
- Tạm dừng khi hover hoặc focus vào slideshow; khi rời ra thì chạy lại từ đầu chu kỳ (không chuyển slide ngay lập tức).
- `prefers-reduced-motion: reduce`: tắt tự chuyển và hiệu ứng fade; người dùng vẫn có thể bật lại bằng nút "Tự chuyển".
- CTA nội bộ (bắt đầu bằng `/`) dùng `RouterLink`, giữ nguyên query như `/catalog?category_slug=...`. Link `http(s)://` dùng thẻ `<a>`; link không hợp lệ hoặc rỗng thì không hiện nút CTA.
- Trạng thái: loading (placeholder), lỗi (thông báo + nút "Thử lại"), danh sách rỗng (không render banner).
- `image` có thể `NULL`: dùng nền gradient; ảnh tải lỗi sẽ bị ẩn, giao diện không vỡ. Tên file ảnh được ghép với `BE/assets/uploads/` (giống `BASE_ASSETS_UPLOADS` ở BE).
- Accessibility: vùng `aria-roledescription="carousel"`, mỗi slide có nhãn `x / n`, nút có `aria-label`, chấm chỉ báo có `aria-current`; dòng trạng thái ẩn cho screen reader chỉ đọc (`aria-live="polite"`) khi người dùng tự chuyển hoặc đang tạm dừng, tắt (`off`) khi tự chạy để tránh đọc liên tục.
- Sửa `FE/src/views/products/catalog.vue` (chỉ FE): BE chỉ lọc theo `category_id`, nên catalog đổi `category_slug` trên URL sang `category_id` dựa vào danh sách `api-categories`, đồng thời đồng bộ ô chọn danh mục. `category_id` trên URL vẫn được ưu tiên như cũ.

### File đã sửa
- `FE/src/components/BannerSlider.vue` (mới)
- `FE/src/App.vue`
- `FE/src/views/products/catalog.vue`
- `vibecoder/Week 2/Vinh.MD` (mới)

### Quyết định đáng chú ý
- Không thêm thư viện; slideshow viết bằng Vue 3 + Bootstrap có sẵn.
- Tiêu đề banner dùng `<p class="h3">` thay vì thẻ heading để không phá thứ tự heading của từng trang.
- Không đổi BE. Để chạy local đã áp dụng `BE/database/migrations/20261106_create_banners_table.sql` và `BE/database/seed/banners.sql` vào database local.

### Kiểm thử
- `npm run build` thành công (output vào `BE/public/dist`, đã bị ignore).
- CTA banner 2 điều hướng tới `/catalog?category_slug=gel-sap-vuot-toc` bằng Vue Router (không reload trang); catalog chỉ hiện 2 sản phẩm gel/sáp và ô danh mục chọn "Gel, Sáp vuốt tóc". `/catalog` không có slug vẫn hiện đủ 4 sản phẩm.
- Tự chuyển sau ~7 giây; hover và focus giữ nguyên slide hơn 7 giây; sau khi rời ra, slide giữ thêm một chu kỳ đầy đủ rồi mới chuyển.
- Giả lập `prefers-reduced-motion: reduce`: không tự chuyển, nút hiển thị "Tự chuyển".
- Chặn request `api-banners`: hiện thông báo lỗi, trang vẫn dùng được; bấm "Thử lại" tải lại thành công.
- Ảnh hỏng: thẻ ảnh bị gỡ, tiêu đề và nền vẫn hiển thị. Giao diện mobile 375px không bị chồng chữ lên nút.

### Tồn đọng
- `category_slug` không tồn tại thì catalog hiện tất cả sản phẩm (không báo "không tìm thấy danh mục").
- Trường hợp `banners` rỗng mới kiểm tra qua code, chưa thử với database thật không có banner.
- Chưa có ảnh banner thật trong `BE/assets/uploads/`; dữ liệu seed đều `image = NULL`.
- Menu chưa có link tới `/catalog`.
- Chưa push; chỉ commit/push khi được giao.
