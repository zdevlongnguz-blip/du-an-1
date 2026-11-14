# Hướng dẫn build FE và chia sẻ thử qua ngrok

Tài liệu này mô tả cách build giao diện (FE), chạy trên Laragon và chia sẻ ra internet bằng ngrok để test môi trường gần giống product.

## 1. Yêu cầu trước khi chạy

- **Laragon** đang bật Apache và MySQL. Vhost `du-an-1.test` trỏ về thư mục gốc dự án (đã có sẵn).
- **Node.js** và `npm` đã cài. Chạy `npm install` trong `FE/` nếu chưa có `node_modules`.
- **ngrok** phiên bản từ 3.29.0 trở lên. Kiểm tra bằng `ngrok version`. Nếu cũ hơn thì chạy `ngrok update`, hoặc `winget upgrade Ngrok.Ngrok`.
- Đã cấu hình authtoken một lần: `ngrok config add-authtoken <token>`.
- Nhánh `dev` đã được cập nhật theo `origin/dev`.

## 2. Build FE

Chạy trong thư mục `FE/`:

```bash
cd FE
npm run build
```

- Kết quả được ghi vào `BE/public/dist/`. Lệnh này xóa bản build cũ trước khi tạo bản mới.
- `BE/public/dist/` nằm trong `.gitignore`, nên không bị commit.
- Build dùng `FE/.env.production`: `VITE_API_BASE_URL=/BE`, nghĩa là FE gọi API theo cùng domain với giao diện.

Build xong phải thấy dòng `✓ built in ...` và không có lỗi.

## 3. Cấu hình `APP_URL` trong `BE/.env`

`APP_URL` là địa chỉ công khai, dùng để tạo link trong mail và chuyển hướng về FE khi đang chia sẻ qua ngrok.

- **Khi chạy local (không chia sẻ):** để dòng `APP_URL` ở dạng comment:
  ```
  # APP_URL=https://...
  ```
- **Khi chạy ngrok:** bỏ dấu `#` và điền đúng URL ngrok hiện tại, không có dấu `/` ở cuối:
  ```
  APP_URL=https://<ten-ngrok>.ngrok-free.dev
  ```
- Khi ngrok đổi URL, phải sửa lại dòng này.
- Khi tắt ngrok, comment lại dòng này để local không bị trỏ về ngrok.

PHP đọc `.env` ở mỗi request, nên sau khi sửa không cần khởi động lại Apache.

## 4. Chạy ngrok

Mở terminal và chạy:

```bash
ngrok http 80 --host-header=du-an-1.test
```

- `--host-header=du-an-1.test` là bắt buộc. Nếu thiếu, Apache không khớp được VirtualHost và sẽ trả trang mặc định của Laragon cho host ngrok.
- Lấy URL công khai từ `http://127.0.0.1:4040` (web giao diện của ngrok) hoặc từ dòng `Forwarding` trong terminal.

## 5. Kiểm tra sau khi chạy

Thay `<ngrok>` bằng URL của bạn:

| Đường dẫn | Kết quả mong đợi |
|---|---|
| `https://<ngrok>/` | Trang FE, HTTP 200 |
| `https://<ngrok>/BE/?action=api-products` | JSON danh sách sản phẩm, HTTP 200 |
| `https://<ngrok>/js/index-<hash>.js` | File JS, HTTP 200 |
| `https://<ngrok>/BE/?action=api-register` (GET) | HTTP 405 (route chỉ nhận POST, như vậy là đúng) |

Khi mở trang lần đầu, ngrok có thể hiện trang cảnh báo. Bấm **Visit Site** để tiếp tục.

Để test mail:
1. Mở `https://<ngrok>/` và đăng ký bằng email bạn có thể mở được.
2. Link xác minh trong mail phải bắt đầu bằng `https://<ngrok>/BE/?action=api-verify-email&token=...`.
3. Bấm link, phải chuyển về trang FE trên ngrok.

Lưu ý: `api-forgot-password` luôn trả HTTP 202 với thông báo chung, kể cả khi email không tồn tại. Email chưa đăng ký thì không có mail nào được gửi, đây là thiết kế có chủ đích và không phải lỗi.

## 6. Dừng khi xong

1. Dừng ngrok: bấm `Ctrl+C` trong terminal đang chạy ngrok.
2. Comment dòng `APP_URL` trong `BE/.env` để local không bị ảnh hưởng.
3. Apache để nguyên như Laragon đang chạy.

## 7. Lỗi thường gặp

| Triệu chứng | Nguyên nhân | Cách xử lý |
|---|---|---|
| ngrok báo `ERR_NGROK_121` (agent quá cũ) | Phiên bản ngrok thấp hơn yêu cầu | Chạy `ngrok update` hoặc `winget upgrade Ngrok.Ngrok` |
| Trang `https://<ngrok>/` hiện trang "Laragon" | Thiếu `--host-header=du-an-1.test` | Chạy lại ngrok với tham số này |
| Trang trắng hoặc `/js/...` trả 404 | Đang mở đường dẫn `/BE/public/dist/` thay vì gốc | Mở `https://<ngrok>/`. Cần `.htaccess` ở gốc dự án (có trong nhánh `dev` mới) |
| Link trong mail trỏ về `du-an-1.test` | `APP_URL` đang bị comment khi test qua ngrok | Bỏ comment và điền URL ngrok hiện tại |
| Link mail trỏ về ngrok cũ | ngrok đã đổi URL | Sửa lại `APP_URL` theo URL mới |
| Không có mail nào được gửi | Email chưa đăng ký hoặc chưa kích hoạt | Đăng ký trước, hoặc kiểm tra bảng `users` |
| Lỗi `APP_URL trong BE/.env phải có dạng https://ten-mien` | `APP_URL` có đường dẫn hoặc dấu `/` ở cuối | Chỉ giữ scheme và host |
| Cần xem lỗi PHP | Lỗi được ghi vào log | Xem `C:/laragon/tmp/php_errors.log` |
| Cần xem request tới BE | Chưa biết request đi đâu | Mở `http://127.0.0.1:4040` và xem từng request |

## 8. Ghi chú về chế độ dev

Khi chạy `npm run dev` ở `localhost:5173`, FE không dùng `/BE` trực tiếp mà gọi `/api`. Vite proxy chuyển `/api` tới `VITE_API_PROXY_TARGET` (`http://du-an-1.test/BE`) và cắt `/api` ở đầu đường dẫn. Tài liệu này chỉ áp dụng cho build và chia sẻ qua ngrok, không áp dụng cho dev.
