# 08. Frontend

> Trạng thái 22/11/2026 (tối): **trang chi tiết sản phẩm đã cập nhật** theo BE mới. Trang admin bình luận chưa làm.

## 1. Đã làm: `FE/src/views/products/ProductDetail.vue`

| Hàm / chỗ | Trước | Sau |
| --- | --- | --- |
| `isBlockedByModeration(error)` (mới) | – | Trả `true` khi lỗi là 422 và `error.response.data.moderation === 'blocked'`, để phân biệt với lỗi 422 khác (nội dung rỗng, quá dài) |
| `submitComment` | Luôn toast xanh, xóa ô nhập, không tải lại danh sách | `approved`: toast `success`, xóa ô nhập, `fetchComments(1)` để bình luận hiện ngay. `pending`: toast `info`, xóa ô nhập. **Bị chặn (422)**: toast `warning`, hiện lỗi dưới ô nhập, **giữ nguyên nội dung** |
| `updateComment` | Luôn toast xanh, thoát chế độ sửa | `approved`: toast `success`. `pending` hoặc "không thay đổi": toast `info`. Cả hai thoát chế độ sửa và tải lại trang hiện tại. **Bị chặn (422)**: toast `warning`, lỗi dưới ô sửa, **giữ chế độ sửa**; bình luận cũ ở DB không đổi |
| Nút "Gửi bình luận" / "Lưu" khi đang gửi | "Đang gửi..." / "Đang lưu..." | "Đang kiểm duyệt..." (request chờ AI, 0.5–3 giây) |

Không đổi: `fetchComments`, `validateComment`, `deleteComment`, phần hiển thị danh sách. Không dùng `StateBlock` vì task chỉ sửa luồng gửi form, không sửa phần tải/hiển thị dữ liệu (FE/CLAUDE.md mục 13). `showToast` dùng kiểu có sẵn `success`, `info`, `warning`, `danger`.

Lỗi dưới ô nhập tự mất khi khách sửa nội dung, vì ô nhập đã có sẵn `@input` gọi lại `validateComment`.

Build: `npm run build` thành công (`✓ built in 4.51s`), ra `BE/public/dist/`.

## 2. Kiểm tra trên trình duyệt

| Thao tác | Kỳ vọng |
| --- | --- |
| Gửi "Sản phẩm rất tốt" | Nút "Đang kiểm duyệt...", rồi toast xanh, ô nhập trống, bình luận hiện ngay ở đầu danh sách |
| Gửi "Hàng tệ quá, thất vọng" | Toast thông tin "đang chờ quản trị viên duyệt", ô nhập trống, không hiện trong danh sách |
| Gửi "Như cặc" | Toast vàng, chữ đỏ dưới ô nhập, ô nhập **vẫn còn** nội dung. Gõ sửa thì chữ đỏ mất |
| Sửa bình luận đang hiện thành câu chửi | Toast vàng, vẫn ở chế độ sửa; bấm Hủy thì thấy bình luận cũ vẫn nguyên |
| Sửa bình luận thành "Giao hàng chậm, đóng gói cẩu thả" | Toast thông tin chờ duyệt, bình luận biến khỏi danh sách |
| Sửa nhưng không đổi nội dung | Toast "Nội dung bình luận không thay đổi" |
| Tắt Colab (hoặc `COMMENT_GUARD_ENABLED=0`) rồi gửi | Toast "chờ quản trị viên duyệt", không lỗi |

## 3. Chưa làm: trang admin bình luận

`AdminDashboard.vue` đang để mục "Bình luận" là `ready: false`. Khi làm:

| Hiển thị | Lấy từ |
| --- | --- |
| Lọc "Chờ duyệt / Đã duyệt / Tất cả" | Query `status` |
| Huy hiệu "AI không chắc" (vàng) | `status = 0` và `moderation_label = 'CLEAN'` |
| Huy hiệu "Chưa chấm" (xám) | `moderation_label = null` (AI lỗi/tắt, hoặc bình luận cũ) |
| Huy hiệu "Bị AI chặn" (đỏ) | `status = 0` và nhãn khác `CLEAN`. Chỉ còn ở dữ liệu cũ, vì từ 22/11/2026 câu bị chặn không được lưu |
| Cảm xúc | `sentiment`: POS = Khen, NEG = Chê, NEU = Trung tính, `null` = – |
| Điểm độc hại | `Number(toxic_prob)` (API trả chuỗi), hiện dạng % |
| Nút Duyệt / Xóa | `api-admin-comment-approve`, `api-admin-comment-delete` |

## 4. Không nên làm

- **Không** gọi `api-comment-guard` trước khi gửi bình luận. Kiểm duyệt đã nằm ở server; API đó chỉ để nhóm thử model và trả nguyên điểm AI.
- **Không** tự quyết hiện/ẩn ở FE dựa trên kết quả AI.
- **Không** hiện nhãn hay điểm AI cho khách thường.
