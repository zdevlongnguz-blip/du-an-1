# BE API Test Log

- Thời điểm hoàn tất: `2026-11-09 20:19:19 +07:00` (giờ local, UTC+7).
- Base URL: `http://localhost/du-an-1/BE/`.
- Môi trường: Laragon, Apache/PHP local; đăng nhập bằng tài khoản demo do người dùng cung cấp. Không ghi mật khẩu hoặc JWT vào log.
- Phạm vi: 22 route hiện có trong `routes/index.php`; smoke test qua HTTP thật. Các status dưới đây là HTTP status nhận được.

## Kết quả

| Route | Request đã thử | Kết quả |
|---|---|---|
| `/` | GET trang gốc | PASS, 200 |
| `api-check` | GET | PASS, 200 |
| `api-register` | POST dữ liệu email/mật khẩu sai định dạng | PASS, 422; không tạo tài khoản, không gửi email |
| `api-verify-email` | GET token sai định dạng | PASS, 400 |
| `api-forgot-password` | POST email sai định dạng | PASS, 422; không gửi email |
| `api-reset-password` | GET token sai định dạng; POST token đúng định dạng nhưng không tồn tại | PASS, 400 cho cả hai; không đổi mật khẩu |
| `reset-password-bridge` | GET token sai định dạng | PASS, 400 |
| `api-login` | POST thông tin tài khoản demo hợp lệ | PASS, 200; token chỉ giữ trong bộ nhớ test |
| `api-change-password` | GET sai method; POST với mật khẩu hiện tại sai | PASS, 405 và 422; không đổi mật khẩu |
| `api-logout` | POST có Bearer token; POST không token | PASS, 200 và 401. Logout dùng JWT stateless, không vô hiệu hóa token phía server |
| `api-posts` | GET có token; GET không token | PASS, 200 và 401 |
| `api-product-detail` | GET slug hợp lệ, thiếu slug, slug không tồn tại | PASS, 200 / 400 / 404 |
| `api-products-top` | GET `sort_by=sales`; giá trị sort không hợp lệ | PASS, 200 / 400 |
| `api-product-comments` | GET slug hợp lệ; phân trang sai; sản phẩm không tồn tại | PASS, 200 / 400 / 404 |
| `api-comment-create` | POST nội dung rỗng; GET sai method; POST không token; POST hợp lệ | PASS, 422 / 405 / 401 / 201 |
| `api-comment-update` | POST sai method; PUT không token; PUT ID không tồn tại; PUT bình luận pending của chủ sở hữu | PASS, 405 / 401 / 404 / 200 |
| `api-comment-delete` | GET sai method; DELETE không token; DELETE ID không tồn tại; DELETE bình luận pending của chủ sở hữu | PASS, 405 / 401 / 404 / 200 |
| `api-products` | GET danh sách, giới hạn một sản phẩm | PASS, 200 |
| `api-categories` | GET | PASS, 200 |
| `api-banners` | GET | PASS, 200 |
| `api-guns` | GET dữ liệu demo | PASS, 200 |
| `api-camchim` | GET dữ liệu demo | PASS, 200 |

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

## Ghi Chú

- Lần thử create đầu tiên từ PowerShell trả 400 vì body JSON có ký tự tiếng Việt không được gửi với UTF-8 tường minh. Gửi lại cùng luồng với body UTF-8 và `charset=utf-8` trả 201; không có fixture nào được tạo ở lần thử lỗi.
- Không thử happy path đăng ký, xác minh email, gửi email quên mật khẩu, reset mật khẩu hoặc đổi mật khẩu vì các thao tác này cần email/token thật hoặc làm thay đổi tài khoản. Đã kiểm tra nhánh lỗi an toàn tương ứng.
- Không kiểm thử phân quyền sửa/xóa bằng một tài khoản thứ hai; truy vấn model giới hạn theo cả `comment_id` và `user_id`.
- Không chạy migration hoặc seed trong lượt kiểm thử API này.