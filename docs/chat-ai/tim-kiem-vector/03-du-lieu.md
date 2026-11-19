# 03. Dữ liệu

## 1. Bảng `product_variant_embeddings`

Migration: `BE/database/migrations/20261119_01_create_product_variant_embeddings_table.sql` (chạy lặp được nhờ `IF NOT EXISTS`).

| Cột | Kiểu | Ý nghĩa |
| --- | --- | --- |
| `variant_id` | BIGINT UNSIGNED, **khóa chính**, FK → `product_variants.id` (`ON DELETE CASCADE`) | Mỗi biến thể một dòng. Xóa biến thể thì xóa luôn vector. |
| `embed_text` | TEXT | Đoạn văn đã ghép để tạo vector |
| `text_hash` | CHAR(64) | SHA-256 của `embed_text` |
| `embedding` | JSON (CHECK là mảng) | Vector, mảng 768 số thực |
| `dim` | SMALLINT UNSIGNED | Số chiều (hiện tại 768) |
| `model_name` | VARCHAR(150) | Model đã tạo vector |
| `created_at`, `updated_at` | TIMESTAMP | Tự động |

```mermaid
erDiagram
    products ||--o{ product_variants : "có"
    product_variants ||--o| product_variant_embeddings : "có 0 hoặc 1 vector"
    product_variant_embeddings {
        bigint variant_id PK
        text embed_text
        char text_hash
        json embedding
        smallint dim
        varchar model_name
    }
```

Đây là **dữ liệu suy ra**: xóa cả bảng vẫn tạo lại được bằng `php BE/database/embed_products.php`.

## 2. Quy ước `product_variants.attributes`

Cột `attributes` (JSON) là **mảng các phần tử `{ key, label, value }`** (từ migration `20261119_02`; trước đó là object `{"dung_tich": "113g"}`).
Định dạng và 5 khóa mặc định được định nghĩa ở **một chỗ duy nhất**: `BE/extensions/VariantAttributes.php`.

```json
[
  {"key": "dung_tich",  "label": "Dung tích",  "value": "113g"},
  {"key": "do_giu_nep", "label": "Độ giữ nếp", "value": "mạnh"},
  {"key": "do_bong",    "label": "Độ bóng",    "value": "bóng vừa"},
  {"key": "phu_hop",    "label": "Phù hợp",    "value": "tóc dày, tóc cứng, tóc rễ tre"},
  {"key": "kieu_toc",   "label": "Kiểu tóc",   "value": "side part, pompadour"},
  {"key": "mui_huong",  "label": "Mùi hương",  "value": "mùi cam chanh"}
]
```

Vì sao dùng mảng thay vì object: giữ đúng thứ tự (object JSON bị MySQL tự sắp lại khóa) và mỗi thuộc tính có **nhãn tiếng Việt đúng dấu**, kể cả khóa tự do; FE hiển thị thẳng `label`, không giữ bảng nhãn riêng.

Quy tắc (BE chuẩn hóa bằng `VariantAttributes::decode()` / `normalize()`):
- **5 khóa mặc định luôn có và luôn đứng đầu** theo thứ tự cố định (`$[0]`..`$[4]`); nhãn của chúng lấy từ BE, không tin nhãn bên ngoài; chưa rõ thì `value = null`.
- **Khóa tự do** (vd `mui_huong`, `xuat_xu`) đứng sau, giữ thứ tự nhập, có `label` riêng.
- **`key`**: không dấu, chữ thường, `snake_case` (`a-z`, `0-9`, `_`, tối đa 50 ký tự), không trùng. Lý do không dấu: chữ có dấu có thể lưu theo 2 kiểu Unicode (NFC/NFD), nhìn giống nhau nhưng máy coi là khác.
- **`value`**: chuỗi tiếng Việt có dấu, hoặc `null`.
- DB có CHECK `chk_product_variants_attributes_array`: `attributes` phải là mảng. Biến thể mới tạo (admin) được ghi sẵn 5 khóa `null`.
- Không lọc SQL theo các khóa này; chúng phục vụ tìm kiếm vector và hiển thị.

### 5 khóa mặc định và mẫu câu ghép `embed_text`

| Khóa | Nhãn (`VariantAttributes::DEFAULTS`) | Mẫu câu (`ProductEmbedText::TEMPLATES`) | Ví dụ giá trị |
| --- | --- | --- | --- |
| `dung_tich` | Dung tích | `Dung tích {v}.` | `113g` |
| `do_giu_nep` | Độ giữ nếp | `Độ giữ nếp {v}.` | `nhẹ`, `trung bình`, `mạnh`, `rất mạnh` |
| `do_bong` | Độ bóng | `Độ bóng: {v}.` | `không bóng`, `bóng nhẹ`, `bóng vừa`, `bóng cao` |
| `phu_hop` | Phù hợp | `Phù hợp với {v}.` | `tóc dày, tóc rễ tre` |
| `kieu_toc` | Kiểu tóc | `Hợp kiểu tóc {v}.` | `side part, pompadour` |
| *khóa tự do* | *label riêng* | `{v}.` | `mùi cam chanh` |

Viết giá trị sao cho ghép vào mẫu câu đọc xuôi: `kieu_toc` ghi `"dựng đứng, mohican"`, **không** ghi `"tóc dựng đứng"` (sẽ thành "Hợp kiểu tóc tóc dựng đứng").

## 3. Cách ghép `embed_text` (`ProductEmbedText::build`)

Thứ tự cố định (cùng dữ liệu luôn ra cùng đoạn văn, nhờ đó `text_hash` ổn định):

1. `{tên sản phẩm} - {tên biến thể}.` (bỏ tên biến thể nếu là `Mặc định`)
2. `Danh mục: {tên danh mục}.`
3. `{mô tả sản phẩm}` (thêm dấu chấm nếu thiếu)
4. 5 khóa mặc định theo mẫu câu; giá trị `null`/rỗng thì **bỏ cả câu**
5. Khóa tự do theo thứ tự trong mảng, chỉ lấy giá trị

**Không** đưa vào: giá, giá giảm, tồn kho, lượt xem, lượt bán, SKU, mã sản phẩm.

Ví dụ kết quả:

> Pomade Reuzel Blue Strong Hold - Hũ 113g. Danh mục: Pomade. Pomade gốc nước của Reuzel, giữ nếp mạnh, độ bóng vừa. Hợp kiểu side part, pompadour cần đứng nếp cả ngày, dễ gội sạch. Dung tích 113g. Độ giữ nếp mạnh. Độ bóng: bóng vừa. Phù hợp với tóc dày, tóc cứng, tóc rễ tre. Hợp kiểu tóc side part, pompadour.

Muốn thêm/đổi khóa mặc định: sửa `VariantAttributes::DEFAULTS` (nhãn, thứ tự) và `ProductEmbedText::TEMPLATES` (mẫu câu), viết migration bổ sung khóa cho dữ liệu cũ, rồi chạy `embed_products.php` (hash đổi nên vector tự được tạo lại).

## 4. Đặc điểm chung ở đâu, đặc điểm riêng ở đâu

| Thông tin | Đặt ở | Ví dụ |
| --- | --- | --- |
| Đặc điểm chung của sản phẩm | `products.description` | "Pomade gốc nước, giữ nếp mạnh..." |
| Đặc điểm riêng của biến thể | `product_variants.variant_name` + `attributes` | Bản Matte: `"do_bong": "không bóng"` |
| Giá, tồn kho | cột riêng của `product_variants` | lọc/sắp xếp bằng SQL |

Nếu sau này cần mô tả dài cho từng biến thể, có thể thêm cột `product_variants.description` (cho phép NULL) bằng migration mới và ghép vào `build()`. Hiện chưa cần.

## 5. Seed demo `products_search_attributes.sql`

- Viết lại `description` và điền 5 khóa cho biến thể của 6 sản phẩm: `CLAYMATION`, `GOT2B-GLUED`, `POMADE-REUZEL-RED`, `POMADE-REUZEL-BLUE`, `POMADE-SUAVECITO-ORIGINAL` (thêm `mui_huong`), `POMADE-LAYRITE-ORIGINAL`.
- Ghi theo vị trí trong mảng (`JSON_SET(..., '$[1].value', ...)`), dựa vào việc 5 khóa mặc định luôn ở `$[0]`..`$[4]`. Không đụng `$[0]` (`dung_tich`) để giữ dung tích riêng từng hũ.
- **Không** sửa sản phẩm `HOANG-VINH-01`, giá, tồn kho, tên biến thể. (Migration `20261119_02` vẫn đổi định dạng dữ liệu của Vinh Râu và thêm các khóa mặc định còn thiếu với `null`; giá trị cũ giữ nguyên.)
- Chạy lặp được. Tên file bắt đầu bằng `products_` để chạy **sau** `catalog_product_variants.sql` khi dùng `run.php --seed`.
- Chạy seed xong phải chạy `embed_products.php` để tạo lại vector.

> FE đọc `attributes` qua helper `FE/src/helpers/format/variantAttributes.js` (`listVariantAttributes`, `getVariantAttribute`): trang chi tiết thả xuống bảng thông số (bỏ `null`), giỏ hàng chỉ hiện dung tích.
