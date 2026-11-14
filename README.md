# Du An 1

Dự án web salon tóc nam theo mô hình monorepo. Backend PHP MVC và frontend Vue 3 được tách riêng để dễ phát triển, test và tích hợp API.

## Tổng quan

- Backend: [BE](BE)
- Frontend: [FE](FE)
- Tài liệu auth riêng: [AUTH_README.md](AUTH_README.md)

## Mục tiêu dự án

- Xây dựng landing page salon tóc nam
- Hiển thị bài viết / tin tức
- Quản lý sản phẩm và dịch vụ
- Tách rõ frontend/backend để dễ mở rộng
- Dễ test API bằng Thunder Client hoặc trình duyệt

## Tính năng chính

- **Trang chủ:** banner trình chiếu, Top 5 sản phẩm theo lượt xem hoặc lượt bán.
- **Danh mục sản phẩm:** tìm kiếm, lọc theo danh mục, phân trang (thanh phân trang dùng lại được ở `FE/src/components/PaginationBar.vue`).
- **Chi tiết sản phẩm:** ảnh slide, chọn biến thể (dung tích/giá/tồn kho), bình luận có kiểm duyệt.
- **Tài khoản:** đăng ký, xác thực email, đăng nhập (JWT), quên/đặt lại/đổi mật khẩu, hồ sơ cá nhân.
- **Giỏ hàng và đặt hàng:** thêm/sửa/xóa giỏ hàng, thanh toán qua SePay, xem đơn, hóa đơn, hủy đơn.
- **Quản trị (API):** quản lý đơn hàng, sản phẩm, ảnh sản phẩm, danh mục, banner và duyệt bình luận.
- **Giao diện tối:** tông đen và vàng đồng, phông DM Sans / DM Serif Display, tham khảo thiết kế "Fule Baber shop" trên Stitch.

## Cấu trúc thư mục

```bash
du-an-1/
├── BE/
│   ├── assets/            # Ảnh upload (assets/uploads) và JS dùng chung
│   ├── configs/           # Cấu hình, helper, mail
│   ├── controllers/
│   ├── database/          # create_database.sql, migrations/, seed/, run.php
│   ├── extensions/
│   ├── models/
│   ├── public/
│   │   └── dist/          # Bản build của FE (tự sinh, không commit)
│   ├── routes/            # Điều hướng theo tham số ?action=
│   ├── views/
│   ├── .env.example
│   ├── index.php
│   └── ...
│
├── FE/
│   ├── public/
│   │   └── .htaccess      # Fallback route khi web root trỏ thẳng vào dist
│   ├── src/
│   │   ├── components/    # BannerSlideshow, PaginationBar, ProductCard*, AuthPage
│   │   ├── helpers/       # api (axios), auth, cart, format, media, payment, ui
│   │   ├── router/        # Vue Router
│   │   ├── views/         # Các trang: home, products, cart, auth, checkout, payment
│   │   └── style.css      # Giao diện dùng chung
│   ├── .env.development
│   ├── .env.production
│   ├── .env.example
│   ├── package.json
│   ├── vite.config.js
│   └── ...
│
├── AUTH_README.md
├── README.md
├── .htaccess              # URL gọn: phục vụ FE ở /, BE ở /BE
├── .gitignore
└── ...
```

## Công nghệ sử dụng

### Backend
- PHP 8+
- MVC tùy chỉnh
- Route API
- JSON response
- JWT cho auth (chi tiết ở [AUTH_README.md](AUTH_README.md))

### Frontend
- Vue 3
- Vite 5
- Vue Router 4
- Axios
- Bootstrap 5

### Thanh toán
- SePay (cổng thanh toán, có môi trường sandbox)

## Yêu cầu môi trường

- PHP 8+
- MySQL (Laragon / XAMPP)
- Laragon / XAMPP / Apache local (bật `mod_rewrite`)
- Node.js 18+
- npm
- Composer (nếu cần cài lại thư mục `vendor`)

## Cách chạy dự án

### 0. Cài đặt lần đầu

```powershell
git clone <repo-url> du-an-1   # đặt trong C:\laragon\www để Laragon tự tạo host du-an-1.test
cd du-an-1
composer install               # cài PHPMailer và SePay vào thư mục vendor (gốc dự án)
Copy-Item BE\.env.example BE\.env
```

Mở `BE/.env` và điền các giá trị cần thiết:

| Nhóm | Biến | Ghi chú |
| --- | --- | --- |
| Database | `DB_USERNAME`, `DB_PASSWORD` | Laragon mặc định: `root` và mật khẩu trống |
| Bảo mật | `JWT_SECRET` | Chuỗi bí mật ngẫu nhiên, bắt buộc |
| URL công khai | `APP_URL`, `FRONTEND_URL` | Không bắt buộc. `APP_URL=https://ten-mien` dùng khi deploy hoặc chia sẻ ngrok để link trong email đúng domain; `FRONTEND_URL` chỉ cần khi FE ở origin khác BE |
| Email | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAIL_ENCRYPTION` | Dùng gửi email xác thực và đặt lại mật khẩu |
| SePay | `SEPAY_ENVIRONMENT`, `SEPAY_MERCHANT_ID`, `SEPAY_SECRET_KEY`, `SEPAY_IPN_SECRET`, `SEPAY_SUCCESS_URL`, `SEPAY_ERROR_URL`, `SEPAY_CANCEL_URL` | Dùng khóa sandbox khi phát triển; `SEPAY_IPN_SECRET` là secret riêng để xác thực IPN |

`BE/.env` không được commit. Không đưa mật khẩu hoặc khóa bí mật vào mã nguồn.

### 1. Cập nhật database

Đảm bảo MySQL đang chạy trong Laragon, sau đó mở PowerShell tại thư mục gốc dự án và chạy lệnh duy nhất:

```powershell
Get-Content -Raw .\BE\database\create_database.sql | mysql --host=localhost --port=3306 --user=root
```

File [BE/database/create_database.sql](BE/database/create_database.sql) dùng để khởi tạo database `du-an-1` cùng các bảng và sản phẩm mẫu ban đầu. Cấu hình không bí mật như host, port và tên database nằm trong `BE/configs/config.php`; thông tin xác thực nằm trong `BE/.env` (tạo theo mẫu `BE/.env.example`). Máy cần có lệnh `mysql` trong `PATH` (có thể mở terminal từ Laragon nếu PowerShell không nhận lệnh). Không cần import bằng phpMyAdmin.

Với database đã khởi tạo, chạy các file migration cần thiết trong `BE/database/migrations/` trước, sau đó chạy seeder tương ứng trong `BE/database/seed/`. Ví dụ cập nhật bộ đếm và ảnh slide sản phẩm:

```powershell
Get-Content -Raw .\BE\database\migrations\20261108_add_product_view_sales_counts.sql | mysql --host=localhost --port=3306 --user=root
Get-Content -Raw .\BE\database\migrations\20261108_create_product_images_table.sql | mysql --host=localhost --port=3306 --user=root
Get-Content -Raw .\BE\database\seed\products.sql | mysql --host=localhost --port=3306 --user=root
```

Không cần chạy lại `create_database.sql` để cập nhật database hiện có. Các migration và seeder được viết để có thể chạy lặp lại. Nếu MySQL có mật khẩu, thêm `--password` để MySQL hỏi mật khẩu khi chạy.

Bộ migration mới nhất (tách `products` thành `products` và `product_variants`, giỏ hàng và đơn hàng dùng biến thể) có thể chạy một lần bằng `php BE/database/run.php --seed --verify`. Hãy sao lưu database trước khi chạy; chi tiết xem [BE/database/README.md](BE/database/README.md).

### 2. Backend
Mở bằng Laragon (tự tạo host `du-an-1.test` từ tên thư mục dự án):

```text
http://du-an-1.test/BE/
```

### 3. Frontend
```bash
cd FE
npm install
npm run dev
```

Sau đó truy cập:

```text
http://localhost:5173
```

Cổng 5173 là cổng mặc định của Vite. Nếu cổng đang bị chiếm, Vite sẽ tự chuyển sang cổng khác; dùng `npm run dev -- --strictPort` để báo lỗi thay vì đổi cổng. Chi tiết biến môi trường xem mục [Cấu hình môi trường frontend](#cấu-hình-môi-trường-frontend).

Email xác minh và đặt lại mật khẩu tạo link từ `APP_URL` trong `BE/.env` (nếu trống thì suy ra từ request). Trang đặt lại mật khẩu mở frontend qua `FRONTEND_URL`: mặc định là `http://localhost:5173`, tự lấy theo `APP_URL` khi có, hoặc đặt riêng `FRONTEND_URL` trong `BE/.env`. Khi deploy hoặc chia sẻ qua ngrok, xem mục [Deploy](#deploy) và [Chia sẻ qua ngrok](#chia-sẻ-qua-ngrok).

#### Lệnh npm (chạy trong thư mục `FE`)

| Lệnh | Tác dụng |
| --- | --- |
| `npm run dev` | Chạy máy chủ phát triển Vite tại `http://localhost:5173`, dùng `.env.development` |
| `npm run build` | Build production vào `BE/public/dist`, dùng `.env.production` |
| `npm run preview` | Chạy thử bản build bằng máy chủ của Vite |

#### Các trang (route) của frontend

| Đường dẫn | Trang |
| --- | --- |
| `/` | Trang chủ (banner, Top 5 sản phẩm) |
| `/catalog` | Danh sách sản phẩm, tìm kiếm, lọc, phân trang |
| `/product-detail/:slug` | Chi tiết sản phẩm và bình luận |
| `/cart`, `/checkout` | Giỏ hàng, đặt hàng (cần đăng nhập; checkout chọn COD hoặc thanh toán online sandbox) |
| `/orders`, `/orders/:id` | Đơn hàng của tôi: danh sách, chi tiết/hóa đơn, thanh toán lại đơn online, hủy đơn (cần đăng nhập). `/payment` cũ tự chuyển về `/orders` |
| `/payment/success`, `/payment/error`, `/payment/cancel` | Trang kết quả khi SePay quay về (URL có `?order=<ID đơn>`); trang success hỏi lại BE để hiển thị trạng thái thanh toán thật |
| `/login`, `/register` | Đăng nhập, đăng ký |
| `/forgot-password`, `/reset-password` | Quên và đặt lại mật khẩu |
| `/profile`, `/change-password` | Hồ sơ, đổi mật khẩu (cần đăng nhập) |

## Cấu hình môi trường frontend

FE đọc cấu hình từ các file `.env.*` trong thư mục `FE`. Vite tự chọn file theo lệnh chạy: `npm run dev` dùng `.env.development`, `npm run build` dùng `.env.production`. Muốn ghi đè trên máy riêng, tạo `FE/.env.local` (không commit); xem mẫu ở `FE/.env.example`.

| Biến | Ý nghĩa | Dev | Build |
| --- | --- | --- | --- |
| `VITE_BASE_PATH` | Đường dẫn gốc nơi FE được phục vụ (cũng là base của Vue Router) | `/` | `/` |
| `VITE_API_BASE_URL` | URL gốc của BE để gọi API và lấy ảnh. Có thể là đường dẫn tương đối (cùng origin) hoặc URL đầy đủ (khác host, BE cần bật CORS) | `/api` | `/BE` |
| `VITE_API_PROXY_TARGET` | BE mà proxy `/api` của Vite chuyển tiếp tới (chỉ dùng khi `npm run dev`) | `http://du-an-1.test/BE` | không dùng |

Ở chế độ dev, FE gọi `/api/...` cùng origin với Vite và proxy chuyển tới BE nên không gặp lỗi CORS khi tải API và ảnh. Nếu không khai báo `VITE_API_BASE_URL`, FE dùng logic đoán URL mặc định trong `FE/src/helpers/api/client.js`.

Các biến `VITE_*` được nhúng vào bundle lúc build, nên phải sửa env rồi chạy lại `npm run build` để đổi cấu hình.

## Build frontend cho production

```bash
cd FE
npm run build
```

Build sẽ xuất vào thư mục:

```text
BE/public/dist
```

Truy cập bản build qua Apache/Laragon, không cần chạy `npm run dev`. URL gọn, không có `BE/public/dist`:

```text
http://du-an-1.test/
http://du-an-1.test/catalog
http://du-an-1.test/product-detail/sap-vuot-toc-hanz-de-fuko-claymation
```

URL gọn hoạt động nhờ file `.htaccess` ở thư mục gốc dự án (Laragon tự trỏ `du-an-1.test` vào thư mục gốc và bật `AllowOverride All`, không cần cấu hình thêm):

- `/BE/...` giữ nguyên cho API và ảnh upload.
- File tĩnh của FE được lấy từ `BE/public/dist`; các route như `/catalog` trả về `index.html` nên tải lại trang vẫn mở được.
- Chặn truy cập web tới `.env` và `.git`.

Apache cần bật `mod_rewrite` và `AllowOverride All`.

### Deploy

Trỏ web root của domain vào thư mục gốc dự án, giữ nguyên `.htaccess` ở gốc. FE chạy ở `https://ten-mien/` và BE ở `https://ten-mien/BE/`, không cần sửa env mặc định. Sau đó chạy `npm run build` và đưa cả dự án lên server.

Các trường hợp khác:

- **BE ở domain khác:** đặt `VITE_API_BASE_URL=https://api.example.com` trong `FE/.env.production` (hoặc `FE/.env.local`) và bật CORS ở BE.
- **Web root trỏ thẳng vào `BE/public/dist`:** dùng `FE/public/.htaccess` (được Vite copy vào dist) để fallback route; BE phải được phục vụ riêng và `VITE_API_BASE_URL` trỏ tới đó.

Khi deploy, đặt `APP_URL=https://ten-mien` trong `BE/.env` để link trong email xác minh/đặt lại mật khẩu trỏ đúng domain, và cập nhật `SEPAY_SUCCESS_URL`, `SEPAY_ERROR_URL`, `SEPAY_CANCEL_URL` (nếu có khai báo) theo domain đó.

### Chia sẻ qua ngrok

Tunnel vào Apache và giữ host `du-an-1.test` để vhost của Laragon nhận đúng dự án:

```bash
ngrok http 80 --host-header=du-an-1.test
```

Vì `--host-header` ghi đè Host, BE không biết domain công khai nên link trong email sẽ trỏ về `du-an-1.test`. Trước khi test đăng ký, quên mật khẩu hoặc thanh toán, thêm vào `BE/.env` (thay bằng URL ngrok đang chạy):

```text
APP_URL=https://ten-cua-ban.ngrok-free.dev
```

Với `APP_URL`, `FRONTEND_URL` tự dùng cùng origin. Trong `BE/.env`, các dòng `SEPAY_SUCCESS_URL`, `SEPAY_ERROR_URL`, `SEPAY_CANCEL_URL` nếu đang trỏ `http://localhost:5173` thì cần đổi sang `https://ten-cua-ban.ngrok-free.dev/payment/success` (và `/error`, `/cancel`), hoặc xóa để dùng giá trị mặc định (nên để trống). Thêm URL IPN trong trang quản trị SePay: `https://ten-cua-ban.ngrok-free.dev/BE/?action=api-sepay-ipn`, kiểu xác thực Secret Key và giá trị bằng `SEPAY_IPN_SECRET`. Xóa `APP_URL` khi không chia sẻ nữa để email về lại link local. Link ngrok miễn phí hiện trang cảnh báo ở lần truy cập đầu tiên; người nhận chỉ cần bấm "Visit Site".

## API backend

BE dùng một điểm vào duy nhất `BE/index.php`; endpoint được chọn bằng tham số `?action=`, ví dụ:

```text
http://du-an-1.test/BE/?action=api-products
```

Các endpoint đánh dấu "đăng nhập" cần header `Authorization: Bearer <token>`; endpoint "admin" cần token của tài khoản có role admin (chi tiết ở [AUTH_README.md](AUTH_README.md)). Mẫu request để test xem [BE/test-api.md](BE/test-api.md).

| Nhóm | Endpoint (`action`) | Quyền |
| --- | --- | --- |
| Tài khoản | `api-register`, `api-verify-email`, `api-login`, `api-logout`, `api-forgot-password`, `api-reset-password`, `reset-password-bridge`, `api-check` | Công khai |
| Tài khoản | `api-change-password` | Đăng nhập |
| Sản phẩm | `api-products`, `api-products-top`, `api-product-detail`, `api-categories`, `api-banners` | Công khai |
| Bài viết | `api-posts` | Đăng nhập |
| Bình luận | `api-product-comments` | Công khai |
| Bình luận | `api-comment-create`, `api-comment-update`, `api-comment-delete` | Đăng nhập |
| Giỏ hàng | `api-cart`, `api-cart-add`, `api-cart-update`, `api-cart-delete` | Đăng nhập |
| Đơn hàng | `api-order-checkout`, `api-order-payment`, `api-order-payment-verify`, `api-orders`, `api-order-invoice`, `api-order-cancel` | Đăng nhập |
| IPN SePay | `api-sepay-ipn` | Công khai, xác thực bằng header `X-Secret-Key` = `SEPAY_IPN_SECRET` (do SePay gọi, không dùng JWT) |
| Quản trị đơn | `api-admin-orders`, `api-admin-order-update` | Admin |
| Quản trị sản phẩm | `api-admin-product-create`, `api-admin-product-update`, `api-admin-product-delete`, `api-admin-product-image-create`, `api-admin-product-image-delete` | Admin |
| Quản trị danh mục | `api-admin-category-create`, `api-admin-category-update`, `api-admin-category-delete` | Admin |
| Quản trị banner | `api-admin-banner-create`, `api-admin-banner-update`, `api-admin-banner-delete` | Admin |
| Quản trị bình luận | `api-admin-comments`, `api-admin-comment-approve`, `api-admin-comment-delete` | Admin |

Ảnh sản phẩm được lưu trong `BE/assets/uploads` và truy cập qua `/BE/assets/uploads/<tên-file>`. Nếu trong database có tên ảnh nhưng thiếu file thật, giao diện hiển thị chữ viết tắt thay cho ảnh.

## Khắc phục sự cố

| Hiện tượng | Nguyên nhân và cách xử lý |
| --- | --- |
| `http://du-an-1.test` không mở được | Thư mục dự án phải tên `du-an-1` và nằm trong `C:\laragon\www`. Trong Laragon chọn Menu, Apache, Reload (hoặc Start All) để tạo host tự động |
| Mở route như `/catalog` bị 404 | Apache chưa đọc `.htaccess`: bật `mod_rewrite` và `AllowOverride All` |
| Trang trắng sau khi build | `VITE_BASE_PATH` không khớp nơi phục vụ FE. Sửa env rồi chạy lại `npm run build` |
| `npm run dev` báo cổng 5173 đã dùng | Tắt tiến trình Vite cũ hoặc bỏ `--strictPort` để Vite tự chọn cổng khác |
| Gọi API bị lỗi hoặc CORS khi chạy dev | Kiểm tra `VITE_API_BASE_URL=/api` và `VITE_API_PROXY_TARGET` trỏ đúng BE đang chạy; restart `npm run dev` sau khi sửa file env |
| Lỗi "Thiếu DB_USERNAME, DB_PASSWORD hoặc JWT_SECRET" | Chưa tạo `BE/.env` từ `BE/.env.example` hoặc thiếu biến |
| Lỗi thiếu class PHPMailer hoặc SePay | Chưa chạy `composer install` ở thư mục gốc |
| Ảnh sản phẩm báo 404 | Tên ảnh trong database không có file tương ứng trong `BE/assets/uploads` |
| Qua ngrok, link trong email trỏ về `du-an-1.test` hoặc `localhost:5173` | Chưa đặt `APP_URL` (và các `SEPAY_*_URL`) theo URL ngrok trong `BE/.env`; xem [Chia sẻ qua ngrok](#chia-sẻ-qua-ngrok) |

## Tài liệu liên quan

- [AUTH_README.md](AUTH_README.md): hướng dẫn chi tiết về JWT, Bearer token và middleware auth

## Ghi chú

- Frontend và backend được tách biệt rõ ràng.
- Dự án hiện đang sử dụng mô hình API JSON đơn giản, dễ mở rộng.
- Auth thực hiện theo JWT, nhưng detail kỹ thuật được giữ riêng trong [AUTH_README.md](AUTH_README.md) để README vẫn là hướng dẫn chung dự án.
- Các file `.env` (`BE/.env`, `FE/.env.local`) và thư mục `vendor`, `BE/public/dist` không được commit. `FE/.env.development`, `FE/.env.production` và `FE/.env.example` chỉ chứa cấu hình không bí mật nên được commit.
- Quy ước khi sửa giao diện: giữ nguyên luồng UI/UX và hành vi gọi API; không sửa `FE/src/helpers/api/client.js` (base URL đọc từ `VITE_API_BASE_URL`, interceptor, xử lý 401) hay cách lưu token đăng nhập nếu chưa được chủ dự án đồng ý (xem [.github/instructions](.github/instructions)).

## Mục tiêu phát triển tiếp theo

- Landing page salon tóc nam
- Trang bài viết / tin tức
- Quản lý dịch vụ / sản phẩm
- Tích hợp đặt lịch / booking
- Tối ưu trải nghiệm frontend và API
