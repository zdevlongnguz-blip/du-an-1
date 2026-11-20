# Task Tuần 3 - Vinh: Nâng Cấp Bộ Lọc Sản Phẩm Ở Catalog (BE → FE)

Tuần: 3
Ngày giao task này: 20/11/2026 (thay toàn bộ các task cũ của Vinh trong file này; task "top 5 / cùng danh mục / bố cục trang chi tiết" đã chuyển sang Dũng, xem `ngocdung.md`)
Phạm vi: làm trọn luồng: BE (`api-products`) rồi FE (`catalog.vue`). Vinh là owner của phần BE và FE liệt kê trong mục "File được sửa".

## Tạo Branch Trước Khi Làm

- Kiểm tra `git status`, fetch `origin`, rồi tạo branch từ `origin/dev` mới nhất.
- Branch đề xuất: `feature/week3-vinh-catalog-filter`.
- Dùng `git switch -c feature/week3-vinh-catalog-filter --no-track origin/dev` rồi `git push -u origin feature/week3-vinh-catalog-filter`, để nút Sync của VS Code không đẩy thẳng lên `dev`.
- Không code trực tiếp trên `dev` hoặc branch của người khác.

## Điều Kiện Bắt Đầu

- Đọc `BE/CLAUDE.md` và `FE/CLAUDE.md` (task đụng cả hai phía).
- Chạy migration và seed theo quy trình của team. Database local cần có sản phẩm ở nhiều mức giá và ít nhất 2 danh mục (dữ liệu demo hiện có: 11 biến thể đang bán, giá từ 300đ đến 899.000đ, danh mục 1 và 10).
- Thiếu dữ liệu hoặc chạy lỗi thì dừng và báo admin.

## Mục Tiêu

Khách ở trang catalog có thể:

1. **Sắp xếp**: mới nhất (mặc định), giá tăng dần, giá giảm dần, xem nhiều nhất, bán chạy nhất.
2. **Lọc theo khoảng giá**: giá từ ... đến ... (nhập một đầu hoặc cả hai).
3. Kết hợp được với tìm kiếm, lọc danh mục và phân trang đang có.

## Hiện Trạng Đã Rà Soát

- `api-products` (`ProductController::index` → `Product::getFiltered`) hiện chỉ nhận `search` (hoặc `q`), `category` (slug), `page`, `per_page` (hoặc `perPage`). Luôn xếp theo mới nhất (`id` giảm dần).
- `search` là mảng đã bị chặn bằng 400 (PR #81). Giữ nguyên kiểm tra này.
- `catalog.vue` gọi `api-products` với `search`, `category`, `page`, `per_page`; khối tải/lỗi/rỗng đang tự viết (chưa dùng `StateBlock`).
- Tham khảo được: bộ lọc giá và sắp xếp từng được làm trong PR #75 (đã revert ở PR #78 cùng tính năng vector). Có thể xem lại bằng `git show afe186e:BE/models/Product.php` (hằng `CATALOG_SORT_SQL`, hàm `buildCatalogFilters`) và `git show afe186e:BE/controllers/ProductController.php` (hàm `readPriceParam`). **Chỉ lấy phần sắp xếp + lọc giá, không lấy bất kỳ code vector nào** (`search_mode`, `vectorSearch`, `EmbedService`, `getCatalogCandidates`...).

## Contract API (admin đã chốt)

`GET /?action=api-products` thêm 3 tham số **tùy chọn**. Không truyền thì hoạt động y như hiện tại (request cũ không đổi kết quả).

| Tham số | Giá trị hợp lệ | Không truyền / rỗng | Sai |
|---|---|---|---|
| `sort` | `newest`, `price_asc`, `price_desc`, `views`, `sales` | `newest` | 400 `sort phải là một trong: newest, price_asc, ...` |
| `min_price` | số ≥ 0 (nguyên hoặc thập phân) | không lọc | 400 `min_price phải là số không âm` |
| `max_price` | số ≥ 0 | không lọc | 400 `max_price phải là số không âm` |

- `min_price > max_price` → 400 `min_price không được lớn hơn max_price`.
- Tham số dạng mảng (`sort[]=x`, `min_price[]=1`) → 400 (giống cách chặn `search[]`).
- **Lọc giá theo biến thể:** giữ sản phẩm nếu có **ít nhất một biến thể đang bán** có `final_price` nằm trong khoảng (dùng `EXISTS`, xem `buildCatalogFilters` cũ).
- **Sắp xếp theo giá** dùng `min_final_price` (giá thấp nhất của sản phẩm); sản phẩm chưa có giá xếp cuối. Hòa thì `id` giảm dần.
- Response giữ nguyên `status`, `products.data`, `products.pagination`. Khối `filters` thêm `sort`, `min_price`, `max_price` (giá trị đã dùng). Không đổi tên hay xóa key cũ.
- Phân trang và đếm tổng (`total_items`) phải tính **sau khi lọc**.

## Phạm Vi Thực Hiện

### File được sửa

| File | Việc |
|---|---|
| `BE/controllers/ProductController.php` | **Chỉ** hàm `index()`, thêm hàm private đọc tham số (ví dụ `readPriceParam`) |
| `BE/models/Product.php` | **Chỉ** hàm `getFiltered()`, thêm hằng sắp xếp và hàm private dựng điều kiện lọc |
| `BE/test-api.md` | Thêm các ca đã test của `api-products` |
| `FE/src/views/products/catalog.vue` | Giao diện sắp xếp + lọc giá, đồng bộ lên URL, đổi khối tải/lỗi/rỗng sang `StateBlock` |

Không đụng hàm `top()` / `getTop()` (vừa được mở rộng cho task của Dũng), `detail()`, các hàm `admin*`.

### BE

- Validate thủ công tại `index()` theo mẫu đang có (`is_string`, `filter_var`). Tên cột `ORDER BY` lấy từ **allowlist** (hằng PHP), không đưa chuỗi từ request vào SQL.
- Giá bind bằng `bindValue` (không nối chuỗi vào SQL).
- Mọi hàm mới/sửa có giá trị mặc định cho tham số và docblock tiếng Việt (mục 2 và 8 `BE/CLAUDE.md`).
- Test bằng curl/Postman, ghi vào `BE/test-api.md`. Tối thiểu:
  - Request cũ không tham số mới: kết quả giống hệt trước khi sửa.
  - Từng giá trị `sort`; `min_price`, `max_price`, cả hai; kết hợp với `search`, `category`, `page`.
  - `total_items` / `total_pages` đúng sau khi lọc.
  - Các ca 400: `sort` sai, giá chữ, giá âm, `min > max`, tham số dạng mảng.

### FE (`catalog.vue`)

- Ô chọn sắp xếp (5 lựa chọn, chữ tiếng Việt). Hai ô giá "Từ" / "Đến" có validate phía FE: chỉ số, không âm, "Từ" ≤ "Đến"; lỗi hiện ngay dưới ô (mục 6 `FE/CLAUDE.md`).
- Đồng bộ bộ lọc lên URL (`?sort=price_asc&min_price=100000...`): F5 hoặc gửi link vẫn giữ bộ lọc; nút Back của trình duyệt đúng.
- Đổi bộ lọc thì về trang 1. Có nút "Xóa bộ lọc".
- Đổi khối đang tải / lỗi / rỗng tự viết sang `StateBlock` (mục 13 `FE/CLAUDE.md`), lỗi có nút "Thử lại". Nếu cần khung xương dạng lưới mà `StateBlock` chưa có thì hỏi admin trước.
- Tránh race khi đổi bộ lọc liên tục (giữ cơ chế `latestProductsRequest` đang có).
- Giá hiển thị đi qua `formatPrice`. Giao diện theo Stitch (mục 12), đẹp ở ~400px và desktop.
- Chỉ dùng `api` từ `@/helpers/api/client`.

### Không được

- Không sửa `routes/index.php` (không cần route mới), `router/index.js`, `App.vue`, `style.css`, `client.js`, `StateBlock.vue`.
- Không sửa `ProductCardCatalog.vue`, `ProductDetail.vue`, `HomeView.vue`, `ProductCardTop5.vue` (Dũng đang làm).
- Không sửa 2 trang đặt hàng `payment/CheckoutView.vue`, `ai/AiCheckout.vue`.
- Không thêm migration, không thêm thư viện.

## Tiêu Chí Nghiệm Thu

- BE: tất cả ca test ở trên có kết quả thật trong `BE/test-api.md`; request cũ không đổi; `php -l` các file PHP đã sửa.
- FE: chọn từng kiểu sắp xếp ra đúng thứ tự; lọc giá đúng (so với giá các biến thể); kết hợp tìm kiếm + danh mục + giá + sắp xếp + phân trang đúng.
- F5 giữ nguyên bộ lọc; "Xóa bộ lọc" về mặc định.
- Lỗi API hiện lỗi kèm "Thử lại", không hiện thành "không có sản phẩm".
- `npm run build` trong `FE/` pass. Kiểm tra trình duyệt ~400px và desktop; ghi rõ phần chưa kiểm tra.
- `git diff`: chỉ có 4 file trong bảng "File được sửa".

## Thứ Tự Thực Hiện

1. Làm BE, test xong và ghi `BE/test-api.md`.
2. Làm FE `catalog.vue` theo contract trên.
3. Kiểm thử trọn luồng trên trình duyệt, chạy build.
4. Tạo **một** PR vào `dev` gồm cả BE và FE (BE không phá request cũ nên merge an toàn).

## Ghi Chú Phối Hợp

- Dũng làm song song task top 5 / trang chi tiết, có dùng `api-products-top` (hàm `top()`/`getTop()` cùng file BE với Vinh). Vinh chỉ sửa `index()`/`getFiltered()`; thêm hàm mới thì đặt ngay cạnh `index()`/`getFiltered()`, không chèn vào cuối file, để giảm conflict.
- Muốn đổi contract ở trên (thêm tham số như "chỉ còn hàng"): hỏi admin trước.
- Báo cáo khi xong: ghi vào `vibecoder/week-3/mission-completed/vinh.md`, mục riêng cho task này. Chỉ ghi điều đã kiểm chứng.
