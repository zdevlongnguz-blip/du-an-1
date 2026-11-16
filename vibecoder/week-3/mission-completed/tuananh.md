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
