---
applyTo: "BE/**"
---

# BE Instructions

Dự án môn học: PHP MVC thuần + MySQL (PDO). Ưu tiên đơn giản, đúng pattern hiện có, dễ giải thích khi bảo vệ.

## 1. Phạm vi và nguyên tắc
- Chỉ sửa `BE/` khi task thuộc BE. Không sửa `FE/` để che lỗi API, không đổi schema/contract ngoài yêu cầu.
- Trước khi sửa: chạy `git status`, đọc đúng route/controller/model/helper liên quan. Không ghi đè, revert hay format hàng loạt thay đổi chưa commit của người khác.
- Mỗi file chỉ một owner tại một thời điểm. Vùng ảnh hưởng rộng (cần phân công trước): `routes/index.php`, `configs/helper.php`, `configs/env.php`, `extensions/AuthMiddleware.php`, `models/BaseModel.php`, `models/User.php`.
- Sửa tối thiểu. Không đổi framework, không thêm dependency (Composer), không tạo tầng kiến trúc mới, không refactor diện rộng nếu task không yêu cầu.

## 2. Kiến trúc
- Luồng: `index.php` (nạp config/helper + autoload `models/`, `controllers/`, `extensions/`) → `routes/index.php` (switch theo `?action=`) → Controller → Model (PDO).
- Router chỉ điều phối (gọi middleware + controller). Không đặt truy vấn DB hay nghiệp vụ trong router.
- Controller xử lý request/response; Model truy cập DB. Model mới kế thừa `BaseModel` và khai báo `protected $table`.
- Tên action dạng `api-<tên>` (kebab-case). Tên class `PascalCase`, file trùng tên class (autoload dựa vào tên file).
- Không đổi tên/di chuyển class hoặc action đang được dùng.
- Mọi method mới hoặc được sửa trong Model/Controller phải khai báo giá trị mặc định cho tất cả tham số; chọn giá trị mặc định an toàn và validate trước khi dùng.

## 3. Cấu hình (`configs/env.php`)
- `BE/configs/env.php` là nơi cấu hình duy nhất của BE (DB, JWT, SMTP, URL, TTL...) bằng hằng `define(...)`. Dự án môn học nên cho phép giữ giá trị trực tiếp trong file này.
- Cần cấu hình mới: thêm `define(...)` vào `env.php`, đặt tên IN HOA_SNAKE_CASE, kèm comment tiếng Việt. Không tạo cơ chế cấu hình khác (không thêm `.env` loader, không thêm thư viện).
- Không đổi giá trị hiện có (DB, JWT_SECRET, SMTP...) trừ khi task yêu cầu rõ.
- Không in giá trị cấu hình ra response, log hay comment. Không copy secret sang file khác.

## 4. Hợp đồng API (phối hợp bắt buộc với FE)
- Endpoint là contract công khai. Trước khi thêm/đổi, thống nhất với FE: action, method, query/body, yêu cầu auth, response thành công, lỗi, mã HTTP. Ghi contract vào task/trao đổi nhóm trước khi code.
- Không âm thầm đổi tên action, key response, kiểu dữ liệu, quy tắc phân trang, mã HTTP hay yêu cầu auth mà FE đang dùng.
- Dùng `response_json($data, $status)` cho mọi response JSON. Không `echo json_encode` trực tiếp (trừ route 404 cũ, giữ nguyên).
- Format chuẩn cho endpoint MỚI:
  - Thành công: `{ "status": "success", "message"?: "...", "<tài_nguyên>": ... }` (ví dụ `products`, `banners`). Danh sách có phân trang theo mẫu `ProductController` (`data` + `pagination`).
  - Lỗi: `{ "status": "error", "message": "..." }` kèm mã HTTP đúng (400 sai dữ liệu, 401 chưa đăng nhập, 403 không đủ quyền, 404 không có, 405 sai method, 409 trùng, 422 không hợp lệ).
- Endpoint cũ giữ nguyên shape hiện tại, không "chuẩn hóa" lại.
- Route cần đăng nhập: gọi `(new AuthMiddleware())->requireAuth()` trong router, truyền payload xuống controller. Lấy user ID/role từ `sub`/`role` trong payload, không tin ID hoặc role do client gửi.
- Mọi API đọc/ghi dữ liệu riêng của user (ví dụ giỏ hàng, đơn hàng, hóa đơn) bắt buộc gọi `requireAuth()` ở router; API quản trị phải xác minh thêm `role` phù hợp. Chỉ để public khi endpoint được thiết kế công khai.
- Kiểm tra method (`$_SERVER['REQUEST_METHOD']`) trong controller với endpoint POST/PUT/DELETE.
- Với endpoint có lọc hoặc phân trang, đọc mặc định, validate query và truyền tham số ngay trong action controller theo mẫu `ProductController::index`; không tách sang helper phân trang chung hoặc dùng cờ khó hiểu. Model nhận tham số có tên rõ ràng và đặt câu truy vấn lọc/phân trang tại method tương ứng.

## 5. Validate và bảo mật dữ liệu
- Validate tại ranh giới request: kiểu, định dạng, trường bắt buộc, miền giá trị, độ dài. Chuẩn validate: viết thủ công theo mẫu `AuthController` (`is_array`, `is_string`, `filter_var`, `strlen`). `extensions/Validator.php` hiện chưa được dùng, chỉ dùng khi task yêu cầu.
- Luôn dùng prepared statement + bind (`bindValue` với `PDO::PARAM_INT` cho số). Identifier động (tên cột, ORDER BY) phải qua allowlist.
- Phân quyền ở server cho từng thao tác. Ẩn nút/route guard ở FE không phải kiểm soát bảo mật.
- Mật khẩu: `password_hash` / `password_verify`. Token xác minh/reset: chỉ lưu hash, có hạn dùng (theo mẫu `AuthController`).
- Endpoint dễ lộ thông tin (đăng nhập, quên mật khẩu): phản hồi chung, không cho biết email có tồn tại.
- Không log hay trả về mật khẩu, token, thông tin kết nối. Không để stack trace ra response cho người dùng cuối.
- Upload file: dùng `validate_upload` / `save_uploaded_file` trong `helper.php`.
- Không đổi CORS, session, JWT, mail, upload hay xử lý lỗi toàn cục như tác dụng phụ của feature. Chỉ sửa khi task yêu cầu trực tiếp.

## 6. Database
- Đổi schema: tạo migration mới trong `BE/database/migrations/`, tên `YYYYMMDD_<mô_tả>.sql`, viết để chạy lặp được (`IF NOT EXISTS`...). Không sửa migration đã áp dụng.
- Mỗi bảng mới bắt buộc có cả `created_at` và `updated_at`, với giá trị mặc định/cập nhật tự động phù hợp.
- `create_database.sql` chỉ dùng để khởi tạo database. Khi cập nhật schema đã tồn tại hoặc file này đã được chạy, không sửa file này; luôn thêm migration mới trong `BE/database/migrations/`. Dữ liệu mẫu để trong `database/seed/`.
- Không chạy lệnh xóa/phá dữ liệu và không chạy trên DB thật nếu chưa được yêu cầu và xác nhận.

## 7. Hiện trạng đã biết (KHÔNG bắt chước, KHÔNG tự sửa khi không có task riêng)
- CORS `*` đặt ở `index.php` và `response_json`.
- `env.php`: biến `$_SERVER['HTTP']` (đáng lẽ `HTTPS`) và secret nằm trực tiếp trong file.
- `handleGlobalError` hiển thị chi tiết lỗi khi `APP_ENV` khác `production`.
- `logout` chỉ xác minh token, chưa vô hiệu hóa token (JWT stateless).
- Chưa có rate limit cho login/register/forgot-password.
- Route demo `api-guns`, `api-camchim`: không dùng làm mẫu.
- `Validator.php` chưa được dùng.

Phát hiện thêm vấn đề tương tự: báo lại, không tự sửa ngầm.

## 8. Phong cách code và comment cho người mới
Nhóm có nhiều thành viên mới học, code do AI sinh ra phải đọc hiểu được mà không cần hỏi lại. Vì vậy bắt buộc comment tiếng Việt theo các quy tắc sau.

### Bắt buộc comment
- **Đầu mỗi file mới** (controller, model, extension): 1-3 dòng nói file này làm gì, thuộc tầng nào.
- **Mỗi class, mỗi hàm/method mới**: docblock `/** ... */` tiếng Việt gồm:
  - Hàm làm gì, nói ngắn bằng ngôn ngữ thường.
  - `@param` và `@return` khi có tham số/giá trị trả về.
  - Điều kiện lỗi hoặc mã HTTP có thể trả về (nếu là API).
- **Mỗi route mới trong `routes/index.php`**: 1 dòng comment nói endpoint dùng để làm gì, có yêu cầu đăng nhập hay không.
- **Chỗ khó hiểu với người mới**, ghi vài dòng giải thích VÌ SAO làm vậy (không chỉ làm gì):
  - Prepared statement và `bindValue` (vì sao không nối chuỗi vào SQL).
  - JWT, Bearer token, middleware, payload `sub`/`role`.
  - Hash mật khẩu/token, token có hạn dùng.
  - Phân trang (`LIMIT`/`OFFSET`), `LEFT JOIN`, query có điều kiện động.
  - Regex, `filter_var`, `random_bytes`, `hash_equals` và các hàm ít gặp.
  - Mọi đoạn có thứ tự quan trọng hoặc dễ làm sai khi sửa (ví dụ xóa user pending khi gửi mail lỗi).
- **Giải thích thuật ngữ** lần đầu xuất hiện trong file bằng 1 câu dễ hiểu. Ví dụ: `// JWT: chuỗi mã hóa chứa thông tin đăng nhập, client gửi kèm mỗi request`.

### Cách viết comment
- Viết tiếng Việt có dấu, câu ngắn, dùng từ đơn giản, không dùng tiếng lóng. Giữ nguyên tên kỹ thuật bằng tiếng Anh (`PDO`, `JWT`, `middleware`) kèm giải thích ngắn.
- Giải thích **ý định và lý do** hơn là dịch lại từng dòng code.
- Không comment dòng hiển nhiên (`$i++`, `return $result;`). Comment thừa làm code khó đọc hơn.
- Khi sửa code, cập nhật comment tương ứng. Comment sai còn tệ hơn không có comment.
- Không xóa, không dịch sang tiếng Anh, không viết lại comment tiếng Việt đang có nếu task không yêu cầu.
- Không đưa secret, mật khẩu hay giá trị cấu hình thật vào comment hoặc ví dụ.
- Không dùng ngôn từ xúc phạm, chế giễu cá nhân hay meme trong code, message, tên route, dữ liệu mẫu.

### Ví dụ chuẩn
```php
/**
 * Lấy danh sách sản phẩm, có lọc theo từ khóa/danh mục và phân trang.
 *
 * @param string   $search     Từ khóa tìm theo tên, mã, mô tả (rỗng = không lọc)
 * @param int|null $categoryId ID danh mục (null = tất cả)
 * @param int      $page       Trang hiện tại, bắt đầu từ 1
 * @param int      $perPage    Số sản phẩm mỗi trang
 * @return array ['data' => danh sách, 'pagination' => thông tin phân trang]
 */
public function getFiltered($search = '', $categoryId = null, $page = 1, $perPage = 8)
{
    // OFFSET = số bản ghi cần bỏ qua. Trang 1 bỏ 0, trang 2 bỏ $perPage, ...
    $offset = ($page - 1) * $perPage;

    // Dùng tham số :keyword thay vì nối chuỗi vào SQL để chống SQL injection
    // (kẻ xấu chèn câu lệnh SQL qua ô tìm kiếm).
    ...
}
```

## 9. Phối hợp để tránh xung đột
1. Trước khi làm: nêu file/phạm vi mình sở hữu, xác nhận không ai đang sửa cùng vùng.
2. Task FE-BE: thống nhất contract trước, chia owner theo file, không để hai người sửa cùng một file.
3. Yêu cầu mới xung đột code/contract hiện có: dừng đúng phần xung đột, mô tả bằng chứng, thống nhất quyết định.
4. Khi xong: báo file đã sửa, contract, migration (nếu có), việc FE cần làm, lệnh kiểm tra đã chạy. Không tuyên bố pass nếu chưa chạy.

## 10. Kiểm tra trước khi bàn giao
- `php -l <file>` cho từng file PHP đã sửa (từ thư mục gốc, ví dụ `php -l BE/routes/index.php`).
- Thử API: request hợp lệ, input sai, thiếu/sai token, sai method, dữ liệu rỗng. Kiểm tra mã HTTP và JSON đúng contract.
- Có migration: kiểm tra thứ tự và khả năng chạy lặp.
- Xem diff: không có file ngoài phạm vi, không lộ secret mới, không đổi contract/schema ngoài ý muốn.
- Mọi hàm, class, route mới đều có comment tiếng Việt theo mục 8; chỗ khó hiểu có giải thích lý do.
- Dự án chưa có test tự động: không bịa lệnh test.
