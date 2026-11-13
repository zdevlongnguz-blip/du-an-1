# Fix log: api-products (danh sách + top 5)

Trạng thái: **Hoàn thành**

## Vấn đề ban đầu
- DB đã bỏ cột `products.price`, `products.sale_price`, `products.stock` (migration 05). BE vẫn đọc các cột này nên API lỗi/sai dữ liệu.
- Tên sản phẩm, mô tả, tên danh mục bị lỗi mã hóa (mojibake, UTF-8 bị đọc thành latin1 rồi mã hóa lại 2 lần).

## Thay đổi
- `BE/models/Product.php`
  - `getFiltered()`: đọc từ `v_product_catalog`, LEFT JOIN biến thể mặc định (đang bán) để lấy `price`, `sale_price`.
  - `getTop()`: cùng nguồn và join như trên, allowlist cho `views`/`sales`, `LIMIT 5`.
- `BE/controllers/ProductController.php`
  - `index()` và `top()` đều map qua `formatProductListItem()`: `stock` = tổng tồn kho các biến thể, `image` = ảnh của sản phẩm, ép kiểu `variant_count`, `in_stock`, giá.
- `FE/src/components/ProductCardTop5.vue` và `FE/src/components/ProductCardCatalog.vue`
  - Giá hiển thị = `min_final_price`. Nhiều mức giá thì thêm "Từ ". Không có biến thể đang bán thì hiện "Liên hệ".
- DB (không nằm trong repo): sửa mojibake cho `products` (name, description), `categories` (name), `order_details` (product_name). Đã backup trước khi sửa.

## Quyết định
- Giá hiển thị dùng giá thấp nhất để khách không thấy giá cao hơn thực tế.
- Stock = tổng stock các biến thể. Ảnh ưu tiên `products.image`, chỉ dùng ảnh biến thể khi null (hiện chưa dùng đến vì không có sản phẩm nào thiếu ảnh).

## Kiểm tra đã làm
- `php -l` cho 2 file PHP: OK.
- `run.php --verify`: 4/4 OK. `run.php --seed --verify`: số dòng không đổi (products 4, variants 7, images 5, comments 4, banners 3, orders 1, order_details 1).
- `?action=api-products-top`: `sort_by=sales` trả 200 đúng thứ tự, `sort_by=bad` trả 400.
- `npm run build` (FE): OK.
- Trình duyệt (`localhost:5173`): trang chủ và `/catalog` hiển thị đúng giá:
  - Cocoon: Từ 145.000 ₫
  - Moroccanoil: Từ 290.000 ₫
  - Got2b: 250.000 ₫
  - Claymation: Từ 450.000 ₫

## Việc còn lại (không thuộc phạm vi api-products)
- Ảnh `cocoon.jpg`, `moroccanoil.jpg`, `got2b.jpg` không có trong `BE/assets/uploads` (chỉ có `default.jpg`). API trả đúng tên file nhưng server 404, thẻ hiển thị chữ "SB".
- `ProductCard.vue`: không được import ở đâu, vẫn dùng `product.price`. Để nguyên, cân nhắc xóa.
- Chưa test trường hợp "Liên hệ" (không có dữ liệu).
- Các chức năng chưa đụng tới, vẫn đọc cột đã bỏ:
  - `api-product-detail` (`Product::getBySlug`)
  - Admin tạo/sửa sản phẩm (`Product::createAdmin`, `Product::updateAdmin`)

## File liên quan
- `BE/models/Product.php`
- `BE/controllers/ProductController.php`
- `FE/src/components/ProductCardTop5.vue`
- `FE/src/components/ProductCardCatalog.vue`
- `BE/database/migrations/20261113_05_slim_down_products.sql` (tham khảo)
- `BE/database/migrations/20261113_06_create_product_catalog_view.sql` (tham khảo)
