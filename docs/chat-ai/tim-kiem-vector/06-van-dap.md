# 06. Câu hỏi thường gặp khi bảo vệ

Trả lời ngắn, nói được ngay. Mỗi câu có file tham chiếu để chỉ khi bị hỏi sâu.

## Khái niệm

**Q: Tìm kiếm thông minh khác tìm kiếm thường thế nào?**
A: Tìm thường dùng `LIKE`, chỉ ra sản phẩm có đúng chữ khách gõ. Tìm thông minh biến câu và sản phẩm thành vector rồi so độ giống về nghĩa, nên "mùi cam chanh" vẫn ra pomade "mùi cam chanh dễ chịu" dù câu chữ không trùng hẳn. → [README.md](README.md)

**Q: Embedding là gì?**
A: Một dãy 768 số đại diện cho ý nghĩa của đoạn văn, do model AI tạo ra. Hai đoạn gần nghĩa thì hai dãy số "chỉ cùng hướng".

**Q: Cosine similarity là gì, sao không dùng khoảng cách thường?**
A: Cosine đo góc giữa hai vector, từ -1 đến 1. Công thức chia cho độ dài vector nên chỉ so "hướng", đoạn văn dài hay ngắn không làm điểm cao hơn. → `EmbedService::cosineSimilarity`

**Q: Dùng model nào, sao chọn nó?**
A: `bkai-foundation-models/vietnamese-bi-encoder` (PhoBERT, 768 chiều): huấn luyện riêng cho tiếng Việt, miễn phí (Apache-2.0), cỡ vừa (~540 MB), chạy được trên CPU.

**Q: "Tách từ" là gì, sao cần?**
A: Model này yêu cầu từ ghép được nối bằng dấu `_` ("Hà_Nội"). Server Python tự tách bằng thư viện pyvi trước khi đưa vào model; bỏ bước này kết quả kém hẳn.

## Kiến trúc

**Q: Sao phải có server Python riêng?**
A: Model chỉ chạy bằng Python. Server nạp model một lần rồi giữ trong RAM nên mỗi lần encode vài chục ms; gọi Python mỗi request thì phải nạp lại model mất vài giây. PHP gọi sang bằng cURL. → [01-kien-truc.md, 7](01-kien-truc.md)

**Q: Server Python chết thì sao?**
A: API tự dùng tìm kiếm thường, vẫn trả 200, kèm `notice` để FE báo khách. Admin lưu sản phẩm cũng không bị ảnh hưởng. Có thêm công tắc `VECTOR_SEARCH_ENABLED` để tắt hẳn. → [01-kien-truc.md, 5](01-kien-truc.md)

**Q: Bộ lọc danh mục, giá chạy thế nào ở chế độ vector?**
A: SQL lọc trước ra "vòng bo", vector chỉ chấm điểm trong vòng bo. Sản phẩm ngoài khoảng giá không bao giờ xuất hiện dù điểm cao. Hai chế độ dùng chung một hàm lọc (`buildCatalogFilters`) nên lọc giống hệt nhau.

**Q: Sao chế độ vector không phân trang?**
A: SQL không biết điểm vector. Phân trang bằng SQL trước thì vector chỉ chấm điểm trang đầu, sản phẩm liên quan nhất có thể bị bỏ sót. Kết quả đã cắt còn tối đa 20 nên trả cả danh sách. → [01-kien-truc.md, 4](01-kien-truc.md)

**Q: Vector cho sản phẩm nào cũng có điểm, làm sao biết cái nào là kết quả?**
A: Ngưỡng tương đối: chỉ giữ sản phẩm có điểm ≥ 80% điểm cao nhất, tối đa 20. Câu rõ ràng (một sản phẩm vượt trội) ra ít kết quả, câu chung chung giữ nhiều hơn. Ví dụ "mùi cam chanh": 0.384 / 0.176 / ... → chỉ còn 1 sản phẩm.

**Q: Sao embed theo biến thể mà không theo sản phẩm?**
A: Biến thể có thể khác đặc điểm (bản bóng / không bóng). Giỏ hàng, đơn hàng làm theo SKU nên biết biến thể khớp là có SKU ngay. Khi trả kết quả thì gom theo sản phẩm, lấy biến thể điểm cao nhất (`matched_variant`).

**Q: Sao không dùng vector database (Pinecone, Qdrant...)?**
A: Dữ liệu chỉ vài chục biến thể. Lưu vector dạng JSON trong MySQL rồi tính cosine trong PHP vẫn nhanh (cả lượt tìm ~100–260 ms, phần lớn là encode câu hỏi), không phải cài thêm hệ thống. MySQL 8.0 chưa có kiểu `VECTOR`.

## Dữ liệu

**Q: Đoạn văn tạo vector gồm những gì?**
A: Tên sản phẩm, tên biến thể, danh mục, mô tả, và các thuộc tính (độ giữ nếp, độ bóng, hợp tóc nào, kiểu tóc, mùi...) ghép theo mẫu câu cố định. → [03-du-lieu.md, 3](03-du-lieu.md)

**Q: Sao không đưa giá, tồn kho vào?**
A: Chúng đổi liên tục, đưa vào thì phải tạo lại vector liên tục; model cũng không hiểu tốt con số. Giá được lọc/sắp xếp bằng SQL.

**Q: Admin sửa sản phẩm thì vector cập nhật thế nào?**
A: Sau khi lưu thành công, controller gọi `ProductEmbedSync::sync`: ghép lại đoạn văn, so `text_hash`. Không đổi (vd chỉ sửa giá) thì không gọi Python; đổi thì tạo lại vector. Mọi lỗi đều bị bỏ qua để không chặn việc lưu. → [01-kien-truc.md, 6](01-kien-truc.md)

**Q: Sao khóa trong `attributes` viết không dấu?**
A: Chữ có dấu có thể lưu theo 2 kiểu Unicode (NFC/NFD), nhìn giống nhau nhưng máy coi là 2 khóa khác. Khóa không dấu tránh được lỗi đó; giá trị thì vẫn viết có dấu. → [03-du-lieu.md, 2](03-du-lieu.md)

## Hạn chế

**Q: Kết quả có luôn đúng không?**
A: Không. Model yếu với tên riêng đứng một mình ("mohican") và phủ định ("không bóng"); 7 sản phẩm demo lại rất giống nhau nên điểm sát nhau. Có ghi rõ trong [05-kiem-thu.md, 3](05-kiem-thu.md).

**Q: Sao không kết hợp thêm tìm theo từ khóa để vá?**
A: Nhóm chọn giữ tìm kiếm nâng cao là vector thuần, vì vấn đề nằm ở độ mạnh của model. Hướng cải thiện là đổi model mạnh hơn; kiến trúc đã sẵn: đổi tên model ở server Python rồi tạo lại vector, BE không sửa. → [04-van-hanh.md, 5](04-van-hanh.md)

**Q: FE đã dùng chưa?**
A: Chưa. Mới xong API; không gửi tham số mới thì API như cũ nên trang hiện tại vẫn chạy bình thường.
