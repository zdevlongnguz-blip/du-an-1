<?php
/**
 * Router chính của ứng dụng.
 *
 * Mỗi action tương ứng với một endpoint API hoặc page.
 * Những route cần bảo vệ auth thì gọi middleware trước khi vào controller.
 */

$action = $_GET['action'] ?? '/';

switch ($action) {
    case '/':
        (new HomeController)->index();
        break;

    case 'api-check':
        (new HomeController)->check();
        break;

    // Bộ api auth ==========================
    case 'api-register':
        (new AuthController)->register();
        break;

    case 'api-verify-email':
        (new AuthController)->verifyEmail();
        break;

    case 'api-forgot-password':
        (new AuthController)->forgotPassword();
        break;

    case 'api-reset-password':
        (new AuthController)->resetPassword();
        break;

    case 'reset-password-bridge':
        (new AuthController)->showPasswordResetBridge();
        break;

    case 'api-change-password':
        // Middleware kiểm tra Bearer JWT và trả payload đã xác minh; claim `sub` là ID user.
        $authPayload = (new AuthMiddleware())->requireAuth();
        // Truyền danh tính từ token xuống controller, không nhận user ID do client tự gửi.
        (new AuthController)->changePassword($authPayload);
        break;

    case 'api-login':
        (new AuthController)->login();
        break;

    case 'api-logout':
        (new AuthController)->logout();
        break;


    // API cần xác thực người dùng
    case 'api-posts':
        (new AuthMiddleware())->requireAuth();
        (new PostController)->index();
        break;

    // API lấy chi tiết sản phẩm theo slug, không yêu cầu đăng nhập.
    case 'api-product-detail':
        (new ProductController)->detail();
        break;

    // API lấy top 5 sản phẩm theo lượt xem hoặc lượt bán, không yêu cầu đăng nhập.
    case 'api-products-top':
        (new ProductController)->top();
        break;

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

    // API checkout giỏ hàng thành đơn hàng; yêu cầu user đăng nhập.
    case 'api-order-checkout':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->checkout($authPayload);
        break;

    // API tạo form thanh toán SePay sandbox cho đơn của user đang đăng nhập.
    case 'api-order-payment':
        $authPayload = (new AuthMiddleware())->requireAuth();
        (new OrderController)->createPayment($authPayload);
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

    // API admin thêm ảnh vào album sản phẩm; yêu cầu multipart/form-data.
    case 'api-admin-product-image-create':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminImageCreate();
        break;

    // API admin xóa một ảnh khỏi album sản phẩm.
    case 'api-admin-product-image-delete':
        (new AdminMiddleware())->requireAdmin();
        (new ProductController)->adminImageDelete();
        break;

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

    case 'api-products':
        // (new AuthMiddleware())->requireAuth();
        (new ProductController)->index();
        break;

    // API lấy danh sách danh mục sản phẩm
    case 'api-categories':
    (new CategoryController)->index();
    break;

    // API lấy danh sách banner
    case 'api-banners':
        (new BannerController)->index();
        break;

    // API test cho thằng mặt cặc Hoàng Vinh
    case 'api-guns':
        (new GunController)->index();
        break;

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