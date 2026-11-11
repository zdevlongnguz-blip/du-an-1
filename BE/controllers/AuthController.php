<?php

// /**
//  * AuthController
//  * VibeCoder: Long
//  *
//  * Chịu trách nhiệm xử lý auth API cho hệ thống.
//  */

class AuthController
{
    private User $userModel;

    /**
     * Khởi tạo controller và model user để dùng trong các hàm xác thực.
     */
    public function __construct()
    {
        $this->userModel = new User();
    }

    /** Tạo tài khoản user chưa kích hoạt và gửi link xác minh qua email. */
    public function register(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json([
                'status' => 'error',
                'message' => 'Phương thức không được hỗ trợ'
            ], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !is_string($input['email'] ?? null) || !is_string($input['password'] ?? null)) {
            response_json([
                'status' => 'error',
                'message' => 'Dữ liệu đăng ký không hợp lệ'
            ], 400);
        }

        $email = strtolower(trim($input['email']));
        $password = $input['password'];

        if (
            strlen($email) > 255
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($password) < 8
            || strlen($password) > 72
        ) {
            response_json([
                'status' => 'error',
                'message' => 'Email không hợp lệ hoặc mật khẩu phải dài từ 8 đến 72 byte'
            ], 422);
        }

        $existingUser = $this->userModel->findByEmail($email);
        if ($existingUser && (int) $existingUser['is_active'] === 1) {
            response_json([
                'status' => 'error',
                'message' => 'Email đã được sử dụng'
            ], 409);
        }

        // Token xác minh có hạn; chỉ lưu hash trong DB, còn token thô chỉ gửi qua email.
        $verificationToken = bin2hex(random_bytes(32));
        $verificationTokenHash = hash('sha256', $verificationToken);
        $verificationExpiresAt = gmdate('Y-m-d H:i:s', time() + EMAIL_VERIFICATION_TTL);

        // Email pending được cấp link mới; không ghi đè mật khẩu đã đăng ký trước đó.
        $saved = $existingUser
            ? $this->userModel->refreshPendingVerificationToken($email, $verificationTokenHash, $verificationExpiresAt)
            : $this->userModel->createUser($email, $password, $verificationTokenHash, $verificationExpiresAt);

        if (!$saved) {
            response_json([
                'status' => 'error',
                'message' => 'Email đã được sử dụng hoặc gặp sự cố khi tạo tài khoản'
            ], 409);
        }

        // Nếu SMTP lỗi, xóa tài khoản pending để người dùng có thể đăng ký lại.
        try {
            send_verification_email($email, $verificationToken);
        } catch (Throwable $exception) {
            if (!$existingUser) {
                try {
                    $this->userModel->deletePendingUser($email, $verificationTokenHash);
                } catch (Throwable $cleanupException) {
                    error_log('Unable to remove pending user after mail failure: ' . $cleanupException->getMessage());
                }
            }

            error_log('Verification email failed: ' . $exception->getMessage());
            response_json([
                'status' => 'error',
                'message' => 'Không thể gửi email xác minh. Vui lòng thử lại sau.'
            ], 503);
        }

        response_json([
            'status' => 'success',
            'message' => $existingUser
                ? 'Tài khoản chưa xác minh. Link xác minh mới đã được gửi qua email.'
                : 'Đăng ký thành công. Vui lòng kiểm tra email để xác minh tài khoản.'
        ], $existingUser ? 200 : 201);
    }

    /** Xác minh token một lần và chỉ khi link vẫn còn hạn. */
    public function verifyEmail(): void
    {
        $token = $_GET['token'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            response_json([
                'status' => 'error',
                'message' => 'Token xác minh không hợp lệ'
            ], 400);
        }

        if (!$this->userModel->activateByVerificationToken(hash('sha256', $token))) {
            response_json([
                'status' => 'error',
                'message' => 'Token xác minh không hợp lệ hoặc đã được sử dụng'
            ], 400);
        }

        response_json([
            'status' => 'success',
            'message' => 'Xác minh email thành công. Bạn có thể đăng nhập.'
        ]);
    }

    /** Gửi link reset công khai nhưng luôn phản hồi chung để tránh dò email tồn tại. */
    public function forgotPassword(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !is_string($input['email'] ?? null)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu email không hợp lệ'], 400);
        }

        $email = strtolower(trim($input['email']));
        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            response_json(['status' => 'error', 'message' => 'Email không hợp lệ'], 422);
        }

        $user = $this->userModel->findByEmail($email);
        if ($user && (int) $user['is_active'] === 1) {
            $resetToken = bin2hex(random_bytes(32));
            $resetTokenHash = hash('sha256', $resetToken);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL);

            if ($this->userModel->storePasswordResetToken($email, $resetTokenHash, $expiresAt)) {
                try {
                    send_password_reset_email($email, $resetToken);
                } catch (Throwable $exception) {
                    $this->userModel->clearPasswordResetToken($resetTokenHash);
                    error_log('Password reset email failed: ' . $exception->getMessage());
                }
            }
        }

        response_json([
            'status' => 'success',
            'message' => 'Nếu email được đăng ký, hướng dẫn đặt lại mật khẩu sẽ được gửi.'
        ], 202);
    }

    /** GET chỉ kiểm tra token cho link email; POST mới dùng token để đổi mật khẩu. */
    public function resetPassword(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method === 'GET') {
            $token = $_GET['token'] ?? '';
            if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)
                || !$this->userModel->isPasswordResetTokenValid(hash('sha256', $token))) {
                response_json(['status' => 'error', 'message' => 'Token không hợp lệ hoặc đã hết hạn'], 400);
            }

            response_json([
                'status' => 'success',
                'message' => 'Token hợp lệ. Gửi POST kèm token và mật khẩu mới để hoàn tất.'
            ]);
        }

        if ($method !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !is_string($input['token'] ?? null) || !is_string($input['new_password'] ?? null)) {
            response_json(['status' => 'error', 'message' => 'Token hoặc mật khẩu mới không hợp lệ'], 400);
        }

        $token = $input['token'];
        $newPassword = $input['new_password'];
        if (!preg_match('/^[a-f0-9]{64}$/', $token)
            || strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            response_json(['status' => 'error', 'message' => 'Token không hợp lệ hoặc mật khẩu phải dài từ 8 đến 72 byte'], 422);
        }

        if (!$this->userModel->resetPasswordWithToken(hash('sha256', $token), $newPassword)) {
            response_json(['status' => 'error', 'message' => 'Token không hợp lệ, đã dùng hoặc hết hạn'], 400);
        }

        response_json(['status' => 'success', 'message' => 'Đặt lại mật khẩu thành công']);
    }

    /** Đổi mật khẩu cho người dùng đã đăng nhập sau khi xác minh mật khẩu hiện tại. */
        /** Hiển thị trang cầu nối sau khi xác minh token reset từ email. */
        public function showPasswordResetBridge(): void
        {
            $token = $_GET['token'] ?? '';
            $isValid = is_string($token)
                && preg_match('/^[a-f0-9]{64}$/', $token) === 1
                && $this->userModel->isPasswordResetTokenValid(hash('sha256', $token));

            $continueUrl = $isValid
                ? FRONTEND_URL . '/reset-password?token=' . rawurlencode($token)
                : FRONTEND_URL . '/forgot-password';

            if (!$isValid) {
                http_response_code(400);
            }

            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, private');
            require PATH_VIEW . 'auth/password_reset_bridge.php';
        }

        /** Đổi mật khẩu cho người dùng đã đăng nhập sau khi xác minh mật khẩu hiện tại. */
    public function changePassword(array $authPayload): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = filter_var($authPayload['sub'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            response_json(['status' => 'error', 'message' => 'Token không hợp lệ'], 401);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)
            || !is_string($input['current_password'] ?? null)
            || !is_string($input['new_password'] ?? null)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu mật khẩu không hợp lệ'], 400);
        }

        $currentPassword = $input['current_password'];
        $newPassword = $input['new_password'];
        if ($currentPassword === '' || strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            response_json(['status' => 'error', 'message' => 'Mật khẩu mới phải dài từ 8 đến 72 byte'], 422);
        }

        if (!$this->userModel->changePassword((int) $userId, $currentPassword, $newPassword)) {
            response_json(['status' => 'error', 'message' => 'Mật khẩu hiện tại không đúng hoặc tài khoản không khả dụng'], 422);
        }

        response_json(['status' => 'success', 'message' => 'Đổi mật khẩu thành công']);
    }

    /**
     * API đăng nhập.
     *
     * 1. Đọc dữ liệu JSON từ request body
     * 2. Validate email/password
        * 3. Kiểm tra tài khoản trong cơ sở dữ liệu bằng $userModel->authenticate()
     * 4. Tạo JWT bằng jwt_sign()
     * 5. Trả token về cho client
     *
     * @return void
     */
    public function login()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !is_string($input['email'] ?? null) || !is_string($input['password'] ?? null)) {
            response_json([
                'status' => 'error',
                'message' => 'Email và mật khẩu không được để trống'
            ], 400);
            return;
        }

        $email = trim($input['email']);
        $password = $input['password'];

        if ($email === '' || $password === '') {
            http_response_code(400);
            response_json([
                'status' => 'error',
                'message' => 'Email và mật khẩu không được để trống'
            ], 400);
            return;
        }

        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            response_json([
                'status' => 'error',
                'message' => 'Email không hợp lệ'
            ], 422);
            return;
        }

        $user = $this->userModel->authenticate($email, $password);

        if ($user === false) {
            http_response_code(401);
            response_json([
                'status' => 'error',
                'message' => 'Sai email hoặc mật khẩu'
            ], 401);
            return;
        }

        $token = jwt_sign([
            'sub' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'token_version' => (int) ($user['token_version'] ?? 0),
        ]);

        response_json([
            'status' => 'success',
            'message' => 'Đăng nhập thành công',
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
            ]
        ]);
    }

    /**
     * Thu hồi các JWT hiện tại của user bằng cách tăng token_version trong DB.
     * Thiếu/sai token hoặc token đã bị thu hồi trả HTTP 401.
     *
     * @return void
     */
    public function logout()
    {
        $payload = requireBearerToken();
        $userId = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        $tokenVersion = filter_var($payload['token_version'] ?? 0, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1 || $tokenVersion === false || $tokenVersion < 0
            || !$this->userModel->revokeTokenVersion($userId, $tokenVersion)) {
            response_json(['status' => 'error', 'message' => 'Token không hợp lệ hoặc đã bị thu hồi'], 401);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đăng xuất thành công',
            'user' => [
                'sub' => $payload['sub'] ?? null,
                'email' => $payload['email'] ?? null,
                'role' => $payload['role'] ?? null,
            ]
        ]);
    }
}
