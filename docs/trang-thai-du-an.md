# Trạng thái dự án

Nơi ghi những việc **chưa xong hẳn**: ca kiểm thử chưa chạy được, lỗi đã biết chưa sửa, việc cần làm tiếp. Xong việc nào thì đánh dấu `[x]`, ghi ngày và người kiểm tra. Kèm theo các **mốc `dev` ổn định** để quay về khi cần.

Cập nhật lần cuối: 23/11/2026 (triển khai bộ lọc catalog + nâng cấp Fule, nhánh `feature/fule-product-filter`).

## Kế hoạch ngày 23/11/2026: nâng cấp Fule (trợ lý AI) + bộ lọc sản phẩm

Hai việc dùng chung một nền: **thuộc tính biến thể** (`product_variants.attributes`). Hiện giá trị thuộc tính là chữ tự do nên vừa không lọc được, vừa không đưa cho Fule tư vấn được. Vì vậy làm theo thứ tự: **Bước 0 chuẩn hóa thuộc tính → Phần A (Fule) và Phần B (bộ lọc) làm song song.**

### Kết quả triển khai 23/11 (đã chốt, thay cho phần đề xuất bên dưới)

Admin chốt cách làm khác đề xuất ban đầu:

| Hạng mục | Đã làm |
| --- | --- |
| Độ giữ nếp / độ bóng | Thành **cột số** `product_variants.hold_level` (1 = nhẹ, 2 = trung bình, 3 = mạnh) và `shine_level` (0 = không bóng, 1 = bóng nhẹ, 2 = bóng vừa, 3 = bóng cao), có `CHECK`. Bỏ khỏi JSON. Lọc dạng chọn (select) |
| Thuộc tính còn lại | JSON chỉ còn 3 khóa mặc định (`dung_tich`, `phu_hop`, `kieu_toc`) + khóa tự do. BE tự gom **giá trị** vào `products.metadata` (chỉ biến thể đang bán, cách nhau " \| ") mỗi khi admin thêm/sửa/xóa biến thể. Lọc theo **tag** bằng `LIKE` (phải khớp mọi tag) |
| Metadata gom gì | Chỉ giá trị thuộc tính. **Không** gom tên/mã/mô tả (đã có ô search), danh mục/giá/giữ nếp/độ bóng (đã có ô lọc riêng), nhãn (sản phẩm nào cũng có, gây nhiễu). Chỉ gom dữ liệu do chính luồng "admin sửa biến thể" ghi, để không bị cũ khi bảng khác đổi |
| Bộ lọc catalog | Ô search + danh mục (select) + khoảng giá (2 ô) + giữ nếp / độ bóng (select) + tag (chip gợi ý + ô nhập) + sắp xếp mới nhất / cũ nhất. Giá, giữ nếp, độ bóng khớp khi có ít nhất 1 biến thể đang bán thỏa cả 3 cùng lúc |
| Fule | `search_product` nhận đủ tham số như catalog (luật kiểm tra chung `BE/extensions/CatalogFilter.php`), trả thêm mô tả, lượt bán, giữ nếp, độ bóng, thuộc tính; nhiều kết quả thì mở catalog với đúng bộ lọc |
| Giá trị cũ | Migration `20261123_01` ánh xạ chữ cũ sang số ("rất mạnh", "Cực cao", "3" → 3; "bóng lộn" → 3; "không bóng, mờ tự nhiên" → 0...) |

Contract mới:
- `api-products`: thêm query tùy chọn `min_price`, `max_price`, `hold`, `shine`, `tags` (cách nhau bằng dấu phẩy), `sort` (`newest` | `oldest`); `search` tối đa 100 ký tự; tham số sai → 400.
- `api-product-filters` (mới, công khai): thang giữ nếp / độ bóng, khoảng giá, tag gợi ý.
- `api-product-detail`, `api-cart`: `attributes` vẫn là mảng như cũ, BE ghép thêm 2 dòng "Độ giữ nếp", "Độ bóng"; chi tiết có thêm `hold_level`, `shine_level`.
- `api-admin-product-detail`: thêm `attribute_config`, biến thể có `hold_level`, `shine_level`; `api-admin-variant-create/update` nhận `hold_level` (1-3), `shine_level` (0-3), null = chưa rõ.

Sau khi pull: `php BE/database/run.php` (migration `20261123_01`), `cd FE && npm run build`.

Chỉnh sau code review 23/11 (nguyên tắc: mỗi quy tắc chỉ một nguồn sự thật):
- **Metadata**: một câu SQL duy nhất `Product::METADATA_REFRESH_SQL` (chạy nguyên khối, không ghi đè bằng bản đọc cũ khi 2 admin sửa cùng lúc); migration và 3 seed chép đúng câu này. Bỏ trùng / sắp xếp theo byte (`utf8mb4_bin`) nên migration và runtime ra giống hệt. Nới `group_concat_max_len` lên 65535 (mặc định 1024 bị cắt ngầm). Lỗi khi tạo lại metadata chỉ ghi log, không làm admin nhận 500 cho lần lưu đã thành công.
- **Tag**: `CatalogFilter` tách mọi tag theo dấu phẩy ở cả URL lẫn AI, nên tool AI và trang catalog nó mở luôn ra cùng kết quả. Tag gợi ý lấy từ chính `metadata` (tag nào gợi ý thì lọc chắc chắn có kết quả).
- **Xếp hạng chung** cho catalog và trợ lý: còn hàng trước, rồi mới nhất / cũ nhất.
- Nhãn sắp xếp và giới hạn (số tag, độ dài) khai báo ở `CatalogFilter`, gửi FE qua `api-product-filters` (`sorts`, `limits`); FE không viết cứng.
- `catalog.vue`: đổi trang / sắp xếp không xóa khung lọc đang nhập dở; bấm "Áp dụng" khi ô tag còn chữ thì tự thêm tag.
- BE/CLAUDE.md §4 ghi ngoại lệ đã chốt cho `CatalogFilter`.

Bổ sung cuối ngày 23/11:
- **Ô search tìm rộng**: ngoài tên, mã, mô tả, còn khớp `metadata` và SKU / tên phiên bản của biến thể đang bán (OR). Tag vẫn là lọc chặt (phải khớp mọi tag). Áp dụng cho cả catalog và Fule.
- **Chip "trợ lý đã làm gì"** cho mọi tool (tra cứu kèm bộ lọc, cập nhật / bỏ đơn nháp, chọn bài hát): BE tạo câu trong `tool_calls[].summary` (`GeminiToolSummary`), dùng chung cho câu trả lời mới và lịch sử. Câu mô tả bộ lọc chuyển về `CatalogFilter::describe()` (tool và chip dùng chung).
- **Fule chọn keyword đúng**: prompt + mô tả tham số bắt chỉ lấy thực thể cốt lõi đã sửa chính tả ("…được dùng bởi Sơn Tùng MTP" → `Sơn Tùng MTP`; "sáp KervinMurphy" → `Kevin Murphy`, chữ "sáp" sang `category`), không ra kết quả thì thử lại tối đa 2 lần. Chat thật với Gemini: 4/4 câu đúng ngay lần gọi đầu.
- **Catalog**: sidebar bộ lọc (sticky) cao hơn màn hình bị che phần dưới → giới hạn chiều cao, cuộn bên trong sidebar (điện thoại giữ nguyên).

Kiểm thử 23/11 (HTTP thật trên DB local): bộ lọc catalog 27/27, admin biến thể + metadata 18/18, hồi quy chi tiết/giỏ/top 9/9; tool Fule chạy dòng lệnh đủ ca lọc và lỗi; chat thật với Gemini 1 câu ("giữ nếp mạnh, không bóng, hợp tóc dày") → gọi tool đúng `hold=3, shine=0, tags=["tóc dày"]` và mở catalog đúng bộ lọc.
- [ ] Thử trên trình duyệt: khung lọc catalog (desktop + điện thoại), form admin sửa biến thể (2 ô chọn mức), trang chi tiết, giỏ hàng.
- [ ] `catalog.vue` vẫn dùng khung xương (skeleton) riêng, chưa chuyển `StateBlock` (FE/CLAUDE.md mục 13: kiểu lưới xương chưa có trong `StateBlock`, cần admin quyết).

### Bước 0: chốt quy ước thuộc tính (cần admin quyết trước khi code)

Hiện trạng giá trị trên DB local (15 biến thể đang bán, đếm ngày 22/11):

| Khóa | Vấn đề | Ví dụ giá trị đang có |
| --- | --- | --- |
| `do_giu_nep` | Lẫn chữ hoa/thường, lẫn số, 4/15 trống | `trung bình`, `mạnh`, `rất mạnh`, `Cực cao`, `3` |
| `do_bong` | Mô tả dài lẫn mức, sai chính tả, 3/15 trống | `bóng nhẹ`, `bóng vừa`, `bóng cao`, `không bóng, mờ tự nhiên`, `Không bóng`, `bóng lộn`, `3` |
| `dung_tich` | Lẫn đơn vị và cách viết | `113g`, `113 g`, `56g`, `120g`, `340g`, `2 lít`, `1 ml` |
| `phu_hop` | Chuỗi nhiều giá trị, không có bộ từ cố định | `tóc dày, tóc cứng, tóc rễ tre`, `Tóc trung bình đến dày cứng, tóc nhiều dầu` |
| `kieu_toc` | Chuỗi nhiều giá trị, sai chính tả, dữ liệu rác | `side part, pompadour`, `mohicam xoăn, premlock`, `Fule` |

Đề xuất quy ước (admin chốt):

| Khóa | Kiểu | Bộ giá trị cho phép |
| --- | --- | --- |
| `do_giu_nep` | 1 giá trị, chọn từ danh sách | `nhẹ` / `trung bình` / `mạnh` / `rất mạnh` |
| `do_bong` | 1 giá trị, chọn từ danh sách | `không bóng` / `bóng nhẹ` / `bóng vừa` / `bóng cao` |
| `dung_tich` | Số + đơn vị viết liền, chữ thường | `113g`, `100ml` (đổi `2 lít` → `2000ml`; `1 ml` nghi dữ liệu test, admin kiểm tra) |
| `phu_hop` | Nhiều giá trị, từ bộ từ cố định | `tóc dày`, `tóc mỏng`, `tóc cứng`, `tóc mềm`, `tóc rễ tre`, `tóc xoăn`, `tóc dầu`, `tóc thường`… |
| `kieu_toc` | Nhiều giá trị, từ bộ từ cố định | `side part`, `pompadour`, `quiff`, `slick back`, `textured crop`, `undercut`, `mohican`, `spiky`… |
| Khóa tự do (`mui_huong`, `xuat_xu`…) | Chữ tự do, chỉ hiển thị, **không lọc** | |

Cần quyết thêm:
- [ ] **Cách lưu thuộc tính nhiều giá trị** (`phu_hop`, `kieu_toc`):
  - (A) Giữ chuỗi, các giá trị chuẩn cách nhau bằng dấu phẩy. Ít sửa code, lọc bằng `LIKE`/`JSON_TABLE`.
  - (B) Đổi `value` thành mảng JSON. Lọc chuẩn hơn, nhưng phải sửa `VariantAttributes`, form admin, trang chi tiết, Fule.
  - Đề xuất (A) để kịp trong 1 ngày.
- [ ] Giá trị lạ hiện có (`3`, `bóng lộn`, `Fule`, `premlock`, `1 ml`) đổi thành gì hoặc xóa.
- [ ] Bộ từ cố định đầy đủ cho `phu_hop`, `kieu_toc`.

Việc code sau khi chốt:
- `BE/extensions/VariantAttributes.php`: thêm danh sách giá trị cho phép theo từng khóa, chuẩn hóa chữ thường/khoảng trắng; giá trị ngoài danh sách bị 422 ở API admin.
- Migration dữ liệu mới (chạy lặp được) đổi giá trị cũ sang giá trị chuẩn theo bảng ánh xạ. Không sửa migration cũ.
- Form admin sửa biến thể: ô chữ tự do của 5 khóa mặc định đổi thành ô chọn (1 giá trị) hoặc chọn nhiều.

### Phần A: nâng cấp Fule (BE, `BE/geminiServices/`)

Hiện tool `search_product` chỉ trả tên, mã, danh mục, khoảng giá, còn hàng, và từng phiên bản (tên, SKU, giá, giá gốc khi giảm, tồn kho, mặc định). Fule **không biết**:

| Thông tin | Có trên DB | Ảnh hưởng |
| --- | --- | --- |
| Thuộc tính biến thể (giữ nếp, độ bóng, phù hợp loại tóc, kiểu tóc, dung tích) | 14/15 biến thể có | Không tư vấn được "sáp giữ nếp mạnh cho tóc dày" |
| Mô tả sản phẩm (`description`) | 10/10 sản phẩm | Tìm theo mô tả (LIKE) nhưng Fule không đọc được mô tả |
| Lượt bán (`sales_count`) | 6/10 sản phẩm > 0 | Không trả lời được "loại nào bán chạy" |

Việc làm:
- [ ] `Product::getActiveVariantsByProductIds`: lấy thêm cột `attributes`; tool trả `attributes` dạng `{nhãn: giá trị}`, bỏ giá trị null.
- [ ] `Product::searchByKeyword`: lấy thêm `description` (tool cắt khoảng 300 ký tự) và `sales_count`.
- [ ] Cho `search_product` nhận **tham số lọc tùy chọn** (`do_giu_nep`, `do_bong`, `phu_hop`, `kieu_toc`), **dùng chung hàm lọc với `api-products` ở Phần B** (một nơi viết SQL lọc, không viết 2 lần).
- [ ] `GeminiPrompt.php`: hướng dẫn tư vấn dựa trên thuộc tính; thuộc tính trống thì nói "chưa có thông tin", không đoán.
- [ ] Kiểm tra số token: 5 sản phẩm × 5 phiên bản × thuộc tính + mô tả; cần thì giảm `MAX_VARIANTS_PER_PRODUCT`.
- [ ] Kiểm thử: chạy tool bằng dòng lệnh (có/không có bộ lọc, thuộc tính trống); chat thật nếu key Gemini còn hạn mức (6 key từng hết hạn mức ngày 18/11).
- [ ] Cập nhật `docs/chat-ai/05-tools.md`.

### Phần B: bộ lọc sản phẩm (BE `api-products` + FE `catalog.vue`)

Hiện `api-products` chỉ có `search`, `category`, `page`, `per_page`. Thay đổi contract nên **thống nhất với FE trước khi code**.

Đề xuất contract (chỉ thêm query tùy chọn, không truyền thì như cũ):

| Query | Ý nghĩa |
| --- | --- |
| `do_giu_nep`, `do_bong` | Lọc 1 hoặc nhiều mức, ví dụ `do_giu_nep=mạnh,rất mạnh` |
| `phu_hop`, `kieu_toc` | Có ít nhất 1 giá trị khớp |
| `price_min`, `price_max` | Theo `final_price` của biến thể đang bán |
| `sort` | `newest` (mặc định hiện tại), `price_asc`, `price_desc`, `sales` (allowlist, sai → 400) |

Sản phẩm khớp khi **có ít nhất 1 biến thể đang bán** thỏa mọi điều kiện.

Việc làm:
- [ ] BE `ProductController::index` + `Product::getFiltered`: đọc, kiểm tra và áp dụng bộ lọc; giá trị lọc phải nằm trong danh sách cho phép (Bước 0), prepared statement.
- [ ] API mới `api-product-filters` (công khai): trả các giá trị đang có kèm số sản phẩm, để FE dựng ô lọc mà không viết cứng.
- [ ] FE `catalog.vue`: khung lọc (checkbox theo nhóm, khoảng giá, sắp xếp), đồng bộ lên URL query để chia sẻ link và quay lại được, nút "Xóa lọc"; điện thoại thì mở khung lọc dạng ngăn kéo.
  - Theo FE/CLAUDE.md mục 13: sửa phần tải dữ liệu của `catalog.vue` thì phải chuyển sang `StateBlock`; khung xương dạng lưới chưa có trong `StateBlock`, cần hỏi admin.
- [ ] Kiểm thử API: từng bộ lọc, kết hợp nhiều bộ lọc, giá trị lạ (400), không có kết quả; build FE; thử trên trình duyệt qua ngrok.
- [ ] Cập nhật README (bảng API) và `BE/test-api.md`.

### Rủi ro / lưu ý

- Đổi giá trị thuộc tính ảnh hưởng: bảng thông số ở trang chi tiết, form admin, câu trả lời của Fule. Kiểm lại cả 3 sau migration dữ liệu.
- Migration dữ liệu phải chạy trên máy từng thành viên (`php BE/database/run.php`).
- Lọc trên cột JSON chạy được trên MySQL 8 (`JSON_TABLE`); dữ liệu nhỏ nên chưa cần index.

## Thay đổi ngày 22/11/2026 (PR #89 → #91)

| PR | Nội dung | Ghi chú |
| --- | --- | --- |
| #89 | Dịch vụ AI kiểm duyệt bình luận trên Google Colab (2 model) + API test `api-comment-guard` + migration `20261122_01` (3 cột nhãn AI ở `comments`) | Long. Tài liệu: [comment-guard/README.md](comment-guard/README.md) |
| #90 | Kiểm duyệt bình luận ở `api-comment-create/update` (câu bị chặn trả 422, không lưu); `COMMENT_GUARD_ENABLED`; ghi chú log API; sắp xếp `routes/index.php`; migration `20261122_02` (comment cho bảng); trang chi tiết: checkbox + nút thêm giỏ chung, bảng thông số, mô tả xem thêm | Vinh. Tài liệu: [binh-luan/README.md](binh-luan/README.md) |
| #91 | Return URL khi đăng nhập / đăng xuất từ navbar | `App.vue`, `Profile.vue`, `helpers/auth/returnUrl.js` |

Sau khi pull: chạy `php BE/database/run.php`; thêm `COMMENT_GUARD_URL`, `COMMENT_GUARD_KEY`, `COMMENT_GUARD_TIMEOUT` (và tùy chọn `COMMENT_GUARD_ENABLED`) vào `BE/.env`; build lại FE.

Việc còn treo của ngày 22/11:
- [ ] Thử tay trên trình duyệt: luồng bình luận (câu bị chặn giữ ô nhập, sửa bình luận bị chặn), nút thêm giỏ chung khi có món lỗi, return URL (xem `docs/binh-luan/08-frontend.md` và PR #91).
- [ ] Trang admin duyệt bình luận (hiện nhãn AI, duyệt/xóa).
- [ ] Route guard chưa lưu return URL khi khách vào thẳng trang cần đăng nhập.

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
- **Vì sao chưa test:** DB local chưa có đơn SePay nào chưa thanh toán và quá `SEPAY_PAYMENT_TIMEOUT_MINUTES` (2 phút). Các ca khác của PR đã test trên trình duyệt.

**Cần chứng minh:** đơn SePay chưa thanh toán quá 2 phút đang giữ hàng. Khi khách xem sản phẩm, xem giỏ, mở đơn nháp AI hoặc chat với Louis, server hủy đơn đó và trả hàng về kho **trước khi** đọc tồn kho, nên khách không thấy "hết hàng" sai.

**Chuẩn bị (chỉ trên DB local):**

1. Chọn một phiên bản còn ít hàng, ghi lại `stock` ban đầu (ví dụ phiên bản `CLAYMATION`):
   ```sql
   SELECT id, sku, stock FROM product_variants WHERE sku = 'CLAYMATION';
   ```
2. Đăng nhập tài khoản test, thêm phiên bản đó vào giỏ với số lượng **bằng toàn bộ tồn kho**, vào `/checkout`, chọn **Thanh toán online**, bấm đặt. Khi sang trang SePay thì **không thanh toán**, đóng tab. Kho của phiên bản giờ là 0.
3. Ghi lại mã đơn vừa tạo, rồi lùi giờ tạo đơn để khỏi chờ 2 phút:
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

**Ca đối chứng:** đơn SePay **chưa** quá 2 phút (bỏ bước 3) thì vẫn giữ hàng. Trang báo hết hàng và đơn vẫn `pending`, như vậy là đúng.

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
