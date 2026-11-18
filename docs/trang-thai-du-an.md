# Trạng thái dự án

Nơi ghi những việc **chưa xong hẳn**: ca kiểm thử chưa chạy được, lỗi đã biết chưa sửa, việc cần làm tiếp. Xong việc nào thì đánh dấu `[x]`, ghi ngày và người kiểm tra.

Cập nhật lần cuối: 18/11/2026.

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
