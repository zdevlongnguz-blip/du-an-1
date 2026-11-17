# Task Tuần 3 - Chạy Thử Web Giai Đoạn 1 (ngrok)

Tuần: 3
Ngày giao task này: 17/11/2026
Người phụ trách: Việt Hoàng.
Phạm vi: chỉ kiểm thử và đọc log. Không sửa bất kỳ file nào trong `BE/` hoặc `FE/`, không đổi API contract, schema hoặc dữ liệu.

## Mục tiêu

Thu thập phản hồi từ người dùng thật và dữ liệu từ log API để admin đánh giá web giai đoạn 1 trước khi chốt.

## Điều kiện bắt đầu

- Môi trường demo đang chạy qua link ngrok. Ghi link và thời điểm kiểm tra vào báo cáo.
- Chạy file SQL đầy đủ theo đúng quy trình của team trước khi test. Thiếu file hoặc lỗi thì dừng và báo admin.
- Không test trên production. Không dùng tài khoản của người khác khi chưa được đồng ý.

## Công việc 1: Mời ít nhất 3 người trải nghiệm web

- Mời tối thiểu 3 người không phải thành viên dự án (bạn bè, người thân, bạn cùng lớp). Người đã làm dự án không tính.
- Gửi cho mỗi người: link ngrok, tài khoản test (nếu cần đăng nhập), và checklist bên dưới.
- Yêu cầu mỗi người tự thực hiện các luồng, không hướng dẫn từng bước. Ghi lại bất kỳ chỗ nào họ gặp khó.

### Checklist luồng cho người trải nghiệm

1. Mở trang chủ, xem banner và top sản phẩm.
2. Tìm kiếm và xem danh sách sản phẩm (catalog), chuyển trang.
3. Mở chi tiết một sản phẩm, xem bình luận.
4. Thêm sản phẩm vào giỏ, đổi số lượng, xóa khỏi giỏ.
5. Đăng nhập, sửa hồ sơ, đổi mật khẩu.
6. Đặt hàng (COD hoặc SePay nếu có), xem danh sách đơn và chi tiết đơn.
7. Thử trợ lý AI (chat và lên đơn nhanh) nếu có thời gian.
8. Thử làm sai cố ý: để trống form, nhập sai định dạng, bấm nhiều lần liên tiếp.

### Mẫu phản hồi (mỗi người một mục)

```
Người trải nghiệm: (tên hoặc biệt danh, không ghi số điện thoại/email)
Thiết bị và trình duyệt:
Luồng đã thử (số thứ tự trong checklist):
Việc làm được dễ dàng:
Việc gây khó khăn hoặc bị lỗi (ghi rõ bước làm, thấy gì, mong đợi gì):
Ảnh chụp màn hình (nếu có):
Đánh giá chung (1-5):
Góp ý khác:
```

## Công việc 2: Đọc log API

- File log nằm ở `BE/storage/logs/api-YYYY-MM-DD.log`, mỗi ngày một file. Chỉ đọc, không sửa, không xóa.
- Lấy log trong khoảng thời gian chạy thử. Ghi rõ ngày và giờ bắt đầu, kết thúc.
- Đếm và tổng hợp:
  - Tổng số request, số request theo endpoint.
  - Số request thành công (2xx), cảnh báo (4xx), lỗi máy chủ (5xx).
  - Các endpoint lỗi 5xx hoặc gặp nhiều 4xx nhất. Ghi kèm một ví dụ dòng log (đã che thông tin cá nhân).
  - Các request chậm (từ 1000 ms trở lên) và endpoint tương ứng.
  - Các lỗi lặp lại và có khớp với phản hồi của người trải nghiệm không.
- Che thông tin nhạy cảm trước khi đưa vào báo cáo: token, mật khẩu, số điện thoại, địa chỉ, email, nội dung thanh toán. Không copy nguyên văn các trường đó.
- Không tự kết luận nguyên nhân lỗi BE. Nếu nghi ngờ, ghi bằng chứng (request/response) và báo owner BE.

## Công việc 3: Báo cáo cho admin

Ghi báo cáo vào `vibecoder/week-3/mission-completed/chay-thu-web-giai-doan-1.md` (tạo file mới) với cấu trúc:

1. **Thông tin chung**: link ngrok, thời gian chạy thử, số người tham gia, người phụ trách.
2. **Tổng quan log**: số liệu từ công việc 2 kèm bảng tóm tắt.
3. **Phản hồi người dùng**: tóm tắt các mẫu phản hồi, gom theo luồng. Ghi số người gặp cùng một vấn đề.
4. **Lỗi và vấn đề ưu tiên**: sắp xếp theo mức độ ảnh hưởng (chặn đặt hàng, sai dữ liệu, khó dùng, giao diện). Mỗi mục có bằng chứng.
5. **Việc chưa làm được**: ghi rõ những gì chưa test được và lý do.
6. **Đề xuất**: việc nên làm trước khi sang giai đoạn tiếp theo.

Chỉ ghi điều đã kiểm chứng. Không ghi "pass" nếu chưa chạy. Không ghi tên thật hay thông tin cá nhân của người trải nghiệm nếu họ không đồng ý.

## Tiêu chí nghiệm thu

- Có ít nhất 3 người trải nghiệm, mỗi người có một mẫu phản hồi đầy đủ.
- Đã đọc log của khoảng thời gian chạy thử; số liệu trong báo cáo khớp với file log.
- Thông tin nhạy cảm đã được che.
- Báo cáo có đủ 6 mục và được gửi admin.
