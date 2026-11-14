# CLAUDE.md (gốc dự án)

Dự án môn học gồm 2 phần:
- `BE/`: PHP MVC thuần + MySQL (PDO). Quy tắc chi tiết: `BE/CLAUDE.md`.
- `FE/`: Vue 3 + Vite + Vue Router + Axios + Bootstrap 5. Quy tắc chi tiết: `FE/CLAUDE.md`.

## Quy tắc chung
- Task thuộc phần nào thì chỉ sửa phần đó. Task chỉ thuộc BE thì không sửa `FE/`, và ngược lại.
- Trước khi sửa code ở `BE/` hoặc `FE/`, đọc `CLAUDE.md` của thư mục đó. Task liên quan cả hai phía (FE-BE) thì đọc cả hai file.
- Trước khi sửa: chạy `git status`. Không ghi đè, revert hay format hàng loạt thay đổi chưa commit của người khác.
- Endpoint API là contract giữa FE và BE: thống nhất contract trước khi code, không âm thầm đổi.
- Comment bằng tiếng Việt có dấu, viết cho người mới học (chi tiết trong từng file).
- Dự án chưa có test tự động: không bịa lệnh test, không tuyên bố pass khi chưa chạy kiểm tra.
