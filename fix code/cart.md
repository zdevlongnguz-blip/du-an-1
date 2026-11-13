# Fix log: cart (giỏ hàng theo phiên bản)

Trạng thái: **Hoàn thành BE + FE trang giỏ, chưa test trình duyệt đầy đủ, chưa commit**. Nhánh: `feature-cart` (từ `dev`, `8b2d703`).

## Vấn đề ban đầu
- DB đã áp migration `20261113_03_cart_items_use_variants.sql`: `cart_items.variant_id` NOT NULL, unique `(cart_id, variant_id)`.
- `BE/models/Cart.php` vẫn dùng `product_id` và các cột đã bỏ khỏi `products` (`price`, `sale_price`, `stock`). Giỏ hàng lỗi khi chạy.
- Chưa có trang giỏ hàng riêng; không có link vào giỏ.

## Thay đổi
- `BE/models/Cart.php`
  - `getByUserId()`: join `cart_items` → `product_variants` → `products`. Trả về `variant_id`, `product_id`, `product_name`, `product_slug`, `image` (ảnh bìa sản phẩm), `variant_name`, `attributes`, `unit_price` (= `final_price`), `stock`, `quantity`, `is_available` (đang bán và còn hàng), `line_total`. `total_amount` chỉ cộng các dòng đang mua được, tính bằng cent.
  - `addItem()`: khóa biến thể bằng `FOR UPDATE`, trả về `invalid` / `not_found` / `unavailable` / `out_of_stock` / `stock_exceeded` / `ok`. Trùng biến thể thì cộng dồn số lượng.
  - `updateItem()`: kiểm tra item thuộc user, khóa biến thể, kiểm tra tồn kho.
  - `removeItem()`: giữ nguyên.
- `BE/controllers/CartController.php`
  - `add()` đọc `variant_id`. Map: 422 (dữ liệu sai), 404 (không có biến thể), 409 (hết hàng, ngừng bán, vượt tồn kho), 201 (thành công).
  - `update()` map `unavailable` / `stock_exceeded` sang 409.
- `FE/src/views/cart/CartView.vue` (mới)
  - Mỗi dòng: ảnh, tên sản phẩm (link tới trang chi tiết), tên và thuộc tính phiên bản, đơn giá, nhãn trạng thái ("Hết hàng", "Ngừng bán", "Chỉ còn N").
  - Số lượng: nút −/+ và ô nhập số tay (kẹp 1..tồn kho khi rời ô, lỗi thì hoàn về giá trị cũ). Nút "Xóa" có xác nhận.
  - Tổng tiền dùng `formatPrice`. Nút "Thanh toán" bị disable khi không có dòng nào mua được.
  - Trạng thái: đang tải, lỗi (có thử lại), giỏ trống.
- `FE/src/router/index.js`: route `/cart` với `meta: { requiresAuth: true }`.
- `FE/src/App.vue`: link "Giỏ hàng" cho user đã đăng nhập, cả desktop và mobile.

## Quyết định
- Tổng tiền giỏ không tính các dòng ngừng bán hoặc hết hàng.
- Biến thể ngừng bán vẫn hiện trong giỏ với nhãn "Ngừng bán" (khác với trang chi tiết, nơi bị ẩn). Cần xác nhận.

## Kiểm tra đã làm
- `php -l` cho `Cart.php` và `CartController.php`: OK.
- `npm run build` (FE): OK.
- `curl api-cart` không có token: 401.

## Việc còn lại (ngày mai)
- `BE/models/Order.php` checkout (khoảng dòng 28–125) vẫn đọc `cart_items.product_id`, `products.price/sale_price/stock`, và trừ `products.stock`. Cần chuyển sang tồn kho theo biến thể, `unit_price = final_price`.
- Hủy đơn (khoảng dòng 362–378) cộng `products.stock`. Cần cộng lại tồn kho của biến thể.
- `FE/src/views/CheckoutView.vue`: hàm mẫu `addProductToCart` (khoảng dòng 76–90) gửi `product_id` không có `variant_id`, sẽ nhận 422. Bộ lọc `availableProducts` dùng `product.stock` mà catalog không còn trả về.
- `ProductController::create/update` và `Product::createAdmin/updateAdmin` vẫn đọc các cột đã bỏ.
- Ảnh: `BE/assets/uploads` chỉ có `default.jpg`, nhiều ảnh sản phẩm bị thiếu file.
- Test trình duyệt đầy đủ: thêm từ trang chi tiết theo phiên bản, đổi số lượng, xóa, kiểm tra tổng tiền, trạng thái trống và lỗi.

## Câu hỏi mở
- Có hiện biến thể ngừng bán (disabled) ở trang chi tiết thay vì ẩn không?
- Checkout có thuộc phạm vi task giỏ hàng này hay làm riêng ngày mai?
