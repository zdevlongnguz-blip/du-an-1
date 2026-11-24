# Mission completed - Vinh - Tuần 3

Ngày ghi: 2026-11-24  
Nhánh: `feature/week3-vinh-catalog-filter` (từ `origin/dev` `760fb68`)  
Tham chiếu task: `vibecoder/week-3/task/vinh.md`

## Mission

Nâng cấp bộ lọc / sắp xếp sản phẩm ở catalog: bổ sung các kiểu sắp xếp còn thiếu theo contract task, chuyển khối tải/lỗi/rỗng của `catalog.vue` sang `StateBlock`, ghi ca kiểm tra vào `BE/test-api.md`.

## Đã làm trong phiên này

### BE

- `BE/extensions/CatalogFilter.php`
  - Thêm kiểu sắp xếp: `price_asc`, `price_desc`, `views`, `sales` (giữ `newest`, `oldest`).
  - Thông báo lỗi `min_price > max_price`: `min_price không được lớn hơn max_price`.
- `BE/models/Product.php`
  - Bổ sung `CATALOG_SORT_SQL` cho 4 kiểu mới; giá dùng `min_final_price`, sản phẩm chưa có giá xếp cuối (`IS NULL ASC`).
- `BE/controllers/ProductController.php`
  - Cập nhật docblock `index()` cho đủ danh sách `sort`.
- `BE/geminiServices/GeminiToolSearchProduct.php`
  - Cập nhật mô tả enum `sort` (tool AI lấy enum từ `CatalogFilter::SORTS`).
- `BE/test-api.md`
  - Thêm các ca `api-products` / `api-product-filters` liên quan sort + lọc giá.

### FE

- `FE/src/views/products/catalog.vue`
  - Đổi khối đang tải / lỗi / rỗng sang `StateBlock` (lỗi có "Thử lại", rỗng có "Xóa bộ lọc").
  - Ô sắp xếp vẫn lấy danh sách từ `api-product-filters` nên tự nhận 4 kiểu mới từ BE.

## File đã sửa

| File | Việc |
|---|---|
| `BE/extensions/CatalogFilter.php` | Thêm 4 kiểu sort + message min>max |
| `BE/models/Product.php` | ORDER BY allowlist cho 4 kiểu sort |
| `BE/controllers/ProductController.php` | Docblock contract |
| `BE/geminiServices/GeminiToolSearchProduct.php` | Mô tả enum sort cho AI |
| `BE/test-api.md` | Ghi ca test |
| `FE/src/views/products/catalog.vue` | Dùng `StateBlock` |
| `vibecoder/week-3/mission-completed/vinh.md` | Báo cáo phiên này (thay nội dung cũ) |

## Kiểm tra đã làm

- `php -l` 4 file PHP đã sửa: không lỗi cú pháp.
- `npm run build` trong `FE/`: OK.
- Validate logic `CatalogFilter::fromInput` (không cần DB): sort hợp lệ / sort sai / giá chữ / giá âm / min>max / tham số mảng.

## Chưa làm / hạn chế

- Máy local không có `BE/.env` nên chưa gọi HTTP thật tới `api-products` để đối chiếu thứ tự giá / views / sales trên DB.
- Chưa kiểm thử trình duyệt (F5 giữ bộ lọc, Back, ~400px).
- Không dùng khung xương lưới khi đang tải: `StateBlock` chưa hỗ trợ; theo `FE/CLAUDE.md` mục 13 không tự thêm kiểu mới.
