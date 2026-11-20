# Task Tuần 3 - Dũng: Lưới Top 5, Sản Phẩm Cùng Danh Mục Bán Chạy Và Bố Cục Trang Chi Tiết

Tuần: 3
Ngày giao task này: 20/11/2026. Task này trước đó giao cho Vinh, admin đã chuyển sang Dũng cùng ngày.
Phạm vi: chỉ FE. Phần BE đã làm xong và đã test (xem mục "API đã sẵn sàng"). Dũng không sửa `BE/`.

## Tạo Branch Trước Khi Làm

- Kiểm tra `git status`, fetch `origin`, rồi tạo branch từ `origin/dev` mới nhất (phải có commit mở rộng `api-products-top`).
- Branch đề xuất: `feature/week3-dung-top5-related-detail`.
- Dùng `git switch -c feature/week3-dung-top5-related-detail --no-track origin/dev` rồi `git push -u origin feature/week3-dung-top5-related-detail`, để nút Sync của VS Code không đẩy thẳng lên `dev`.
- Không code trực tiếp trên `dev` hoặc branch của người khác.

## Điều Kiện Bắt Đầu

- Chạy migration và seed theo quy trình của team để database local có đủ sản phẩm ở ít nhất 2 danh mục (dữ liệu demo hiện có: danh mục 1 "Gel, Sáp vuốt tóc" 3 sản phẩm, danh mục 10 "Pomade" 4 sản phẩm).
- Thiếu dữ liệu hoặc chạy lỗi thì dừng và báo admin. Không tự sửa SQL, không sửa BE.

## Mục Tiêu

1. **Thiết kế lại lưới top 5 sản phẩm** ở trang chủ.
2. **Thêm khối "Sản phẩm cùng danh mục bán chạy"** ở trang chi tiết sản phẩm.
3. **Sắp xếp lại bố cục trang chi tiết sản phẩm** cho gọn, dễ đọc, đúng thiết kế Stitch.

## API Đã Sẵn Sàng (BE đã làm, đã test 17/17 ca)

Không có endpoint mới. `api-products-top` được mở rộng thêm 2 tham số **tùy chọn**:

```
GET /?action=api-products-top&sort_by=sales&category_id=<id danh mục>&exclude_id=<id sản phẩm đang xem>
```

| Tham số | Bắt buộc | Ý nghĩa |
|---|---|---|
| `sort_by` | Không (mặc định `sales`) | `sales` (lượt bán) hoặc `views` (lượt xem). Sai thì 400 |
| `category_id` | Không | Chỉ lấy sản phẩm thuộc danh mục này. Không truyền hoặc rỗng thì lấy toàn shop |
| `exclude_id` | Không | Bỏ sản phẩm có ID này (sản phẩm đang xem). Không truyền hoặc rỗng thì không bỏ |

- Luôn trả **tối đa 5** sản phẩm, xếp giảm dần theo `sort_by`, hòa thì sản phẩm mới hơn (ID lớn) đứng trước.
- Thành công 200: `{ "status": "success", "sort_by": "sales", "products": [ ... ] }`. Mỗi sản phẩm **cùng dạng** với item của trang chủ hiện tại (`id`, `name`, `slug`, `image`, `category_id`, `category_name`, `min_final_price`, `max_final_price`, `stock`, `in_stock`, `view_count`, `sales_count`...), nên dùng lại được card có sẵn.
- `category_id`/`exclude_id` sai định dạng (chữ, 0, số âm, số lẻ, mảng) trả **400** `{ "status": "error", "message": "category_id phải là số nguyên dương" }`.
- Danh mục không có sản phẩm nào khác: 200 với `products: []`.
- Trang chủ gọi như cũ (không truyền 2 tham số mới) vẫn nhận đúng top 5 toàn shop, không bị ảnh hưởng.

Lấy dữ liệu cho khối cùng danh mục từ `api-product-detail` đang có sẵn trên trang chi tiết: `product.category_id` và `product.id`.

Ví dụ: đang xem Pomade Layrite (`id = 27`, `category_id = 10`):
`/?action=api-products-top&sort_by=sales&category_id=10&exclude_id=27` trả về 3 pomade còn lại.

## Phạm Vi Thực Hiện (FE)

### File được sửa

| File | Việc |
|---|---|
| `FE/src/views/HomeView.vue` | Lưới top 5 (bố cục, khoảng cách, responsive) |
| `FE/src/components/ProductCardTop5.vue` | Thẻ sản phẩm trong lưới top 5 |
| `FE/src/views/products/ProductDetail.vue` | Sắp xếp lại bố cục; gắn khối sản phẩm cùng danh mục |
| `FE/src/components/ProductRelated.vue` (**mới**) | Khối "Sản phẩm cùng danh mục bán chạy": nhận `categoryId`, `excludeId`, tự gọi API |

Admin đã giao 3 file có sẵn ở trên cho Dũng trong task này (trước đây `ProductDetail.vue` thuộc Văn Hoàng, `ProductCardTop5.vue` thuộc Tuấn Anh). Không sửa file nào khác. Cần sửa thêm file nào thì hỏi admin trước.

### 1. Lưới top 5 (trang chủ)

- Giữ nguyên lời gọi `api-products-top` hiện có (không truyền tham số mới).
- Thiết kế lại lưới theo Stitch (mục 12 `FE/CLAUDE.md`): bảng màu, font Playfair Display / Inter, bo góc 6-8px. Đẹp ở cả điện thoại (~400px), tablet và desktop.
- Giá đi qua `formatPrice`. Không tự nối "đ" ở template.
- Trạng thái tải / lỗi / rỗng dùng `StateBlock` (mục 13 `FE/CLAUDE.md`). Nếu cần kiểu "khung xương" dạng lưới mà `StateBlock` chưa có thì hỏi admin trước, không tự viết khối riêng.

### 2. Khối sản phẩm cùng danh mục (trang chi tiết)

- Component mới `ProductRelated.vue`, props: `categoryId` (bắt buộc), `excludeId` (bắt buộc). Đầu `<script setup>` có 1-3 dòng comment nói component làm gì, gọi API nào.
- Gọi `api.get('/?action=api-products-top', { params: { sort_by: 'sales', category_id, exclude_id } })` qua `@/helpers/api/client`. Không dùng `fetch`, không hardcode URL.
- `watch` theo `categoryId` / `excludeId`: bấm sang sản phẩm khác (cùng route `/product-detail/:slug`) thì tải lại danh sách.
- Trạng thái: đang tải, lỗi có nút "Thử lại", rỗng. Dùng `StateBlock`. Đây là khối nhỏ trong trang: nếu thấy `StateBlock` không hợp (ví dụ muốn ẩn hẳn khối khi rỗng) thì hỏi admin trước. **Lỗi API không được hiện thành "không có sản phẩm"**.
- Mỗi sản phẩm dùng lại card có sẵn (`ProductCard.vue` hoặc `ProductCardTop5.vue`), không tạo card mới nếu không cần. Bấm vào chuyển đến `/product-detail/<slug>`.
- Có tiêu đề rõ, ví dụ "Cùng danh mục bán chạy".

### 3. Bố cục trang chi tiết

- Chỉ đổi `<template>` (cấu trúc, class) và `<style scoped>`. **Giữ nguyên mọi logic và binding** đang chạy: chọn biến thể, nút "Chi tiết" thả xuống bảng thông số, thêm giỏ theo số lượng, tồn kho từng biến thể, bình luận (gửi/sửa/xóa), `StateBlock` khi tải sản phẩm.
- Đề xuất thứ tự: breadcrumb → (ảnh | tên, giá, chọn biến thể, thêm giỏ) → mô tả → bình luận → khối cùng danh mục. Có thể đổi nếu hợp lý hơn, ghi lý do trong báo cáo.
- Điện thoại (~400px): xếp một cột, không cuộn ngang.

### Không được

- Không sửa `BE/`, `router/index.js`, `App.vue`, `style.css`, `helpers/api/client.js`, `StateBlock.vue`.
- Không sửa 2 trang đặt hàng `payment/CheckoutView.vue`, `ai/AiCheckout.vue`.
- Không thêm thư viện (`npm install`).
- Comment tiếng Việt có dấu theo mục 8 `FE/CLAUDE.md` (mỗi hàm, mỗi lần gọi API, mỗi `ref`/`computed`/`watch` không hiển nhiên, comment HTML cho từng khối lớn).

## Tiêu Chí Nghiệm Thu

- Trang chủ: lưới top 5 mới hiển thị đúng 5 sản phẩm như trước, đẹp ở ~400px và desktop.
- Trang chi tiết: khối cùng danh mục hiện tối đa 5 sản phẩm **cùng danh mục**, **không có sản phẩm đang xem**, xếp theo lượt bán giảm dần.
- Bấm một sản phẩm trong khối: sang đúng trang chi tiết, khối tự tải lại cho sản phẩm mới.
- Tắt server (hoặc làm API lỗi): khối hiện lỗi kèm nút "Thử lại", không hiện "không có sản phẩm".
- Danh mục chỉ có 1 sản phẩm: khối hiện trạng thái rỗng (hoặc ẩn, theo cách admin chốt).
- Các chức năng cũ của trang chi tiết vẫn chạy: chọn biến thể, "Chi tiết", thêm giỏ, bình luận.
- `npm run build` trong `FE/` pass. Ghi kết quả thật.
- `git diff`: chỉ có 4 file trong bảng "File được sửa".

## Thứ Tự Thực Hiện

1. Kéo `dev` mới (đã có BE mở rộng `api-products-top`), tạo branch.
2. Làm `ProductRelated.vue`, test với ví dụ Pomade ở trên.
3. Sắp xếp lại bố cục `ProductDetail.vue` và gắn `ProductRelated`.
4. Thiết kế lại lưới top 5 (`HomeView.vue`, `ProductCardTop5.vue`).
5. Kiểm thử ~400px + desktop, chạy build, tạo PR vào `dev`.

## Ghi Chú Phối Hợp

- Vinh làm song song task nâng cấp bộ lọc catalog (`catalog.vue`, `api-products`, `ProductController::index`, `Product::getFiltered`). Trong task này không đụng `catalog.vue`, `ProductCardCatalog.vue` và không sửa `BE/`, để hai PR không chồng nhau.
- Phát hiện lỗi BE khi test: ghi lại request/response thực tế và báo admin, không tự sửa BE.
- Báo cáo khi xong: ghi vào `vibecoder/week-3/mission-completed/ngocdung.md`, mục riêng cho task này. Chỉ ghi điều đã kiểm chứng; phần chưa kiểm tra thì ghi rõ.
