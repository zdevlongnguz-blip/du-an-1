# 04. Vận hành

## 1. Cài đặt lần đầu (mỗi máy làm một lần)

Cần Python 3.10 trở lên. Trong thư mục `embed-service/`:

```bash
python -m venv .venv
# Cài torch bản CPU trước để khỏi tải bản CUDA nặng vài GB
.venv/Scripts/python -m pip install torch --index-url https://download.pytorch.org/whl/cpu
.venv/Scripts/python -m pip install -r requirements.txt
```

(macOS/Linux thay `.venv/Scripts/python` bằng `.venv/bin/python`.)

Dung lượng tải: torch CPU ~200 MB, các thư viện khác ~100 MB, model ~540 MB (tải ở lần chạy server đầu tiên, lưu cache của Hugging Face).

Phía DB (từ thư mục gốc repo, PHP của Laragon):

```bash
php BE/database/run.php                                   # migration (tạo bảng vector)
mysql -uroot du-an-1 < BE/database/seed/products_search_attributes.sql   # dữ liệu demo
```

## 2. Chạy server Python

Lần đầu (cần mạng để tải model):

```bash
.venv/Scripts/python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

**Từ lần thứ hai: chạy chế độ offline** (model đã có trong cache):

```bash
HF_HUB_OFFLINE=1 .venv/Scripts/python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

PowerShell: `$env:HF_HUB_OFFLINE=1; .venv/Scripts/python -m uvicorn main:app --host 127.0.0.1 --port 8001`

Sẵn sàng khi thấy `Uvicorn running on http://127.0.0.1:8001`. Kiểm tra: mở `http://127.0.0.1:8001/health` → `{"status":"ok","model":"...","dim":768}`.

Địa chỉ này phải khớp `EMBED_SERVICE_URL` trong `BE/configs/config.php`.

## 3. Tạo vector

```bash
php BE/database/embed_products.php          # chỉ tạo cho biến thể có đoạn văn đổi / chưa có vector
php BE/database/embed_products.php --force  # tạo lại toàn bộ
```

Script in đoạn văn của từng biến thể vừa tạo và dòng tổng kết `Đã tạo vector: X, bỏ qua (không đổi): Y`.

Khi nào phải chạy:

| Tình huống | Lệnh |
| --- | --- |
| Lần đầu cài, sau khi chạy seed | `embed_products.php` |
| Server Python tắt lúc admin sửa tên/mô tả sản phẩm | `embed_products.php` |
| Đổi tên danh mục (đoạn văn có tên danh mục, admin danh mục chưa tự đồng bộ) | `embed_products.php` |
| Sửa mẫu câu / khóa trong `ProductEmbedText` | `embed_products.php` |
| Đổi model ở server Python | `embed_products.php --force` |

Admin tạo/sửa sản phẩm **tự** đồng bộ, không cần chạy tay.

## 4. Tắt tìm kiếm thông minh

Trong `BE/configs/config.php`:

```php
define('VECTOR_SEARCH_ENABLED', false);
```

Khi tắt: mọi request `search_mode=vector` dùng tìm kiếm thường và trả `notice`; admin lưu sản phẩm không gọi server Python. Dùng khi demo trên máy không cài/không chạy được Python. Không phải sửa FE.

## 5. Đổi model

1. Sửa `MODEL_NAME` trong `embed-service/main.py`.
2. Kiểm tra model mới có cần **tách từ** (pyvi) không. Model hiện tại bắt buộc; model khác (vd `BAAI/bge-m3`) thì thường không, khi đó bỏ dòng `tokenize(...)`.
3. Chạy server (lần đầu cần mạng để tải model).
4. `php BE/database/embed_products.php --force`.

BE không phải sửa: bảng đã lưu `dim` và `model_name`. Lưu ý dung lượng/RAM: `bge-m3` khoảng 2,2 GB (chưa thử trong dự án).

## 6. Xử lý sự cố

| Hiện tượng | Nguyên nhân | Cách xử lý |
| --- | --- | --- |
| API trả `notice: "...tạm thời không khả dụng..."` | Server Python chưa bật / sai cổng | Bật server (mục 2), kiểm tra `/health`, `EMBED_SERVICE_URL` |
| Server tự tắt, `Segmentation fault` (exit code 139) lúc nạp model | Đã gặp trên Windows ở bước kiểm tra model qua mạng; máy thiếu RAM cũng có thể góp phần | Chạy với `HF_HUB_OFFLINE=1`; đóng bớt ứng dụng nếu RAM trống thấp |
| Sản phẩm mới không bao giờ ra trong chế độ vector | Chưa có vector (server tắt lúc tạo) | `embed_products.php` |
| Thử bằng curl trong Git Bash, chữ có dấu bị lỗi `There was an error parsing the body` / JSON hỏng | Git Bash gửi chữ có dấu trên dòng lệnh không đúng UTF-8 | Ghi body ra file UTF-8 rồi `--data-binary @file`, hoặc dùng Thunder Client / Python |
| Cảnh báo `symlinks` khi chạy server trên Windows | Windows chưa bật Developer Mode | Chỉ là cảnh báo, bỏ qua được (`HF_HUB_DISABLE_SYMLINKS_WARNING=1` để ẩn) |
| Trang chi tiết/giỏ hàng hiện dòng thuộc tính dài, có " · " trống | FE in toàn bộ `attributes` | Việc của FE (xem [03-du-lieu.md, mục 5](03-du-lieu.md)) |
