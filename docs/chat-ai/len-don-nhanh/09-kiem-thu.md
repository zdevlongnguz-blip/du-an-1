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

Xem bảng S1-S13 ở [11-dong-bo-don-nhap.md, mục 9](11-dong-bo-don-nhap.md): kiểm tra định dạng `checkout_draft`, loại SKU dạng câu lệnh khỏi prompt, người nhận không đi qua luồng AI, sửa đơn bằng lệnh qua chat.

### 1.7. Sửa đơn nháp bằng lệnh qua chat (ngày 2026-11-17)

Lỗi gốc: mua 1 hộp → bấm + lên 5 → nhắn "thêm 5 hộp nữa" → Louis lên **6** (lấy số 1 cũ trong lịch sử + 5). Cách sửa: thiết kế lệnh, [11-dong-bo-don-nhap.md, mục 6.5-6.6](11-dong-bo-don-nhap.md).

Script PHP gọi thẳng `readOps` + `applyOps` (không cần DB, không tốn request Gemini). Trang có Clay 5, Vinh 3, trừ dòng Q1:

| # | Lệnh | Kết quả |
| --- | --- | --- |
| Q1 | Trang rỗng: `add Clay 2`, `add Got2b 1` | Clay 2, Got2b 1 |
| Q2 | `add clay 5` (SKU viết thường) | Clay 10, Vinh 3 |
| Q3 | `subtract Clay 2` | Clay 3, Vinh 3 |
| Q4 | `set Vinh 1` | Clay 5, Vinh 1 |
| Q5 | `remove Vinh` | Clay 5 |
| Q6 | `remove Vinh`, `add Got2b 1`, `set Clay 2` | Clay 2, Got2b 1 |
| Q7 | `remove Clay`, `add Clay-56g 5` (đổi phiên bản) | Vinh 3, Clay-56g 5 |
| Q8 | `add Got2b` không có số / số `2.0` | Got2b 1 / Got2b 2 |
| Q9 | `remove XYZ` (không có trong đơn) | Giữ nguyên, kèm `notes` cho AI |
| Q10 | `subtract Clay 7` | Lỗi cho AI: đơn chỉ có 5, hỏi lại khách |
| Q11 | `subtract Got2b 1` (không có) | Lỗi cho AI: không có trong đơn |
| Q12 | `remove` cả hai món | Lỗi cho AI: hướng dẫn bấm "Bỏ đơn nháp" |
| Q13 | `add Clay 95` | Lỗi cho AI: tổng 100 vượt 99 |
| Q14 | `op: "double"` / `set` thiếu số / gửi dạng cũ `items` | Đều lỗi rõ cho AI |
| Q15 | `subtract Clay 2` khi trang có Clay 2, Layrite 1 | Bỏ Clay: Layrite 1 (bớt đúng bằng số đang có = bỏ món) |
| Q16 | Gọi tool 2 lần trong một lượt (trang Clay 2): `add Layrite 1` rồi `add Suavecito 1` | Lần 2 ra Clay 2, Layrite 1, Suavecito 1 (áp tiếp lên đơn làm việc) |
| Q17 | Lần 1 `add Clay 90` (kho 17, lỗi), lần 2 `add Layrite 1` | Lần 2 ra Clay 2, Layrite 1 (lần lỗi không được ghi nhận) |
| Q18 | Tool mới (lượt chat mới) | Không mang đơn làm việc của lượt trước |
| Q19 | `checkout_draft_dropped = 1`, gọi tool 2 lần | Lần 1 có `notes` báo 1 món ngừng bán; lần 2 không lặp lại |
| Q20 | `ChatController` nhận 3 món, trong đó 1 SKU không có và 1 SKU dạng câu lệnh | Còn 1 món, `checkout_draft_dropped = 2`; SKU dạng câu lệnh không lọt vào đâu |

Q15-Q19 chạy `execute()` thật với DB local (chỉ đọc). Q20 gọi `readCheckoutDraft` + `describeDraftItems` của `ChatController`.

Chrome thật + Gemini thật (Puppeteer tự bấm, tài khoản test):

| # | Bước | AI gửi | Đơn mới |
| --- | --- | --- | --- |
| C1 | "Tôi muốn mua 1 hộp sáp Hanz de Fuko phiên bản Hũ 113 gam" | `set Hanz-113g 1` | Hanz 1 |
| C2 | Bấm + trên trang đến 5 | — | Hanz 5 |
| C3 | "thêm 5 hộp nữa" | `add 5` | Hanz **10** |
| C4 | "bớt 3 hộp" | `subtract 3` | Hanz **7** |
| C5 | "lấy thêm 1 hộp Pomade Layrite Original Hold" | `add Layrite 1` | Hanz (giữ nguyên) + Layrite 1 |
| C6 | "Layrite lấy 2 hộp thôi" | `set Layrite 2` | Hanz (giữ nguyên) + Layrite 2 |
| C7 | "bỏ Layrite đi" | `remove Layrite` | Hanz (giữ nguyên) |
| C8 | "thêm 2 hộp Pomade Suavecito Original Hold, còn sáp Hanz để 3 hộp thôi" | `add Suavecito 2`, `set Hanz 3` | Hanz 3, Suavecito 2 |

C1-C4 và C5-C8 chạy ở hai lượt khác nhau, vì có lượt Gemini báo quá tải (502) đúng ở bước C3, C4. Ở C5-C8, Hanz đang là 5 do lượt đó C3, C4 lỗi, và mọi bước đều giữ đúng số 5 của món không được nhắc tới.

Chưa đạt, ngoài phạm vi: "đổi sáp Hanz sang phiên bản hũ 56 gam". AI gọi `search_product` với từ khóa có tên phiên bản, tìm kiếm không ra, AI trả lời nhầm là không có. Lỗi nằm ở cách tìm sản phẩm; cơ chế đổi phiên bản đã đúng ở Q7.

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
