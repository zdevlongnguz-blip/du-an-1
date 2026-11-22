<?php
/**
 * Router chính của ứng dụng.
 *
 * Mỗi action tương ứng với một endpoint API hoặc page.
 * Những route cần bảo vệ auth thì gọi middleware trước khi vào controller.
 *
 * Thứ tự nhóm trong file (chỉ để dễ tìm, không ảnh hưởng cách chạy):
 *  1. Hệ thống
 *  2. Tài khoản và xác thực
 *  3. Hồ sơ cá nhân
 *  4. Sản phẩm, danh mục, banner (công khai)
 *  5. Bài viết
 *  6. Bình luận (công khai và user)
 *  7. Giỏ hàng
 *  8. Đơn hàng và thanh toán
 *  9. Trợ lý AI
 * 10. Quản trị (admin): chung, đơn hàng, sản phẩm, biến thể, ảnh, danh mục, banner, bình luận
 * 11. Route test / demo (không dùng làm mẫu)
 */

$action = $_GET['action'] ?? '/';

switch ($action) {
    // ======================================================================
    // 1. HỆ THỐNG
    // ======================================================================

    // Trang gốc của BE.
    case '/':
        (new HomeController)->index();
        break;

    // API kiểm tra BE còn chạy, không yêu cầu đăng nhập.
    case 'api-check':
        (new HomeController)->check();
        break;

    // ======================================================================
    // 2. TÀI KHOẢN VÀ XÁC THỰC
    // ======================================================================

    // API đăng ký tài khoản, không yêu cầu đăng nhập.
    case 'api-register':
        (new AuthController)->register();
        break;

    // API xác thực email qua link trong thư, không yêu cầu đăng nhập.
    case 'api-verify-email':
        (new AuthController)->verifyEmail();
        break;

    // API đăng nhập, trả JWT; không yêu cầu đăng nhập.
    case 'api-login':
        (new AuthController)->login();
        break;

    // API đăng xuất.
    case 'api-logout':
        (new AuthController)->logout();
        break;

    // API gửi email quên mật khẩu, không yêu cầu đăng nhập.
    case 'api-forgot-password':
        (new AuthController)->forgotPassword();
        break;

    // API đặt lại mật khẩu bằng token trong email, không yêu cầu đăng nhập.
    case 'api-reset-password':
        (new AuthController)->resetPassword();
        break;

    // Trang trung gian mở từ link đặt lại mật khẩu trong email, chuyển sang FE.
    case 'reset-password-bridge':
        (new AuthController)->showPasswordResetBridge();
        break;

    // API đổi mật khẩu của user đang đăng nhập.
    case 'api-change-password':
        // Middleware kiểm tra Bearer JWT và trả payload đã xác minh; claim `sub` là ID user.
        $authPayload = (new AuthMiddleware())->requireAuth();
        // Truyền danh tính từ token xuống controller, không nhận user ID do client tự gửi.
        (new AuthController)->changePassword($authPayload);
        break;

    // ======================================================================
    // 3. HỒ SƠ CÁ NHÂN
    // ======================================================================

    // API lấy hồ sơ cá nhân (họ tên, SĐT, địa chỉ) của user đang đăng nhập. Yêu cầu Bearer token.
    case 'api-profile':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new ProfileController)->show($authPayload);
        break;

    // API cập nhật họ tên, SĐT, địa chỉ của user đang đăng nhập (PUT). Yêu cầu Bearer token.
    case 'api-profile-update':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new ProfileController)->update($authPayload);
        break;

    // ======================================================================
    // 4. SẢN PHẨM, DANH MỤC, BANNER (CÔNG KHAI)
    // ======================================================================

    // API danh sách sản phẩm (tìm kiếm, lọc, phân trang), không yêu cầu đăng nhập.
    case 'api-products':
        // (new AuthMiddleware())->requireAuth();
        (new ProductController)->index();
        break;

    // API lấy top 5 sản phẩm theo lượt xem hoặc lượt bán, không yêu cầu đăng nhập.
    case 'api-products-top':
        (new ProductController)->top();
        break;

    // API lấy chi tiết sản phẩm theo slug, không yêu cầu đăng nhập.
    case 'api-product-detail':
        (new ProductController)->detail();
        break;

    // API tra tồn kho hiện tại của mọi biến thể trong một sản phẩm (không tăng lượt xem), không yêu cầu đăng nhập.
    case 'api-product-stock':
        (new ProductController)->stock();
        break;

    // API lấy danh sách danh mục sản phẩm
    case 'api-categories':
        (new CategoryController)->index();
        break;

    // API lấy danh sách banner
    case 'api-banners':
        (new BannerController)->index();
        break;

    // ======================================================================
    // 5. BÀI VIẾT
    // ======================================================================

    // API cần xác thực người dùng
    case 'api-posts':
        (new AuthMiddleware())->requireAuth();
        (new PostController)->index();
        break;

    // ======================================================================
    // 6. BÌNH LUẬN (CÔNG KHAI VÀ USER)
    // ======================================================================

    // API lấy danh sách bình luận theo slug sản phẩm, không yêu cầu đăng nhập.
    case 'api-product-comments':
        (new CommentController)->byProductSlug();
        break;

    // API tạo bình luận; yêu cầu user đăng nhập bằng Bearer JWT.
    case 'api-comment-create':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new CommentController)->create($authPayload);
        break;

    // API sửa bình luận của chính user đang đăng nhập.
    case 'api-comment-update':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new CommentController)->update($authPayload);
        break;

    // API xóa bình luận của chính user đang đăng nhập.
    case 'api-comment-delete':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new CommentController)->delete($authPayload);
        break;

    // ======================================================================
    // 7. GIỎ HÀNG
    // ======================================================================

    // API lấy giỏ hàng riêng của user đang đăng nhập.
    case 'api-cart':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new CartController)->index($authPayload);
        break;

    // API thêm sản phẩm vào giỏ của user đang đăng nhập.
    case 'api-cart-add':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new CartController)->add($authPayload);
        break;

    // API cập nhật số lượng một dòng hàng thuộc giỏ của user.
    case 'api-cart-update':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new CartController)->update($authPayload);
        break;

    // API xóa một dòng hàng thuộc giỏ của user.
    case 'api-cart-delete':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new CartController)->delete($authPayload);
        break;

    // ======================================================================
    // 8. ĐƠN HÀNG VÀ THANH TOÁN
    // ======================================================================

    // API checkout giỏ hàng thành đơn hàng; yêu cầu user đăng nhập.
    case 'api-order-checkout':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->checkout($authPayload);
        break;

    // API tạo đơn từ đơn nháp của trợ lý AI (danh sách SKU + số lượng, không dùng giỏ); yêu cầu user đăng nhập.
    case 'api-order-checkout-items':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->checkoutItems($authPayload);
        break;

    // API tra lại giá và tồn kho hiện tại của các món trong đơn nháp AI (theo SKU); yêu cầu user đăng nhập.
    case 'api-order-draft-items':
        (new AuthMiddleware())->requireAuth();
        (new OrderController)->draftItems();
        break;

    // API lấy danh sách đơn của user đang đăng nhập.
    case 'api-orders':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->index($authPayload);
        break;

    // API lấy chi tiết đơn làm dữ liệu hóa đơn của user/admin.
    case 'api-order-invoice':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->invoice($authPayload);
        break;

    // API hủy đơn đang chờ và chưa thanh toán của user.
    case 'api-order-cancel':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->cancel($authPayload);
        break;

    // API tạo form thanh toán SePay cho đơn online của user đang đăng nhập.
    case 'api-order-payment':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->createPayment($authPayload);
        break;

    // API hỏi lại SePay xem đơn đã thanh toán chưa (dùng khi quay về từ SePay); yêu cầu đăng nhập.
    case 'api-order-payment-verify':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->verifyPayment($authPayload);
        break;

    // API nhận IPN từ SePay; KHÔNG dùng JWT (do SePay gọi), xác thực bằng header X-Secret-Key.
    case 'api-sepay-ipn':
        (new OrderController)->sepayIpn();
        break;

    // ======================================================================
    // 9. TRỢ LÝ AI
    // ======================================================================

    // API chat với trợ lý AI Gemini (POST, body JSON message + conversation_id). Yêu cầu đăng nhập.
    case 'api-ai-chat':
        // Middleware kiểm tra Bearer JWT; payload có sub = ID user, dùng để kiểm tra quyền sở hữu hội thoại.
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new ChatController)->chat($authPayload);
        break;

    // API lấy lại cuộc chat gần nhất của user để hiện lại trong khung chat (GET). Yêu cầu đăng nhập.
    case 'api-ai-history':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new ChatController)->history($authPayload);
        break;

    // API xóa toàn bộ lịch sử trò chuyện với trợ lý AI của user đang đăng nhập (DELETE). Yêu cầu đăng nhập.
    case 'api-ai-history-clear':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new ChatController)->clearHistory($authPayload);
        break;

    // ======================================================================
    // 10. QUẢN TRỊ (ADMIN)
    // Mọi route nhóm này gọi AdminMiddleware: kiểm tra JWT và role=admin trước khi vào controller.
    // ======================================================================

    // --- Chung ---

    // API kiểm tra quyền vào khu quản trị (FE gọi khi mở /admin). Cần Bearer token VÀ role admin:
    // dùng chung AdminMiddleware với mọi API admin, nên điều kiện "là admin" chỉ định nghĩa ở một nơi.
    case 'api-admin-me':
        $authPayload = (new AdminMiddleware())->requireAdmin();
        (new AuthController)->adminMe($authPayload);
        break;

    // --- Đơn hàng ---

    // API admin lấy danh sách tất cả đơn hàng.
    case 'api-admin-orders':
        $authPayload = (new AdminMiddleware())->requireAdmin();
        (new OrderController)->adminIndex($authPayload);
        break;

    // API admin cập nhật trạng thái giao hàng và thanh toán.
    case 'api-admin-order-update':
        $authPayload = (new AdminMiddleware())->requireAdmin();
        (new OrderController)->adminUpdate($authPayload);
        break;

    // --- Sản phẩm ---

    // API admin lấy chi tiết sản phẩm theo id cho form sửa (mọi biến thể, album; không tăng lượt xem); yêu cầu role admin.
    case 'api-admin-product-detail':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminDetail();
        break;

    // API admin tạo sản phẩm; yêu cầu JWT có role admin.
    case 'api-admin-product-create':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminCreate();
        break;

    // API admin cập nhật sản phẩm; dùng PUT JSON hoặc POST multipart khi đổi ảnh.
    case 'api-admin-product-update':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminUpdate();
        break;

    // API admin xóa sản phẩm và ảnh album liên quan.
    case 'api-admin-product-delete':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminDelete();
        break;

    // --- Biến thể sản phẩm ---

    // API admin thêm biến thể cho sản phẩm; yêu cầu role admin.
    case 'api-admin-variant-create':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminVariantCreate();
        break;

    // API admin sửa biến thể (giá, kho, bật/tắt bán, thuộc tính, đặt mặc định); yêu cầu role admin.
    case 'api-admin-variant-update':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminVariantUpdate();
        break;

    // API admin đổi / bỏ ảnh riêng của biến thể (POST multipart/form-data); yêu cầu role admin.
    case 'api-admin-variant-image':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminVariantImage();
        break;

    // API admin xóa biến thể (không xóa được biến thể mặc định); yêu cầu role admin.
    case 'api-admin-variant-delete':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminVariantDelete();
        break;

    // --- Ảnh album (slide) sản phẩm ---

    // API admin thêm ảnh vào album sản phẩm; yêu cầu multipart/form-data.
    case 'api-admin-product-image-create':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminImageCreate();
        break;

    // API admin đổi thứ tự ảnh slide (sort_order); yêu cầu role admin.
    case 'api-admin-product-image-update':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminImageUpdate();
        break;

    // API admin xóa một ảnh khỏi album sản phẩm.
    case 'api-admin-product-image-delete':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminImageDelete();
        break;

    // --- Danh mục ---

    // API admin tạo danh mục.
    case 'api-admin-category-create':
        (new AdminMiddleware())->requireAdmin();
        (new CategoryController)->adminCreate();
        break;

    // API admin sửa tên và slug danh mục.
    case 'api-admin-category-update':
        (new AdminMiddleware())->requireAdmin();
        (new CategoryController)->adminUpdate();
        break;

    // API admin xóa danh mục trống.
    case 'api-admin-category-delete':
        (new AdminMiddleware())->requireAdmin();
        (new CategoryController)->adminDelete();
        break;

    // --- Banner ---

    // API admin tạo banner và tải ảnh tùy chọn.
    case 'api-admin-banner-create':
        (new AdminMiddleware())->requireAdmin();
        (new BannerController)->adminCreate();
        break;

    // API admin sửa banner; dùng PUT JSON hoặc POST multipart khi đổi ảnh.
    case 'api-admin-banner-update':
        (new AdminMiddleware())->requireAdmin();
        (new BannerController)->adminUpdate();
        break;

    // API admin xóa banner và ảnh do hệ thống tải lên.
    case 'api-admin-banner-delete':
        (new AdminMiddleware())->requireAdmin();
        (new BannerController)->adminDelete();
        break;

    // --- Bình luận ---

    // API admin xem bình luận, lọc status=0/1 và phân trang.
    case 'api-admin-comments':
        (new AdminMiddleware())->requireAdmin();
        (new CommentController)->adminIndex();
        break;

    // API admin duyệt một bình luận.
    case 'api-admin-comment-approve':
        (new AdminMiddleware())->requireAdmin();
        (new CommentController)->adminApprove();
        break;

    // API admin xóa một bình luận.
    case 'api-admin-comment-delete':
        (new AdminMiddleware())->requireAdmin();
        (new CommentController)->adminDelete();
        break;

    // ======================================================================
    // 11. ROUTE TEST / DEMO (không dùng làm mẫu, FE không dùng)
    // ======================================================================

    // API kiểm duyệt nội dung bình luận bằng AI Comment Guard mỗi lần user bình luận (POST, body text). Yêu cầu đăng nhập.
    // API test để thử 2 model trên Google Colab (trả nguyên nhãn và điểm AI). FE không dùng; kiểm duyệt thật nằm trong api-comment-create/update.
    case 'api-comment-guard':
        // Middleware kiểm tra Bearer JWT; chỉ user đã đăng nhập mới được gọi để tránh người lạ spam dịch vụ AI.
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new CommentGuardController)->analyze($authPayload);
        break;

    // API test cho Gemini service
    case 'api-test-gemini':
        (new Test)->sayHello();
        break;

    // API demo (route thử nghiệm cũ).
    case 'api-guns':
        (new GunController)->index();
        break;

    // API demo (route thử nghiệm cũ).
    case 'api-camchim':
        (new ProductController)->camChim();
        break;

    default:
        // Xử lý khi không khớp route nào (404 Not Found)
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
        break;

}

/*
 * Các endpoint quản trị kiểm tra JWT và role=admin bằng AdminMiddleware trước khi vào controller.
 * Chưa có API dashboard hoặc quản lý tài khoản user.
 */
