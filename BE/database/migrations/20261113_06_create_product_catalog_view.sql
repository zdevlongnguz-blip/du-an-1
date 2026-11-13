USE `du-an-1`;

-- =====================================================================
-- Migration 06: view v_product_catalog cho trang danh sách sản phẩm
-- Gộp thông tin chung của sản phẩm với giá thấp nhất/cao nhất và tổng tồn kho của các biến thể đang bán.
-- Cột: variant_count (số biến thể đang bán), min_final_price/max_final_price (giá bán thực tế thấp/cao nhất),
-- total_stock (tổng tồn kho), in_stock (1 nếu còn ít nhất một biến thể còn hàng).
-- Sản phẩm không có biến thể đang bán sẽ có giá NULL và variant_count = 0.
-- =====================================================================

CREATE OR REPLACE VIEW `v_product_catalog` AS
SELECT
  `p`.`id`, `p`.`category_id`, `p`.`code`, `p`.`name`, `p`.`slug`, `p`.`image`, `p`.`description`,
  `p`.`view_count`, `p`.`sales_count`, `p`.`created_at`, `p`.`updated_at`,
  COUNT(`v`.`id`) AS `variant_count`,
  MIN(`v`.`final_price`) AS `min_final_price`,
  MAX(`v`.`final_price`) AS `max_final_price`,
  COALESCE(SUM(`v`.`stock`), 0) AS `total_stock`,
  COALESCE(MAX(`v`.`stock` > 0), 0) AS `in_stock`
FROM `products` AS `p`
LEFT JOIN `product_variants` AS `v` ON `v`.`product_id` = `p`.`id` AND `v`.`is_active` = 1
GROUP BY `p`.`id`;
