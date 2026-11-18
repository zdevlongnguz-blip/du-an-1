# Mission completed - Tuấn Anh (FE) - Tuần 3

Ngày ghi: 2026-11-16. Ghi theo phân công của admin Long, nhánh `feature/user-profile-fields`. Chưa commit, chưa tạo PR.

## Phân công
- Vai trò: chuyên FE. Chia đôi công FE với Ngọc Dung.
- Công kiểm thử tay API: chia đôi với Ngọc Dung. Phần của Tuấn Anh là kiểm thử bằng Thunder Client.

## Phần FE được ghi công
- `helpers/auth/profile.js`: gọi `api-profile` và `api-profile-update`, lưu lại `auth_user`, validate tên/địa chỉ theo byte.
- `helpers/format/phone.js`: quy tắc SĐT di động Việt Nam ở FE, giống hệt bản BE.
- `views/auth/Profile.vue`: hiển thị và sửa hồ sơ tại chỗ, có trạng thái đang tải, lỗi, thử lại. Bố cục 4 cụm.

## Kiểm thử tay API bằng Thunder Client (đã hoàn thiện)
- [x] `GET api-profile`: 200 với token hợp lệ; 401 khi thiếu hoặc sai token; 405 khi sai method.
- [x] `PUT api-profile-update`: lưu hợp lệ; SĐT `+84 867 490 629` được lưu thành `0867490629`; SĐT số cố định trả 422; tên quá 100 byte trả 422; thiếu key trả 400; sai method trả 405.
- [x] `POST api-order-checkout`: SĐT không hợp lệ trả 422, không tạo đơn.

Kết quả: Đã hoàn thiện. Admin Long xác nhận bạn đã kéo code từ `dev` và kiểm thử tay theo checklist.

## Ghi chú
- Phạm vi: chỉ FE và kiểm thử. Không sửa `BE/`.
- Các lần kiểm thử API trong phiên làm việc này do Claude Code chạy bằng curl và script, không phải Thunder Client. Kết quả đó nằm trong `long.md` và `hoangvinh.md`, không tính là kết quả của Tuấn Anh.

---

# Task Bổ Sung: StateBlock (gộp trạng thái đang tải / lỗi / trống)

Ngày ghi: 18/11/2026. Nhánh `feature/week3-tuananh-state-block`, PR #72. Task: `vibecoder/week-3/task/tuananh.md`, kế hoạch: `FE/docs/ke-hoach-state-component.md`.

## Phần Tuấn Anh làm (ghi công)
Commit `9a7e9b6` "feat: add reusable StateBlock component" (tài khoản `kiritosama659-stack`, 18/11/2026 21:34):
- Tạo `FE/src/components/StateBlock.vue`: props `loading`, `error`, `empty`, `loadingText`, `emptyText`, `onRetry`; đúng thứ tự ưu tiên đang tải → lỗi → trống → nội dung; slot `empty-action`; `role="status"` / `role="alert"`; comment tiếng Việt cho từng prop.
- Áp dụng cho 4 trang theo task gốc: `OrderList.vue`, `OrderDetail.vue`, `CheckoutView.vue`, `CartView.vue`; xóa các khối loading/lỗi/trống tự viết và CSS `cart-state`, `cart-empty` không còn dùng.
- Giữ đúng phạm vi: chỉ sửa 5 file của task; không đụng `BE/`, `client.js`, `router/index.js`, `App.vue`, `style.css` và các trang ngoài phạm vi; giữ `<script setup>` của các trang (chỉ thêm `import StateBlock`).
- Ý tưởng và phạm vi đúng; lỗi API không bị hiển thị thành "trống".

Chưa có trong nhánh: ghi nhận kết quả `npm run build` và kiểm tra trình duyệt của Tuấn Anh (task yêu cầu). Kế hoạch đề nghị mỗi trang một commit, nhánh gộp vào 1 commit.

## Review, chỉnh sửa và quyết định cuối (admin Long cùng Claude)
Review bằng `/code-review`, đối chiếu với `dev`, phát hiện:
- `CheckoutView`: form đặt hàng bị bọc trong StateBlock nên biến mất mỗi lần tải lại giỏ (kể cả sau khi đặt hàng bị 409), và mất luôn khi tải lại bị lỗi.
- StateBlock gắn cứng class Bootstrap có `!important`, làm lệch giao diện Stitch của trang Giỏ hàng (trang phải ghi đè bằng `:deep`).
- `OrderDetail` đổi chữ "Đang tải đơn hàng..." thành "Đang tải..."; thiếu comment HTML cho khối StateBlock; một comment cũ ở `CheckoutView` bị viết lại.

Chỉnh sửa (commit do phiên admin Long + Claude tạo):
- `02e68f3`: StateBlock tự mang style theo Stitch (bỏ class Bootstrap gắn cứng); nút "Thử lại" gọi `onRetry()` không kèm sự kiện click; trả lại chữ "Đang tải đơn hàng..."; đưa phân trang của `OrderList` vào trong StateBlock; thêm comment HTML; thụt lề lại nội dung slot; bỏ `:deep` ở `CartView`.
- `5a7cf0e`: Chi tiết đơn ẩn nút "Thử lại" khi lỗi 404 (không tìm thấy đơn); lỗi mạng/server vẫn có nút.
- `9f07557`: **quyết định an toàn cuối cùng của admin Long**: trả `CheckoutView.vue` về đúng bản `dev`, không áp dụng StateBlock cho 2 trang đặt hàng (`CheckoutView.vue`, `ai/AiCheckout.vue`). Lý do: hai trang có trạng thái nghiệp vụ riêng do BE trả về (409 hết hàng, giá đổi, thiếu kho, SePay lỗi, tải lại giỏ mà phải giữ form) và bản trên `dev` đã chạy đúng. Cập nhật kế hoạch và task còn 3 trang.

Kết quả cuối: StateBlock áp dụng cho 3 trang `OrderList`, `OrderDetail`, `CartView`. `git diff origin/dev` của `CheckoutView.vue`, `ai/AiCheckout.vue`, `helpers/`, khung chat và `BE/` rỗng.

## Kiểm thử trên trình duyệt (admin Long cùng Claude)
Claude chạy Chrome thật bằng Node.js (Chrome DevTools Protocol) với tài khoản test, code của nhánh, admin Long theo dõi trực tiếp trên màn hình. Trạng thái đang tải / lỗi / trống được tạo bằng cách chặn và giả lập response API; các ca 409 sửa request để server thật trả 409. Không tạo đơn thật (đơn mới nhất trước và sau test vẫn là #63), giỏ test đã dọn.

- [x] `npm run build`: pass. `git diff --check`: pass.
- [x] 3 trang StateBlock: **21/21 đạt**.
  - Danh sách đơn: có dữ liệu, đang tải, lỗi (không hiện "trống") + Thử lại tải lại được, trống + nút "Xem sản phẩm".
  - Chi tiết đơn: có dữ liệu, đang tải, lỗi + Thử lại; 404 thật (`/orders/99999999`) không có nút Thử lại.
  - Giỏ hàng: có dữ liệu, đang tải, lỗi + Thử lại, trống + nút "Xem sản phẩm".
  - Điện thoại 400px: lỗi và trống của Danh sách đơn, Giỏ hàng không tràn ngang.
- [x] Trọn luồng 2 trang đặt hàng (không đổi so với `dev`): **12/12 đạt**.
  - Đặt tay: thiếu tên báo lỗi dưới ô và không gửi đơn; COD thành công (giả lập 201) gửi kèm `expected_total`; SePay lỗi hiện "Thử thanh toán lại" + "Xem đơn hàng"; 409 giá đổi (server thật) giữ form; thiếu kho "Kho chỉ còn N" khóa nút; ngừng bán khóa nút.
  - AI: xác nhận thành công chuyển sang chi tiết đơn và xóa đơn nháp; 409 (server thật) giữ đơn nháp, mở lại nút.

- [x] Chưa đăng nhập (phiên trình duyệt riêng, không có token): mở `/orders`, `/orders/63`, `/cart` đều bị chuyển sang `/login`.

Chưa kiểm tra:
- Trạng thái "trống" với tài khoản thật không có đơn/giỏ (đã kiểm tra bằng response giả lập).

## Còn lại
- Admin Long duyệt rồi push 3 commit chỉnh sửa lên nhánh, cập nhật PR #72.
