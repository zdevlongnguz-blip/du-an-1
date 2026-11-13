# Fix log: product-detail (trang chi tiết sản phẩm)

Trạng thái: **Hoàn thành BE + FE, chưa commit**. Nhánh: `feature-product-detail`.

## Vấn đề ban đầu
- `Product::getBySlug` vẫn đọc các cột đã bỏ khỏi `products` (`price`, `sale_price`, `stock`) và không trả về biến thể.
- FE chỉ có một bộ số lượng + "Thêm vào giỏ" cho cả sản phẩm, không chọn được biến thể.
- Ảnh bìa (`products.image`) không nằm trong thư viện ảnh; chỉ hiện `product_images`.

## Thay đổi
- `BE/models/Product.php` — `getBySlug()`
  - Lấy tổng hợp từ `v_product_catalog`: `variant_count`, `min_final_price`, `max_final_price`, `stock` (= `total_stock`), `in_stock`.
  - Lấy giá từ biến thể mặc định đang bán (`price`, `sale_price`).
  - Trả về `variants`: chỉ biến thể `is_active = 1`, biến thể mặc định đứng đầu.
  - Trả về `images`: ảnh bìa đứng đầu (`id: null`, `sort_order: -1`); slide trùng tên file với ảnh bìa bị bỏ.
- `BE/controllers/ProductController.php`
  - `detail()` trả về `formatProductDetail()` (helper private mới): dùng lại `formatProductListItem()`, ép kiểu số, decode `attributes` JSON của biến thể, format `variants` và `images`.
- `FE/src/views/products/ProductDetail.vue`
  - Giá đầu trang: "Từ" + giá thấp nhất; tồn kho tổng hoặc "Hết hàng".
  - Khối `section.pd-variants` liệt kê đủ tất cả biến thể: tên, thuộc tính, giá (có giá gốc gạch nếu đang sale), tồn kho từng biến thể.
  - Đã đăng nhập: mỗi phiên bản có stepper số lượng riêng (nút −/+ và ô nhập số tay, kẹp về 1..tồn kho khi rời ô) và nút "Thêm vào giỏ" gửi `{product_id, variant_id, quantity}` tới `api-cart-add`. Phiên bản hết hàng bị disable.
  - Tiêu đề khối là "Chọn phiên bản". Mỗi thẻ không lặp lại thuộc tính đã có trong tên phiên bản (ví dụ "Hũ 56g" không hiện thêm dòng "56g").
  - Xóa khu vực mua hàng cũ (một stepper + "Thêm vào giỏ" + "Mua ngay" không hoạt động) và CSS `.pd-purchase*`, `.pd-quantity-*`, thanh mua cố định mobile.
  - Thêm CSS cho danh sách biến thể.

## Quyết định
- Ảnh bìa được gộp trong model (không xử lý ở FE), dedupe theo tên file.
- Biến thể không hoạt động (`is_active = 0`) bị ẩn khỏi trang chi tiết. Cần xác nhận lại với người dùng.
- "Mua ngay" bị bỏ vì chưa có luồng nào phía sau.

## Kiểm tra đã làm
- `php -l` cho 2 file PHP: OK.
- `curl api-product-detail`:
  - `sap-vuot-toc-hanz-de-fuko-claymation`: 2 biến thể, 3 ảnh (bìa đứng đầu), giá Từ 450.000 ₫, tồn kho 70.
  - `gel-vuot-toc-got2b-glued-spiking-wax`: 1 biến thể, `attributes: []`.
- `npm run build` (FE): OK.
- Trình duyệt (`localhost:5173/product-detail/sap-vuot-toc-hanz-de-fuko-claymation`, chưa đăng nhập): hiển thị 3 ảnh, giá Từ, "Còn 70 sản phẩm", đủ 2 biến thể với giá gạch và tồn kho riêng, gợi ý đăng nhập.
- Trình duyệt, đã đăng nhập: ô số lượng hiện đúng; gõ 999 rồi rời ô được kẹp về 50 (tồn kho "Hũ 56g").
- Chưa test: bấm "Thêm vào giỏ" theo phiên bản (giỏ hàng chưa hỗ trợ `variant_id`).

## Việc còn lại (không thuộc phạm vi task này)
- Giỏ hàng (`Cart.php`, `api-cart-add`) vẫn đọc `products.price/sale_price/stock` và chưa nhận `variant_id`. Nút "Thêm vào giỏ" theo biến thể sẽ lỗi cho tới khi làm giỏ hàng.
- Ảnh `cocoon.jpg`, `moroccanoil.jpg`, `got2b.jpg`, ... không có trong `BE/assets/uploads` (chỉ có `default.jpg`).
- Admin tạo/sửa sản phẩm (`Product::createAdmin`, `Product::updateAdmin`) và `Order.php` vẫn đọc cột đã bỏ.

## File liên quan
- `BE/models/Product.php`
- `BE/controllers/ProductController.php`
- `FE/src/views/products/ProductDetail.vue`
