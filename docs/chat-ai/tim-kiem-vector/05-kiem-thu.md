# 05. Kiểm thử và giới hạn

Dự án chưa có test tự động. Dưới đây là các kiểm tra **đã chạy thật** ngày 19/11/2026 trên DB local (dữ liệu demo: 7 sản phẩm, 11 biến thể), bằng script Python gọi API và gọi trực tiếp class PHP.

## 1. Đã kiểm tra

### Tương thích ngược
- `api-products` mới so với bản cũ (qua API thử nghiệm đã chứng minh giống bản cũ): **13/13 ca khớp** `products` — không tham số, `search`, `q`, `category`, `page`+`per_page`, `perPage`, `search`+`category`, các ca sort/giá/vector.
- Tên tham số cũ `perPage` vẫn chạy.

### Chế độ keyword
- `sort=price_asc|price_desc|views`, lọc `min_price`/`max_price`, phân trang: đúng thứ tự và số lượng.

### Chế độ vector
| Câu tìm | Bộ lọc | Kết quả |
| --- | --- | --- |
| mùi cam chanh | | Chỉ Suavecito (0.384), ngưỡng 80% loại hết sản phẩm khác |
| pomade dễ gội | | 4 pomade |
| pomade dễ gội | `category=pomade`, `max_price=400000` | 3 pomade, Layrite (420k) bị loại từ vòng bo |
| pomade dễ gội | `sort=price_desc`, `page=3`, `per_page=1` | Vẫn đủ 4 sản phẩm theo điểm (bỏ qua sort/page) |
| pomade | `min_price=5000000` | Rỗng, không gọi server Python (vòng bo rỗng) |
| (rỗng) | | Dùng keyword, không `notice` |
| tóc dựng đứng chĩa gai mohican | | Got2b đứng đầu (0.449), `matched_variant` đúng biến thể, có `attributes` + `embed_text` |

### Lùi về tìm kiếm thường
- Tắt server Python → 200, `search_mode_used = keyword`, `notice` "tạm thời không khả dụng".
- `VECTOR_SEARCH_ENABLED = false` → 200, `notice` "đang tắt".

### Lỗi (400)
`search_mode=x`, `sort=relevance`, `min_price=abc`, `min_price=-1`, `min_price > max_price`, `search` > 200 ký tự ở chế độ vector, `search[]=x`.

### Đồng bộ vector (`ProductEmbedSync::sync`, gọi trực tiếp)
| Tình huống | Kết quả |
| --- | --- |
| Sản phẩm không đổi | 0 vector, ~14 ms, không gọi Python |
| Đổi mô tả | Tạo lại đúng 1 vector (~360 ms) |
| Python tắt, không đổi gì (giống admin sửa giá) | 0 vector, ~10 ms, không lỗi |
| Python tắt, đổi mô tả | Ném lỗi sau ~2 s; ở controller lỗi này bị bỏ qua |
| Script `embed_products.php` chạy lần 2 | `Đã tạo vector: 0, bỏ qua: 11` |

### Khác
- Migration và seed chạy 2 lần liên tiếp không lỗi, kết quả như nhau; sản phẩm `HOANG-VINH-01` không bị đổi.
- Mọi file PHP đã sửa qua `php -l`.

## 2. Chưa kiểm tra

- Gọi thật endpoint admin tạo/sửa sản phẩm (cần token admin). Phần đồng bộ chỉ được test bằng cách gọi trực tiếp `ProductEmbedSync`.
- Giao diện FE (FE chưa nối).
- Dữ liệu lớn (hàng nghìn biến thể).

## 3. Giới hạn đã biết

| Giới hạn | Ví dụ | Ghi chú |
| --- | --- | --- |
| Model yếu với tên riêng, chữ nước ngoài đứng một mình | `mohican` → Suavecito (0.17), dù "mohican" nằm nguyên văn trong đoạn văn của Got2b | Câu có ngữ cảnh ("tóc dựng đứng chĩa gai mohican") thì đúng |
| Model yếu với phủ định | "sáp không bóng cho tóc dày" → Got2b (0.522) trên Claymation (0.513) | "không bóng" và "bóng" bị coi là gần nghĩa |
| Sản phẩm quá giống nhau | 7 sản phẩm đều là đồ vuốt tóc, điểm chênh nhau 0.01–0.05 | Danh mục đa dạng hơn thì vector phát huy tốt hơn |
| Sản phẩm có chữ trùng câu hỏi được lợi | Got2b, "Vinh Râu" có "Vuốt Tóc" trong tên nên hay chen lên | |
| Ngưỡng tương đối luôn giữ sản phẩm đứng đầu | Câu không liên quan vẫn ra ít nhất 1 sản phẩm | |
| Giá hiển thị khi lọc giá | Lọc `min_price=500000` vẫn ra Reuzel Blue với `min_final_price` 390k (vì hũ 340g giá 899k nằm trong khoảng) | `matched_variant.final_price` ở chế độ vector là giá đúng khoảng |
| Đổi tên danh mục không tự tạo lại vector | | Chạy `embed_products.php` |
| Phụ thuộc server Python và RAM | Máy dev chỉ còn ~1 GB RAM trống, từng bị segfault | Có công tắc tắt |

Nhóm đã quyết định **không** làm tìm kết hợp (cộng điểm từ khóa) để vá các giới hạn trên; hướng cải thiện là đổi model mạnh hơn ([04-van-hanh.md, mục 5](04-van-hanh.md)).
