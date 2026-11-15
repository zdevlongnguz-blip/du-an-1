# 04. Contract API

## 1. Endpoint

| Mục | Giá trị |
| --- | --- |
| URL | `POST /BE/?action=api-ai-chat` |
| Xác thực | Bắt buộc. Header `Authorization: Bearer <JWT>` |
| Content-Type | `application/json` |
| Router | `BE/routes/index.php`, `requireAuth()` trước khi vào controller |

## 2. Request

```json
{
  "message": "Có sáp nào giữ nếp mạnh không?",
  "conversation_id": 12
}
```

| Trường | Kiểu | Bắt buộc | Quy tắc |
| --- | --- | --- | --- |
| `message` | string | Có | Sau khi `trim` không rỗng, tối đa 2000 ký tự (đếm theo ký tự UTF-8) |
| `conversation_id` | số nguyên | Không | Không gửi hoặc `null` = tạo hội thoại mới. Gửi lên phải là số nguyên dương (`12` hoặc `"12"`) |
| `history` | — | — | **Không còn dùng.** Nếu gửi lên, server bỏ qua |

## 3. Response thành công (200)

```json
{
  "status": "success",
  "conversation_id": 12,
  "reply": "Sáp vuốt tóc Vinh Râu có giá 250.000đ, hiện còn hàng...",
  "tool_calls": [
    { "name": "search_product", "args": { "keyword": "Vinh Râu" } }
  ],
  "actions": [
    { "type": "navigate", "route": "product-detail", "query": {}, "params": { "slug": "sap-vuot-toc-vinh-rau" } }
  ],
  "suggestions": ["Thêm vào giỏ hàng", "Đặt mua ngay", "Xem sản phẩm tương tự"]
}
```

| Trường | Ý nghĩa | Khi rỗng |
| --- | --- | --- |
| `conversation_id` | Dùng cho lượt sau | Không bao giờ rỗng khi thành công |
| `reply` | Câu trả lời đã bỏ dòng gợi ý | Chuỗi rỗng nếu AI không trả chữ (FE hiện câu dự phòng) |
| `tool_calls` | Tool đã chạy trong lượt này | `[]` |
| `actions` | Lệnh điều hướng (tối đa 1) | `[]` |
| `suggestions` | Tối đa 3 câu gợi ý | `[]` (FE dùng chip mặc định) |

`tool_calls` chỉ có `name` và `args`, không chứa kết quả tool.

## 4. Các dạng `actions`

```json
{ "type": "navigate", "route": "catalog", "query": { "search": "sáp" }, "params": {} }
{ "type": "navigate", "route": "catalog", "query": {}, "params": {} }
{ "type": "navigate", "route": "product-detail", "query": {}, "params": { "slug": "..." } }
{ "type": "navigate", "route": "fule-sing", "query": { "song": "Vì con", "video": "vi-con.mp4" }, "params": {} }
```

FE chỉ chấp nhận các route trong danh sách cho phép: `catalog`, `product-detail`, `fule-sing`. Route khác bị bỏ qua.

## 5. Lỗi

| Mã | Điều kiện | Thông điệp (`message`) |
| --- | --- | --- |
| 401 | Thiếu hoặc sai JWT (do `requireAuth`), hoặc `sub` không hợp lệ | Từ `requireBearerToken` hoặc "Bạn cần đăng nhập để sử dụng trợ lý AI" |
| 405 | Không phải POST | "Phương thức không được hỗ trợ" |
| 400 | Body không phải JSON, thiếu `message` không phải chuỗi, `conversation_id` không hợp lệ | "Thiếu câu hỏi (message) hoặc dữ liệu không phải JSON" / "conversation_id không hợp lệ" |
| 422 | `message` rỗng hoặc quá 2000 ký tự | "Câu hỏi không được để trống và tối đa 2000 ký tự" |
| 404 | `conversation_id` không tồn tại hoặc không thuộc user | "Không tìm thấy cuộc hội thoại" |
| 500 | Chưa có `GEMINI_API_KEY` | "Trợ lý AI chưa được cấu hình" |
| 500 | Không lưu được lịch sử | "Không lưu được lịch sử hội thoại, vui lòng thử lại sau" |
| 502 | Gemini lỗi: HTTP lỗi, bị chặn nội dung, vượt 5 vòng tool, mạng | "Trợ lý AI đang gặp sự cố, vui lòng thử lại sau" |

Chi tiết lỗi thật (ví dụ mã 429 của Google) chỉ ghi vào log PHP với tiền tố `[ChatController]`, không trả cho client.

## 6. Quy ước ghi nhận

- Mọi lỗi đều trả JSON qua `response_json`, dạng `{ "status": "error", "message": "..." }`.
- Response không chứa `history`, không chứa API key, không chứa thông tin cấu hình.
- Gọi lại với cùng `conversation_id` sau khi đã có 500 lưu lịch sử: không có lượt nào được ghi, nên gửi lại an toàn.

## 7. Thay đổi so với bản đầu

| Bản đầu | Bản hiện tại |
| --- | --- |
| Không cần đăng nhập | Bắt buộc đăng nhập |
| Client gửi `history` | Server lưu, client gửi `conversation_id` |
| Response có `history` | Không còn `history` |
| Không có `actions`, `suggestions` | Có cả hai |

File `docs/huong-dan-gemini-chat-tools.md` là bản mô tả cũ, còn nhắc tới `history` và chưa đăng nhập. Hãy đọc bộ tài liệu này thay thế.
