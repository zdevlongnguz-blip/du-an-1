# 06. Kiểm thử API bình luận

Kiểm thử **7 API bình luận thật** sau khi tích hợp AI kiểm duyệt. Phần thử riêng 2 model qua API test `api-comment-guard` nằm ở [../comment-guard/README.md](../comment-guard/README.md) mục 6.

## 1. Tổng kết

| Mục | Giá trị |
| --- | --- |
| Ngày chạy | 22/11/2026 |
| Môi trường | Laragon local, `http://du-an-1.test/BE/index.php`, PHP 8.1.10, Colab đang chạy, `COMMENT_GUARD_ENABLED` bật |
| Cách chạy | Script PHP gọi HTTP thật bằng cURL (giữ đúng dấu tiếng Việt), sau mỗi request tạo/sửa thì đọc lại DB |
| Tài khoản | `user@example.com` (user) và `admin@example.com` (admin), mật khẩu mẫu trong `BE/database/seed/users_profiles.sql` |
| Sản phẩm | id 1, slug `sap-vuot-toc-hanz-de-fuko-claymation` |
| Kết quả | **61/61 PASS** (so mã HTTP mong đợi với thực tế) |
| Dọn dữ liệu | Toàn bộ bình luận test đã xóa bằng chính API (`api-comment-delete`, `api-admin-comment-delete`). Kiểm tra cuối: còn 0 dòng |

| Nhóm | Số ca | PASS |
| --- | --- | --- |
| Xem công khai | 6 | 6 |
| Tạo bình luận | 18 | 18 |
| Sửa bình luận | 11 | 11 |
| Quản trị | 15 | 15 |
| User xóa và dọn dữ liệu | 11 | 11 |

Câu test được ghi **nguyên văn, kể cả lời thô tục**, để nhóm đối chiếu khi chỉnh ngưỡng hoặc đổi model. Cột "DB sau request" có dạng `status / moderation_label / sentiment / toxic_prob`. Email người dùng khác trong response được che.

## 2. Kiểm tra thêm (không tính vào 61 ca)

| Kiểm tra | Kết quả |
| --- | --- |
| Sau khi tạo/sửa, bình luận nào hiện ở `api-product-comments` | Hiện: #29 "Size M có còn không shop?", #31 (đã sửa thành "Áo đẹp, vải mát, mặc vừa in"), #35 (2000 ký tự "á"). Ẩn: #28 (sửa thành chê lịch sự, chờ duyệt), #30, #32, #33, #34. Đúng với `status` trong DB |
| Key của mỗi dòng ở API công khai | `id, product_id, user_id, email, role, content, created_at, updated_at`. Không lộ `status` và nhãn AI |
| Admin duyệt câu bị AI chặn (#32 "Thằng chủ shop óc chó") rồi xem API công khai | Câu đó **hiện** ra, nhãn `HATE` vẫn giữ trong DB. Đúng thiết kế: admin có quyền quyết định cuối |
| Colab lỗi (giả lập `COMMENT_GUARD_URL=http://127.0.0.1:9`, chạy từ dòng lệnh gọi thẳng `moderate()` và model) | Sau ~2 giây trả `pending`, DB `0 / NULL / NULL / NULL`, log có dòng `[CommentGuard] Kiểm duyệt lỗi...Connection refused` |
| `COMMENT_GUARD_ENABLED` = `0`, `false`, `off` (chạy từ dòng lệnh) | Trả `pending` ngay (0.00s), không gọi Colab |
| `COMMENT_GUARD_ENABLED` = `1`, `on`, để trống, không khai báo, `abc` | Bật, "Như cặc" bị chấm `blocked` |
| Chạy lại migration lần 2 | Không lỗi, không thêm cột trùng |

Hai mục Colab lỗi và bật/tắt **không** chạy qua HTTP, vì muốn vậy phải sửa `BE/.env` thật của máy. Đoạn code được gọi là đúng đoạn mà API dùng.

## 3. Chi tiết từng ca

### 1. Xem công khai (`api-product-comments`)

| # | Ca test | Request | Mong đợi | Thực tế | Kết quả | Thời gian | DB sau request | Response (nguyên văn) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Danh sách hợp lệ | `GET ?action=api-product-comments&slug=sap-vuot-toc-hanz-de-fuko-claymation&page=1&per_page=2` | 200 | 200 | PASS | 0.05s |  | `{"status":"success","comments":{"data":[{"id":16,"product_id":1,"user_id":8,"email":"<đã che>","role":"user","content":"tốt","created_at":"2026-11-17 14:15:04","updated_at":"2026-11-17 14:15:27"},{"id":6,"product_id":1,"user_id":2,"email":"<đã che>","role":"user","conte…(cắt bớt, chuỗi 2000 ký tự "á")` |
| 2 | Thiếu slug | `GET ?action=api-product-comments` | 400 | 400 | PASS | 0.09s |  | `{"status":"error","message":"Slug sản phẩm không hợp lệ"}` |
| 3 | per_page=0 | `GET ?action=api-product-comments&slug=sap-vuot-toc-hanz-de-fuko-claymation&per_page=0` | 400 | 400 | PASS | 0.04s |  | `{"status":"error","message":"Thông tin phân trang không hợp lệ"}` |
| 4 | per_page=101 | `GET ?action=api-product-comments&slug=sap-vuot-toc-hanz-de-fuko-claymation&per_page=101` | 400 | 400 | PASS | 0.05s |  | `{"status":"error","message":"Thông tin phân trang không hợp lệ"}` |
| 5 | Slug không tồn tại | `GET ?action=api-product-comments&slug=khong-ton-tai-xyz` | 404 | 404 | PASS | 0.04s |  | `{"status":"error","message":"Không tìm thấy sản phẩm"}` |
| 6 | Sai method POST | `POST ?action=api-product-comments&slug=sap-vuot-toc-hanz-de-fuko-claymation` | 405 | 405 | PASS | 0.06s |  | `{"status":"error","message":"Phương thức không được hỗ trợ"}` |

### 2. Tạo bình luận (`api-comment-create`)

| # | Ca test | Request | Mong đợi | Thực tế | Kết quả | Thời gian | DB sau request | Response (nguyên văn) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 7 | Tạo: Sản phẩm rất tốt, giao hàng nhanh, sẽ ủng hộ shop dài dài | `POST ?action=api-comment-create` | 201 | 201 | PASS | 1.83s | `1 / CLEAN / POS / 0.0035` | `{"status":"success","message":"Thêm bình luận thành công!","comment":{"id":28,"product_id":1,"content":"Sản phẩm rất tốt, giao hàng nhanh, sẽ ủng hộ shop dài dài","status":1,"moderation":"approved"}}` |
| 8 | Tạo: Size M có còn không shop? | `POST ?action=api-comment-create` | 201 | 201 | PASS | 1.01s | `1 / CLEAN / NEU / 0.0066` | `{"status":"success","message":"Thêm bình luận thành công!","comment":{"id":29,"product_id":1,"content":"Size M có còn không shop?","status":1,"moderation":"approved"}}` |
| 9 | Tạo: Hàng tệ quá, thất vọng | `POST ?action=api-comment-create` | 201 | 201 | PASS | 0.58s | `0 / CLEAN / NEG / 0.6285` | `{"status":"success","message":"Thêm bình luận thành công, bình luận đang chờ quản trị viên duyệt.","comment":{"id":30,"product_id":1,"content":"Hàng tệ quá, thất vọng","status":0,"moderation":"pending"}}` |
| 10 | Tạo: Như cặc | `POST ?action=api-comment-create` | 201 | 201 | PASS | 1.04s | `0 / OFFENSIVE / NULL / 0.9973` | `{"status":"success","message":"Bình luận chứa ngôn từ không phù hợp nên đã bị ẩn. Vui lòng sửa lại nội dung lịch sự hơn.","comment":{"id":31,"product_id":1,"content":"Như cặc","status":0,"moderation":"blocked"}}` |
| 11 | Tạo: Thằng chủ shop óc chó | `POST ?action=api-comment-create` | 201 | 201 | PASS | 0.56s | `0 / HATE / NULL / 0.9962` | `{"status":"success","message":"Bình luận chứa ngôn từ không phù hợp nên đã bị ẩn. Vui lòng sửa lại nội dung lịch sự hơn.","comment":{"id":32,"product_id":1,"content":"Thằng chủ shop óc chó","status":0,"moderation":"blocked"}}` |
| 12 | Tạo: vcl hàng gì mà xấu thế | `POST ?action=api-comment-create` | 201 | 201 | PASS | 0.62s | `0 / OFFENSIVE / NULL / 0.9923` | `{"status":"success","message":"Bình luận chứa ngôn từ không phù hợp nên đã bị ẩn. Vui lòng sửa lại nội dung lịch sự hơn.","comment":{"id":33,"product_id":1,"content":"vcl hàng gì mà xấu thế","status":0,"moderation":"blocked"}}` |
| 13 | Tạo: fuck this shop | `POST ?action=api-comment-create` | 201 | 201 | PASS | 0.64s | `0 / CLEAN / NEG / 0.6503` | `{"status":"success","message":"Thêm bình luận thành công, bình luận đang chờ quản trị viên duyệt.","comment":{"id":34,"product_id":1,"content":"fuck this shop","status":0,"moderation":"pending"}}` |
| 14 | Không token | `POST ?action=api-comment-create` | 401 | 401 | PASS | 0.05s |  | `{"status":"error","message":"Unauthorized: thiếu Bearer token"}` |
| 15 | Token sai | `POST ?action=api-comment-create` | 401 | 401 | PASS | 0.05s |  | `{"status":"error","message":"Unauthorized: Chữ ký token không hợp lệ"}` |
| 16 | Sai method GET | `GET ?action=api-comment-create` | 405 | 405 | PASS | 0.06s |  | `{"status":"error","message":"Phương thức không được hỗ trợ"}` |
| 17 | Body không phải JSON | `POST ?action=api-comment-create` | 400 | 400 | PASS | 0.04s |  | `{"status":"error","message":"Dữ liệu bình luận không hợp lệ"}` |
| 18 | content là số | `POST ?action=api-comment-create` | 400 | 400 | PASS | 0.07s |  | `{"status":"error","message":"Nội dung bình luận không hợp lệ"}` |
| 19 | content chỉ có khoảng trắng | `POST ?action=api-comment-create` | 422 | 422 | PASS | 0.07s |  | `{"status":"error","message":"Bình luận phải có nội dung và không quá 2.000 ký tự"}` |
| 20 | content 2001 ký tự | `POST ?action=api-comment-create` | 422 | 422 | PASS | 0.08s |  | `{"status":"error","message":"Bình luận phải có nội dung và không quá 2.000 ký tự"}` |
| 21 | content đúng 2000 ký tự | `POST ?action=api-comment-create` | 201 | 201 | PASS | 2.33s | `1 / CLEAN / POS / 0.0669` | `{"status":"success","message":"Thêm bình luận thành công!","comment":{"id":35,"product_id":1,"content":"áááááááááááááááá...` |
| 22 | Thiếu product_id | `POST ?action=api-comment-create` | 422 | 422 | PASS | 0.05s |  | `{"status":"error","message":"ID sản phẩm không hợp lệ"}` |
| 23 | product_id = 0 | `POST ?action=api-comment-create` | 422 | 422 | PASS | 0.06s |  | `{"status":"error","message":"ID sản phẩm không hợp lệ"}` |
| 24 | product_id không tồn tại | `POST ?action=api-comment-create` | 404 | 404 | PASS | 0.67s |  | `{"status":"error","message":"Không tìm thấy sản phẩm"}` |

### 3. Sửa bình luận (`api-comment-update`)

| # | Ca test | Request | Mong đợi | Thực tế | Kết quả | Thời gian | DB sau request | Response (nguyên văn) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 25 | Giữ nguyên nội dung bị chặn | `PUT ?action=api-comment-update&id=31` | 200 | 200 | PASS | 0.08s | `0 / OFFENSIVE / NULL / 0.9973` | `{"status":"success","message":"Nội dung bình luận không thay đổi","comment":{"id":31,"content":"Như cặc","status":0,"moderation":"blocked"}}` |
| 26 | Thô tục -> lịch sự | `PUT ?action=api-comment-update&id=31` | 200 | 200 | PASS | 0.61s | `1 / CLEAN / POS / 0.0110` | `{"status":"success","message":"Cập nhật bình luận thành công!","comment":{"id":31,"content":"Áo đẹp, vải mát, mặc vừa in","status":1,"moderation":"approved"}}` |
| 27 | Giữ nguyên nội dung đã duyệt | `PUT ?action=api-comment-update&id=31` | 200 | 200 | PASS | 0.11s |  | `{"status":"success","message":"Nội dung bình luận không thay đổi","comment":{"id":31,"content":"Áo đẹp, vải mát, mặc vừa in","status":1,"moderation":"approved"}}` |
| 28 | Sạch -> thô tục | `PUT ?action=api-comment-update&id=28` | 200 | 200 | PASS | 1.28s | `0 / HATE / NULL / 0.9904` | `{"status":"success","message":"Bình luận chứa ngôn từ không phù hợp nên đã bị ẩn. Vui lòng sửa lại nội dung lịch sự hơn.","comment":{"id":28,"content":"đm shop làm ăn như l","status":0,"moderation":"blocked"}}` |
| 29 | Thô tục -> chê lịch sự | `PUT ?action=api-comment-update&id=28` | 200 | 200 | PASS | 0.55s | `0 / CLEAN / NEG / 0.4330` | `{"status":"success","message":"Cập nhật bình luận thành công, bình luận đang chờ quản trị viên duyệt.","comment":{"id":28,"content":"Giao hàng chậm, đóng gói cẩu thả","status":0,"moderation":"pending"}}` |
| 30 | Không token | `PUT ?action=api-comment-update&id=28` | 401 | 401 | PASS | 0.03s |  | `{"status":"error","message":"Unauthorized: thiếu Bearer token"}` |
| 31 | Sai method POST | `POST ?action=api-comment-update&id=28` | 405 | 405 | PASS | 0.09s |  | `{"status":"error","message":"Phương thức không được hỗ trợ"}` |
| 32 | Thiếu id | `PUT ?action=api-comment-update` | 400 | 400 | PASS | 0.06s |  | `{"status":"error","message":"ID bình luận không hợp lệ"}` |
| 33 | id không tồn tại | `PUT ?action=api-comment-update&id=99999999` | 404 | 404 | PASS | 0.11s |  | `{"status":"error","message":"Không tìm thấy bình luận"}` |
| 34 | Sửa bình luận người khác (admin) | `PUT ?action=api-comment-update&id=28` | 404 | 404 | PASS | 0.08s | `0 / CLEAN / NEG / 0.4330` | `{"status":"error","message":"Không tìm thấy bình luận"}` |
| 35 | content rỗng | `PUT ?action=api-comment-update&id=28` | 422 | 422 | PASS | 0.08s |  | `{"status":"error","message":"Bình luận phải có nội dung và không quá 2.000 ký tự"}` |

### 4. Quản trị (`api-admin-comments`, `api-admin-comment-approve`, `api-admin-comment-delete`)

| # | Ca test | Request | Mong đợi | Thực tế | Kết quả | Thời gian | DB sau request | Response (nguyên văn) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 36 | Danh sách chờ duyệt | `GET ?action=api-admin-comments&status=0&page=1&per_page=3` | 200 | 200 | PASS | 0.09s |  | `{"status":"success","comments":{"data":[{"id":34,"product_id":1,"product_name":"Sáp Vuốt Tóc Hanz de Fuko Claymation","user_id":2,"email":"<đã che>","content":"fuck this shop","status":0,"moderation_label":"CLEAN","sentiment":"NEG","toxic_prob":"0.6503","created_at":"2026-11-22 11:10:26","up…(cắt bớt, chuỗi 2000 ký tự "á")` |
| 37 | Không lọc status | `GET ?action=api-admin-comments&page=1&per_page=1` | 200 | 200 | PASS | 0.11s |  | `{"status":"success","comments":{"data":[{"id":35,"product_id":1,"product_name":"Sáp Vuốt Tóc Hanz de Fuko Claymation","user_id":2,"email":"<đã che>","content":"áááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááááá…(cắt bớt, chuỗi 2000 ký tự "á")` |
| 38 | status=5 | `GET ?action=api-admin-comments&status=5` | 400 | 400 | PASS | 0.07s |  | `{"status":"error","message":"status phải là 0 hoặc 1"}` |
| 39 | per_page=0 | `GET ?action=api-admin-comments&per_page=0` | 400 | 400 | PASS | 0.06s |  | `{"status":"error","message":"Thông tin phân trang không hợp lệ"}` |
| 40 | User thường gọi | `GET ?action=api-admin-comments` | 403 | 403 | PASS | 0.05s |  | `{"status":"error","message":"Bạn không có quyền thực hiện thao tác này"}` |
| 41 | Không token | `GET ?action=api-admin-comments` | 401 | 401 | PASS | 0.03s |  | `{"status":"error","message":"Unauthorized: thiếu Bearer token"}` |
| 42 | Duyệt tay câu bị AI chặn | `PUT ?action=api-admin-comment-approve&id=32` | 200 | 200 | PASS | 0.09s | `1 / HATE / NULL / 0.9962` | `{"status":"success","message":"Đã duyệt bình luận"}` |
| 43 | Duyệt lại câu đã duyệt | `PUT ?action=api-admin-comment-approve&id=32` | 200 | 200 | PASS | 0.07s |  | `{"status":"success","message":"Đã duyệt bình luận"}` |
| 44 | Duyệt id không tồn tại | `PUT ?action=api-admin-comment-approve&id=99999999` | 404 | 404 | PASS | 0.07s |  | `{"status":"error","message":"Không tìm thấy bình luận"}` |
| 45 | Duyệt sai method GET | `GET ?action=api-admin-comment-approve&id=32` | 405 | 405 | PASS | 0.06s |  | `{"status":"error","message":"Phương thức không được hỗ trợ"}` |
| 46 | User thường duyệt | `PUT ?action=api-admin-comment-approve&id=32` | 403 | 403 | PASS | 0.03s |  | `{"status":"error","message":"Bạn không có quyền thực hiện thao tác này"}` |
| 47 | Xóa bình luận | `DELETE ?action=api-admin-comment-delete&id=33` | 200 | 200 | PASS | 0.06s | `không còn` | `{"status":"success","message":"Đã xóa bình luận"}` |
| 48 | Xóa id không tồn tại | `DELETE ?action=api-admin-comment-delete&id=99999999` | 404 | 404 | PASS | 0.07s |  | `{"status":"error","message":"Không tìm thấy bình luận"}` |
| 49 | Xóa thiếu id | `DELETE ?action=api-admin-comment-delete` | 400 | 400 | PASS | 0.08s |  | `{"status":"error","message":"ID bình luận không hợp lệ"}` |
| 50 | User thường xóa | `DELETE ?action=api-admin-comment-delete&id=32` | 403 | 403 | PASS | 0.08s |  | `{"status":"error","message":"Bạn không có quyền thực hiện thao tác này"}` |

### 5. User xóa bình luận (`api-comment-delete`) và dọn dữ liệu

| # | Ca test | Request | Mong đợi | Thực tế | Kết quả | Thời gian | DB sau request | Response (nguyên văn) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 51 | Admin xóa bằng API của user (không phải chủ) | `DELETE ?action=api-comment-delete&id=32` | 404 | 404 | PASS | 0.04s |  | `{"status":"error","message":"Không tìm thấy bình luận"}` |
| 52 | Không token | `DELETE ?action=api-comment-delete&id=32` | 401 | 401 | PASS | 0.05s |  | `{"status":"error","message":"Unauthorized: thiếu Bearer token"}` |
| 53 | Sai method GET | `GET ?action=api-comment-delete&id=32` | 405 | 405 | PASS | 0.10s |  | `{"status":"error","message":"Phương thức không được hỗ trợ"}` |
| 54 | id không tồn tại | `DELETE ?action=api-comment-delete&id=99999999` | 404 | 404 | PASS | 0.06s |  | `{"status":"error","message":"Không tìm thấy bình luận"}` |
| 55 | Dọn dữ liệu: xóa #28 (clean) | `DELETE ?action=api-comment-delete&id=28` | 200 | 200 | PASS | 0.08s |  | `{"status":"success","message":"Xóa bình luận thành công"}` |
| 56 | Dọn dữ liệu: xóa #29 (neutral) | `DELETE ?action=api-comment-delete&id=29` | 200 | 200 | PASS | 0.09s |  | `{"status":"success","message":"Xóa bình luận thành công"}` |
| 57 | Dọn dữ liệu: xóa #30 (review) | `DELETE ?action=api-comment-delete&id=30` | 200 | 200 | PASS | 0.04s |  | `{"status":"success","message":"Xóa bình luận thành công"}` |
| 58 | Dọn dữ liệu: xóa #31 (offensive) | `DELETE ?action=api-comment-delete&id=31` | 200 | 200 | PASS | 0.06s |  | `{"status":"success","message":"Xóa bình luận thành công"}` |
| 59 | Dọn dữ liệu: xóa #32 (hate) | `DELETE ?action=api-comment-delete&id=32` | 200 | 200 | PASS | 0.06s |  | `{"status":"success","message":"Xóa bình luận thành công"}` |
| 60 | Dọn dữ liệu: xóa #34 (english) | `DELETE ?action=api-comment-delete&id=34` | 200 | 200 | PASS | 0.08s |  | `{"status":"success","message":"Xóa bình luận thành công"}` |
| 61 | Dọn dữ liệu: xóa #35 (max) | `DELETE ?action=api-comment-delete&id=35` | 200 | 200 | PASS | 0.06s |  | `{"status":"success","message":"Xóa bình luận thành công"}` |

## 4. Phát hiện trong lúc test

| # | Phát hiện | Mức độ | Đã sửa? |
| --- | --- | --- | --- |
| 1 | DB local còn bình luận cũ #6 "NHƯ CẶC" (14/11/2026) đang `status = 1`, hiện công khai. Bình luận này có từ trước khi có AI và đã được duyệt tay, nên không bị chấm lại | Dữ liệu | Không. Admin nên xóa, hoặc chấm bù bình luận cũ (xem [07-van-hanh.md](07-van-hanh.md)) |
| 2 | API công khai `api-product-comments` trả **email đầy đủ** của người bình luận. Có từ trước, không liên quan AI | Riêng tư | Không (ngoài phạm vi task). FE hiện chỉ hiện phần trước `@`, nhưng email vẫn lộ trong response |
| 3 | `product_id` không tồn tại vẫn gọi AI trước rồi mới trả 404 (ca 24 mất 0.67s), vì model kiểm tra sản phẩm ngay trong lúc INSERT | Tốn lượt gọi | **Đã sửa** (mục 6): kiểm tra sản phẩm trước khi gọi AI, ca 24 còn 0.06s |
| 4 | Gửi bình luận mất khoảng 0.5–2.3 giây (chờ Colab), trước đây dưới 0.1 giây | Trải nghiệm | Không phải lỗi. FE nên hiện trạng thái "Đang kiểm duyệt..." |

## 5. Tự test lại bằng Postman / Thunder Client

1. Đăng nhập lấy token:

   ```text
   POST http://du-an-1.test/BE/index.php?action=api-login
   Content-Type: application/json

   { "email": "user@example.com", "password": "<mật khẩu mẫu>" }
   ```

   Lấy `token` trong response. Làm tương tự với `admin@example.com` để có token admin.

2. Tạo bình luận với các câu mẫu, kiểm tra `comment.moderation`:

   | Câu | Kỳ vọng |
   | --- | --- |
   | Sản phẩm rất tốt, giao hàng nhanh | `approved` |
   | Size M có còn không shop? | `approved` |
   | Hàng tệ quá, thất vọng | `pending` |
   | Như cặc | HTTP 422, `moderation: "blocked"`, không lưu |
   | Thằng chủ shop óc chó | HTTP 422, `moderation: "blocked"`, không lưu |
   | fuck this shop | `pending` |

   Kết quả AI có thể lệch nhẹ nếu Colab đổi model hoặc ngưỡng.

3. Mở `api-product-comments` của sản phẩm đó: chỉ câu `approved` xuất hiện.
4. Mở `api-admin-comments?status=0` bằng token admin: thấy các câu còn lại kèm nhãn.
5. Xóa bình luận test bằng `api-comment-delete`.

Lưu ý: test bằng `curl` trong Git Bash trên Windows mà gõ tiếng Việt thẳng trong lệnh sẽ bị mất dấu (Colab nhận `d? ngu`). Hãy để body trong file UTF-8 rồi gửi bằng `--data-binary @body.json`, hoặc dùng Postman.

## 6. Sửa sau code review và test lại (22/11/2026, tối)

| # | Lỗi | Sửa | Test lại | Kết quả |
| --- | --- | --- | --- | --- |
| 1 | `product_id` không tồn tại vẫn gọi AI rồi mới 404 | Thêm `Comment::productExists()`, `create()` kiểm tra trước khi gọi `moderate()`. Vẫn giữ kiểm tra trong lúc INSERT phòng sản phẩm bị xóa khi đang chờ AI | Ca 24 qua HTTP + đọc log API | 404 sau **0.06s** (trước 0.67s), log không còn dòng `Kiểm duyệt AI` |
| 2 | Nhãn từ Colab dài hơn cột DB làm INSERT lỗi "Data too long", mất bình luận | `CommentGuard::normalizeResult()` từ chối `reason` > 20 ký tự, `sentiment` > 10 ký tự (hằng `MAX_REASON_LENGTH`, `MAX_SENTIMENT_LENGTH`, khớp cột). Controller bắt lỗi, lưu chờ duyệt | Class con giả `post()`, chạy dòng lệnh | 20/10 ký tự: nhận. 21 ký tự, `SEVERE_HARASSMENT_CONTENT`, `NEGATIVE_STRONG`: báo lỗi → `moderate()` trả `pending`, nhãn NULL |
| 3 | `moderate()` chỉ bắt `Exception`, PHP `Error` (ví dụ thiếu extension curl) làm request trả 500 | Đổi sang `catch (Throwable $e)` | Class `CommentGuard` giả ném `Error` và `TypeError`, chạy dòng lệnh | Cả hai trả `pending`. Đối chứng với code cũ: văng `Error: Call to undefined function curl_init()` |
| 4 | Chờ kết nối tối đa 10s khi tunnel treo | `CURLOPT_CONNECTTIMEOUT` = hằng `CONNECT_TIMEOUT_SECONDS` = 3 | `COMMENT_GUARD_URL=http://10.255.255.1` (IP không phản hồi), chạy dòng lệnh | `pending` sau **3.0s**, log `Connection timed out after 3005 milliseconds` |

Sau khi sửa, chạy lại đủ 61 ca HTTP ở mục 3: **61/61 PASS**, dữ liệu test đã dọn (còn 0 dòng). Các bảng ở mục 3 là lần chạy đầu; lần chạy lại cho cùng mã HTTP, cùng nhãn và cùng `moderation`, chỉ khác ID bình luận và thời gian.

## 7. Đổi sang "AI chặn thì không lưu" và test lại (22/11/2026, tối)

Quyết định mới: câu bị AI chặn trả **422 kèm `moderation: "blocked"`, không lưu**; sửa bình luận mà nội dung mới bị chặn thì bình luận cũ giữ nguyên. Các bảng ở mục 3 là trước khi đổi: các ca 10–12 (201, lưu ẩn) và 25, 28 (sửa thành câu bị chặn) không còn đúng.

Chạy lại bộ test đã chỉnh theo quyết định mới, qua HTTP thật: **58/58 PASS**, dữ liệu test đã dọn (còn 0 dòng). Số ca ít hơn 61 vì các ca sửa/duyệt từng dựa vào câu bị chặn đã lưu, nay đổi sang câu chờ duyệt.

| # | Ca | Mong đợi | Thực tế | Kiểm tra DB |
| --- | --- | --- | --- | --- |
| 11 | Tạo "Như cặc" | 422 | 422 | `MAX(id)` không đổi: **không lưu** |
| 12 | Tạo "Thằng chủ shop óc chó" | 422 | 422 | Không lưu |
| 13 | Tạo "vcl hàng gì mà xấu thế" | 422 | 422 | Không lưu |
| 14 | Tạo "fuck you shiet" | 422 | 422 | Không lưu |
| 25 | Tạo "Như cặc" với `product_id` không tồn tại | 404 | 404 | Kiểm tra sản phẩm trước, không gọi AI |
| 26 | Sửa, giữ nguyên nội dung đã duyệt | 200 | 200 | "Nội dung bình luận không thay đổi", `approved` |
| 27 | Sửa câu đã duyệt thành "đm shop làm ăn như l" | 422 | 422 | Bình luận cũ **giữ nguyên**: `1 / CLEAN / POS / 0.0035 / Sản phẩm rất tốt...` |
| 28 | Sửa thành "Giao hàng chậm, đóng gói cẩu thả" | 200 | 200 | `0 / CLEAN / NEG / 0.4330`, `pending` |
| 29 | Sửa câu chờ duyệt thành "Áo đẹp, vải mát, mặc vừa in" | 200 | 200 | `1 / CLEAN / POS / 0.0110`, `approved` |

Response 422 nguyên văn:

```json
{"status":"error","message":"Bình luận chứa ngôn từ không phù hợp. Vui lòng sửa lại nội dung lịch sự hơn.","moderation":"blocked"}
```

Các ca còn lại (đọc công khai, lỗi token/method/body/độ dài, admin xem/duyệt/xóa, user xóa) cho cùng mã HTTP như mục 3.

## 8. So với log test cũ

`BE/test-api.md` có 22 ca bình luận chạy từ **trước khi có AI**. Ở đó "User tạo bình luận (chờ duyệt)" luôn ra chờ duyệt. Sau 22/11/2026 điều này không còn đúng: câu sạch được hiện ngay. File này thay thế phần bình luận của log cũ cho hành vi hiện tại.
