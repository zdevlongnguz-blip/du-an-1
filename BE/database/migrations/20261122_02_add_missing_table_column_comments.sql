USE `du-an-1`;

-- =====================================================================
-- Migration 20261122_02: thêm comment tiếng Việt cho các bảng và cột còn thiếu
--
-- Bảng được bổ sung: banners, carts, categories, comments, orders, product_images, users, schema_migrations.
-- Các bảng đã đủ comment từ trước (giữ nguyên): ai_conversations, ai_messages, cart_items,
-- order_details, product_variants, products.
--
-- Chỉ thêm/sửa COMMENT, KHÔNG đổi kiểu dữ liệu, NULL/NOT NULL, giá trị mặc định, charset, khóa hay index.
-- MySQL chỉ cho thêm comment cột bằng MODIFY COLUMN, mà MODIFY phải viết lại TOÀN BỘ định nghĩa cột.
-- Vì vậy mỗi định nghĩa dưới đây được chép nguyên từ SHOW CREATE TABLE của DB ngày 22/11/2026.
-- Khi sửa file này: chép sai một chi tiết (ví dụ quên DEFAULT, quên ON UPDATE) là đổi luôn cấu trúc bảng.
--
-- Chạy lặp được: chạy lại chỉ ghi đè cùng comment, kết quả không đổi.
-- Với MySQL 8, ALTER chỉ đổi comment là thao tác sửa metadata, không chép lại dữ liệu bảng.
-- =====================================================================

-- ---------------------------------------------------------------------
-- banners: ảnh trình chiếu ở trang chủ
-- ---------------------------------------------------------------------
ALTER TABLE `banners`
  COMMENT = 'Banner trình chiếu ở trang chủ: tiêu đề, ảnh, nội dung và đường dẫn nút bấm',
  MODIFY COLUMN `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính của banner',
  MODIFY COLUMN `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tiêu đề hiển thị trên banner',
  MODIFY COLUMN `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Tên file ảnh trong BE/assets/uploads hoặc URL ảnh. NULL = banner không có ảnh',
  MODIFY COLUMN `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nội dung mô tả hiển thị trên banner',
  MODIFY COLUMN `CTA_link` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Đường dẫn khi bấm nút kêu gọi hành động (CTA = Call To Action) trên banner',
  MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo banner',
  MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất';

-- ---------------------------------------------------------------------
-- carts: mỗi user một giỏ hàng
-- ---------------------------------------------------------------------
ALTER TABLE `carts`
  COMMENT = 'Giỏ hàng của user, mỗi user tối đa một giỏ. Các dòng sản phẩm nằm ở cart_items',
  MODIFY COLUMN `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính của giỏ hàng',
  MODIFY COLUMN `user_id` bigint unsigned NOT NULL COMMENT 'Chủ giỏ hàng (users.id), duy nhất. Xóa user thì xóa luôn giỏ',
  MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo giỏ (lần đầu user thêm hàng)',
  MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất';

-- ---------------------------------------------------------------------
-- categories: danh mục sản phẩm
-- ---------------------------------------------------------------------
ALTER TABLE `categories`
  COMMENT = 'Danh mục sản phẩm, ví dụ sáp vuốt tóc, gôm xịt tóc',
  MODIFY COLUMN `id` int NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính của danh mục',
  MODIFY COLUMN `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tên danh mục hiển thị',
  MODIFY COLUMN `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Đường dẫn thân thiện của danh mục, duy nhất, dùng để lọc sản phẩm trên URL',
  MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo danh mục',
  MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất';

-- ---------------------------------------------------------------------
-- comments: bình luận sản phẩm, có AI kiểm duyệt (xem docs/binh-luan)
-- ---------------------------------------------------------------------
ALTER TABLE `comments`
  COMMENT = 'Bình luận sản phẩm của user. AI kiểm duyệt khi tạo/sửa; status quyết định hiện hay ẩn',
  MODIFY COLUMN `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính của bình luận',
  MODIFY COLUMN `product_id` int NOT NULL COMMENT 'Sản phẩm được bình luận (products.id). Xóa sản phẩm thì xóa luôn bình luận',
  MODIFY COLUMN `user_id` bigint unsigned NOT NULL COMMENT 'Người viết (users.id), lấy từ JWT, không lấy từ body. Xóa user thì xóa luôn bình luận',
  MODIFY COLUMN `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nội dung bình luận đã trim, tối đa 2000 ký tự (kiểm tra ở controller)',
  MODIFY COLUMN `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Hiện công khai hay không: 1 = hiện, 0 = ẩn (chờ admin duyệt hoặc bị AI chặn)',
  MODIFY COLUMN `moderation_label` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nhãn AI kiểm duyệt (model visolex/visobert-hsd): CLEAN, OFFENSIVE, HATE. NULL = chưa chấm',
  MODIFY COLUMN `sentiment` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nhãn AI cảm xúc (model 5CD-AI/Vietnamese-Sentiment-visobert): POS, NEG, NEU. NULL = bị chặn hoặc chưa chấm',
  MODIFY COLUMN `toxic_prob` decimal(5,4) DEFAULT NULL COMMENT 'Xác suất độc hại 0..1 do AI tính, lưu để chỉnh ngưỡng. NULL = chưa chấm',
  MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo bình luận',
  MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm sửa hoặc duyệt gần nhất';

-- ---------------------------------------------------------------------
-- orders: đơn hàng (payment_method và payment_invoice_number đã có comment, chép lại nguyên văn)
-- ---------------------------------------------------------------------
ALTER TABLE `orders`
  COMMENT = 'Đơn hàng của user: người nhận, tổng tiền, trạng thái giao hàng và thanh toán. Các dòng hàng nằm ở order_details',
  MODIFY COLUMN `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính, cũng là mã đơn hàng',
  MODIFY COLUMN `user_id` bigint unsigned NOT NULL COMMENT 'Người đặt (users.id). Khóa ngoại không có ON DELETE: không xóa được user còn đơn, để giữ lịch sử đơn',
  MODIFY COLUMN `recipient_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tên người nhận hàng (snapshot lúc đặt, không đổi khi user sửa hồ sơ)',
  MODIFY COLUMN `recipient_phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Số điện thoại người nhận (snapshot lúc đặt)',
  MODIFY COLUMN `shipping_address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Địa chỉ giao hàng (snapshot lúc đặt)',
  MODIFY COLUMN `total_amount` decimal(12,2) NOT NULL COMMENT 'Tổng tiền đơn (VND) do server tính từ giá biến thể lúc đặt, không nhận từ client',
  MODIFY COLUMN `order_status` enum('pending','confirmed','shipping','delivered','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'Trạng thái giao hàng: pending = chờ xử lý, confirmed = đã xác nhận, shipping = đang giao, delivered = đã giao, cancelled = đã hủy',
  MODIFY COLUMN `payment_status` enum('unpaid','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid' COMMENT 'Trạng thái thanh toán: unpaid = chưa trả, paid = đã trả',
  MODIFY COLUMN `payment_method` enum('cod','sepay') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cod' COMMENT 'Phương thức thanh toán: cod = khi nhận hàng, sepay = online qua SePay',
  MODIFY COLUMN `payment_invoice_number` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Mã hóa đơn SePay của lần thanh toán online gần nhất (NULL nếu chưa thanh toán online)',
  MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm đặt hàng. Dùng để tự hủy đơn online quá hạn thanh toán',
  MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất';

-- ---------------------------------------------------------------------
-- product_images: album ảnh slide của sản phẩm
-- ---------------------------------------------------------------------
ALTER TABLE `product_images`
  COMMENT = 'Album ảnh slide ở trang chi tiết sản phẩm (ngoài ảnh đại diện products.image)',
  MODIFY COLUMN `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính của ảnh',
  MODIFY COLUMN `product_id` int NOT NULL COMMENT 'Sản phẩm sở hữu ảnh (products.id). Xóa sản phẩm thì xóa luôn ảnh',
  MODIFY COLUMN `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tên file ảnh trong BE/assets/uploads',
  MODIFY COLUMN `sort_order` int NOT NULL DEFAULT '0' COMMENT 'Thứ tự hiển thị trong slide, số nhỏ đứng trước',
  MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm thêm ảnh',
  MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất';

-- ---------------------------------------------------------------------
-- users: tài khoản (cột password có comment tiếng Anh, đổi sang tiếng Việt)
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  COMMENT = 'Tài khoản người dùng và admin: đăng nhập, xác thực email, đặt lại mật khẩu, hồ sơ giao hàng',
  MODIFY COLUMN `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính, cũng là claim sub trong JWT',
  MODIFY COLUMN `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Email đăng nhập, lưu chữ thường, duy nhất',
  MODIFY COLUMN `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Họ tên trong hồ sơ, dùng để điền sẵn người nhận khi đặt hàng. NULL = chưa điền',
  MODIFY COLUMN `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Số điện thoại trong hồ sơ. NULL = chưa điền',
  MODIFY COLUMN `address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Địa chỉ giao hàng mặc định trong hồ sơ. NULL = chưa điền',
  MODIFY COLUMN `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Mật khẩu đã băm bằng password_hash() (bcrypt), không bao giờ lưu mật khẩu dạng rõ',
  MODIFY COLUMN `role` enum('admin','user') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user' COMMENT 'Vai trò: user = khách hàng, admin = quản trị. Ghi vào JWT và kiểm tra lại với DB ở mỗi request',
  MODIFY COLUMN `is_active` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Đã xác thực email chưa: 1 = đã kích hoạt, đăng nhập được; 0 = chưa xác thực',
  MODIFY COLUMN `token_version` int unsigned NOT NULL DEFAULT '0' COMMENT 'Phiên bản token. Tăng lên khi đổi mật khẩu để mọi JWT cũ (mang số cũ) bị từ chối',
  MODIFY COLUMN `verification_token` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Mã SHA-256 của token xác thực email (không lưu token gốc). NULL sau khi đã xác thực',
  MODIFY COLUMN `verification_expires_at` datetime DEFAULT NULL COMMENT 'Hạn dùng token xác thực email, giờ UTC',
  MODIFY COLUMN `password_reset_token` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Mã SHA-256 của token đặt lại mật khẩu (không lưu token gốc). NULL khi không có yêu cầu',
  MODIFY COLUMN `password_reset_expires_at` datetime DEFAULT NULL COMMENT 'Hạn dùng token đặt lại mật khẩu, giờ UTC',
  MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm đăng ký',
  MODIFY COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất';

-- ---------------------------------------------------------------------
-- schema_migrations: bảng kỹ thuật do BE/database/run.php tự tạo
-- ---------------------------------------------------------------------
ALTER TABLE `schema_migrations`
  COMMENT = 'Bảng kỹ thuật do BE/database/run.php tạo: ghi các file migration đã chạy để không chạy lại',
  MODIFY COLUMN `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tên file migration đã chạy, ví dụ 20261122_01_add_comments_ai_labels.sql',
  MODIFY COLUMN `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm chạy migration';
