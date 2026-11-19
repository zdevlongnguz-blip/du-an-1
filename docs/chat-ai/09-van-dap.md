# 09. Câu hỏi thường gặp khi bảo vệ

Các câu trả lời ngắn, có thể nói trực tiếp khi vấn đáp. Mỗi câu có một sơ đồ hoặc file tham chiếu để chỉ khi được hỏi sâu.

## Tổng quan

**Q: Chức năng này làm gì?**
A: Khách đã đăng nhập chat với trợ lý AI tên Louis Dé Fule. Louis tra cứu sản phẩm thật của cửa hàng, gợi ý câu hỏi tiếp theo, mở đúng trang sản phẩm hoặc danh sách, và có thể hát một bài. Tham chiếu: [README.md](README.md).

**Q: Tại sao chọn Gemini?**
A: Gemini có function calling miễn phí ở mức thử nghiệm, và REST API dễ gọi bằng cURL trong PHP thuần, không cần thư viện ngoài. Dự án không dùng Composer nên đây là lựa chọn phù hợp.

**Q: AI có tự viết SQL không?**
A: Không. AI chỉ yêu cầu gọi tool với tham số. Tool gọi hàm của Model, Model dùng prepared statement. Tham chiếu: [05-tools.md](05-tools.md).

## Kiến trúc

**Q: Tại sao lưu lịch sử ở server?**
A: Nếu client giữ và gửi lại, client có thể tự chèn kết quả tool giả. Lưu ở server thì kiểm soát được. Đây cũng là nền để sau này tool ghi dữ liệu (như tạo đơn) an toàn hơn. Tham chiếu: [01-kien-truc.md](01-kien-truc.md), mục 4.

**Q: Tại sao cần hai bảng?**
A: `ai_conversations` xác định chủ sở hữu, `ai_messages` lưu từng lượt. Tách như vậy để kiểm tra quyền rõ ràng và truy vấn lịch sử theo thứ tự. Tham chiếu: [03-co-so-du-lieu.md](03-co-so-du-lieu.md).

**Q: Vì sao lưu nguyên văn JSON `parts` thay vì tách cột?**
A: Lượt gọi tool của Gemini có `thoughtSignature`, và kết quả tool có cấu trúc lồng nhau. Gemini cần thấy đúng nguyên văn khi nhận lại lịch sử. Tách cột dễ làm sai.

**Q: Tại sao cuộc hội thoại mới được tạo sau khi AI trả lời xong?**
A: Nếu tạo trước, khi AI lỗi sẽ để lại hội thoại rỗng trong DB. Tạo cùng transaction với các lượt thì lỗi nào cũng rollback sạch.

**Q: Sao không gọi AI thêm một lần để sinh gợi ý?**
A: Tốn thêm request và thời gian. Gợi ý được yêu cầu viết ngay trong câu trả lời, server tách ra. Tham chiếu: [02-luong-nghiep-vu.md](02-luong-nghiep-vu.md), mục 2.7.

## Bảo mật

**Q: Làm sao AI không đưa khách sang trang lạ?**
A: Ba lớp. Một, lệnh điều hướng chỉ do code PHP tạo, AI không ghi URL. Hai, FE chỉ chấp nhận danh sách route và tham số đã khai báo, và điều hướng theo tên route. Ba, trang đích kiểm tra lại dữ liệu, ví dụ tên file video. Tham chiếu: [07-bao-mat.md](07-bao-mat.md), mục 1.

**Q: Làm sao khách không đọc được hội thoại của người khác?**
A: Mọi truy cập kiểm tra `user_id` trong bảng khớp `sub` trong JWT. Không đúng thì trả 404, không lộ việc hội thoại có tồn tại.

**Q: `user_id` có lấy từ client không?**
A: Không. Controller lấy từ token đã xác thực. Tool nhận `user_id` từ `$context` do server đặt.

**Q: Chống SQL injection thế nào?**
A: Mọi truy vấn dùng PDO prepared statement và `bindValue`. Ký tự đặc biệt của LIKE được escape.

**Q: Còn rủi ro gì?**
A: Chưa có rate limit. Mỗi request tốn tiền gọi Google. Đây là việc cần làm đầu tiên nếu đưa vào thực tế. Tham chiếu: [07-bao-mat.md](07-bao-mat.md), mục 2.

## Tool

**Q: Tool làm được gì?**
A: `search_product` tra sản phẩm theo từ khóa, tối đa 5 kết quả. `fule_sing` chọn bài hát trong playlist. Tham chiếu: [05-tools.md](05-tools.md).

**Q: Làm sao thêm tool mới?**
A: Kế thừa `GeminiTool`, viết 4 phương thức, đăng ký trong controller. Nếu tool mở trang thì thêm route vào danh sách cho phép ở FE. Tham chiếu: [08-van-hanh.md](08-van-hanh.md), mục 6.

**Q: `_action` là gì?**
A: Key tạm trong kết quả tool để gửi lệnh điều hướng cho FE. Server tách nó ra trước khi gửi kết quả cho AI, nên AI không thấy.

**Q: Nếu AI gọi tool hai lần thì sao?**
A: Chỉ giữ lệnh điều hướng của lần gọi cuối cùng, vì câu trả lời thường dựa vào lần đó.

**Q: Tối đa bao nhiêu vòng tool?**
A: 5 vòng, cộng câu hỏi gốc thành tối đa 6 lượt gọi API. Vượt quá thì trả 502.

## Giao diện

**Q: Vì sao popup nằm ở `App.vue`?**
A: Để nó tồn tại trên mọi trang và không bị hủy khi chuyển route, nên hội thoại không mất.

**Q: Vì sao state để ở module chứ không trong component?**
A: Component bị hủy khi đổi trang. State ở module thì giữ nguyên.

**Q: Tại sao đĩa nhạc phải bấm nút mới phát?**
A: Trình duyệt chặn tự phát video có tiếng khi chưa có thao tác của người dùng.

## Kết quả và hạn chế

**Q: Đã kiểm thử những gì?**
A: Cú pháp PHP, build FE, lưu và đọc lịch sử trên DB thật, tool với Gemini giả lập, và vài câu với Gemini thật. Chi tiết: [07-bao-mat.md](07-bao-mat.md), mục 3.

**Q: Còn gì chưa làm?**
A: Chưa có API lấy lại lịch sử khi F5, chưa có rate limit, chưa có tool giỏ hàng và đặt hàng, chưa có chính sách dọn hội thoại cũ. Tham chiếu: [07-bao-mat.md](07-bao-mat.md), mục 2.

**Q: Vì sao có lỗi 429?**
A: Gói miễn phí của Gemini chỉ cho 5 request mỗi phút. Một câu hỏi có tool tốn 2 request. Đây là giới hạn của nhà cung cấp, không phải lỗi code. Để giảm lỗi, `GEMINI_API_KEY` nhận nhiều key cách nhau bằng dấu phẩy: `Gemini::callApi()` dùng luân phiên từng key và gặp 429 thì thử ngay key tiếp theo; chỉ khi mọi key đều hết lượt mới báo lỗi.

**Q: Làm sao các request PHP độc lập biết lần trước đã dùng key nào?**
A: Mỗi request PHP chạy xong là mất hết biến. Vì vậy vị trí key kế tiếp được lưu trong một file nhỏ ở thư mục tạm của hệ thống, khóa bằng `flock` khi đọc-ghi để hai request cùng lúc không lấy trùng vị trí. Không ghi được file thì chọn key ngẫu nhiên, chat vẫn chạy.

**Q: Vì sao AI có thể nói sai giá?**
A: Giá lấy từ tool, nhưng AI diễn đạt lại bằng văn bản nên có thể sai chi tiết. Giao diện có dòng chú thích nhắc khách xem trang sản phẩm để biết giá chính xác.

**Q: Tại sao câu trả lời có khi cụt hoặc không đúng quy tắc?**
A: Mô hình ngôn ngữ không hoàn toàn tuân thủ prompt. Đã sửa prompt nhiều lần, và đã kiểm tra với Gemini thật. Kết quả vẫn phụ thuộc mô hình.

## Câu hỏi khó

**Q: Hệ thống có mở rộng được không?**
A: Thêm tool và route không cần sửa kiến trúc. Lượng request bị giới hạn bởi quota Gemini và sẽ cần rate limit trước khi mở rộng. Lịch sử tăng theo số lượt, cần chính sách dọn sau này.

**Q: Vì sao không dùng vector database hay RAG?**
A: Dữ liệu sản phẩm nhỏ và có cấu trúc. Tra SQL theo từ khóa đủ chính xác, và dễ giải thích hơn. RAG phù hợp hơn khi dữ liệu là văn bản dài.

**Q: Dự án có test tự động không?**
A: Chưa. Kiểm thử là script thủ công kiểm tra logic và DB, cùng test tay trên giao diện. Đây là hạn chế cần nêu rõ.
