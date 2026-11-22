# Chức năng Bình luận sản phẩm (có AI kiểm duyệt)

Bộ tài liệu mô tả toàn bộ chức năng bình luận sản phẩm **sau khi đã tích hợp AI kiểm duyệt** (22/11/2026): nghiệp vụ, kiến trúc, luồng xử lý, cơ sở dữ liệu, 7 API bình luận, cách AI quyết định hiện/ẩn, kết quả kiểm thử, vận hành và kế hoạch FE.

> Tài liệu riêng về **dịch vụ AI trên Google Colab** (2 model, API test `api-comment-guard`, 26 câu thử model) nằm ở [../comment-guard/README.md](../comment-guard/README.md). Bộ này tập trung vào **API bình luận thật** mà FE dùng.

## Mục lục

| File | Nội dung | Đọc khi |
| --- | --- | --- |
| [01-kien-truc.md](01-kien-truc.md) | Các thành phần, file, tầng, sơ đồ kiến trúc | Muốn hiểu hệ thống gồm những gì |
| [02-luong-nghiep-vu.md](02-luong-nghiep-vu.md) | Sơ đồ luồng xem, tạo, sửa, xóa, duyệt bình luận | Cần vẽ/giải thích luồng hoạt động |
| [03-co-so-du-lieu.md](03-co-so-du-lieu.md) | Bảng `comments`, 3 cột nhãn AI, migration | Cần giải thích cách lưu dữ liệu |
| [04-api.md](04-api.md) | Contract đầy đủ 7 endpoint bình luận, mã lỗi | Cần biết FE gọi và nhận gì |
| [05-kiem-duyet-ai.md](05-kiem-duyet-ai.md) | Bảng quyết định hiện/ẩn, biến bật/tắt, xử lý khi Colab lỗi | Cần giải thích "chốt chặn" AI |
| [06-kiem-thu.md](06-kiem-thu.md) | 61 ca test API thật, nguyên văn request/response, cách tự test | Cần chứng minh chức năng chạy đúng |
| [07-van-hanh.md](07-van-hanh.md) | Cài đặt, cấu hình, xử lý sự cố, vấn đề đã biết | Cần cài lại hoặc sửa lỗi |
| [08-frontend.md](08-frontend.md) | Hiện trạng FE và kế hoạch cập nhật theo BE mới | Làm phần FE |
| [09-van-dap.md](09-van-dap.md) | Câu hỏi thường gặp khi bảo vệ | Chuẩn bị vấn đáp |

## Tóm tắt một dòng

Khách đăng nhập → gửi bình luận lên `api-comment-create` → server validate → gửi nội dung cho AI trên Colab → **câu sạch hiện ngay, câu độc hại bị từ chối (422, không lưu, khách sửa lại), câu AI không chắc hoặc lúc AI lỗi thì lưu và chờ admin duyệt** → admin duyệt/xóa ở trang quản trị.

## Trước và sau khi có AI

| | Trước 22/11/2026 | Sau 22/11/2026 |
| --- | --- | --- |
| Bình luận mới | Luôn `status = 0`, chờ admin duyệt tay | AI quyết định: sạch thì `status = 1` hiện ngay |
| Câu chửi tục | Chờ admin phát hiện và xóa | Bị ẩn ngay, lưu kèm nhãn `OFFENSIVE`/`HATE` |
| Sửa bình luận | Luôn về `status = 0` | Chấm lại nội dung mới; không đổi nội dung thì giữ nguyên |
| Admin | Phải đọc mọi bình luận | Chỉ xem câu bị chặn hoặc chờ duyệt, có nhãn AI hỗ trợ |
| Khi AI lỗi | (không có AI) | Bình luận vẫn được lưu, chờ admin duyệt như trước |

## Thuật ngữ

| Thuật ngữ | Giải thích |
| --- | --- |
| Kiểm duyệt (moderation) | Tự động quyết định một bình luận được hiện hay phải ẩn. |
| Chốt chặn | Bước kiểm duyệt đặt **ở server**, trước khi lưu bình luận. FE không bỏ qua được. |
| `status` | Cột trong bảng `comments`: `1` = đang hiện công khai, `0` = ẩn (chờ duyệt hoặc bị chặn). |
| `moderation` | Trường trong response tạo/sửa: `approved` (đã hiện), `pending` (chờ admin). Bị AI chặn thì là lỗi 422 kèm `moderation: "blocked"`. |
| Nhãn AI | 3 cột `moderation_label`, `sentiment`, `toxic_prob` lưu kết quả AI chấm. |
| `needs_review` | Cờ AI trả về khi không chắc chắn. Bình luận khi đó chờ admin duyệt. |
| Google Colab | Dịch vụ chạy notebook Python miễn phí của Google, ở đây dùng để chạy 2 model AI. |
| JWT / Bearer token | Chuỗi mã hóa chứa thông tin đăng nhập, FE gửi kèm header `Authorization: Bearer <token>`. |
| Middleware | Đoạn code chạy trước controller để kiểm tra đăng nhập (`AuthMiddleware`) hoặc quyền admin (`AdminMiddleware`). |
