# Công việc FE Product Detail - Vinh

## Thông tin nhánh
- Branch: `feature/FE/product-detail-vinh`, tạo từ `dev` mới nhất (`7bb2850`).
- File code đã sửa: chỉ `FE/src/views/products/ProductDetail.vue`.
- Không sửa router, `services/api.js`, `dd.js`, component dùng chung, catalog, trang chủ, BE, database hay schema.

## Thiết kế tham khảo
- Stitch MCP: chưa dùng được. Đã thêm cấu hình `stitch` vào MCP global của Cursor (đọc key từ biến môi trường `STITCH_API_KEY`), nhưng chưa có API key nên chưa kết nối.
- Thay bằng Figma (qua Figma MCP), file `4lF7RgRpX0JppcA8wYoAfR`, frame "Sharp Barber - Product Detail Inner Body Specification Suite" (`1:3660`). Các frame đã đối chiếu:
  - `1:3679` 1A Desktop - đã đăng nhập, bình luận thu gọn.
  - `1:3743` 1B Desktop - khách, không có khu vực mua.
  - `1:4130` Desktop Extra 3 - bình luận mở, có form và phân trang.
  - `1:4244` Desktop Extra 4 - giá giảm và album 4 ảnh.
  - `1:4318` Desktop Extra 5 - hết hàng.
  - `1:3907` 1E Mobile - đã đăng nhập, nút mua cố định ở đáy.

## Đã làm
- Bỏ hai khối `<pre>`/`dd()` debug, thay bằng giao diện chi tiết sản phẩm theo Figma, style Sharp Barber, `<style scoped>`.
- Khi mở trang chỉ gọi `GET ?action=api-product-detail&slug=<slug>`.
- Hiển thị breadcrumb (mobile là link "← Danh sách sản phẩm"), album ảnh có ảnh nhỏ để chọn khi có từ 2 ảnh, danh mục, tên, mã, lượt xem/đã bán, giá (giá giảm màu đỏ, giá gốc gạch ngang, nhãn "Giảm giá"), tồn kho/hết hàng, mô tả.
- Nút **Xem bình luận**: chỉ khi bấm mới gọi `GET ?action=api-product-comments&slug=<slug>&page=1&per_page=10`. Có trạng thái đang tải, lỗi kèm nút "Thử lại", rỗng. Ẩn/hiện lại không gọi API. Tiêu đề hiện tổng số bình luận sau khi tải.
- Phân trang bình luận (Trang trước / x / y / Trang sau) chỉ hiện khi có từ 2 trang.
- Khách chưa đăng nhập: không có khu vực mua và form bình luận, hiện "Đăng nhập để mua hàng và bình luận".
- Theo yêu cầu bổ sung: khu vực **Thêm vào giỏ / Mua ngay / số lượng** và **form nhập bình luận** chỉ là giao diện demo, chưa viết logic FE/BE; có ghi chú "Giao diện demo" ngay trên giao diện.
- Theo dõi slug trên URL: chuyển sang sản phẩm khác thì tải lại sản phẩm và xoá bình luận cũ; bỏ qua response cũ nếu người dùng đã chuyển trang.
- Comment tiếng Việt cho hàm, `ref`, `computed`, `watch`, lời gọi API và các khối template theo `fe.instructions.md`.

## Quyết định đáng chú ý
- API bình luận chỉ trả `email`, không có tên: hiện phần trước `@` và chữ viết tắt làm avatar để không lộ email đầy đủ. Đề xuất BE bổ sung tên hiển thị.
- API chi tiết không trả slug danh mục, catalog lại lọc theo slug (`?category=`), nên danh mục trên breadcrumb chỉ là chữ, không có link.
- Thời gian bình luận hiện dạng ngày giờ (`vi-VN`) thay cho "2 ngày trước" trong mẫu.
- Ảnh lấy theo `BE/assets/uploads/<tên file>` giống `ProductCardCatalog`; ảnh lỗi/thiếu hiện khung "SB".

## Kiểm thử
- `npm --prefix FE run build` thành công; `git diff --check` sạch.
- Mở trang: chỉ có request `api-product-detail`.
- Bấm "Xem bình luận": đúng 1 request `api-product-comments&page=1&per_page=10`; ẩn/hiện nhiều lần không phát sinh request mới.
- Chặn API bình luận: hiện "Không tải được bình luận." và nút "Thử lại"; bỏ chặn rồi bấm "Thử lại" tải thành công.
- Slug sai: hiện "Không tìm thấy sản phẩm" và nút quay lại danh sách.
- Khách: không có khu vực mua và form bình luận. Đăng nhập `user@example.com`: có khu vực mua demo và form demo.
- Giá giảm hiển thị đúng với sản phẩm Claymation. Mobile 375px: không tràn ngang, thanh mua cố định ở đáy.

## Tồn đọng
- Chưa thử runtime trường hợp hết hàng, sản phẩm không có bình luận và album nhiều ảnh, vì dữ liệu seed hiện không có các trường hợp này (đã xử lý trong code).
- Chưa có file ảnh sản phẩm thật trong `BE/assets/uploads`, nên ảnh đang hiện khung "SB".
- Giỏ hàng, mua ngay, chọn số lượng và gửi bình luận chưa có logic, chờ nhóm thống nhất API.
- Chưa có Stitch reference vì chưa có Stitch API key.
- Ghi chú môi trường: cổng 5173 trên máy đang bị dự án khác chiếm, nên đã test `du-an-1` ở cổng 5174.
