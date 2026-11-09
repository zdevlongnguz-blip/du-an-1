USE `du-an-1`;

UPDATE `banners`
SET `CTA_link` = REPLACE(`CTA_link`, 'category_slug=', 'category=')
WHERE `CTA_link` LIKE '/catalog?category_slug=%';

INSERT INTO `banners` (`title`, `image`, `content`, `CTA_link`)
SELECT 'Chăm sóc tóc mỗi ngày', NULL,
  'Khám phá sản phẩm chăm sóc tóc phù hợp với nhu cầu của bạn.', '/catalog'
WHERE NOT EXISTS (
  SELECT 1 FROM `banners`
  WHERE `title` = 'Chăm sóc tóc mỗi ngày' AND `CTA_link` = '/catalog'
);

INSERT INTO `banners` (`title`, `image`, `content`, `CTA_link`)
SELECT 'Định hình phong cách riêng', NULL,
  'Chọn sáp và gel vuốt tóc để hoàn thiện kiểu tóc bạn yêu thích.',
  '/catalog?category=gel-sap-vuot-toc'
WHERE NOT EXISTS (
  SELECT 1 FROM `banners`
  WHERE `title` = 'Định hình phong cách riêng'
    AND `CTA_link` = '/catalog?category=gel-sap-vuot-toc'
);

INSERT INTO `banners` (`title`, `image`, `content`, `CTA_link`)
SELECT 'Nuôi dưỡng mái tóc khỏe', NULL,
  'Tìm sản phẩm dưỡng tóc giúp chăm sóc mái tóc từ gốc đến ngọn.',
  '/catalog?category=duong-toc'
WHERE NOT EXISTS (
  SELECT 1 FROM `banners`
  WHERE `title` = 'Nuôi dưỡng mái tóc khỏe'
    AND `CTA_link` = '/catalog?category=duong-toc'
);