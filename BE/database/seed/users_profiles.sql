-- Seed: dữ liệu mẫu cho hồ sơ người dùng (họ tên, số điện thoại, địa chỉ).
-- Cần chạy sau migration 20261116_add_user_profile_fields.sql (cột name, phone, address phải có sẵn).
-- Chỉ dùng UPDATE theo email nên chạy lại nhiều lần vẫn cho cùng kết quả, không tạo dòng trùng.
-- Tài khoản không tồn tại thì câu UPDATE không làm gì (0 dòng), không gây lỗi.

USE `du-an-1`;

-- Tài khoản test thật của nhóm: chỉ điền hồ sơ, KHÔNG đổi mật khẩu.
UPDATE `users`
SET `name` = 'Lê Xuân Long',
    `phone` = '0867490629',
    `address` = '08/05 Thôn Bột Trung, Hoằng Lộc, Hoằng Hóa, Thanh Hóa'
WHERE `email` = 'xuanlonghh4@gmail.com';

-- Hai tài khoản mẫu: đặt lại mật khẩu thành 12345678 và điền hồ sơ giả định.
-- Cột password chỉ lưu kết quả password_hash() (chuỗi bcrypt bắt đầu bằng $2y$), không bao giờ lưu mật khẩu dạng rõ.
UPDATE `users`
SET `password` = '$2y$10$I2sjj8g/jNLUwrrr0x56tuZXxDzSUCMVa9XQaps./CbuDVwQfQomq',
    `name` = 'Quản Trị Viên',
    `phone` = '0900000001',
    `address` = '01 Đường Mẫu, Phường Bến Nghé, Quận 1, TP. Hồ Chí Minh'
WHERE `email` = 'admin@example.com';

UPDATE `users`
SET `password` = '$2y$10$I2sjj8g/jNLUwrrr0x56tuZXxDzSUCMVa9XQaps./CbuDVwQfQomq',
    `name` = 'Người Dùng Mẫu',
    `phone` = '0900000002',
    `address` = '02 Đường Mẫu, Phường Tràng Tiền, Quận Hoàn Kiếm, Hà Nội'
WHERE `email` = 'user@example.com';
