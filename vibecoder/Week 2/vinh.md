# Task FE Product Detail - Vinh

## Bắt buộc trước khi làm
1. Kiểm tra `git status --short --branch`; nếu working tree có thay đổi chưa commit, dừng và hỏi người phụ trách trước khi đổi branch, không tự stash/xóa thay đổi.
2. Cập nhật local `dev` từ remote gốc: `git fetch upstream`, `git switch dev`, rồi `git pull --ff-only upstream dev`.
3. Tạo branch mới từ `dev` vừa cập nhật: `git switch -c feature/FE/product-detail-vinh`. Nếu tên branch đã tồn tại, chọn một tên mới riêng cho task.
4. Chỉ bắt đầu code sau khi đã xác nhận đang ở branch task mới và branch đó được tạo từ `dev` mới nhất.

## Mục tiêu
Hoàn thiện giao diện trang chi tiết sản phẩm hiện đang là trang debug thô, in response của `api-product-detail` và `api-product-comments` bằng hai lời gọi `dd()`.

## Thiết kế bắt buộc
- Dùng MCP Stitch để tham khảo hoặc tạo mẫu giao diện Product Detail, theo phong cách Sharp Barber hiện có.
- Dùng luồng/mẫu MCP Stitch example của nhóm nếu có; không tự nhận đã dùng Stitch nếu MCP không truy cập được.
- Ghi lại Stitch project/screen hoặc design reference đã dùng trong phần bàn giao để nhóm đối chiếu.
- Không sửa hoặc ghi đè các màn Stitch khác không thuộc task này.

## Phạm vi code
- Chỉ sở hữu và sửa `FE/src/views/products/ProductDetail.vue`.
- Khi mở trang, chỉ gọi `GET ?action=api-product-detail&slug=<slug>` để tải sản phẩm.
- Thêm nút **Xem bình luận**. Chỉ sau khi người dùng bấm nút này mới gọi endpoint BE hiện có `GET ?action=api-product-comments&slug=<slug>&page=1&per_page=10`; tuyệt đối không gọi API comments lúc load/mount trang hoặc gọi song song với API sản phẩm.
- Hiển thị các thông tin chi tiết sản phẩm do API trả về, gồm ảnh/album ảnh, tên, danh mục, giá, mô tả và các chỉ số nếu có trong response; chỉ hiển thị danh sách comments sau khi người dùng yêu cầu xem.
- Có trạng thái đang tải/lỗi cho thao tác tải comments. Nếu tải lỗi, cho phép người dùng bấm thử lại; sau khi tải thành công, có thể ẩn/hiện comments mà không gọi lại API.
- Giữ trạng thái loading, lỗi và trường hợp không có comments; không thêm dữ liệu sản phẩm/comments giả.
- Xóa phần `<pre>`/`dd()` khỏi giao diện sau khi đã thay bằng UI thực tế. Giữ nguyên endpoint và query params nêu trên.

## Ranh giới bắt buộc
- Không sửa router, `services/api.js`, helper `dd.js`, component dùng chung, catalog, trang chủ, API/backend, database hoặc schema.
- Không đổi URL `/product-detail/:slug`, endpoint, query params, cấu trúc response hay hành vi chức năng hiện có ngoài việc thay cách trình bày dữ liệu trên trang detail.
- Không thêm dependency hoặc triển khai chức năng mới như giỏ hàng/yêu thích.
- Nếu thiếu trường dữ liệu hoặc cần đổi API để hoàn thành UI, dừng phần đó và báo nhóm; không tự mở rộng phạm vi.

## Tránh xung đột khi gộp
1. Thực hiện đúng các bước cập nhật `dev` và tạo branch riêng ở đầu task; không code trực tiếp trên branch dùng chung.
2. Trước khi bắt đầu, kiểm tra `git status` và xác nhận không có người khác đang sửa `ProductDetail.vue`.
3. Chỉ commit thay đổi thuộc file được giao; không đưa thay đổi build artifact hoặc file ngoài phạm vi vào commit.
4. Trước khi push/PR, kiểm tra `git diff -- FE/src/views/products/ProductDetail.vue` và `git diff --check`. Nếu file đã có thay đổi mới từ người khác, dừng và trao đổi để phối hợp, không ghi đè.

## Tiêu chí nghiệm thu
- Giao diện theo mẫu đã tham khảo bằng Stitch MCP và phù hợp Sharp Barber, có responsive desktop/mobile.
- Khi mở trang, Network chỉ có request `api-product-detail`; không có request `api-product-comments`.
- Bấm **Xem bình luận** mới gửi một request `api-product-comments` với slug hiện tại; lỗi tải comments cho phép thử lại và dữ liệu đã tải không bị gọi lại khi chỉ ẩn/hiện.
- Một slug hợp lệ hiển thị đúng sản phẩm/comments; slug lỗi/API lỗi hiển thị thông báo phù hợp; sản phẩm không có comments không làm vỡ layout.
- Không còn hai khối dump `dd()` trên UI sau khi thay bằng giao diện.
- `npm --prefix FE run build` thành công.
- Bàn giao kèm branch/commit, kết quả build, các trường hợp đã kiểm tra và Stitch project/screen reference.
