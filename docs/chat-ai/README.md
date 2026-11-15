# Chức năng Chat với trợ lý AI (Louis Dé Fule)

Bộ tài liệu mô tả toàn bộ chức năng chat với trợ lý AI của FuLe BarberShop: nghiệp vụ, kiến trúc, luồng xử lý, cơ sở dữ liệu, API, tool, giao diện và các điểm cần biết khi bảo vệ dự án.

Trợ lý tên **Louis Dé Fule**, dùng model Gemini (`gemini-2.5-flash`) qua REST API, có cơ chế gọi tool (function calling) để tra cứu dữ liệu thật của cửa hàng.

## Mục lục

| File | Nội dung | Đọc khi |
| --- | --- | --- |
| [01-kien-truc.md](01-kien-truc.md) | Các thành phần, tầng, sơ đồ kiến trúc tổng thể | Muốn hiểu hệ thống gồm những gì |
| [02-luong-nghiep-vu.md](02-luong-nghiep-vu.md) | Sơ đồ luồng từng nghiệp vụ (hỏi đáp, tool, điều hướng, gợi ý, hát) | Cần vẽ/giải thích luồng hoạt động |
| [03-co-so-du-lieu.md](03-co-so-du-lieu.md) | Hai bảng `ai_conversations`, `ai_messages`, ERD, ràng buộc | Cần giải thích cách lưu lịch sử |
| [04-api.md](04-api.md) | Contract endpoint `api-ai-chat`, mã lỗi | Cần biết FE gọi và nhận gì |
| [05-tools.md](05-tools.md) | Hai tool `search_product`, `fule_sing`, cơ chế lệnh điều hướng | Cần giải thích AI tra cứu/hát thế nào |
| [06-frontend.md](06-frontend.md) | Popup chat, trạng thái giao diện, trang `/fule-sing` | Cần giải thích phần giao diện |
| [07-bao-mat.md](07-bao-mat.md) | Các lớp bảo mật, rủi ro đã biết, kết quả kiểm thử | Cần trả lời câu hỏi an toàn |
| [08-van-hanh.md](08-van-hanh.md) | Cài đặt, cấu hình, migration, cách thêm tool/bài hát | Cần cài lại hoặc mở rộng |
| [09-van-dap.md](09-van-dap.md) | Câu hỏi thường gặp khi bảo vệ, trả lời ngắn gọn | Chuẩn bị vấn đáp |

## Tóm tắt một dòng

Khách đăng nhập → gửi câu hỏi lên `api-ai-chat` → server đọc lịch sử trong DB → Gemini trả lời, có thể gọi tool tra sản phẩm hoặc hát → server lưu các lượt mới → FE hiện câu trả lời, gợi ý và điều hướng đến trang phù hợp.

## Thuật ngữ

| Thuật ngữ | Giải thích |
| --- | --- |
| Gemini | Mô hình AI của Google, gọi qua REST API. |
| System prompt | Chỉ dẫn gửi kèm mọi request, quy định vai trò và quy tắc trả lời. Khách không thấy. |
| Tool / function calling | Cơ chế để AI yêu cầu server chạy một hàm (ví dụ tra sản phẩm) rồi nhận kết quả để trả lời. AI không tự đọc DB được. |
| `functionCall` | Lượt AI nói "tôi muốn gọi tool X với tham số Y". |
| `functionResponse` | Lượt server gửi kết quả tool lại cho AI. |
| `thoughtSignature` | Chuỗi mã hóa Gemini gắn vào lượt gọi tool. Phải giữ nguyên khi gửi lại, nếu không AI mất mạch suy nghĩ. |
| Action / lệnh điều hướng | Lệnh server gửi FE để mở một trang (ví dụ trang chi tiết sản phẩm). FE chỉ chấp nhận danh sách route cho phép. |
| Suggestion / gợi ý | Các câu khách có thể bấm để hỏi tiếp, do AI sinh cùng câu trả lời. |
| Conversation | Một cuộc hội thoại, thuộc về một user đã đăng nhập. |
| Turn / lượt | Một tin nhắn trong hội thoại (của user, của model, hoặc kết quả tool). |
