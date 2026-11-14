# BE API Test Log

Log test được tạo lại từ đầu, thay thế hoàn toàn log cũ (đã lỗi thời sau khi schema chuyển sang biến thể).

- Thời điểm chạy: `2026-11-14 16:58:04` (giờ local).
- Base URL: `http://localhost/du-an-1/BE/` (Laragon, Apache/PHP và MySQL local, dùng DB đã chạy đủ migration đến `20261113_06`).
- Phạm vi: **toàn bộ 46 route của BE**: nhóm đã sửa theo schema biến thể (giỏ hàng, checkout, đơn hàng, hóa đơn, hủy đơn, admin đơn hàng, admin tạo/sửa/xóa sản phẩm) cùng nhóm không đổi (public, auth nhánh lỗi, bình luận, danh mục, banner, ảnh album) để hồi quy. Code đã sửa: `Order::checkout()`, `Order::restoreStock()`, snapshot biến thể trên hóa đơn, `Product::createAdmin()`, `Product::updateAdmin()`, `getDefaultVariantPricing()`.
- Tài khoản: admin `admin@example.com` và user thường `user@example.com` (mật khẩu và JWT không ghi vào log).
- Dữ liệu test (thương mại): sản phẩm `id=4` (`xit-duong-toc-buoi-cocoon`), biến thể 4 (Chai 140ml, giá 145000) và biến thể 10 (Chai 310ml, giá 235000).
- Cách chạy: script gọi HTTP thật; mọi dữ liệu tạo ra (bình luận, danh mục, banner, ảnh album, sản phẩm, giỏ) được dọn bằng chính API; riêng đơn hàng chỉ hủy được, không xóa được. Không chạy SQL trực tiếp trong bộ test.

## Tổng kết

**181/181 ca PASS.**

| Nhóm | Số ca | PASS |
|---|---:|---:|
| Public và catalog (đọc) | 16 | 16 |
| Xác thực và tài khoản | 16 | 16 |
| Giỏ hàng (api-cart*) | 19 | 19 |
| Checkout (api-order-checkout) | 11 | 11 |
| Danh sách đơn (api-orders) | 4 | 4 |
| Hóa đơn (api-order-invoice) | 6 | 6 |
| Thanh toán (api-order-payment) | 1 | 1 |
| Hủy đơn và hoàn kho (api-order-cancel) | 7 | 7 |
| Admin đơn hàng (api-admin-orders, api-admin-order-update) | 16 | 16 |
| Admin sản phẩm: tạo/sửa/xóa kèm biến thể mặc định (api-admin-product-*) | 26 | 26 |
| Tài khoản user thường: phân quyền và quyền chéo | 15 | 15 |
| Bình luận (user và admin) | 22 | 22 |
| Danh mục (admin) | 10 | 10 |
| Banner (admin) | 7 | 7 |
| Ảnh album sản phẩm (admin) | 5 | 5 |

## Đối chiếu tồn kho và sales_count

| Thời điểm | Kho biến thể 4 | Kho biến thể 10 | sales_count sản phẩm 4 |
|---|---:|---:|---:|
| Trước test | 100 | 45 | 61 |
| Sau test (mọi đơn đã hủy) | 100 | 45 | 61 |

Trong test: đặt 3 sản phẩm biến thể 4 làm kho giảm 100 -> 97 và `sales_count` 61 -> 64; hủy đơn hoàn lại đúng. Đơn thứ hai (1 sản phẩm biến thể 10) được admin hủy qua `api-admin-order-update` và cũng hoàn kho đúng.

## Chi tiết từng ca

### Public và catalog (đọc)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| GET | `/` | Trang chủ | 200 | 200 | PASS |
| GET | `api-check` | api-check | 200 | 200 | PASS |
| GET | `api-products`?per_page=2 | api-products danh sách | 200 | 200 | PASS |
| GET | `api-products`?search=cocoon | api-products tìm kiếm | 200 | 200 | PASS |
| GET | `api-products`?category=duong-toc | api-products lọc theo danh mục (slug) | 200 | 200 | PASS |
| GET | `api-products-top`?sort_by=sales | api-products-top sort sales | 200 | 200 | PASS |
| GET | `api-products-top`?sort_by=views | api-products-top sort views | 200 | 200 | PASS |
| GET | `api-products-top`?sort_by=invalid | api-products-top sort sai | 400 | 400 | PASS |
| GET | `api-product-detail`?slug=xit-duong-toc-buoi-cocoon | api-product-detail hợp lệ | 200 | 200 | PASS |
| GET | `api-product-detail` | api-product-detail thiếu slug | 400 | 400 | PASS |
| GET | `api-product-detail`?slug=khong-ton-tai-xyz | api-product-detail slug không tồn tại | 404 | 404 | PASS |
| GET | `api-categories` | api-categories | 200 | 200 | PASS |
| GET | `api-banners` | api-banners | 200 | 200 | PASS |
| GET | `api-product-comments`?slug=xit-duong-toc-buoi-cocoon | api-product-comments hợp lệ | 200 | 200 | PASS |
| GET | `api-product-comments`?slug=xit-duong-toc-buoi-cocoon&per_page=0 | api-product-comments phân trang sai | 400 | 400 | PASS |
| GET | `api-product-comments`?slug=khong-ton-tai-xyz | api-product-comments sản phẩm không tồn tại | 404 | 404 | PASS |

### Xác thực và tài khoản

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-login` | Login sai mật khẩu | 401 | 401 | PASS |
| POST | `api-login` | Login sai mật khẩu (user) | 401 | 401 | PASS |
| POST | `api-login` | Login thiếu body | 400 | 400 | PASS |
| POST | `api-register` | Register dữ liệu sai | 422 | 422 | PASS |
| GET | `api-verify-email`?token=invalid | Verify email token sai | 400 | 400 | PASS |
| POST | `api-forgot-password` | Forgot password email sai định dạng | 422 | 422 | PASS |
| GET | `api-reset-password`?token=invalid | Reset password GET token sai | 400 | 400 | PASS |
| POST | `api-reset-password` | Reset password POST token không tồn tại | 400 | 400 | PASS |
| GET | `reset-password-bridge`?token=invalid | Reset password bridge token sai | 400 | 400 | PASS |
| POST | `api-change-password` | Đổi mật khẩu không token | 401 | 401 | PASS |
| GET | `api-change-password` | Đổi mật khẩu sai method | 405 | 405 | PASS |
| POST | `api-change-password` | Đổi mật khẩu sai mật khẩu hiện tại | 422 | 422 | PASS |
| POST | `api-logout` | Logout không token | 401 | 401 | PASS |
| GET | `api-posts` | api-posts có token | 200 | 200 | PASS |
| GET | `api-posts` | api-posts không token | 401 | 401 | PASS |
| GET | `api-posts` | Bearer token sai định dạng | 401 | 401 | PASS |

### Giỏ hàng (api-cart*)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| GET | `api-cart` | GET giỏ không token | 401 | 401 | PASS |
| GET | `api-cart` | GET giỏ rỗng | 200 | 200 | PASS |
| GET | `api-cart-add` | POST add sai method (GET) | 405 | 405 | PASS |
| POST | `api-cart-add` | Add không token | 401 | 401 | PASS |
| POST | `api-cart-add` | Add chỉ product_id (contract cũ) | 422 | 422 | PASS |
| POST | `api-cart-add` | Add quantity = 0 | 422 | 422 | PASS |
| POST | `api-cart-add` | Add biến thể không tồn tại | 404 | 404 | PASS |
| POST | `api-cart-add` | Add vượt tồn kho | 409 | 409 | PASS |
| POST | `api-cart-add` | Add biến thể A (qty 2) | 201 | 201 | PASS |
| POST | `api-cart-add` | Add cùng biến thể A (qty mặc định 1, gộp dòng) | 201 | 201 | PASS |
| POST | `api-cart-add` | Add biến thể B (qty 1) | 201 | 201 | PASS |
| - | (đối chiếu dữ liệu) | Giỏ có 2 dòng (A gộp, B) | 2 | 2 | PASS |
| - | (đối chiếu dữ liệu) | Dòng A quantity = 3 sau khi gộp | 3 | 3 | PASS |
| PUT | `api-cart-update`?id=39 | Update quantity item B = 2 | 200 | 200 | PASS |
| PUT | `api-cart-update`?id=39 | Update vượt tồn kho | 409 | 409 | PASS |
| PUT | `api-cart-update`?id=999999 | Update item không tồn tại | 404 | 404 | PASS |
| PUT | `api-cart-update`?id=39 | Update quantity 0 | 422 | 422 | PASS |
| DELETE | `api-cart-delete`?id=999999 | Delete item không tồn tại | 404 | 404 | PASS |
| DELETE | `api-cart-delete`?id=39 | Delete item B | 200 | 200 | PASS |

### Checkout (api-order-checkout)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-order-checkout` | Checkout không token | 401 | 401 | PASS |
| GET | `api-order-checkout` | Checkout sai method (GET) | 405 | 405 | PASS |
| POST | `api-order-checkout` | Checkout thiếu thông tin giao hàng | 400 | 400 | PASS |
| POST | `api-order-checkout` | Checkout số điện thoại sai | 422 | 422 | PASS |
| POST | `api-order-checkout` | Checkout hợp lệ (giỏ có A x3) | 201 | 201 | PASS |
| - | (đối chiếu dữ liệu) | Kho A giảm đúng số lượng đặt | 97 | 97 | PASS |
| - | (đối chiếu dữ liệu) | Kho B không đổi | 45 | 45 | PASS |
| - | (đối chiếu dữ liệu) | sales_count sản phẩm tăng đúng số lượng | 64 | 64 | PASS |
| - | (đối chiếu dữ liệu) | Tổng tiền = 145000 x 3 | 435000.00 | 435000.00 | PASS |
| - | (đối chiếu dữ liệu) | Giỏ rỗng sau checkout | 0 | 0 | PASS |
| POST | `api-order-checkout` | Checkout giỏ rỗng | 422 | 422 | PASS |

### Danh sách đơn (api-orders)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| GET | `api-orders` | Danh sách đơn không token | 401 | 401 | PASS |
| GET | `api-orders` | Danh sách đơn của user | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Đơn vừa tạo có trong danh sách | True | True | PASS |
| GET | `api-orders`?page=0 | Phân trang sai (page=0) | 400 | 400 | PASS |

### Hóa đơn (api-order-invoice)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| GET | `api-order-invoice`?id=10 | Invoice đơn của mình | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Invoice có 1 dòng, unit_price snapshot 145000.00 | [[4, 3, '145000.00']] | [[4, 3, '145000.00']] | PASS |
| - | (đối chiếu dữ liệu) | Snapshot biến thể trên hóa đơn (variant_id, sku, label) | [[4, 'COCOON-BUOI', 'Chai 140ml']] | [[4, 'COCOON-BUOI', 'Chai 140ml']] | PASS |
| GET | `api-order-invoice`?id=abc | Invoice ID sai định dạng | 400 | 400 | PASS |
| GET | `api-order-invoice`?id=999999 | Invoice đơn không tồn tại | 404 | 404 | PASS |
| GET | `api-order-invoice`?id=10 | Invoice không token | 401 | 401 | PASS |

### Thanh toán (api-order-payment)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-order-payment`?id=10 | api-order-payment cho đơn pending/unpaid | 200 | 200 | PASS |

### Hủy đơn và hoàn kho (api-order-cancel)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-order-cancel`?id=10 | Hủy đơn không token | 401 | 401 | PASS |
| GET | `api-order-cancel`?id=10 | Hủy đơn sai method (GET) | 405 | 405 | PASS |
| POST | `api-order-cancel`?id=999999 | Hủy đơn không tồn tại | 404 | 404 | PASS |
| POST | `api-order-cancel`?id=10 | Hủy đơn pending/unpaid | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Kho A hoàn về như ban đầu | 100 | 100 | PASS |
| - | (đối chiếu dữ liệu) | sales_count hoàn về như ban đầu | 61 | 61 | PASS |
| POST | `api-order-cancel`?id=10 | Hủy lần hai | 409 | 409 | PASS |

### Admin đơn hàng (api-admin-orders, api-admin-order-update)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-order-checkout` | Tạo đơn mới (B x1) để test admin | 201 | 201 | PASS |
| GET | `api-admin-orders` | Admin list không token | 401 | 401 | PASS |
| POST | `api-admin-orders` | Admin list sai method | 405 | 405 | PASS |
| GET | `api-admin-orders` | Admin list đơn | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Đơn mới có trong danh sách admin | True | True | PASS |
| POST | `api-admin-order-update`?id=11 | Update sai method (POST) | 405 | 405 | PASS |
| PUT | `api-admin-order-update`?id=11 | Update không token | 401 | 401 | PASS |
| PUT | `api-admin-order-update`?id=11 | Update trạng thái sai giá trị | 422 | 422 | PASS |
| PUT | `api-admin-order-update`?id=999999 | Update đơn không tồn tại | 404 | 404 | PASS |
| PUT | `api-admin-order-update`?id=11 | pending -> confirmed | 200 | 200 | PASS |
| PUT | `api-admin-order-update`?id=11 | confirmed -> delivered (nhảy bước, bị chặn) | 409 | 409 | PASS |
| POST | `api-order-cancel`?id=11 | User hủy đơn đã confirmed (bị chặn) | 409 | 409 | PASS |
| PUT | `api-admin-order-update`?id=11 | confirmed -> cancelled | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Admin hủy đơn: kho B hoàn về | 45 | 45 | PASS |
| - | (đối chiếu dữ liệu) | Admin hủy đơn: sales_count hoàn về | 61 | 61 | PASS |
| PUT | `api-admin-order-update`?id=11 | cancelled -> confirmed (bị chặn) | 409 | 409 | PASS |

### Admin sản phẩm: tạo/sửa/xóa kèm biến thể mặc định (api-admin-product-*)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-admin-product-create` | Tạo sản phẩm không token | 401 | 401 | PASS |
| GET | `api-admin-product-create` | Tạo sản phẩm sai method | 405 | 405 | PASS |
| POST | `api-admin-product-create` | Tạo thiếu trường stock | 422 | 422 | PASS |
| POST | `api-admin-product-create` | Tạo giá khuyến mãi >= giá gốc | 422 | 422 | PASS |
| POST | `api-admin-product-create` | Tạo sản phẩm hợp lệ | 201 | 201 | PASS |
| POST | `api-admin-product-create` | Tạo trùng mã/slug | 409 | 409 | PASS |
| - | (đối chiếu dữ liệu) | Có đúng 1 biến thể mặc định (sku, giá, kho) | [['TEST-API-VARIANT', True, 100000, 5]] | [['TEST-API-VARIANT', True, 100000, 5]] | PASS |
| PUT | `api-admin-product-update`?id=10 | Sửa chỉ tên | 200 | 200 | PASS |
| PUT | `api-admin-product-update`?id=10 | Sửa giá + kho | 200 | 200 | PASS |
| PUT | `api-admin-product-update`?id=10 | Sửa giá khuyến mãi hợp lệ (chỉ gửi sale_price) | 200 | 200 | PASS |
| PUT | `api-admin-product-update`?id=10 | Sửa giá khuyến mãi >= giá hiện tại (chỉ gửi sale_price) | 422 | 422 | PASS |
| PUT | `api-admin-product-update`?id=999999 | Sửa sản phẩm không tồn tại | 404 | 404 | PASS |
| PUT | `api-admin-product-update`?id=10 | Sửa không token | 401 | 401 | PASS |
| - | (đối chiếu dữ liệu) | Sau khi sửa: tên, giá, giá giảm, kho, final_price | ['Sản phẩm test API (sửa)', 120000, 100000, 7, 100000] | ['Sản phẩm test API (sửa)', 120000, 100000, 7, 100000] | PASS |
| POST | `api-cart-add` | Thêm biến thể của SP mới vào giỏ | 201 | 201 | PASS |
| POST | `api-order-checkout` | Checkout SP mới (2 cái, giá giảm) | 201 | 201 | PASS |
| - | (đối chiếu dữ liệu) | Tổng tiền = 100000 x 2 (dùng final_price) | 200000.00 | 200000.00 | PASS |
| - | (đối chiếu dữ liệu) | Kho SP mới còn 5 sau khi đặt 2 | 5 | 5 | PASS |
| POST | `api-order-cancel`?id=12 | Hủy đơn SP mới | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Kho SP mới hoàn về 7 | 7 | 7 | PASS |
| DELETE | `api-admin-product-delete`?id=10 | Xóa sản phẩm không token | 401 | 401 | PASS |
| DELETE | `api-admin-product-delete`?id=10 | Xóa sản phẩm | 200 | 200 | PASS |
| GET | `api-product-detail`?slug=test-api-san-pham-bien-the | Chi tiết SP đã xóa | 404 | 404 | PASS |
| GET | `api-order-invoice`?id=12 | Hóa đơn vẫn đọc được sau khi xóa SP | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Snapshot (tên, sku, nhãn biến thể) còn nguyên và product_id về NULL | ['Sản phẩm test API (sửa)', 'TEST-API-VARIANT', 'Mặc định', None] | ['Sản phẩm test API (sửa)', 'TEST-API-VARIANT', 'Mặc định', None] | PASS |
| - | (đối chiếu dữ liệu) | variant_id về NULL sau khi xóa sản phẩm (theo schema doc) | None | None | PASS |

### Tài khoản user thường: phân quyền và quyền chéo

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-login` | Login user thường | 200 | 200 | PASS |
| GET | `api-admin-orders` | User gọi admin list đơn | 403 | 403 | PASS |
| PUT | `api-admin-order-update`?id=11 | User gọi admin update đơn | 403 | 403 | PASS |
| POST | `api-admin-product-create` | User gọi admin tạo sản phẩm | 403 | 403 | PASS |
| GET | `api-order-invoice`?id=10 | User xem hóa đơn đơn của admin | 404 | 404 | PASS |
| POST | `api-order-cancel`?id=10 | User hủy đơn của admin | 404 | 404 | PASS |
| GET | `api-orders` | User xem danh sách đơn của mình | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Danh sách đơn của user không chứa đơn của admin | False | False | PASS |
| POST | `api-cart-add` | Admin thêm 1 dòng giỏ (biến thể A) | 201 | 201 | PASS |
| GET | `api-cart` | User xem giỏ của mình | 200 | 200 | PASS |
| - | (đối chiếu dữ liệu) | Giỏ của user không chứa dòng của admin | False | False | PASS |
| PUT | `api-cart-update`?id=42 | User sửa dòng giỏ của admin | 404 | 404 | PASS |
| DELETE | `api-cart-delete`?id=42 | User xóa dòng giỏ của admin | 404 | 404 | PASS |
| DELETE | `api-cart-delete`?id=42 | Admin dọn dòng giỏ | 200 | 200 | PASS |
| - | (bỏ qua) | Bỏ qua test user tự đặt đơn (giỏ user đang có hàng) | - | - | BỎ QUA |

### Bình luận (user và admin)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-comment-create` | Tạo bình luận không token | 401 | 401 | PASS |
| GET | `api-comment-create` | Tạo bình luận sai method | 405 | 405 | PASS |
| POST | `api-comment-create` | Tạo bình luận product_id sai | 422 | 422 | PASS |
| POST | `api-comment-create` | Tạo bình luận sản phẩm không tồn tại | 404 | 404 | PASS |
| POST | `api-comment-create` | Tạo bình luận nội dung rỗng | 422 | 422 | PASS |
| POST | `api-comment-create` | User tạo bình luận (chờ duyệt) | 201 | 201 | PASS |
| PUT | `api-comment-update`?id=8 | User sửa bình luận của mình | 200 | 200 | PASS |
| PUT | `api-comment-update`?id=8 | Sửa bình luận không token | 401 | 401 | PASS |
| PUT | `api-comment-update`?id=999999999 | Sửa bình luận không tồn tại | 404 | 404 | PASS |
| POST | `api-comment-update`?id=8 | Sửa bình luận sai method | 405 | 405 | PASS |
| PUT | `api-comment-update`?id=8 | Admin (user khác) sửa bình luận của user | 404 | 404 | PASS |
| GET | `api-admin-comments`?status=0 | Admin xem danh sách chờ duyệt | 200 | 200 | PASS |
| GET | `api-admin-comments`?status=5 | Admin lọc status sai | 400 | 400 | PASS |
| GET | `api-admin-comments`?per_page=0 | Admin phân trang sai | 400 | 400 | PASS |
| PUT | `api-admin-comment-approve`?id=8 | Admin duyệt bình luận | 200 | 200 | PASS |
| PUT | `api-admin-comment-approve`?id=999999999 | Admin duyệt bình luận không tồn tại | 404 | 404 | PASS |
| - | (đối chiếu dữ liệu) | Bình luận đã duyệt xuất hiện ở danh sách công khai | True | True | PASS |
| DELETE | `api-comment-delete`?id=999999999 | User xóa bình luận không tồn tại | 404 | 404 | PASS |
| DELETE | `api-admin-comment-delete`?id=999999999 | Admin xóa bình luận không tồn tại | 404 | 404 | PASS |
| DELETE | `api-admin-comment-delete`?id=8 | Admin xóa bình luận (dọn dữ liệu) | 200 | 200 | PASS |
| POST | `api-comment-create` | User tạo bình luận thứ hai | 201 | 201 | PASS |
| DELETE | `api-comment-delete`?id=9 | User xóa bình luận pending của mình | 200 | 200 | PASS |

### Danh mục (admin)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| GET | `api-admin-category-create` | Tạo danh mục sai method | 405 | 405 | PASS |
| POST | `api-admin-category-create` | Tạo danh mục thiếu slug | 422 | 422 | PASS |
| POST | `api-admin-category-create` | Tạo danh mục | 201 | 201 | PASS |
| POST | `api-admin-category-create` | Tạo danh mục trùng slug | 409 | 409 | PASS |
| PUT | `api-admin-category-update`?id=3 | Sửa danh mục | 200 | 200 | PASS |
| PUT | `api-admin-category-update`?id=999999 | Sửa danh mục không tồn tại | 404 | 404 | PASS |
| PUT | `api-admin-category-update`?id=abc | Sửa danh mục ID sai | 400 | 400 | PASS |
| DELETE | `api-admin-category-delete`?id=1 | Xóa danh mục đang có sản phẩm bị chặn | 409 | 409 | PASS |
| DELETE | `api-admin-category-delete`?id=999999 | Xóa danh mục không tồn tại | 404 | 404 | PASS |
| DELETE | `api-admin-category-delete`?id=3 | Xóa danh mục trống (dọn dữ liệu) | 200 | 200 | PASS |

### Banner (admin)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-admin-banner-create` | Tạo banner thiếu trường | 422 | 422 | PASS |
| POST | `api-admin-banner-create` | Tạo banner (không ảnh) | 201 | 201 | PASS |
| PUT | `api-admin-banner-update`?id=4 | Sửa banner | 200 | 200 | PASS |
| PUT | `api-admin-banner-update`?id=4 | Sửa banner không có trường nào | 422 | 422 | PASS |
| PUT | `api-admin-banner-update`?id=999999 | Sửa banner không tồn tại | 404 | 404 | PASS |
| DELETE | `api-admin-banner-delete`?id=999999 | Xóa banner không tồn tại | 404 | 404 | PASS |
| DELETE | `api-admin-banner-delete`?id=4 | Xóa banner (dọn dữ liệu) | 200 | 200 | PASS |

### Ảnh album sản phẩm (admin)

| Method | Route | Test | Mong đợi | Nhận được | Kết quả |
|---|---|---|---|---|---|
| POST | `api-admin-product-image-create` | Upload ảnh album hợp lệ (PNG) | 201 | 201 | PASS |
| POST | `api-admin-product-image-create` | Upload file không phải ảnh bị từ chối | 422 | 422 | PASS |
| POST | `api-admin-product-image-create` | Upload ảnh cho sản phẩm không tồn tại | 404 | 404 | PASS |
| DELETE | `api-admin-product-image-delete`?id=999999 | Xóa ảnh album không tồn tại | 404 | 404 | PASS |
| DELETE | `api-admin-product-image-delete`?id=13 | Xóa ảnh album (dọn dữ liệu) | 200 | 200 | PASS |

## Request body mẫu

Header xác thực khi cần: `Authorization: Bearer <JWT>` (token thật không ghi vào log).

`POST ?action=api-cart-add` (contract hiện tại dùng `variant_id`; gửi `product_id` bị từ chối 422):

```json
{"variant_id": 4, "quantity": 2}
```

`PUT ?action=api-cart-update&id=<id dòng giỏ>`:

```json
{"quantity": 2}
```

`POST ?action=api-order-checkout`:

```json
{"recipient_name": "Test Buyer", "recipient_phone": "0900000000", "shipping_address": "1 Test Street, District 1"}
```

`PUT ?action=api-admin-order-update&id=<id đơn>`:

```json
{"order_status": "confirmed", "payment_status": "unpaid"}
```

## Đã xử lý trong lần chạy này

- **`order_details.variant_id` không về NULL khi xóa sản phẩm** (lệch so với schema doc). Đã sửa bằng migration `20261114_01_order_details_variant_fk_set_null.sql` (thêm khóa ngoại đơn `variant_id` với `ON DELETE SET NULL`, dọn các dòng đơn cũ đang trỏ tới biến thể đã xóa). Migration được chạy bằng `run.php --verify` sau khi sao lưu DB: bốn kiểm tra toàn vẹn đều ĐẠT, chạy lại báo `Không có migration mới.`. Bộ test này được chạy lại sau migration và ca `variant_id về NULL sau khi xóa sản phẩm` đã đạt.

## Chưa kiểm tra và lưu ý

- **User tự đặt đơn:** bỏ qua vì giỏ của `user@example.com` đang có sẵn hàng; test không đụng dữ liệu đó. Quyền chéo (403, 404) và giỏ tách biệt giữa hai user đã được test.
- **Thiếu hàng, biến thể ngừng bán:** chưa dựng được ca `stock_unavailable` (409 khi checkout) vì giỏ chặn vượt tồn kho từ lúc thêm; chưa test đặt đơn đồng thời (race condition).
- **Thanh toán:** `api-order-payment` trả 200 trong môi trường này; kết quả phụ thuộc cấu hình SePay sandbox, chưa test gửi form sang SePay.
- **Chưa phủ (có chủ đích):** happy path của đăng ký, xác minh email, quên và đặt lại mật khẩu (gửi email thật), `api-logout` hợp lệ (thu hồi mọi phiên của tài khoản), upload banner kèm ảnh, token hết hạn. Chưa có endpoint quản trị nhiều biến thể (form admin chỉ tạo và sửa biến thể mặc định).
- **Tác dụng phụ để lại trong DB:** các đơn ID 10, 11, 12 của lần chạy cuối ở trạng thái `cancelled` (API không cho xóa đơn); các lần chạy trước đó cũng để lại đơn `cancelled` tương tự. Sản phẩm test đã được xóa bằng API. `view_count` của một số sản phẩm tăng do gọi `api-product-detail`. Giỏ hàng của admin đã rỗng; giỏ của user không bị thay đổi.
