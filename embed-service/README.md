# embed-service

Server Python nhỏ biến câu tiếng Việt thành vector 768 chiều, dùng model
[`bkai-foundation-models/vietnamese-bi-encoder`](https://huggingface.co/bkai-foundation-models/vietnamese-bi-encoder).
BE PHP gọi sang qua class `BE/embedServices/EmbedService.php`.

## Cài đặt (làm một lần)

Cần Python 3.10 trở lên. Chạy trong thư mục `embed-service/`:

```bash
python -m venv .venv
# Cài torch bản CPU trước để khỏi tải bản CUDA nặng vài GB
.venv/Scripts/python -m pip install torch --index-url https://download.pytorch.org/whl/cpu
.venv/Scripts/python -m pip install -r requirements.txt
```

(macOS/Linux thay `.venv/Scripts/python` bằng `.venv/bin/python`.)

## Chạy server

```bash
.venv/Scripts/python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

Lần chạy đầu tiên sẽ tải model khoảng 540 MB về cache của Hugging Face, những lần sau không phải tải lại.
Khi thấy dòng `Uvicorn running on http://127.0.0.1:8001` là server đã sẵn sàng.

Địa chỉ này phải khớp với `EMBED_SERVICE_URL` trong `BE/configs/config.php`.

### Từ lần chạy thứ hai: nên bật chế độ offline
Model đã nằm trong cache sau lần chạy đầu, nên không cần hỏi Hugging Face qua mạng nữa.
Trên một máy Windows đã gặp lỗi server tự tắt với `Segmentation fault` (exit code 139) ngay ở bước kiểm tra
model qua mạng; bật offline thì hết. Cách chạy:

```bash
HF_HUB_OFFLINE=1 .venv/Scripts/python -m uvicorn main:app --host 127.0.0.1 --port 8001
```

(PowerShell: `$env:HF_HUB_OFFLINE=1; .venv/Scripts/python -m uvicorn main:app --host 127.0.0.1 --port 8001`)

## Contract

### `GET /health`
Kiểm tra server có đang chạy.
```json
{ "status": "ok", "model": "bkai-foundation-models/vietnamese-bi-encoder", "dim": 768 }
```

### `POST /embed`
Body:
```json
{ "texts": ["Rapper nhạc chill", "Ca sĩ hát bolero"] }
```
Thành công 200 (vector thứ i ứng với câu thứ i):
```json
{ "vectors": [[0.12, -0.03, ...], [...]], "dim": 768 }
```
Lỗi 422: `texts` rỗng, quá 64 câu, có câu rỗng hoặc dài quá 2000 ký tự.

Server tự tách từ tiếng Việt (pyvi) trước khi đưa vào model, phía gọi chỉ cần gửi văn bản bình thường.

## Dùng với BE

1. Chạy migration và seed, rồi tạo vector cho toàn bộ biến thể sản phẩm (từ thư mục gốc repo):
   ```
   php BE/database/embed_products.php
   ```
   Sau này admin tạo/sửa sản phẩm sẽ tự tạo lại vector. Nếu lúc đó server Python đang tắt,
   chạy lại lệnh trên để bù.
2. Tìm kiếm thông minh qua API danh sách sản phẩm:
   ```
   GET http://localhost/du-an-1/BE/?action=api-products&search_mode=vector&search=pomade dễ gội
   ```
   Server Python tắt thì API tự dùng tìm kiếm thường và trả `notice`.
   Tắt hẳn tìm kiếm thông minh: đặt `VECTOR_SEARCH_ENABLED` = `false` trong `BE/configs/config.php`.
