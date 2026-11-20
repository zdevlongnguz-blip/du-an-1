# Trạng thái dự án

Nơi ghi những việc **chưa xong hẳn**: ca kiểm thử chưa chạy được, lỗi đã biết chưa sửa, việc cần làm tiếp. Xong việc nào thì đánh dấu `[x]`, ghi ngày và người kiểm tra. Kèm theo các **mốc `dev` ổn định** để quay về khi cần.

Cập nhật lần cuối: 20/11/2026 (sau PR #85).

## Thay đổi lớn ngày 19–20/11/2026 (PR #78 → #85)

| PR | Nội dung | Ghi chú |
| --- | --- | --- |
| #78 | **Gỡ tìm kiếm thông minh (vector search)**, `dev` quay về đúng nội dung mốc #74 | Kết quả vector chưa đạt (tìm "pomade" chỉ ra 2/4, câu vô nghĩa vẫn ra sản phẩm). Code cũ còn ở nhánh `feature/vector-embbed-db`, `feature/fe-vector-search` để tham khảo |
| #79 | Kết nối DB dùng `utf8mb4` (`BaseModel.php`) | Trước đó gõ emoji vào ô tìm kiếm làm API sập |
| #80 | Thuộc tính biến thể dạng **mảng** `{key, label, value}` + bảng thông số ở trang chi tiết | Migration `20261119_01`, seed `products_variant_attributes.sql` |
| #81 | `api-products` trả 400 khi `search` là mảng (`?search[]=x`) | |
| #82 | Nút "+" ở giỏ hàng / checkout AI không khóa theo tồn kho cũ; giỏ hàng hiện "Còn N sản phẩm"; checkout AI tự tra lại kho | Admin Long yêu cầu trực tiếp sửa `AiCheckout.vue` |
| #83 | `api-products-top` nhận thêm `category_id`, `exclude_id` (tùy chọn) + task tuần 3 cho Dũng, Vinh | |
| #84 | Khung khu quản trị `/admin` + `api-admin-me` | Hướng dẫn: [admin/huong-dan-trang-admin.md](admin/huong-dan-trang-admin.md) |
| #85 | **Quản trị sản phẩm đầy đủ** (danh sách, tạo/sửa/xóa, biến thể, ảnh slide) + **khóa nghiệp vụ** | Xem mục "Thay đổi contract" bên dưới |

`dev` sau #85 (`7dd5946`) **chưa được admin xác nhận là mốc ổn định** (2 trang đặt hàng mới được kiểm bằng script, chưa thử tay trọn luồng). Thử tay xong thì thêm vào bảng "Mốc `dev` ổn định".

### Quyết định đã chốt

- **Không dùng vector search** nữa. Muốn làm lại thì phải đổi model mạnh hơn và thêm điểm tối thiểu; không làm tìm kiếm kết hợp.
- `product_variants.attributes` là **mảng** `[{key, label, value}]`, luôn đủ 5 khóa mặc định đứng đầu (`dung_tich`, `do_giu_nep`, `do_bong`, `phu_hop`, `kieu_toc`). Mọi bảng chỉ lưu **tên file** ảnh, FE ghép URL bằng `resolveImageUrl`.
- **Khóa nghiệp vụ sản phẩm** (lịch sử đơn là bất biến vì `order_details` lưu ảnh chụp tên/SKU/giá):
  - Không xóa sản phẩm / biến thể **đã có đơn hàng** (kể cả đơn đã hủy) → dùng "Tắt bán".
  - Tồn kho không được ghi đè khi kho vừa đổi (khóa theo phiên bản bằng `expected_stock`).
  - SKU không đổi sau khi tạo.
- Với giỏ hàng / checkout: **số tồn kho trên màn hình chỉ là gợi ý**, không khóa thao tác của khách bằng số cũ; hỏi lại server lúc khách quyết định; server giữ kho trong transaction khi ghi đơn.
- **Không làm:** ảnh sản phẩm ở trang chi tiết đơn hàng (admin quyết định 20/11/2026); kiểm tra file ảnh dùng chung ở `BannerController` (ảnh tải lên luôn có tên ngẫu nhiên).

### Thay đổi contract cần biết

| API | Thay đổi |
| --- | --- |
| `api-product-detail`, `api-cart` | `attributes` của biến thể là **mảng** `[{key, label, value}]` (trước là object) |
| `api-products` | `search` / `q` dạng mảng → 400 |
| `api-products-top` | Thêm tùy chọn `category_id`, `exclude_id` (không truyền thì như cũ) |
| `api-admin-me` (mới) | Kiểm tra quyền vào `/admin`: 200 admin, 401, 403 |
| `api-admin-product-detail`, `api-admin-variant-create/update/delete`, `api-admin-product-image-update` (mới) | Xem `BE/test-api.md` |
| `api-admin-product-update`, `api-admin-variant-update` | **Có gửi `stock` thì bắt buộc gửi `expected_stock`** (số kho lúc mở form): thiếu → 422; kho đã đổi → 409 kèm `current_stock` |
| `api-admin-variant-update` | Đổi `sku` → 422 |
| `api-admin-product-delete`, `api-admin-variant-delete` | Đã có đơn hàng → 409 kèm `order_count` |

Đổi role của tài khoản trong DB thì token cũ bị 401 (role trong token lệch DB) → phải đăng nhập lại.

## Việc còn treo (chưa làm)

- [ ] **Ẩn cả sản phẩm**: cần cột `products.status` (migration); trang khách, catalog, chatbot phải lọc theo. Hiện sản phẩm đã có đơn không xóa được, mà biến thể mặc định bắt buộc đang bán, nên admin chưa có cách gỡ hẳn sản phẩm khỏi trang khách.
- [ ] Hộp xác nhận khi "Đặt mặc định" cho biến thể **đang ngừng bán** (sẽ tự bật bán lại) hoặc **hết kho**.
- [ ] Lọc sản phẩm theo thuộc tính (admin Long tự xử lý). Cần chuẩn hóa bộ giá trị trước: `phu_hop`, `kieu_toc` đang là chuỗi nhiều giá trị; `dung_tich` lẫn g/ml/lít.
- [ ] Router: khi trang tải riêng (lazy) không tải được thì trang trống, chưa báo lỗi / thử lại.
- [ ] Đổi thứ tự ảnh slide chưa nguyên tử (lỗi giữa chừng có thể trùng `sort_order`); trang sửa sản phẩm gọi `api-admin-product-detail` 3 lần.

## Lưu ý môi trường

Máy dev (Windows) thỉnh thoảng **chặn kết nối tới server local theo từng đợt**: trình duyệt báo `ERR_NETWORK_ACCESS_DENIED`, Node báo `EACCES`, curl không kết nối được, trong khi Apache vẫn chạy. Nghi do phần mềm bảo mật / tường lửa. Gặp lỗi kiểu trang trống hoặc request hỏng ngẫu nhiên thì thử lại trước khi kết luận là lỗi code. Script test có dọn dữ liệu thì nên dọn qua API (xóa bằng SQL sẽ để lại file ảnh rác trong `BE/assets/uploads`).

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

### [ ] Thử tay 2 trang đặt hàng trên `dev` sau #85

- **Vì sao:** #80 (thuộc tính dạng mảng) và #82 (tra lại kho ở checkout AI) đụng tới luồng đặt hàng. Đã kiểm bằng script trên Chrome (đặt COD, 409 khi kho tụt), **chưa thử tay**: SePay lỗi, món ngừng bán, trang đặt hàng tay (`/checkout`).
- **Làm:** theo danh sách ca trong `FE/CLAUDE.md` mục 2 (đặt thành công, COD, SePay lỗi, 409 giá đổi, thiếu kho, ngừng bán, đơn nháp AI tra lại kho). Đạt hết thì ghi mốc ổn định mới.

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
