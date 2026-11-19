# 02. API `api-products`

Endpoint có sẵn, được **mở rộng** thêm tham số tùy chọn. Không gửi tham số mới thì kết quả y như trước.

```
GET {BASE_URL}/?action=api-products
```

- Không yêu cầu đăng nhập.
- Code: `BE/controllers/ProductController.php` → `index()`.

## 1. Tham số (query string)

| Tham số | Kiểu | Mặc định | Áp dụng | Ghi chú |
| --- | --- | --- | --- | --- |
| `search` (hoặc `q`) | chuỗi | rỗng | cả hai | Chế độ vector: tối đa 200 ký tự |
| `category` | chuỗi | tất cả | cả hai | Slug danh mục, tối đa 255 ký tự |
| `min_price` | số ≥ 0 | không lọc | cả hai | VND. Giữ sản phẩm có **ít nhất 1 biến thể đang bán** có giá ≥ min |
| `max_price` | số ≥ 0 | không lọc | cả hai | VND. Như trên, giá ≤ max |
| `search_mode` | `keyword` \| `vector` | `keyword` | | Chọn chế độ cho ô tìm kiếm |
| `sort` | `newest` \| `price_asc` \| `price_desc` \| `views` \| `sales` | `newest` | chỉ keyword | Giá sắp theo `min_final_price` (giá thấp nhất), sản phẩm chưa có giá xuống cuối |
| `page` | số | 1 | chỉ keyword | Như cũ |
| `per_page` (hoặc `perPage`) | số | 8 | chỉ keyword | Như cũ |

## 2. Response thành công (200)

Khung chung (giữ nguyên các key cũ, thêm `search_mode_used`, `notice`, và 4 key mới trong `filters`):

```json
{
  "status": "success",
  "products": { "data": [ ... ], "pagination": { ... } },
  "filters": {
    "search": "...", "category": null,
    "page": 1, "per_page": 8,
    "search_mode": "keyword", "sort": "newest",
    "min_price": null, "max_price": null
  },
  "search_mode_used": "keyword",
  "notice": null
}
```

| Key | Ý nghĩa |
| --- | --- |
| `search_mode_used` | Chế độ **thực tế** đã dùng: `keyword` hoặc `vector`. Có thể khác `filters.search_mode` khi phải lùi về. |
| `notice` | `null`, hoặc câu thông báo khi muốn vector nhưng phải dùng keyword: `"Tìm kiếm thông minh đang tắt, đã dùng tìm kiếm thường."` / `"Tìm kiếm thông minh tạm thời không khả dụng, đã dùng tìm kiếm thường."` |

### 2.1. Chế độ keyword

`products.data` là các sản phẩm như trước (cùng cột với bản cũ), `products.pagination` là phân trang thật:

```json
"pagination": { "current_page": 1, "per_page": 2, "total_items": 4, "total_pages": 2 }
```

### 2.2. Chế độ vector

- `products.data` theo **thứ tự liên quan** (điểm giảm dần), tối đa 20 sản phẩm, đã cắt ngưỡng 80%.
- Mỗi sản phẩm có thêm `score` và `matched_variant`.
- `products.pagination` luôn là **1 trang chứa toàn bộ** (giữ key này để FE đang đọc `pagination` không bị lỗi).
- `filters.page`, `filters.per_page`, `filters.sort` là `null` (không áp dụng).

```json
{
  "id": 2,
  "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax",
  "category_name": "Gel, Sáp vuốt tóc",
  "min_final_price": 250000,
  "...": "các cột khác như chế độ keyword",
  "score": 0.4487,
  "matched_variant": {
    "variant_id": 2,
    "sku": "GOT2B-GLUED",
    "variant_name": "Mặc định",
    "final_price": 250000,
    "stock": 30,
    "attributes": [
      {"key": "dung_tich",  "label": "Dung tích",  "value": null},
      {"key": "do_giu_nep", "label": "Độ giữ nếp", "value": "rất mạnh"},
      {"key": "do_bong",    "label": "Độ bóng",    "value": "bóng nhẹ"},
      {"key": "phu_hop",    "label": "Phù hợp",    "value": "tóc dày, tóc cứng, tóc ngắn"},
      {"key": "kieu_toc",   "label": "Kiểu tóc",   "value": "dựng đứng, chĩa gai (spiky), mohican"}
    ],
    "embed_text": "Gel Vuốt Tóc Got2b Glued Spiking Wax. Danh mục: Gel, Sáp vuốt tóc. ... Hợp kiểu tóc dựng đứng, chĩa gai (spiky), mohican."
  }
}
```

| Key | Ý nghĩa |
| --- | --- |
| `score` | Cosine giữa câu tìm kiếm và biến thể khớp nhất, làm tròn 4 chữ số. Chỉ dùng để so trong cùng một lần tìm, **không** so giữa các câu khác nhau. |
| `matched_variant` | Biến thể điểm cao nhất của sản phẩm (nằm trong khoảng giá nếu có lọc giá). |
| `matched_variant.attributes` | Mảng `{key, label, value}` của biến thể (xem [03-du-lieu.md](03-du-lieu.md)): 5 khóa mặc định đứng đầu, `value` có thể `null`. Cùng định dạng với `attributes` của `api-product-detail` và `api-cart`. |
| `matched_variant.embed_text` | Đoạn văn đã dùng tạo vector, để biết vì sao sản phẩm được điểm như vậy. |

## 3. Lỗi (400)

| Trường hợp | `message` |
| --- | --- |
| `search` không phải chuỗi (vd `search[]=x`) | Từ khóa tìm kiếm không hợp lệ |
| `category` không phải chuỗi hoặc dài > 255 | Slug danh mục không hợp lệ |
| `search_mode` khác `keyword`/`vector` | search_mode phải là keyword hoặc vector |
| `search_mode=vector` và `search` > 200 ký tự | Từ khóa tìm kiếm thông minh tối đa 200 ký tự |
| `sort` sai | sort phải là một trong: newest, price_asc, price_desc, views, sales |
| `min_price`/`max_price` không phải số hoặc âm | min_price phải là số không âm (tương tự max_price) |
| `min_price` > `max_price` | min_price không được lớn hơn max_price |

Server Python lỗi **không** sinh mã lỗi: API trả 200 với `search_mode_used = keyword` và `notice`.

## 4. Ví dụ để test (Thunder Client)

URL chung: `http://localhost/du-an-1/BE/?action=api-products`, nhập tham số ở tab **Query** (để chữ có dấu được mã hóa đúng). Ô URL không ghi chữ `GET`.

| # | Tham số | Kết quả mong đợi (dữ liệu demo) |
| --- | --- | --- |
| 1 | (không có) | Như bản cũ: 7 sản phẩm, mới nhất trước |
| 2 | `category=pomade`, `sort=price_asc`, `per_page=2` | Suavecito 320k, Reuzel Blue 390k; trang 1/2 |
| 3 | `max_price=400000`, `sort=price_desc` | 5 sản phẩm ≤ 400k, giá giảm dần |
| 4 | `search_mode=vector`, `search=mùi cam chanh` | Chỉ Suavecito |
| 5 | `search_mode=vector`, `search=pomade dễ gội`, `category=pomade`, `max_price=400000` | 3 pomade, không có Layrite (420k) |
| 6 | `search_mode=vector` (không có `search`) | Như #1, `search_mode_used = keyword`, `notice = null` |
| 7 | Tắt server Python rồi gọi lại #4 | 200, `search_mode_used = keyword`, có `notice` |
| 8 | `sort=abc` hoặc `min_price=-1` | 400 |

## 5. Gợi ý cho FE khi nối (chưa làm)

- Thêm ô khoảng giá, dropdown sắp xếp, nút bật/tắt "Tìm kiếm thông minh" (bật thì gửi `search_mode=vector`).
- Khi `search_mode_used === 'vector'`: ẩn phân trang và dropdown sắp xếp, hiện như "danh sách đề xuất".
- Khi `notice` khác `null`: hiện dòng thông báo cho khách.
- Hiển thị `attributes` thì dùng `label` từ API và **bỏ qua giá trị `null`** (helper `FE/src/helpers/format/variantAttributes.js`).
