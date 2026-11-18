# Task Tuần 3 - Tuấn Anh: Gộp Trạng Thái Loading / Lỗi / Trống Thành Component

Tuần: 3
Ngày giao task này: 17/11/2026
Phạm vi: chỉ làm trên FE. Tuấn Anh không được sửa bất kỳ file nào trong `BE/`, không đổi API contract, schema hoặc migration.

## Tạo Branch Trước Khi Làm

- Trước khi code, yêu cầu AI trong VS Code kiểm tra `git status`, fetch code mới nhất từ remote `origin`, rồi tạo branch riêng cho task này từ `origin/dev` mới nhất. Không code trực tiếp trên `dev` hoặc branch của người khác.
- Branch đề xuất: `feature/week3-tuananh-state-block`. Ví dụ: `git fetch origin` rồi `git switch -c feature/week3-tuananh-state-block origin/dev`.
- Nếu working tree không sạch hoặc branch đề xuất đã tồn tại, dừng và báo admin để thống nhất; không bỏ thay đổi hiện có.

## Điều Kiện Bắt Đầu

- Trước khi code, chạy file SQL đầy đủ được cung cấp để chuẩn bị database local/dev và xác nhận có dữ liệu để test 3 trang (đơn hàng, giỏ hàng).
- Nếu không tìm thấy file SQL đầy đủ, file thiếu bảng/dữ liệu, hoặc chạy SQL thất bại: dừng task và báo admin. Không tự tạo/chỉnh SQL, không sửa BE để né lỗi database.
- Chỉ chạy trên database local/dev đã được chỉ định; không chạy script có thể xóa dữ liệu trên môi trường dùng chung/production.
- Xác nhận với admin trước khi bắt đầu: 3 trang trong phạm vi chưa có ai đang sửa (theo mục 10 của `FE/CLAUDE.md`).

## Mục Tiêu

Tạo `FE/src/components/StateBlock.vue` dùng chung cho các trạng thái loading, lỗi (có nút thử lại) và trống, rồi áp dụng cho 3 trang: `OrderList.vue`, `OrderDetail.vue`, `CartView.vue`.

**Cập nhật 18/11/2026 (quyết định của admin):** bỏ `CheckoutView.vue` khỏi phạm vi. Hai trang đặt hàng (`CheckoutView.vue` đặt tay và `ai/AiCheckout.vue` đặt qua AI) có trạng thái nghiệp vụ riêng do BE trả về (hết hàng, giá đổi, thiếu kho, SePay lỗi, tải lại giỏ mà phải giữ form). Bản trên `dev` đã chạy đúng, nên **không sửa** hai file này; `git diff origin/dev` của chúng phải rỗng.

Kế hoạch chi tiết (props, thứ tự ưu tiên, các bước, checklist): `FE/docs/ke-hoach-state-component.md`. Đọc hết file này trước khi code.

## Phạm Vi

- Tạo `StateBlock.vue` theo kế hoạch, có comment tiếng Việt đầy đủ.
- Chỉ sửa phần `<template>` và `<style scoped>` của 3 trang; giữ nguyên `<script setup>`, API call và tên biến hiện có, trừ khi cần truyền prop mới.
- Không sửa `FE/src/helpers/api/client.js`, `router/index.js`, `App.vue`, `style.css`, `BE/`, contract hoặc schema.
- Không đụng `CheckoutView.vue`, `ai/AiCheckout.vue`, `ProductDetail.vue`, `HomeView.vue`, `Profile.vue`, `catalog.vue` (ngoài phạm vi).
- Giữ nguyên nội dung thông báo tiếng Việt của từng trang.

## Tiêu Chí Nghiệm Thu

- Lỗi API không bao giờ hiện là trạng thái trống.
- Mỗi trang có đủ 4 trường hợp: có dữ liệu, rỗng, lỗi API (có nút thử lại và bấm lại được), loading. Riêng Chi tiết đơn không có trạng thái rỗng (đơn không tồn tại thì BE trả 404, hiện dạng lỗi, không có nút thử lại).
- `CheckoutView.vue` và `ai/AiCheckout.vue` không đổi so với `dev`; cả hai luồng đặt hàng vẫn chạy như cũ.
- `npm run build` trong `FE/` pass; ghi kết quả thật vào báo cáo.
- Kiểm tra thủ công trên trình duyệt ở ~400px và desktop; ghi rõ phần nào chưa kiểm tra được.
- `git diff`: chỉ có file trong phạm vi, không có URL cứng mới. `git diff --check` pass.

## Báo Cáo

Ghi vào `vibecoder/week-3/mission-completed/tuananh.md` (mục riêng cho task này): task/branch, file đã đổi, kết quả build, kết quả kiểm tra từng trang, việc còn lại và vấn đề đã báo admin. Chỉ ghi điều đã kiểm chứng. Không sửa hoặc ghi đè task brief này.
