# Mission completed - Vinh (FE) - Tuần 3

Ngày ghi: 2026-11-13. Ghi tự động vì không có công cụ kết nối vibe coder.

Tham chiếu: `vibecoder/Week 2/vinh.md` (phần FE Product Detail).

## Mission
- Sửa danh sách catalog, top 5 sản phẩm và trang detail sau khi DB thay đổi.

## Trạng thái

| Hạng mục | Trạng thái | Ghi chú |
| --- | --- | --- |
| FE catalog và top 5 | Hoàn thành | Hiển thị giá thấp nhất sau giảm, đã merge (PR #41) |
| FE trang detail theo biến thể | Hoàn thành | `ProductDetail.vue` hiển thị đủ biến thể, tồn kho riêng, giá "Từ" |
| Chọn số lượng theo từng biến thể | Hoàn thành | Nút −/+ và ô nhập số, kẹp 1..tồn kho khi rời ô, đã merge (PR #42) |
| Thêm vào giỏ theo biến thể | Hoàn thành, chưa test đầy đủ | Gửi `variant_id` và `quantity` tới `api-cart-add` |
| Trang giỏ hàng `/cart` | Hoàn thành, chưa test trình duyệt | `CartView.vue`, có route và link nav (nhánh `feature-cart`, chưa commit) |

## Kiểm tra đã làm
- `npm run build` (FE): OK, lặp lại sau khi thêm route `/cart` và link nav.
- Trình duyệt, chưa đăng nhập: trang detail hiển thị 3 ảnh, giá "Từ", tồn kho, đủ 2 biến thể.
- Trình duyệt, đã đăng nhập: gõ 999 rồi rời ô số lượng được kẹp về 50 (tồn kho).
- `GET /cart` khi chưa đăng nhập: BE trả 401.

## Còn lại
1. Test trình duyệt giỏ hàng khi đã đăng nhập: thêm từ trang detail, đổi số lượng, xóa, kiểm tra tổng tiền.
2. Trang giỏ hàng: kiểm tra trạng thái trống, lỗi và thử lại.
3. Commit và tạo PR cho `feature-cart`.

Chi tiết giỏ hàng: [cart.md](/C:/laragon/www/du-an-1/fix%20code/cart.md).
