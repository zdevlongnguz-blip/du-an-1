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