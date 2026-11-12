USE `du-an-1`;

-- Dữ liệu demo cho thanh toán ShePay: 1 đơn hàng của user@example.com vẫn chưa thanh toán.
-- Chạy lại seeder không tạo trùng vì kiểm tra điều kiện trước khi insert.
INSERT INTO `orders` (
  `user_id`,
  `recipient_name`,
  `recipient_phone`,
  `shipping_address`,
  `total_amount`,
  `order_status`,
  `payment_status`
)
SELECT
  `u`.`id`,
  'Nguyễn Văn Demo',
  '0901234567',
  '123 Đường Lê Lợi, Quận 1, TP.HCM',
  '450000.00',
  'pending',
  'unpaid'
FROM `users` AS `u`
WHERE `u`.`email` = 'user@example.com'
  AND NOT EXISTS (
    SELECT 1
    FROM `orders` AS `o`
    WHERE `o`.`user_id` = `u`.`id`
      AND `o`.`recipient_name` = 'Nguyễn Văn Demo'
      AND `o`.`order_status` = 'pending'
      AND `o`.`payment_status` = 'unpaid'
  );

INSERT INTO `order_details` (
  `order_id`,
  `product_id`,
  `product_name`,
  `quantity`,
  `unit_price`
)
SELECT
  `o`.`id`,
  `p`.`id`,
  `p`.`name`,
  1,
  COALESCE(`p`.`sale_price`, `p`.`price`)
FROM `orders` AS `o`
JOIN `users` AS `u` ON `u`.`id` = `o`.`user_id`
JOIN `products` AS `p` ON `p`.`code` = 'CLAYMATION'
WHERE `u`.`email` = 'user@example.com'
  AND `o`.`recipient_name` = 'Nguyễn Văn Demo'
  AND `o`.`order_status` = 'pending'
  AND `o`.`payment_status` = 'unpaid'
  AND NOT EXISTS (
    SELECT 1
    FROM `order_details` AS `od`
    WHERE `od`.`order_id` = `o`.`id`
      AND `od`.`product_id` = `p`.`id`
  );
