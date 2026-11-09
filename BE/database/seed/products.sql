USE `du-an-1`;

UPDATE `products`
SET `view_count` = GREATEST(`view_count`, 850 + `id` * 137),
    `sales_count` = GREATEST(`sales_count`, 17 + `id` * 11);

INSERT INTO `product_images` (`product_id`, `image_path`, `sort_order`)
SELECT `products`.`id`, `products`.`image`, 1
FROM `products`
WHERE `products`.`image` IS NOT NULL
  AND TRIM(`products`.`image`) <> ''
  AND NOT EXISTS (
    SELECT 1
    FROM `product_images`
    WHERE `product_images`.`product_id` = `products`.`id`
      AND `product_images`.`image_path` = `products`.`image`
  );

-- Demo Hanz de Fuko dùng default.jpg làm thumbnail và hai ảnh upload làm album.
UPDATE `products`
SET `image` = 'default.jpg'
WHERE `slug` = 'sap-vuot-toc-hanz-de-fuko-claymation';

DELETE FROM `product_images`
WHERE `product_id` = (
  SELECT `id` FROM `products`
  WHERE `slug` = 'sap-vuot-toc-hanz-de-fuko-claymation'
  LIMIT 1
);

INSERT INTO `product_images` (`product_id`, `image_path`, `sort_order`)
SELECT `id`, 'hanz-claymation-1.webp', 1
FROM `products`
WHERE `slug` = 'sap-vuot-toc-hanz-de-fuko-claymation'
  AND EXISTS (
    SELECT 1 FROM `products` AS `seed_product`
    WHERE `seed_product`.`slug` = 'sap-vuot-toc-hanz-de-fuko-claymation'
  );

INSERT INTO `product_images` (`product_id`, `image_path`, `sort_order`)
SELECT `id`, 'images.jpg', 2
FROM `products`
WHERE `slug` = 'sap-vuot-toc-hanz-de-fuko-claymation'
  AND EXISTS (
    SELECT 1 FROM `products` AS `seed_product`
    WHERE `seed_product`.`slug` = 'sap-vuot-toc-hanz-de-fuko-claymation'
  );