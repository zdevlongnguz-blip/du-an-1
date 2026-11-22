# 09. Vấn đáp

**Vì sao kiểm duyệt ở BE mà không để FE gọi AI trước?**
Vì FE không phải ranh giới bảo mật. Ai cũng có thể dùng Postman gọi thẳng `api-comment-create`. Đặt kiểm duyệt ở server thì mọi bình luận đều đi qua.

**Bình luận bị chặn có bị xóa không?**
Không lưu. API trả 422, FE giữ nguyên nội dung trong ô nhập để khách sửa lại cho lịch sự rồi gửi tiếp. Nếu lưu thì mỗi lần khách thử lại sẽ để một dòng rác trong DB. Nội dung và nhãn AI vẫn được ghi trong log API để tra lại khi cần.

**Nếu Colab sập thì sao?**
Bình luận vẫn được lưu, ở trạng thái chờ admin duyệt, nhãn `NULL`. Khách không thấy lỗi. Nếu Colab sập lâu, đặt `COMMENT_GUARD_ENABLED=0` để khách không phải chờ timeout.

**Vì sao khi AI lỗi lại không cho hiện luôn?**
Vì lúc đó không có gì chặn câu chửi. An toàn hơn là để admin duyệt, giống cách hệ thống chạy trước khi có AI.

**Vì sao dùng 2 model?**
Hai việc khác nhau: model 1 trả lời "câu này có độc hại không", model 2 trả lời "khách khen hay chê". Câu chê lịch sự vẫn là câu sạch.

**Vì sao câu bị chặn không có nhãn cảm xúc?**
Colab chỉ chạy model 2 khi model 1 cho qua, để tiết kiệm thời gian. Câu chửi thì không cần biết khen hay chê.

**Vì sao lưu 3 cột mà không lưu nguyên JSON của AI?**
Lưu cột riêng thì lọc và thống kê bằng SQL được (ví dụ đếm câu `NEG` của một sản phẩm). Chỉ lưu những gì dùng tới; `sentiment_score` và `needs_review` không cần.

**`NULL` và `CLEAN` khác nhau thế nào?**
`CLEAN` là AI đã chấm và thấy sạch. `NULL` là chưa chấm (bình luận cũ, hoặc AI lỗi/tắt lúc gửi). Tách ra để sau này chấm bù đúng chỗ.

**User có lách được bằng cách đăng câu sạch rồi sửa thành câu chửi không?**
Không. Sửa nội dung thì chấm lại; nội dung mới bị chặn thì bị từ chối và bình luận cũ giữ nguyên.

**Vì sao response không trả điểm AI cho khách?**
Để người cố tình lách không dò được ngưỡng chặn bằng cách thử nhiều câu.

**Admin có duyệt được câu AI đã chặn không?**
Có. AI chỉ lọc tự động, người quyết định cuối. Khi duyệt, nhãn AI vẫn giữ để biết câu đó từng bị chặn.

**Model có sai không?**
Có. Theo test: chặn nhầm câu chê gay gắt ("Hàng như rác, phí tiền"), không chặn được chửi tiếng Anh ("fuck this shop" chỉ bị gắn cờ chờ duyệt), không lọc spam. Vì các câu đáng ngờ đều về `status = 0`, admin vẫn sửa được quyết định.

**Gửi bình luận chậm hơn bao nhiêu?**
Khoảng 0.5–2 giây (chờ Colab chạy trên CPU). Tối đa bằng `COMMENT_GUARD_TIMEOUT` (15 giây) nếu Colab treo.

**Vì sao dùng `VARCHAR` thay vì `ENUM` cho nhãn?**
Colab có thể thêm nhãn mới. Dùng `ENUM` thì mỗi lần thêm nhãn phải viết migration.

**Làm sao chứng minh chức năng chạy đúng?**
[06-kiem-thu.md](06-kiem-thu.md): 61 ca gọi API thật, so mã HTTP và đọc lại DB sau mỗi request, 61/61 PASS.
