# BE API Test Log

- Thời điểm smoke test ban đầu: `2026-11-09 20:19:19 +07:00` (giờ local, UTC+7; lần chạy này dùng contract tạo comment bằng slug).
- Thời điểm retest contract tạo bình luận bằng `product_id`: `2026-11-09 20:39:40 +07:00` (giờ local, UTC+7).
- Thời điểm migrate và kiểm thử commerce: `2026-11-09 21:07:14 +07:00` (giờ local, UTC+7).
- Thời điểm retest pagination sau chỉnh sửa: `2026-11-09 21:12:53 +07:00` (giờ local, UTC+7).
- Thời điểm auth/security suite: `2026-11-09 21:24:05 +07:00` (giờ local, UTC+7).
- Thời điểm counter và token revocation retest: `2026-11-09 21:31:25 +07:00` (giờ local, UTC+7).
- Thời điểm auth matrix cuối: `2026-11-09 21:34:45 +07:00` (giờ local, UTC+7).
- Base URL: `http://localhost/du-an-1/BE/`.
- Môi trường: Laragon, Apache/PHP local; đăng nhập bằng tài khoản demo do người dùng cung cấp. Không ghi mật khẩu hoặc JWT vào log.
- Phạm vi: 22 route ban đầu và 10 commerce endpoint mới; smoke test qua HTTP thật. Các status dưới đây là HTTP status nhận được.

## Kết quả

### Auth Và Tài Khoản

| Route | Test | HTTP |
|---|---|---|
| `api-login` | Hợp lệ / mật khẩu sai / account inactive | 200 / 401 / 401 |
| `api-register` | Dữ liệu sai / email active đã tồn tại | 422 / 409 |
| `api-verify-email` | Token sai định dạng | 400 |
| `api-forgot-password` | Email sai định dạng | 422; không gửi email |
| `api-reset-password` | GET token sai; POST token không tồn tại | 400 / 400; không đổi mật khẩu |
| `reset-password-bridge` | Token sai định dạng | 400 |
| `api-change-password` | Thiếu token / sai method / current password sai | 401 / 405 / 422 |
| `api-logout` | Token hợp lệ / thiếu token / dùng lại token sau logout | 200 / 401 / 401 |
| `api-posts` | Token hợp lệ / thiếu token | 200 / 401 |

### Public Và Catalog

| Route | Test | HTTP |
|---|---|---|
| `/`, `api-check` | GET | 200 |
| `api-products` | GET danh sách, một item | 200 |
| `api-product-detail` | Slug hợp lệ / thiếu / không tồn tại; detail hợp lệ tăng view_count | 200 / 400 / 404 |
| `api-products-top` | `sort_by=sales` / sort sai | 200 / 400 |
| `api-categories`, `api-banners`, `api-guns`, `api-camchim` | GET | 200 |

### Comments

| Route | Test | HTTP |
|---|---|---|
| `api-product-comments` | Slug hợp lệ / page sai / sản phẩm không tồn tại | 200 / 400 / 404 |
| `api-comment-create` | Slug lịch sử; contract hiện tại `product_id`; invalid ID/product | 201 / 201 / 422 / 404 |
| `api-comment-update` | Sai method / không token / ID sai / owner update pending | 405 / 401 / 404 / 200 |
| `api-comment-delete` | Sai method / không token / ID sai / owner delete pending | 405 / 401 / 404 / 200 |

### Cart

| Route | Test | HTTP |
|---|---|---|
| `api-cart` | GET token hợp lệ / thiếu token | 200 / 401 |
| `api-cart-add` | Thêm/gộp item, default quantity, ID sai/không có, thiếu/sai token | 201 / 201 / 201 / 422 / 404 / 401 |
| `api-cart-update` | Số lượng hợp lệ / vượt stock / item không có / thiếu token | 200 / 409 / 404 / 401 |
| `api-cart-delete` | Xóa hợp lệ / item không có / thiếu token | 200 / 404 / 401 |

### Orders Và Invoice

| Route | Test | HTTP |
|---|---|---|
| `api-order-checkout` | Hợp lệ / cart rỗng / thiếu token | 201 / 422 / 401 |
| `api-orders` | List / page sai / thiếu token | 200 / 400 / 401 |
| `api-order-invoice` | Owner / ID sai / user khác / thiếu token | 200 / 404 / 404 / 401 |
| `api-order-cancel` | Pending / hủy lần hai / thiếu token | 200 / 409 / 401 |

### Admin

| Route | Test | HTTP |
|---|---|---|
| `api-admin-orders` | User thường / thiếu token / admin hợp lệ | 403 / 401 / 200 |
| `api-admin-order-update` | User thường / sai method / thiếu token / admin cập nhật trạng thái | 403 / 405 / 401 / 200 |

## Auth Và Phân Quyền

Lần chạy security suite ban đầu: `2026-11-09 21:24:05 +07:00`; lượt auth matrix cuối: `2026-11-09 21:34:45 +07:00`. Kết quả mới nhất theo từng test name: **84/84 PASS**. Một lần thử quyền chéo ban đầu dùng JWT đã hết hạn; sau đăng nhập lại, các ca quyền chéo chạy lại đạt kết quả mong đợi.

Có **16 endpoint yêu cầu đăng nhập**: `api-change-password`, `api-logout`, `api-posts`, ba `api-comment-*`, bốn `api-cart*`, `api-order-checkout`, `api-orders`, `api-order-invoice`, `api-order-cancel`, `api-admin-orders`, `api-admin-order-update`.

| Trường hợp | Số route | Kết quả |
|---|---:|---|
| Không gửi Bearer token sau middleware mới | 16/16 | HTTP 401 |
| Bearer token sai định dạng sau middleware mới | 16/16 | HTTP 401 |
| JWT ký hợp lệ nhưng hết hạn sau middleware mới | 16/16 | HTTP 401 |
| JWT version cũ sau logout | 2 token/2 | HTTP 401 |
| JWT sau khi user inactive hoặc role đổi | 2/2 | HTTP 401 |

Các kiểm tra tài khoản: login đúng trả 200; sai password và user `is_active=0` trả 401; đăng ký lại email active trả 409. Không thử gửi lại email xác minh cho tài khoản pending vì sẽ kích hoạt side effect gửi email.

Migration `20261109_add_user_token_version.sql` thêm version cho mỗi user. Login đưa version vào JWT; middleware đối chiếu version, `is_active` và role hiện tại với DB ở mỗi request. Logout tăng version nên token hiện tại và các phiên khác cùng user đều bị thu hồi; login mới nhận version mới. Token cũ chưa có claim version được hiểu là version 0 để tương thích cho tới lần logout đầu tiên.

Kiểm thử thực tế: đăng nhập hai phiên cùng version; logout một phiên trả 200, cả hai JWT cũ trả 401, đăng nhập mới trả 200. Token sau khi user inactive hoặc role đổi đều trả 401. Token cũ version 0 dùng được sau migration cho tới lần logout đầu; logout thu hồi tất cả phiên của user, không chỉ thiết bị hiện tại.

Kiểm tra quyền chéo với hai user:

- User B xem cart của mình trả 200 nhưng không có item của user A.
- User B sửa/xóa cart item hoặc comment của user A đều trả 404.
- User B xem invoice của user A trả 404; danh sách đơn của B không có đơn của A.
- User thường gọi admin list/update trả 403.
- Admin test đăng nhập thành công, xem admin orders và invoice trả 200; cập nhật đơn pending → confirmed → cancelled trả 200.

Các user phụ/admin được tạo tạm cho test đã bị xóa; comment/cart fixtures đã dọn. Order admin test cũng được xóa sau khi hủy qua API và hoàn kho. JWT/password không ghi vào log.

## Product Counters

Kiểm thử lúc `2026-11-09 21:31:25 +07:00` trên `product_id=29`:

| Counter | Trước | Sau sự kiện | Sau hủy |
|---|---:|---:|---:|
| `view_count` | 4824 | 4825 sau một GET detail | không áp dụng |
| `sales_count` | 336 | 337 sau checkout quantity 1 | 336 sau cancel |
| `stock` | 11 | 10 sau checkout quantity 1 | 11 sau cancel |

`view_count` tăng khi `api-product-detail` trả sản phẩm thành công. `sales_count` hiện đếm số lượng trong đơn đã checkout và chưa bị hủy; hủy đơn trừ lại số lượng. Order fixture đã được hủy và xóa sau test.

Sau các lần GET detail dùng trong auth/security test, DB ghi `view_count=4827`, `sales_count=336`, `stock=11` cho sản phẩm test.

## Retest Contract `product_id`

Lần chạy: `2026-11-09 20:39:40 +07:00`. Sản phẩm thử nghiệm có `product_id=29`. Ca hợp lệ gửi:

```json
{
	"product_id": 29,
	"content": "Product ID API test bcf7cbd9c705463d914cc4d8dafb4165"
}
```

HTTP 201, response JSON thực tế:

```json
{
	"status": "success",
	"message": "Thêm bình luận thành công",
	"comment": {
		"id": 7,
		"product_id": 29,
		"content": "Product ID API test bcf7cbd9c705463d914cc4d8dafb4165"
	}
}
```

ID sản phẩm không hợp lệ gửi `{"product_id":"not-an-id","content":"Invalid id case"}` và nhận HTTP 422:

```json
{
	"status": "error",
	"message": "ID sản phẩm không hợp lệ"
}
```

ID sản phẩm `2147483647` không tồn tại, body `{"product_id":2147483647,"content":"Unknown product case"}`, nhận HTTP 404:

```json
{
	"status": "error",
	"message": "Không tìm thấy sản phẩm"
}
```

Fixture comment ID 7 đã được xóa bằng API DELETE, nhận HTTP 200.

## Request JSON Cụ Thể

Các body dưới đây là payload đã gửi hoặc payload tương đương cho ca đã chạy. Header xác thực, khi cần: `Authorization: Bearer <JWT>`; token thật không ghi vào log. GET và DELETE không gửi JSON body.

### Auth

`POST ?action=api-login` trả 200 với tài khoản demo. Bí mật đăng nhập được lược khỏi file:

```json
{
	"email": "xuanlonghh4@gmail.com",
	"password": "<đã ẩn>"
}
```

`POST ?action=api-register` trả 422, không tạo tài khoản:

```json
{
	"email": "invalid",
	"password": "short"
}
```

`GET ?action=api-verify-email&token=invalid` trả 400; không có body.

`POST ?action=api-forgot-password` trả 422, không gửi email:

```json
{
	"email": "invalid"
}
```

`GET ?action=api-reset-password&token=invalid` trả 400; không có body.

`POST ?action=api-reset-password` dùng token đúng định dạng nhưng không tồn tại, trả 400:

```json
{
	"token": "0000000000000000000000000000000000000000000000000000000000000000",
	"new_password": "test-pass-123"
}
```

`GET ?action=reset-password-bridge&token=invalid` trả 400 HTML, không phải JSON.

`GET ?action=api-change-password` có JWT trả 405 vì sai method.

`POST ?action=api-change-password` có JWT, mật khẩu hiện tại sai, trả 422 và không đổi mật khẩu:

```json
{
	"current_password": "wrong-test-only",
	"new_password": "new-password-test"
}
```

`POST ?action=api-logout` có JWT trả 200, không body. Cùng request không có Authorization trả 401.

`GET ?action=api-posts` có JWT trả 200; cùng request không có Authorization trả 401. Không có body.

### Sản Phẩm Và Bình Luận

`GET ?action=api-product-detail&slug=serum-xit-duong-olaplex-no-9` trả 200. Thiếu slug trả 400; slug `__copilot_missing_product__` trả 404. Không có body.

`GET ?action=api-products-top&sort_by=sales` trả 200; `?sort_by=invalid` trả 400. Không có body.

`GET ?action=api-product-comments&slug=serum-xit-duong-olaplex-no-9&page=1&per_page=10` trả 200. `?slug=valid&per_page=0` trả 400; `?slug=__copilot_missing_product__` trả 404. Không có body.

`POST ?action=api-comment-create` có JWT, nội dung rỗng trả 422:

```json
{
	"slug": "serum-xit-duong-olaplex-no-9",
	"content": "   "
}
```

Body thực tế đã gửi trong ca tạo thành công:

```json
{
	"slug": "serum-xit-duong-olaplex-no-9",
	"content": "Binh luan kiem thu 13563484916e40dabcc683ba3525dce2"
}
```

Kết quả: 201. Cùng route không JWT trả 401; GET sai method có JWT trả 405.

`PUT ?action=api-comment-update&id=6` có JWT trả 200:

```json
{
	"content": "Updated API test fixture"
}
```

ID `999999999` trả 404 với body:

```json
{
	"content": "No such comment"
}
```

Cùng route không JWT trả 401; POST sai method có JWT trả 405.

`DELETE ?action=api-comment-delete&id=6` có JWT trả 200, không body. ID `999999999` trả 404; cùng route không JWT trả 401; GET sai method có JWT trả 405.

### Các Route GET Còn Lại

Các request sau không có JSON body:

```text
GET /                                      -> 200, HTML
GET ?action=api-check                     -> 200
GET ?action=api-products&per_page=1       -> 200
GET ?action=api-categories                -> 200
GET ?action=api-banners                   -> 200
GET ?action=api-guns                      -> 200
GET ?action=api-camchim                   -> 200
```

## Response JSON

Các response lỗi JSON đã nhận được:

```json
{
	"status": "error",
	"message": "Email không hợp lệ hoặc mật khẩu phải dài từ 8 đến 72 byte"
}
```

```json
{
	"status": "error",
	"message": "Token xác minh không hợp lệ"
}
```

```json
{
	"status": "error",
	"message": "Email không hợp lệ"
}
```

```json
{
	"status": "error",
	"message": "Token không hợp lệ hoặc đã hết hạn"
}
```

```json
{
	"status": "error",
	"message": "Token không hợp lệ, đã dùng hoặc hết hạn"
}
```

```json
{
	"status": "error",
	"message": "Bình luận phải có nội dung và không quá 2.000 ký tự"
}
```

```json
{
	"status": "error",
	"message": "Unauthorized: thiếu Bearer token"
}
```

Response thành công CRUD; đây là dữ liệu thực của fixture đã được xóa sau test:

```json
{
	"status": "success",
	"message": "Thêm bình luận thành công",
	"comment": {
		"id": 6,
		"slug": "serum-xit-duong-olaplex-no-9",
		"content": "Binh luan kiem thu 13563484916e40dabcc683ba3525dce2"
	}
}
```

```json
{
	"status": "success",
	"message": "Cập nhật bình luận thành công",
	"comment": {
		"id": 6,
		"content": "Updated API test fixture"
	}
}
```

```json
{
	"status": "success",
	"message": "Xóa bình luận thành công"
}
```

Các response danh sách/sản phẩm có dữ liệu thay đổi theo database; log phía trên ghi status thực nhận. Token login, email người dùng và dữ liệu cá nhân trong response không được lưu.

## CRUD Bình Luận

- Tạo bình luận thử thành công với HTTP 201; bản ghi ở trạng thái chờ duyệt không xuất hiện trong API GET công khai.
- Chủ sở hữu sửa bình luận pending thành công với HTTP 200.
- Xóa bình luận thử thành công với HTTP 200; fixture đã được dọn qua API DELETE.
- Các request không token cho create/update/delete đều trả 401.

## Commerce APIs

Migrations `20261109_create_commerce_tables.sql` và `20261109_add_user_token_version.sql` đã chạy. Tạo 4 bảng commerce: `carts`, `cart_items`, `orders`, `order_details`; mỗi bảng có `created_at` và `updated_at`. Không có bảng invoice riêng: `orders` là phần đầu hóa đơn, `order_details` giữ snapshot tên/giá/số lượng. Migration token thêm `users.token_version` mặc định 0.

Tất cả 10 endpoint commerce yêu cầu Bearer JWT. User chỉ thao tác dữ liệu của mình; admin list/update yêu cầu claim `role=admin`. Các ca không token đều trả 401; token user thường gọi admin list/update trả 403.

| Method và action | Query/body | Kết quả chính |
|---|---|---|
| `GET api-cart` | Không body | `cart.items`, `cart.total_amount` |
| `POST api-cart-add` | `{"product_id":29,"quantity":1}`; bỏ quantity thì mặc định 1 | Cộng item trùng; trả giỏ mới |
| `PUT api-cart-update?id=1` | `{"quantity":3}` | Đặt số lượng item thuộc giỏ user |
| `DELETE api-cart-delete?id=1` | Không body | Xóa item thuộc giỏ user |
| `POST api-order-checkout` | JSON thông tin người nhận và địa chỉ | Tạo đơn pending/unpaid, trừ tồn kho và làm rỗng giỏ |
| `GET api-orders?page=1&per_page=10` | Không body | Danh sách đơn của user |
| `GET api-order-invoice?id=1` | Không body | Header và snapshot chi tiết hóa đơn |
| `POST api-order-cancel?id=1` | Không body | Chỉ hủy pending/unpaid, hoàn tồn kho |
| `GET api-admin-orders?page=1&per_page=10` | Không body | Admin xem tất cả đơn |
| `PUT api-admin-order-update?id=1` | `{"order_status":"confirmed","payment_status":"unpaid"}` | Admin đổi trạng thái theo luồng hợp lệ |

Trạng thái đơn: `pending`, `confirmed`, `shipping`, `delivered`, `cancelled`. Thanh toán theo dõi riêng: `unpaid` hoặc `paid`.

### Test Thực Tế

Có **36/36 ca commerce PASS** trong lượt đầu lúc `2026-11-09 21:07:14 +07:00`, sau đó **2/2 ca pagination PASS** lúc `2026-11-09 21:12:53 +07:00` (tổng **38/38**). Sản phẩm thử `product_id=29`, stock ban đầu 11; giỏ tài khoản test ban đầu rỗng.

Body thêm sản phẩm:

```json
{
	"product_id": 29,
	"quantity": 1
}
```

Thêm hai lần cùng sản phẩm trả 201 và gộp thành một dòng. Cập nhật item `id=1` với `{"quantity":3}` trả 200; cập nhật quantity 12 khi stock 11 trả 409. Xóa item trả 200. Body `{"product_id":29}` không có quantity trả 201 với quantity mặc định 1; item test đã được xóa sau đó.

Body checkout:

```json
{
	"recipient_name": "Test Buyer",
	"recipient_phone": "0900000000",
	"shipping_address": "1 Test Street, Ward 1, District 1"
}
```

Response checkout thực tế:

```json
{
	"status": "success",
	"message": "Đặt hàng thành công",
	"order": {
		"id": 1,
		"total_amount": "620000.00",
		"order_status": "pending",
		"payment_status": "unpaid"
	}
}
```

`GET api-order-invoice?id=1` trả JSON hóa đơn thực tế:

```json
{
	"status": "success",
	"invoice": {
		"order": {
			"id": 1,
			"user_id": 3,
			"recipient_name": "Test Buyer",
			"recipient_phone": "0900000000",
			"shipping_address": "1 Test Street, Ward 1, District 1",
			"total_amount": "620000.00",
			"order_status": "pending",
			"payment_status": "unpaid",
			"created_at": "2026-11-09 21:06:02",
			"updated_at": "2026-11-09 21:06:02"
		},
		"items": [
			{
				"id": 1,
				"product_id": 29,
				"product_name": "Serum Xịt Dưỡng Olaplex No.9",
				"quantity": 1,
				"unit_price": "620000.00",
				"line_total": "620000.00"
			}
		]
	}
}
```

`POST api-order-cancel?id=1` trả `{"status":"success","message":"Đã hủy đơn hàng"}`. Hóa đơn tiếp tục đọc được với trạng thái `cancelled`; stock được hoàn từ 10 về 11. Hủy lần hai trả 409.

Ca lỗi: checkout giỏ trống 422; product ID sai 422; product/item/order ID không tồn tại 404; admin list/update bằng token user thường 403; admin update sai method 405. Đơn test ID 1 được giữ trong DB ở trạng thái cancelled để đối chiếu log; giỏ test đã sạch.

## Ghi Chú

- Lần thử create đầu tiên từ PowerShell trả 400 vì body JSON có ký tự tiếng Việt không được gửi với UTF-8 tường minh. Gửi lại cùng luồng với body UTF-8 và `charset=utf-8` trả 201; không có fixture nào được tạo ở lần thử lỗi.
- Không thử happy path đăng ký, xác minh email, gửi email quên mật khẩu, reset mật khẩu hoặc đổi mật khẩu vì các thao tác này cần email/token thật hoặc làm thay đổi tài khoản. Đã kiểm tra nhánh lỗi an toàn tương ứng.
- Runner đã chạy migrations pending, gồm `20261109_create_commerce_tables.sql` và `20261109_add_user_token_version.sql`; không chạy seed.
- Chạy lại runner sau đó trả `Không có migration mới.`
- Thay các nhánh no-op `SELECT 1` trong migration pending bằng `SET` không trả result set để tránh lỗi PDO 2014 của migration runner.
- Auth suite dùng user/admin tạm; các tài khoản phụ đã được xóa sau test. Order commerce ID 1 được giữ ở trạng thái cancelled như fixture log; tồn kho đã hoàn về giá trị ban đầu và cart rỗng.

## Admin CRUD APIs

Các endpoint dưới đây yêu cầu Bearer JWT của tài khoản có `role=admin`; role được đọc từ JWT đã xác minh, không nhận từ body. Danh sách sản phẩm/danh mục/banner hiện tại vẫn đọc qua các public GET API tương ứng.

| Method và action | Query/body | Kết quả chính |
|---|---|---|
| `POST api-admin-product-create` | JSON gồm `category_id`, `code`, `name`, `slug`, `price`, `stock`; `sale_price`, `description` tùy chọn. Ảnh `image` tùy chọn qua multipart | Tạo sản phẩm, HTTP 201 |
| `PUT api-admin-product-update?id=1` | JSON các trường cần sửa; gửi ảnh mới bằng multipart `POST` cùng action | Cập nhật sản phẩm |
| `DELETE api-admin-product-delete?id=1` | Không body | Xóa sản phẩm và ảnh album/đại diện |
| `POST api-admin-product-image-create` | multipart: `product_id`, `image`, `sort_order` tùy chọn | Thêm ảnh album, HTTP 201 |
| `DELETE api-admin-product-image-delete?id=1` | ID ảnh album | Xóa ảnh album |
| `POST api-admin-category-create` | JSON `{"name":"...","slug":"..."}` | Tạo danh mục, HTTP 201 |
| `PUT api-admin-category-update?id=1` | JSON gồm `name` và `slug` | Cập nhật danh mục |
| `DELETE api-admin-category-delete?id=1` | Không body | Xóa danh mục trống; HTTP 409 nếu còn sản phẩm |
| `POST api-admin-banner-create` | JSON gồm `title`, `content`, `CTA_link`; ảnh `image` tùy chọn qua multipart | Tạo banner, HTTP 201 |
| `PUT api-admin-banner-update?id=1` | JSON các trường cần sửa; gửi ảnh mới bằng multipart `POST` cùng action | Cập nhật banner |
| `DELETE api-admin-banner-delete?id=1` | Không body | Xóa banner và ảnh do API tải lên |
| `GET api-admin-comments?status=0&page=1&per_page=10` | `status` tùy chọn: 0 chờ duyệt, 1 đã duyệt | Danh sách có phân trang |
| `PUT api-admin-comment-approve?id=1` | Không body | Duyệt bình luận |
| `DELETE api-admin-comment-delete?id=1` | Không body | Xóa bình luận |

Ảnh được kiểm tra và lưu bằng `validate_upload`, `save_uploaded_file`; ảnh liên quan được dọn bằng `delete_uploaded_file`. DB chỉ lưu tên file trong `assets/uploads`; FE dùng helper ảnh chung để ghép URL từ `api.defaults.baseURL`, đồng thời vẫn hỗ trợ URL tuyệt đối cũ.

### Admin API Test Run

- Thời điểm chạy: `2026-11-09 22:24:00 +07:00` (giờ local, UTC+7).
- Kết quả: **46/46 PASS** qua HTTP local Laragon.
- Đăng nhập bằng tài khoản demo admin/user; JWT không ghi vào log.
- Quyền: thiếu token trả 401; user thường gọi admin API trả 403; admin xem danh sách đơn trả 200; sai method trả 405.
- Danh mục: tạo 201, slug trùng 409, sửa 200, đang được sản phẩm dùng thì xóa trả 409, xóa sau khi dọn sản phẩm trả 200.
- Sản phẩm: upload multipart hợp lệ tạo 201; DB/response chỉ có filename; file tồn tại và URL `/BE/assets/uploads/<filename>` trả 200. Giá sale không hợp lệ trả 422; sửa và xóa trả 200.
- Ảnh album: upload 201, filename xuất hiện trong chi tiết sản phẩm, xóa trả 200 và file vật lý được dọn.
- Banner: tạo với ảnh 201; sửa nội dung/ảnh trả 200; `api-banners` xác nhận DB chỉ chứa filename; thay ảnh dọn file cũ, xóa banner dọn file mới.
- Bình luận: user tạo comment pending 201; admin lọc danh sách pending/approved, duyệt và xóa đều trả 200.
- MIME: file `default.jpg` có MIME thật `image/webp` nên upload bị từ chối 422; JPEG hợp lệ dùng cho các ca upload thành công.
- Cleanup: toàn bộ fixture category/product/banner/comment và JPEG tạm đã được xóa; thư mục `BE/assets/uploads` còn lại `default.jpg`.