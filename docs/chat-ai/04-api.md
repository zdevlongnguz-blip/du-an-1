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
| 502 | Gemini lỗi: HTTP lỗi, mọi key đều hết hạn mức hoặc hỏng, bị chặn nội dung, vượt 5 vòng tool, mạng | "Trợ lý AI đang gặp sự cố, vui lòng thử lại sau" |

Chi tiết lỗi thật (ví dụ mã 429 của Google) chỉ ghi vào log PHP với tiền tố `[ChatController]`, không trả cho client. Khi có nhiều key, mỗi lần một key bị 429/401/403 và được thay bằng key khác, log ghi thêm dòng `[Gemini] Key số i/n lỗi HTTP ...` (chỉ số thứ tự, không ghi key); client không thấy gì nếu key sau thành công.

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

## Endpoint lấy lại lịch sử: `api-ai-history`

Dùng khi khung chat mở sau lúc đăng nhập lại hoặc tải lại trang, để khách thấy lại cuộc chat gần nhất và chat tiếp trong cùng cuộc đó.

| Mục | Nội dung |
| --- | --- |
| URL | `GET /BE/?action=api-ai-history` |
| Xác thực | Bearer token (`requireAuth` ở router). Chỉ đọc hội thoại của user trong token, không nhận ID cuộc hội thoại từ client |
| 200 | `{ status: 'success', conversation_id, messages }` |
| 401 / 405 / 500 | Thiếu token / sai method / lỗi đọc DB |

- `conversation_id`: cuộc có lượt mới nhất của user; `null` nếu chưa chat lần nào (khi đó `messages: []`).
- `messages`: `[{ role: 'user' | 'assistant', text, created_at, tool_calls, suggestions }]`, cũ trước mới sau, tối đa `Gemini::MAX_HISTORY` lượt gần nhất.
- Đã lọc: lượt gọi tool (`functionCall`/`functionResponse`) không hiện thành tin nhắn; dòng `[[GOI_Y: ...]]` được tách ra `suggestions`.
- `tool_calls` chỉ giữ từ khóa của `search_product` (chip "Đã tra cứu"); tham số tool khác bị bỏ vì có thể chứa SĐT, địa chỉ.
- Lệnh `_action` không lưu trong DB, nên nạp lại lịch sử không mở lại trang nào.

Phía FE (`chatAssistant.js`): `loadHistory()` chạy 1 lần khi khung chat mở lúc đã đăng nhập. Nút "cuộc mới" không nạp lại cuộc cũ. Đăng xuất gọi `forgetChat()` để lần đăng nhập sau nạp đúng lịch sử của người mới.

## Trường `checkout_draft` của `api-ai-chat` (không bắt buộc)

FE gửi kèm khi khách đang có đơn nháp (tính năng lên đơn nhanh), để Louis lên đơn tiếp từ đúng trạng thái trên trang:

```json
"checkout_draft": {
  "items": [{ "sku": "CLAYMATION", "quantity": 3 }]
}
```

- `items`: 1-10 món, `sku` chuỗi 1-100 byte, `quantity` số nguyên 1-99. Server tra SKU trong DB, chỉ SKU có thật được đưa vào system prompt của lượt đó (không lưu lịch sử).
- Không có thông tin người nhận: tên, SĐT, địa chỉ không đi qua `api-ai-chat`. Trường khác bị bỏ qua.
- Sai định dạng: 400 `checkout_draft không hợp lệ`.
- Chi tiết: [len-don-nhanh/11-dong-bo-don-nhap.md](len-don-nhanh/11-dong-bo-don-nhap.md).

## Endpoint xóa lịch sử: `api-ai-history-clear`

Xóa **toàn bộ** cuộc trò chuyện với trợ lý AI của user đang đăng nhập. Nút xóa trên header khung chat gọi API này.

| Mục | Nội dung |
| --- | --- |
| URL | `DELETE /BE/?action=api-ai-history-clear` |
| Xác thực | Bearer token (`requireAuth` ở router). User lấy từ `sub` trong JWT, không nhận ID từ client |
| 200 | `{ status: 'success', message, deleted_conversations }` (0 nếu chưa có cuộc nào) |
| 401 / 405 / 500 | Thiếu token / sai method / lỗi DB |

- Lệnh xóa luôn có `WHERE user_id = :user_id`. Các lượt trong `ai_messages` xóa theo `ON DELETE CASCADE`.
- FE: nút xóa hỏi xác nhận bằng hộp thoại `window.confirm()` của trình duyệt. Sau khi xóa thành công thì xóa đơn nháp và màn hình chat.
- Không xóa khi đang gửi câu hỏi.

