# 09. Kiểm thử

Dự án chưa có test tự động. Phần này ghi lại những gì **đã chạy thật** và danh sách test tay cần làm.

## 1. Đã kiểm tra (ngày 2026-11-16, DB local)

### 1.1. Cú pháp và build

| Lệnh | Kết quả |
| --- | --- |
| `php -l` cho `Order.php`, `Product.php`, `OrderController.php`, `ChatController.php`, `routes/index.php`, `GeminiToolCreateOrderDraft.php`, `GeminiPrompt.php` | Không lỗi cú pháp |
| `npm run build` trong `FE/` | Thành công |

### 1.2. Tool `create_order_draft` (gọi trực tiếp bằng script PHP)

| # | Đầu vào | Kết quả |
| --- | --- | --- |
| T1 | 2 SKU hợp lệ (một SKU viết thường) | `ready=true`, giá đúng, có `original_price` khi giảm giá, `total_amount = 1150000` |
| T2 | Kết quả có `recipient` không? | Không: `_action.draft` chỉ có `items`, `total_amount` |
| T3 | Món sai (SKU không tồn tại) | `ready=false`, `problems`, không có `_action` |
| T4 | SKU không tồn tại + số lượng 99 vượt kho 46 | `ready=false`, `problems` đủ 2 món, lý do đúng |
| T5 | `items` rỗng, `items` là chuỗi, `quantity = 0`, `quantity = 2.5` | Đều ném `InvalidArgumentException` |
| T6 | Thiếu `user_id` trong context | Ném `RuntimeException` |
| T7 | AI cố truyền `recipient_phone`, `shipping_address` | Bị bỏ qua (schema không có) |
| T8 | Schema tool | Chỉ còn `items` |

### 1.3. `Order::checkoutItems` (chỉ các nhánh rollback)

| # | Đầu vào | Kết quả |
| --- | --- | --- |
| M1 | `expected_total = "1.00"` | `price_changed`, `total_amount = "1150000.00"` |
| M2 | SKU không tồn tại | `stock_unavailable` |
| M3 | Số lượng = tồn kho + 1 | `stock_unavailable` |
| M4 | `userId = 0` | `invalid_user` |
| — | Tổng tồn kho / số đơn trước và sau | 387/387, 49/49: **không ghi gì** |

### 1.4. HTTP

| # | Request | Kết quả |
| --- | --- | --- |
| H1 | `POST api-order-checkout-items` không có token | `Unauthorized: thiếu Bearer token` |

### 1.5. Chạy thật với Gemini

Trong một lần chat thật, Louis đã gọi `create_order_draft` và trả lời đúng: nói là **đơn nháp**, nhắc bấm "Xác nhận đặt hàng". Phát hiện chip gợi ý sai "Hủy đơn nháp này" → đã siết prompt (xem [07-prompt.md](07-prompt.md)).

### 1.6. Đồng bộ đơn nháp

Xem bảng S1-S11 ở [11-dong-bo-don-nhap.md, mục 9](11-dong-bo-don-nhap.md): kiểm tra định dạng `checkout_draft`, loại SKU dạng câu lệnh khỏi prompt, người nhận không đi qua luồng AI.

## 2. Chưa kiểm tra, cần test tay

| # | Bước | Mong đợi |
| --- | --- | --- |
| U1 | Chat "lấy cho anh 2 hũ Claymation" | Trang `/ai-checkout` mở, hiệu ứng chạy đủ 3 màn |
| U2 | Tài khoản thiếu SĐT trong hồ sơ, chat mua hàng | Louis **không hỏi** SĐT; trang mở, hiệu ứng bỏ qua ô SĐT, ô viền đồng nét đứt + "Hồ sơ chưa có số điện thoại...", dòng trạng thái màu đồng; điền xong mới xác nhận được |
| U16 | F5 trên `/ai-checkout` sau khi khách đã sửa địa chỉ | Đơn và địa chỉ đã sửa còn nguyên; không gọi lại `api-profile` |
| U17 | Đóng tab rồi mở lại | Đơn nháp mất (sessionStorage) |
| U18 | Bấm "Bỏ đơn nháp", chọn Hủy ở hộp thoại | Đơn nháp giữ nguyên |
| U19 | Bấm "Bỏ đơn nháp", chọn OK | Toast "Đã bỏ đơn nháp.", về trang sản phẩm; mở lại `/ai-checkout` thấy "Chưa có đơn nháp" |
| U20 | Chọn SePay, đơn tạo xong nhưng chuyển cổng lỗi | Nút "Bỏ đơn nháp" không còn hiện; nút chính là "Thanh toán lại" |
| U3 | Tăng số lượng tới tồn kho | Nút + bị khóa |
| U4 | Xóa trống tên, bấm xác nhận | Lỗi dưới ô tên, không gọi API |
| U5 | Xác nhận COD | Đơn mới trong "Đơn hàng của tôi", tồn kho giảm đúng, giỏ hàng **không đổi** |
| U6 | Xác nhận SePay | Chuyển sang trang SePay |
| U7 | Đổi giá trong admin giữa lúc xem, rồi bấm xác nhận | Khối đỏ "Giá sản phẩm vừa thay đổi. Tổng mới là ..." |
| U8 | Mở `/ai-checkout` khi chưa có đơn nháp | Màn "Chưa có đơn nháp" |
| U9 | Đăng xuất rồi đăng nhập tài khoản khác, mở `/ai-checkout` | Màn "Chưa có đơn nháp" |
| U10 | Đang ở trang lên đơn, nhắn Louis "đổi thành 3 hũ" | Trang diễn lại với đơn mới |
| U11 | Checkout từ **giỏ hàng** như cũ | Vẫn đặt được (kiểm tra `insertOrderRows` sau khi tách) |
| U12 | Chat "Claymation giá bao nhiêu?" | Louis chỉ tra cứu, **không** mở trang lên đơn |
| U13 | Điện thoại (≤ 576px) | Hai cột xếp chồng, khung chat tự thu nhỏ khi mở trang |
| U14 | Có đơn nháp Vinh Râu × 5, bấm − còn 3, sửa địa chỉ, rồi nhắn "thêm 1 hũ Claymation" | Đơn mới: Vinh Râu × 3 + Claymation × 1, giữ địa chỉ đã sửa |
| U15 | Có đơn nháp, sang trang khác, nhắn "bỏ món Vinh Râu" | Đơn mới không còn Vinh Râu |

## 3. Cách thử API an toàn trên DB thật

Gửi `expected_total` sai (ví dụ `1`) để đi nhánh 409 "giá đổi": request đi qua toàn bộ validate, khóa dòng, tính tiền rồi **rollback**, không tạo đơn. Xem ví dụ cURL ở [05-api-xac-nhan.md, mục 6](05-api-xac-nhan.md).

Muốn kiểm tra tool mà không tốn request Gemini: viết script PHP nạp `vendor/autoload.php`, `configs/config.php`, `configs/helper.php`, đăng ký autoload như `BE/index.php`, rồi gọi `(new GeminiToolCreateOrderDraft())->execute($args, ['user_id' => ...])`. Không commit script này vào dự án.
