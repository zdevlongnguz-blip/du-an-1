# Bộ database: tách products thành products + product_variants

## Cấu trúc
- `run.php`: chạy migration, `--seed` chạy seed, `--verify` kiểm tra toàn vẹn (thoát mã 2 nếu có vi phạm).
- `create_database.sql`: khởi tạo DB trống (giữ nguyên bản cũ, các migration sẽ nâng cấp lên schema mới).
- `migrations/`: các file cũ giữ nguyên + 6 file mới `20261113_01..06` + `20261114_01_order_details_variant_fk_set_null.sql` (sửa `order_details.variant_id` về NULL khi xóa sản phẩm) + `20261114_02_orders_add_payment_method.sql` (thêm `orders.payment_method` và `orders.payment_invoice_number`).
- `seed/`: seed cũ giữ nguyên, trừ `catalog_product_variants.sql` (dữ liệu mẫu với giá giả định).

## Cách chạy
```
mysqldump -u root -p du-an-1 > backup_truoc_khi_migrate.sql   # BẮT BUỘC sao lưu trước
php run.php --seed --verify
```
Migration chạy lại nhiều lần vẫn an toàn (mỗi bước kiểm tra trạng thái trước khi làm).
MySQL không rollback được DDL, nên nếu một migration lỗi giữa chừng, sửa nguyên nhân rồi chạy lại là chạy tiếp được.

## Schema mới
- `products`: id, category_id, code, name, slug, image, description, view_count, sales_count, created_at, updated_at (không còn price, sale_price, stock).
- `product_variants`: sku, variant_name, attributes (JSON), price, sale_price, final_price (DB tự tính), stock, is_default, is_active.
- `cart_items.variant_id` thay `product_id`. `order_details` thêm variant_id, variant_sku, variant_label (snapshot).
- View `v_product_catalog`: giá thấp/cao nhất, tổng tồn kho cho trang danh sách.

## Ràng buộc DB đảm bảo
SKU duy nhất; tên biến thể duy nhất trong sản phẩm; tối đa 1 biến thể mặc định/sản phẩm; mặc định phải đang bán;
giá >= 0; giá giảm < giá gốc; attributes phải là JSON object; tồn kho không âm (UNSIGNED);
số lượng giỏ/đơn >= 1; dòng đơn chỉ gắn được biến thể thuộc đúng sản phẩm (khóa ngoại kép);
không xóa được danh mục còn sản phẩm.

## Việc ứng dụng PHP phải làm (DB không ép được)
- Tạo sản phẩm mới phải tạo luôn >= 1 biến thể mặc định trong cùng transaction (`--verify` sẽ báo nếu thiếu).
- Trừ kho nguyên tử: `UPDATE product_variants SET stock = stock - :qty WHERE id = :id AND stock >= :qty` rồi kiểm tra số dòng bị ảnh hưởng.
- Khi tạo đơn: ghi unit_price = final_price, product_name, variant_sku, variant_label tại thời điểm mua.
- Tổng tiền đơn (`orders.total_amount`) vẫn do ứng dụng tính trong transaction.

## Lưu ý
- Bảng không thuộc phạm vi (users, categories, banners, comments, carts, orders, product_images, schema_migrations) giữ nguyên 100%, kể cả chưa có comment cột.
- `fk_products_category` đổi từ CASCADE sang RESTRICT.
- Seed `catalog_product_variants.sql` là dữ liệu demo với giá giả định, có thể xóa.
