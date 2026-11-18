# Trạng thái dự án

Nơi ghi những việc **chưa xong hẳn**: ca kiểm thử chưa chạy được, lỗi đã biết chưa sửa, việc cần làm tiếp. Xong việc nào thì đánh dấu `[x]`, ghi ngày và người kiểm tra. Kèm theo các **mốc `dev` ổn định** để quay về khi cần.

Cập nhật lần cuối: 18/11/2026.

## Mốc `dev` ổn định (dùng khi cần roll back)

Admin Long chọn 2 trạng thái `dev` dưới đây là **tốt nhất tuần 3**. Ở cả hai mốc, 2 trang đặt hàng (tay và AI) đã được kiểm thử trên trình duyệt và chạy đúng. Nếu sau này gộp nhầm làm hỏng `dev`, quay về một trong hai mốc này.

| Mốc | Commit merge | Tag git | `dev` lúc đó có gì |
| --- | --- | --- | --- |
| Sau PR #71 | `b3816ed` | `dev-good-w3-pr71` | Sửa đơn treo: tra lại kho/giá, 409 có thông báo rõ, trả kho đơn SePay quá hạn (#69). Tool `cancel_order_draft` cho Louis hủy đơn nháp, nút "Xem đơn nháp" trong khung chat (#71). **Chưa có** `StateBlock`. |
| Sau PR #73 (**mới nhất, ưu tiên**) | `6e43c68` | `dev-good-w3-pr73` | Như mốc trên, thêm `StateBlock` cho Danh sách đơn, Chi tiết đơn, Giỏ hàng (#72) và quy tắc FE mục 2, 13: bắt buộc dùng `StateBlock`, cấm sửa 2 trang đặt hàng (#73). |

Tag git chỉ có trên GitHub khi đã được push. Nếu chưa thấy tag, dùng mã commit ở cột "Commit merge" (luôn còn trong lịch sử).

### Cách roll back an toàn

`dev` có ruleset bắt buộc gộp qua PR: **không** `git reset` hay force-push để đè `dev`. Luôn hỏi admin trước khi roll back.

1. Tìm PR gây lỗi: so `dev` hiện tại với mốc tốt.
   ```bash
   git fetch origin
   git log --oneline 6e43c68..origin/dev          # các commit được gộp sau mốc tốt
   git diff --stat 6e43c68 origin/dev            # các file đã đổi so với mốc tốt
   ```
2. Tạo nhánh mới từ `dev`:
   ```bash
   git switch -c fix/rollback-<mô-tả> --no-track origin/dev
   ```
3. Chọn một trong hai cách:
   - **Hoàn tác cả một PR bị gộp nhầm** (tạo commit đảo ngược, lịch sử cũ giữ nguyên):
     ```bash
     git revert -m 1 <commit merge của PR lỗi>
     ```
   - **Chỉ lấy lại vài file** từ mốc tốt:
     ```bash
     git checkout 6e43c68 -- FE/src/views/payment/CheckoutView.vue
     ```
4. Build và kiểm thử lại trên trình duyệt (nhất là 2 trang đặt hàng), rồi `git push -u origin <nhánh>` và tạo PR vào `dev`.

## Việc cần kiểm thử

### [ ] Đơn SePay quá hạn được trả hàng về kho trước khi đọc tồn kho

- **Nhánh/PR:** `fix/ai-draft-stock-sync`
- **Tài liệu:** [don-hang/dong-bo-ton-kho-va-gia.md](don-hang/dong-bo-ton-kho-va-gia.md), mục 2
- **Vì sao chưa test:** DB local chưa có đơn SePay nào chưa thanh toán và quá `SEPAY_PAYMENT_TIMEOUT_MINUTES` (30 phút). Các ca khác của PR đã test trên trình duyệt.

**Cần chứng minh:** đơn SePay chưa thanh toán quá 30 phút đang giữ hàng. Khi khách xem sản phẩm, xem giỏ, mở đơn nháp AI hoặc chat với Louis, server hủy đơn đó và trả hàng về kho **trước khi** đọc tồn kho, nên khách không thấy "hết hàng" sai.

**Chuẩn bị (chỉ trên DB local):**

1. Chọn một phiên bản còn ít hàng, ghi lại `stock` ban đầu (ví dụ phiên bản `CLAYMATION`):
   ```sql
   SELECT id, sku, stock FROM product_variants WHERE sku = 'CLAYMATION';
   ```
2. Đăng nhập tài khoản test, thêm phiên bản đó vào giỏ với số lượng **bằng toàn bộ tồn kho**, vào `/checkout`, chọn **Thanh toán online**, bấm đặt. Khi sang trang SePay thì **không thanh toán**, đóng tab. Kho của phiên bản giờ là 0.
3. Ghi lại mã đơn vừa tạo, rồi lùi giờ tạo đơn để khỏi chờ 30 phút:
   ```sql
   UPDATE orders SET created_at = NOW() - INTERVAL 31 MINUTE
   WHERE id = <mã đơn> AND payment_method = 'sepay' AND payment_status = 'unpaid';
   ```

**Các ca (làm lại bước chuẩn bị trước mỗi ca, vì mỗi ca sẽ trả hàng của đơn đó):**

| # | Thao tác ngay sau bước chuẩn bị | Kết quả đúng |
| --- | --- | --- |
| 1 | Mở trang chi tiết sản phẩm (`api-product-detail`) | Hiện tồn kho ban đầu, không phải "Hết hàng" |
| 2 | Mở trang danh sách sản phẩm (`api-products`) | Sản phẩm không bị gắn "Hết hàng" |
| 3 | Mở `/cart` khi giỏ có phiên bản đó (`api-cart`) | Không có nhãn "Hết hàng" hay "Chỉ còn N" |
| 4 | Thêm phiên bản đó vào giỏ (`api-cart-add`) | Thêm được, không báo "vượt tồn kho" |
| 5 | Mở `/ai-checkout` với đơn nháp có phiên bản đó (`api-order-draft-items`) | Không bị đánh dấu "Hết hàng hoặc ngừng bán", nút xác nhận không bị khóa |
| 6 | Nhắn Louis "lấy cho anh 1 hũ <tên>" (tool `create_order_draft`) | Louis lên đơn được, không báo "Hết hàng" |
| 7 | Hỏi Louis "<tên> còn hàng không" (tool `search_product`) | Louis báo còn hàng |

**Sau mỗi ca, kiểm tra DB:**

```sql
SELECT id, order_status, payment_status FROM orders WHERE id = <mã đơn>;
-- Đúng: order_status = 'cancelled', payment_status = 'unpaid'
SELECT stock FROM product_variants WHERE sku = 'CLAYMATION';
-- Đúng: bằng stock ban đầu ở bước 1
```

Xem thêm log API trong `BE/storage/logs/api-<ngày>.log` để chắc request của ca đó trả đúng tồn kho.

**Ca đối chứng:** đơn SePay **chưa** quá 30 phút (bỏ bước 3) thì vẫn giữ hàng. Trang báo hết hàng và đơn vẫn `pending`, như vậy là đúng.

**Không làm:** không chạy câu `UPDATE` trên DB dùng chung/production; không sửa giờ của đơn không phải đơn test.

### [ ] Louis hủy đơn nháp bằng tool `cancel_order_draft` (chat với Gemini thật)

- **Nhánh/PR:** `feat/ai-cancel-draft` (tạo từ `fix/ai-draft-stock-sync`, merge sau PR đó)
- **Vì sao chưa test:** ngày 18/11/2026 cả 6 key Gemini đều hết hạn mức (HTTP 429). Đã test riêng từng phần: tool chạy bằng CLI; FE nhận lệnh `checkout-draft-clear` bằng response `api-ai-chat` giả lập (đạt cả khi đang ở `/ai-checkout` và ở trang chủ). **Chưa test được việc Gemini có chọn đúng tool theo prompt hay không.**

| # | Nhắn Louis (đang có đơn nháp) | Kết quả đúng |
| --- | --- | --- |
| 1 | "hủy đơn nháp giúp anh" | Louis gọi `cancel_order_draft`, báo đã bỏ; trang chuyển `/ai-checkout` màn "Chưa có đơn nháp"; dải "Xem đơn nháp" trong chat biến mất |
| 2 | "thôi" / "để sau" | Louis **hỏi lại** muốn bỏ hẳn hay giữ, chưa gọi tool, đơn nháp còn nguyên |
| 3 | "bỏ món X đi" (đơn có 2 món) | Louis gọi `create_order_draft` lệnh `remove`, KHÔNG gọi `cancel_order_draft` |
| 4 | "hủy đơn hàng #63" | Louis không gọi tool, hướng dẫn vào trang chi tiết đơn để hủy |
| 5 | "hủy đơn nháp" khi **không** có đơn nháp | Louis báo chưa có đơn nháp nào |

Xem cột "tool_calls" trong log `BE/storage/logs/api-<ngày>.log` (response của `api-ai-chat`) để biết Louis đã gọi tool nào.
