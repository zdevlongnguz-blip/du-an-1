# 05. Kiểm duyệt bằng AI (chốt chặn)

Chi tiết về 2 model, endpoint Colab và 26 câu thử model nằm ở [../comment-guard/README.md](../comment-guard/README.md). File này chỉ mô tả **cách API bình luận dùng kết quả AI**.

## 1. Hai model

| # | Model | Nhiệm vụ | Nhãn |
| --- | --- | --- | --- |
| 1 | `visolex/visobert-hsd` | Kiểm duyệt: phát hiện ngôn từ xúc phạm hoặc thù ghét (HSD = Hate Speech Detection) | `CLEAN` / `OFFENSIVE` / `HATE` |
| 2 | `5CD-AI/Vietnamese-Sentiment-visobert` | Gắn nhãn cảm xúc của bình luận | `POS` (tích cực) / `NEG` (tiêu cực) / `NEU` (trung tính) |

Bình luận chạy qua model 1 trước. Chỉ khi model 1 cho qua (`CLEAN`) thì mới chạy model 2.

Colab trả về `status` (`VISIBLE`/`HIDDEN`), `reason` (nhãn model 1), `toxic_prob`, `needs_review`, `sentiment`, `sentiment_score`. Ngưỡng chặn Colab đặt là `toxic_prob ≥ 0.7`.

## 2. Bảng quyết định

Code: `CommentController::moderate()`.

| Kết quả AI | `status` lưu | 3 nhãn | `moderation` |
| --- | --- | --- | --- |
| `HIDDEN` | **Không lưu**, trả 422 | – | `blocked` |
| `VISIBLE` + `needs_review = true` | 0 | Lưu | `pending` |
| `VISIBLE` + `needs_review = false` | **1** | Lưu | `approved` |
| Colab/service lỗi | 0 | `NULL` | `pending` |
| `COMMENT_GUARD_ENABLED` tắt | 0 | `NULL` | `pending` |

Nguyên tắc:
- **Câu bị chặn không được lưu** (quyết định ngày 22/11/2026, thay cho cách cũ "lưu với status 0"). Lý do: FE giữ nguyên ô nhập để khách sửa rồi gửi lại; nếu lưu thì mỗi lần thử để lại một dòng rác. Khi sửa bình luận mà nội dung mới bị chặn, bình luận cũ giữ nguyên. Nội dung, nhãn và `toxic_prob` của câu bị chặn vẫn có trong log API (`Ghi chú: Kiểm duyệt AI: blocked ...`). DB có thể còn vài dòng bị chặn từ trước ngày đổi.
- **Các trường hợp khác luôn được lưu.** AI chỉ quyết định hiện ngay hay chờ duyệt.
- **Chỉ dựa vào `status` và `needs_review`** của AI, không liệt kê cứng từng nhãn (`OFFENSIVE`, `HATE`...), vì Colab có thể thêm nhãn mới.
- **AI lỗi thì không chặn khách.** Bình luận vẫn được lưu ở trạng thái chờ duyệt, giống cách hệ thống chạy trước khi có AI.
- **Không tự hiện khi không có AI.** Khi AI lỗi hoặc tắt, không có gì chặn câu chửi, nên để admin duyệt tay.

## 3. "Lỗi" gồm những gì

`CommentGuard` ném `RuntimeException` khi:
- `COMMENT_GUARD_URL` trống.
- Không kết nối được (Colab tắt, sai URL, tunnel hết hạn).
- Quá `COMMENT_GUARD_TIMEOUT` giây.
- Colab trả HTTP khác 200.
- Dữ liệu trả về không phải JSON, hoặc thiếu `status`/`toxic_prob`.
- Nhãn dài hơn cột DB: `reason` > 20 ký tự hoặc `sentiment` > 10 ký tự (lưu vào sẽ lỗi "Data too long").

Không kết nối được thì báo lỗi sau tối đa 3 giây (`CONNECT_TIMEOUT_SECONDS`), không chờ đủ `COMMENT_GUARD_TIMEOUT`.

`CommentController::moderate()` bắt mọi `Throwable` (cả `Exception` lẫn `Error` của PHP, ví dụ thiếu extension curl), ghi log `[CommentGuard] Kiểm duyệt lỗi, chuyển bình luận sang chờ duyệt: <chi tiết>`, rồi trả `pending`. Khách chỉ thấy message chờ duyệt, không thấy lỗi kỹ thuật, URL hay key.

## 4. Biến bật/tắt `COMMENT_GUARD_ENABLED`

```text
COMMENT_GUARD_ENABLED=0   # tắt
COMMENT_GUARD_ENABLED=1   # bật
```

| Giá trị trong `BE/.env` | Kết quả |
| --- | --- |
| `1`, `true`, `on`, `yes` | Bật |
| `0`, `false`, `off`, `no` | Tắt |
| Để trống, không khai báo, giá trị lạ (ví dụ `abc`) | **Bật** (mặc định) |

- Khi tắt: không gọi Colab, bình luận lưu ngay với `status = 0`, nhãn `NULL`. Dùng khi Colab tắt lâu, để khách không phải chờ timeout ở mỗi lần gửi.
- Đổi giá trị là có hiệu lực từ request tiếp theo, không cần khởi động lại Apache (`config.php` đọc `.env` ở mỗi request).
- Mặc định bật để quên khai báo biến **không** vô tình tắt kiểm duyệt.

## 5. Chống lách luật

| Cách lách | Có chặn được không |
| --- | --- |
| Gọi thẳng `api-comment-create` bằng Postman, bỏ qua FE | Có: kiểm duyệt nằm ở server |
| Đăng câu sạch cho được duyệt, rồi sửa thành câu chửi | Có: sửa nội dung là chấm lại, câu chửi bị từ chối và bình luận cũ giữ nguyên |
| Viết tắt, không dấu, chèn ký tự (`đm`, `vcl`, `dit me`, `c.ặ.c`) | Phần lớn có (theo kết quả test) |
| Chửi bằng tiếng Anh (`fuck this shop`) | Một phần: AI không chặn nhưng gắn `needs_review` nên vẫn phải chờ admin |
| Thử nhiều câu để dò ngưỡng | Khó: response không trả nhãn hay điểm |
| Spam, quảng cáo (`Inbox zalo 09xx...`) | **Không**: model không có nhiệm vụ này |

## 6. Điểm yếu của model cần biết

Từ kết quả test (chi tiết ở [../comment-guard/README.md](../comment-guard/README.md) mục 6):
- Chê gay gắt nhưng không chửi ("Hàng như rác, phí tiền") bị chặn nhầm.
- Chê lịch sự ("Hàng tệ quá, thất vọng", "Giao hàng chậm, đóng gói cẩu thả") bị gắn `needs_review` nên phải chờ duyệt.
- Model 2 không hiểu mỉa mai, và có câu trung tính bị gắn tích cực.

Những trường hợp này đều rơi vào `status = 0`, nên admin vẫn duyệt lại được. Không có trường hợp nào câu chửi tục tiếng Việt được tự hiện trong các lần test.
