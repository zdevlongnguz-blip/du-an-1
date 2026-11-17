# 07. System prompt cho tool lên đơn

File: `BE/geminiServices/GeminiPrompt.php`. System prompt là đoạn chỉ dẫn gửi kèm **mọi** request tới Gemini, khách không thấy. Mô tả trong `description()` của tool chỉ nói tool làm gì; **khi nào** và **nói gì sau đó** nằm ở prompt.

## 1. Khi nào gọi tool

| Quy tắc | Lý do |
| --- | --- |
| Gọi `create_order_draft` khi khách **nói rõ** muốn mua, đặt hàng, chốt đơn | Tránh lên đơn ngoài ý muốn |
| Chỉ hỏi giá, còn hàng, so sánh → **không** gọi, dùng `search_product` | Câu hỏi thông tin không phải lệnh mua |
| Chưa có SKU → gọi `search_product` trước | Tool chỉ nhận SKU |
| Nhiều phiên bản mà khách không nói rõ → dùng phiên bản `is_default` và nói rõ đã chọn bản nào | Không đứng hỏi vòng vo; khách đổi được |
| Số lượng mặc định 1, không tự tăng | Tránh đặt thừa |
| **Tuyệt đối không** hỏi, không xin tên/SĐT/địa chỉ trong chat, kể cả khi hồ sơ thiếu | Thông tin cá nhân không đi qua AI; khách điền trên trang |
| Khách tự gõ SĐT/địa chỉ/tên vào chat: không nhắc lại, không dùng, mời điền trên trang | Tool không có tham số nhận các thông tin này |

## 2. Xử lý kết quả

| Kết quả tool | Louis phải |
| --- | --- |
| `ready=false` + `problems` | Báo từng món có vấn đề, gợi ý phiên bản khác hoặc giảm số lượng |
| `ready=true` | Tóm tắt ngắn món + tổng tiền, nói đơn nháp vừa mở trên trang "Lên đơn nhanh"; website tự điền người nhận, khách kiểm tra rồi bấm "Xác nhận đặt hàng" |

## 3. Sau khi đã có đơn nháp

| Khách muốn | Louis làm |
| --- | --- |
| Đổi món, phiên bản, số lượng, thêm/bớt món | Gọi lại tool với **danh sách đầy đủ** sau khi đổi (đơn mới thay hẳn đơn cũ); nhắc khách cũng tự chỉnh được trên trang |
| Bỏ/hủy đơn nháp | Hướng dẫn khách bấm nút "Bỏ đơn nháp" trên trang (có hỏi xác nhận); đơn nháp chưa lưu ở hệ thống, không giữ hàng. **Không** gọi tool nào |

## 4. Những câu cấm nói

- "Đã đặt hàng", "đơn đã được tạo", "đã thanh toán": đơn chỉ được tạo khi khách bấm xác nhận.
- Nhắc lại SĐT, địa chỉ của khách trong câu trả lời (Louis cũng không được nhận các giá trị này từ tool khi `ready=true`).
- "Đã thêm vào giỏ": Louis không thao tác giỏ hàng.

## 5. Quy tắc cho chip gợi ý

Cuối mỗi câu trả lời, Louis viết `[[GOI_Y: câu 1 | câu 2 | câu 3]]`; server tách ra thành nút bấm. Khách bấm thì câu đó được **gửi lại cho Louis**, nên:

- Mỗi gợi ý phải là việc Louis làm được ngay: hỏi sản phẩm/phiên bản/giá/còn hàng, lên đơn nháp hoặc đổi món/số lượng, nghe hát.
- **Cấm** gợi ý: thêm vào giỏ hàng, hủy đơn nháp, hủy đơn, thanh toán, xem đơn hàng, theo dõi giao hàng, áp mã giảm giá, đổi trả.

Ví dụ trong prompt:

```text
Khi khách đang xem 1 sản phẩm: [[GOI_Y: Lên đơn 1 hộp | Có phiên bản nào khác? | Xem sản phẩm tương tự]]
Khi đơn nháp vừa mở:           [[GOI_Y: Đổi thành 2 hộp | Thêm một sản phẩm khác | Có phiên bản nhỏ hơn không?]]
```

Lý do siết: trong một lần chạy thử thật, Louis gợi ý chip "Hủy đơn nháp này", là việc Louis không làm được. Ví dụ mẫu cũ trong prompt còn có "Thêm vào giỏ hàng", khiến AI bắt chước. Cả hai đã được sửa.

## 5b. Đoạn prompt động khi có đơn nháp đang mở

Khi FE gửi kèm `checkout_draft`, `GeminiPrompt::checkoutDraftContext()` nối thêm vào cuối system prompt **của riêng lượt đó** danh sách món đang mở (SKU, tên lấy từ DB, số lượng khách đã chỉnh) và các quy tắc:

| Quy tắc | Lý do |
| --- | --- |
| Danh sách này là trạng thái mới nhất, dùng làm gốc thay cho lịch sử chat | Lịch sử chat không có chỉnh sửa trên trang |
| Mua thêm món khác: giữ món cũ, thêm món mới, trừ khi khách nói rõ bỏ/thay | Khách đang xây dần một đơn |
| Bỏ một món: gọi tool với danh sách còn lại; bỏ hết: không gọi tool | Không có tool hủy đơn nháp |
| Thông tin người nhận do website tự lo (FE, không đi qua AI) | Không có tham số trong tool; không hỏi lại trong chat |
| Chỉ hỏi thông tin thì không gọi tool | Có đơn nháp không có nghĩa là mọi câu đều là lệnh mua |

Đoạn này không được lưu vào lịch sử chat. Nội dung đầy đủ: [11-dong-bo-don-nhap.md, mục 6.3](11-dong-bo-don-nhap.md).

## 5c. Danh mục và sản phẩm sinh tự động từ DB

Mỗi lượt chat, `ChatController` gọi `Product::getCatalogOverview()` rồi nối `GeminiPrompt::catalogContext()` vào cuối system prompt:

```text
Danh mục và sản phẩm cửa hàng ĐANG kinh doanh (hệ thống cập nhật tự động mỗi lượt, chỉ có tên, không có giá):
- Gel, Sáp vuốt tóc: Gel Vuốt Tóc Got2b Glued Spiking Wax; Sáp Vuốt Tóc Hanz de Fuko Claymation; ...
- Pomade: Pomade Reuzel Blue Strong Hold; ...
```

| Quy tắc | Lý do |
| --- | --- |
| Chỉ bán các danh mục, sản phẩm trong danh sách | Louis không bịa sản phẩm, không gợi ý loại cửa hàng không bán |
| Khách hỏi loại không có: nói chưa kinh doanh, giới thiệu danh mục đang có | Trả lời rõ ràng thay vì gọi tool tìm vô ích |
| Giá, phiên bản, tồn kho vẫn phải gọi `search_product` | Danh sách chỉ có tên, số liệu phải lấy mới nhất |

- Chỉ tính sản phẩm còn ít nhất một phiên bản đang bán; tối đa 30 tên mỗi danh mục để prompt không quá dài.
- Admin thêm/bớt danh mục hay sản phẩm thì Louis biết ngay ở lượt sau, không phải sửa prompt bằng tay.
- Đọc DB lỗi thì bỏ qua đoạn này (ghi log), lượt chat vẫn chạy với prompt gốc.
- Đoạn này nằm trong system prompt nên không lưu vào `ai_messages`.

## 6. Prompt không phải lớp bảo mật

Prompt chỉ giúp AI **cư xử đúng**. Mọi giới hạn quan trọng đều được **code** kiểm tra lại, dù AI làm sai:

| Nếu AI làm sai | Code chặn ở đâu |
| --- | --- |
| Gửi SKU bịa | Tool trả `problems`, không mở trang |
| Gửi số lượng 1000 | `readItems` từ chối |
| Hỏi rồi cố truyền SĐT/địa chỉ vào tool | Tool không có tham số này, bỏ qua |
| Cố lên đơn cho người khác | Tool chỉ dùng `user_id` từ JWT |
| Nói "đã đặt hàng" | Không có đơn nào được tạo; khách vẫn phải bấm xác nhận |
| Gọi tool khi khách chỉ hỏi giá | Chỉ mở trang xem trước, không tạo đơn |
