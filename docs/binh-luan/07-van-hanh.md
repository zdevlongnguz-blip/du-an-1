# 07. Vận hành

## 1. Cài đặt trên máy mới

1. Có database `du-an-1` (xem README gốc, mục "Cập nhật database").
2. Chạy migration thêm 3 cột nhãn AI (xem [03-co-so-du-lieu.md](03-co-so-du-lieu.md) mục 4).
3. Thêm vào `BE/.env` (tên biến có trong `BE/.env.example`):

   ```text
   COMMENT_GUARD_ENABLED=1
   COMMENT_GUARD_URL=https://<ten-tunnel>.trycloudflare.com
   COMMENT_GUARD_KEY=
   COMMENT_GUARD_TIMEOUT=15
   ```

4. Chạy notebook Colab, copy URL `trycloudflare` nó in ra vào `COMMENT_GUARD_URL`.
5. Mở `<URL>/` trên trình duyệt, thấy `{"status":"ok",...}` là Colab đã sẵn sàng.

Không có Colab vẫn chạy được: đặt `COMMENT_GUARD_ENABLED=0`, mọi bình luận sẽ chờ admin duyệt như trước khi có AI.

## 2. Cấu hình

| Biến | Mặc định | Ý nghĩa |
| --- | --- | --- |
| `COMMENT_GUARD_ENABLED` | bật | `0`/`false`/`off`/`no` = tắt. Để trống hoặc giá trị lạ = bật |
| `COMMENT_GUARD_URL` | trống | URL gốc Colab, không có `/` cuối. Trống thì mọi lần gọi đều lỗi và bình luận chờ duyệt |
| `COMMENT_GUARD_KEY` | trống | Gửi qua header `x-api-key`. Colab hiện chưa kiểm tra key |
| `COMMENT_GUARD_TIMEOUT` | 15 | Số giây chờ tối đa, 1–60. Ngoài khoảng thì dùng 15 |

Đổi `.env` là có hiệu lực từ request tiếp theo, không cần khởi động lại Apache.

## 3. Xử lý sự cố

| Hiện tượng | Nguyên nhân | Cách xử lý |
| --- | --- | --- |
| Bình luận nào cũng "đang chờ quản trị viên duyệt", kể cả câu khen | AI đang lỗi hoặc đang tắt | Mở log API `BE/storage/logs/api-YYYY-MM-DD.log`, tìm khối `api-comment-create`/`api-comment-update`: dòng `Ghi chú (WARN): Kiểm duyệt AI LỖI...` cho biết lý do (URL được che thành `<COMMENT_GUARD_URL>`), `Ghi chú (INFO): Kiểm duyệt AI đang TẮT` nghĩa là biến đang tắt. Chi tiết đầy đủ (có URL thật) ở log PHP (Laragon: `C:\laragon\tmp\php_errors.log`) dòng `[CommentGuard]` |
| Gửi bình luận chờ lâu | Không kết nối được thì chờ tối đa 3 giây (`CommentGuard::CONNECT_TIMEOUT_SECONDS`); kết nối được nhưng Colab xử lý treo thì chờ tới `COMMENT_GUARD_TIMEOUT` (15 giây) | Chạy lại Colab, hoặc tạm đặt `COMMENT_GUARD_ENABLED=0` |
| Log: `nhãn reason/sentiment dài hơn ... ký tự` | Colab trả nhãn mới dài hơn cột DB (20/10 ký tự), bình luận đã được lưu ở trạng thái chờ duyệt | Viết migration nới cột, rồi sửa `MAX_REASON_LENGTH`/`MAX_SENTIMENT_LENGTH` trong `CommentGuard` cho khớp |
| Log: `Không kết nối được dịch vụ kiểm duyệt ... Could not resolve host` | URL tunnel đã đổi (Colab chạy lại) | Cập nhật `COMMENT_GUARD_URL` |
| Log: `Chưa cấu hình COMMENT_GUARD_URL` | Thiếu biến trong `.env` | Thêm biến |
| Log: `Dịch vụ kiểm duyệt trả về lỗi HTTP 5xx` | Colab lỗi bên trong (hết RAM, model chưa nạp xong) | Xem output notebook, chạy lại cell server |
| Lỗi SQL `Unknown column 'moderation_label'` | Chưa chạy migration | Chạy migration `20261122_01_add_comments_ai_labels.sql` |
| Câu tiếng Việt bị chấm sai hẳn khi test bằng curl | Git Bash làm mất dấu | Gửi body từ file UTF-8 hoặc dùng Postman |

### Đọc log kiểm duyệt

Mỗi request tạo/sửa bình luận có thêm một dòng ghi chú trong log API (ghi bằng `ApiLogger::note()`, khách không thấy):

| Ghi chú | Mức của khối log | Nghĩa |
| --- | --- | --- |
| `Kiểm duyệt AI: approved (nhãn CLEAN, cảm xúc POS, toxic_prob 0.0035).` | INFO | AI chấm xong, hiện ngay |
| `Kiểm duyệt AI: pending (nhãn CLEAN, cảm xúc NEG, toxic_prob 0.6285, AI không chắc chắn).` | INFO | AI không chắc, chờ duyệt |
| `Kiểm duyệt AI: blocked (nhãn OFFENSIVE, cảm xúc NULL, toxic_prob 0.9973).` | INFO | Bị chặn |
| `Kiểm duyệt AI LỖI, bình luận chuyển sang chờ duyệt: <lý do>` | **WARN** (dù HTTP 201) | Colab/service lỗi |
| `Kiểm duyệt AI đang TẮT (COMMENT_GUARD_ENABLED=0), ...` | INFO | Biến đang tắt |

Lọc nhanh các lần AI lỗi: tìm chữ `Kiểm duyệt AI LỖI` trong file log của ngày.

## 4. Vấn đề đã biết

| Vấn đề | Ảnh hưởng | Hướng xử lý |
| --- | --- | --- |
| URL `trycloudflare` đổi mỗi lần chạy lại Colab | Phải sửa `.env` mỗi lần | Dùng tunnel có tên cố định nếu đưa lên môi trường thật |
| Colab miễn phí có thể ngắt bất cứ lúc nào | Bình luận chuyển sang chờ duyệt | Đã xử lý: không chặn khách, admin duyệt tay |
| Colab chưa kiểm tra `x-api-key` | Ai biết URL đều gọi được Colab | Thêm kiểm tra key ở phía Colab |
| Bình luận cũ (trước 22/11/2026) có nhãn `NULL`, một số câu chửi cũ đã được duyệt (ví dụ #6 "NHƯ CẶC" trên DB local) | Câu chửi cũ vẫn hiện | Admin xóa tay, hoặc viết script chấm bù bằng `CommentGuard::analyzeBatch()` (tối đa 50 câu mỗi lần) |
| API công khai trả email đầy đủ của người bình luận | Lộ email | Có từ trước, cần task riêng để chỉ trả tên hiển thị |
| Nội dung bình luận được gửi ra dịch vụ bên ngoài (Colab, Cloudflare) | Quyền riêng tư | Nêu rõ khi được hỏi; khi lên môi trường thật nên tự host model |
| Model chặn nhầm câu chê gay gắt, không chặn spam | Một số câu hợp lệ phải chờ duyệt; spam lọt | Admin duyệt tay; lọc spam cần cơ chế riêng |

## 5. API test `api-comment-guard`

Giữ lại trong code (`BE/controllers/CommentGuardController.php` và case trong `routes/index.php`) để nhóm thử model: trả nguyên nhãn và điểm AI, nên chỉ dùng để kiểm thử. **FE không dùng.** Chi tiết ở [../comment-guard/README.md](../comment-guard/README.md) mục 5.
