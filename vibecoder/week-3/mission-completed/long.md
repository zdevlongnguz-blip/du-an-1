# Mission completed - Long (BE) - Tuần 3

Ngày ghi: 2026-11-13. Ghi tự động vì không có công cụ kết nối vibe coder.

Tham chiếu: `vibecoder/Week 2/Long.MD` (phần BE).

## Mission
- Đổi cấu trúc DB cho product thành có biến thể.
- Viết lại các API endpoint cần thiết cho catalog, detail, cart.
- Viết test kiểm thử.

## Trạng thái

| Hạng mục | Trạng thái | Ghi chú |
| --- | --- | --- |
| DB có biến thể (`product_variants`, `cart_items.variant_id`) | Hoàn thành | Migration 03 đã áp dụng |
| API catalog (`api-products`, top 5) | Hoàn thành | Đã sửa cho schema biến thể, đã merge (PR #41) |
| API detail (`api-product-detail`) | Hoàn thành | `Product::getBySlug`, `ProductController::detail`, đã merge (PR #42) |
| API cart (`api-cart`, add, update, delete) | Hoàn thành, chưa commit | Nhánh `feature-cart`. `Cart.php` và `CartController.php` đã viết lại theo `variant_id` |
| Checkout và hủy đơn theo biến thể | Chưa làm | `BE/models/Order.php` còn đọc `product_id`, `products.price/stock` |
| Test kiểm thử tự động | Chưa làm | Mới kiểm tra bằng `php -l` và curl |

## Kiểm tra đã làm (cart)
- `php -l` cho `Cart.php` và `CartController.php`: OK.
- `GET api-cart` không token: 401.
- Chưa test với JWT hợp lệ: thêm trùng biến thể, vượt tồn kho, item của user khác.

## Còn lại
1. Test API cart với JWT hợp lệ (201, 200, 404, 409, 422).
2. Commit và tạo PR cho `feature-cart`.
3. Sửa `Order.php` checkout và hủy đơn theo biến thể.
4. Viết test kiểm thử cho BE.

Chi tiết: [cart.md](/C:/laragon/www/du-an-1/fix%20code/cart.md).
