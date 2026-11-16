<?php

/**
 * VibeCoder: Long
 * Mô hình thao tác với tài khoản trong bảng users.
 */
class User extends BaseModel
{
    protected $table = 'users';

    /**
     * Lấy danh sách tài khoản từ cơ sở dữ liệu.
     *
     * @return array Danh sách tài khoản.
     */
    public function getAll()
    {
        return $this->all();
    }

    /**
        * Tìm tài khoản theo địa chỉ email.
     *
        * @param string $email Địa chỉ email cần tìm.
        * @return array|false Thông tin tài khoản hoặc false nếu không tìm thấy.
     */
    public function findByEmail($email)
    {
        $sql = 'SELECT * FROM `users` WHERE `email` = :email LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['email' => trim($email)]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** Kiểm tra email trước khi tạo tài khoản; unique index vẫn bảo vệ trường hợp race. */
    public function emailExists($email): bool
    {
        return $this->findByEmail($email) !== false;
    }

     /**
      * Tạo tài khoản user chưa kích hoạt; chỉ lưu hash của token xác minh.
      *
      * @param string $email Địa chỉ email của tài khoản.
      * @param string $password Mật khẩu dạng rõ, sẽ được mã hóa trước khi lưu.
      * @param string $verificationTokenHash Hash SHA-256 của token xác minh.
    * @param string $verificationExpiresAt Thời điểm hết hạn theo UTC.
      * @return bool True nếu tạo thành công, false nếu dữ liệu không hợp lệ hoặc email trùng.
      */
    public function createUser($email, $password, $verificationTokenHash, string $verificationExpiresAt): bool
    {
        $email = trim($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || !preg_match('/^[a-f0-9]{64}$/', $verificationTokenHash)) {
            return false;
        }

        try {
            return $this->save([
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'user',
                'is_active' => 0,
                'verification_token' => $verificationTokenHash,
                'verification_expires_at' => $verificationExpiresAt,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }
    }

    /** Cấp token và hạn mới cho tài khoản chưa kích hoạt để gửi lại email xác minh. */
    public function refreshPendingVerificationToken(string $email, string $tokenHash, string $expiresAt): bool
    {
        $sql = 'UPDATE `users` SET `verification_token` = :token, `verification_expires_at` = :expires_at '
            . 'WHERE `email` = :email AND `is_active` = 0';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'token' => $tokenHash,
            'expires_at' => $expiresAt,
            'email' => $email,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Kích hoạt tài khoản nếu token còn hạn rồi xóa token để chỉ dùng được một lần. */
    public function activateByVerificationToken($verificationTokenHash): bool
    {
        $sql = 'UPDATE `users` SET `is_active` = 1, `verification_token` = NULL, `verification_expires_at` = NULL '
            . 'WHERE `verification_token` = :token AND `is_active` = 0 '
            . 'AND `verification_expires_at` > UTC_TIMESTAMP()';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['token' => $verificationTokenHash]);

        return $stmt->rowCount() === 1;
    }

    /** Xóa đúng tài khoản pending vừa tạo nếu không gửi được email xác minh. */
    public function deletePendingUser($email, $verificationTokenHash): bool
    {
        $sql = 'DELETE FROM `users` WHERE `email` = :email AND `verification_token` = :token AND `is_active` = 0';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => $email,
            'token' => $verificationTokenHash,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Lưu hash token reset và hạn dùng cho tài khoản đã kích hoạt. */
    public function storePasswordResetToken(string $email, string $tokenHash, string $expiresAt): bool
    {
        $sql = 'UPDATE `users` SET `password_reset_token` = :token, `password_reset_expires_at` = :expires_at '
            . 'WHERE `email` = :email AND `is_active` = 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'token' => $tokenHash,
            'expires_at' => $expiresAt,
            'email' => $email,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Xóa reset token nếu gửi email thất bại, để token chưa gửi không dùng được. */
    public function clearPasswordResetToken(string $tokenHash): bool
    {
        $sql = 'UPDATE `users` SET `password_reset_token` = NULL, `password_reset_expires_at` = NULL '
            . 'WHERE `password_reset_token` = :token';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['token' => $tokenHash]);

        return $stmt->rowCount() === 1;
    }

    /** Kiểm tra reset token còn hạn mà không tiêu thụ token, dùng cho link trong email. */
    public function isPasswordResetTokenValid(string $tokenHash): bool
    {
        $sql = 'SELECT 1 FROM `users` WHERE `password_reset_token` = :token '
            . 'AND `password_reset_expires_at` > UTC_TIMESTAMP() AND `is_active` = 1 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['token' => $tokenHash]);

        return $stmt->fetchColumn() !== false;
    }

    /** Đổi mật khẩu theo reset token hợp lệ rồi xóa token ngay trong cùng câu lệnh. */
    public function resetPasswordWithToken(string $tokenHash, string $newPassword): bool
    {
        $sql = 'UPDATE `users` SET `password` = :password, `password_reset_token` = NULL, '
            . '`password_reset_expires_at` = NULL WHERE `password_reset_token` = :token '
            . 'AND `password_reset_expires_at` > UTC_TIMESTAMP() AND `is_active` = 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'token' => $tokenHash,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** Xác minh mật khẩu hiện tại trước khi đổi và vô hiệu hóa reset token cũ. */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): bool
    {
        $user = $this->find($userId);
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($currentPassword, $user['password'])) {
            return false;
        }

        $sql = 'UPDATE `users` SET `password` = :new_password, `password_reset_token` = NULL, '
            . '`password_reset_expires_at` = NULL WHERE `id` = :id AND `password` = :current_hash';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'new_password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
            'current_hash' => $user['password'],
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
        * Xác thực email và mật khẩu; chỉ trả về tài khoản đã được kích hoạt.
        * Mật khẩu đã mã hóa được loại khỏi kết quả để tránh lộ thông tin.
     *
        * @param string $email Địa chỉ email đăng nhập.
        * @param string $password Mật khẩu đăng nhập.
        * @return array|false Thông tin tài khoản đã xác thực hoặc false nếu thất bại.
     */
    public function authenticate($email, $password)
    {
        $user = $this->findByEmail($email);

        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password'])) {
            return false;
        }

        unset($user['password']);

        return $user;
    }

    /**
     * Lấy hồ sơ của tài khoản đang hoạt động theo ID.
     * Chỉ chọn các cột hồ sơ cần hiển thị; không chọn password, token hay token_version để tránh lộ dữ liệu nhạy cảm.
     * Cột họ tên, số điện thoại, địa chỉ có thể là NULL nếu user chưa điền (PDO trả về null).
     *
     * @param int $userId ID user lấy từ payload JWT (claim sub).
     * @return array|false Thông tin hồ sơ hoặc false nếu không có tài khoản hoạt động.
     */
    public function findProfileById(int $userId = 0)
    {
        // Dùng bindValue với PDO::PARAM_INT để ID được coi là số, không bị nối chuỗi vào SQL.
        $statement = $this->pdo->prepare(
            'SELECT `id`, `email`, `role`, `name`, `phone`, `address` FROM `users` '
            . 'WHERE `id` = :id AND `is_active` = 1 LIMIT 1'
        );
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Cập nhật hồ sơ (họ tên, số điện thoại, địa chỉ) của tài khoản đang hoạt động.
     * Chỉ ghi 3 cột này; email, role, mật khẩu không bao giờ bị đổi qua hàm này.
     * Giá trị null nghĩa là xóa trường đó (lưu NULL trong DB). Dữ liệu phải được controller validate trước.
     *
     * @param int         $userId  ID user lấy từ payload JWT.
     * @param string|null $name    Họ tên mới hoặc null.
     * @param string|null $phone   Số điện thoại mới hoặc null.
     * @param string|null $address Địa chỉ mới hoặc null.
     * @return bool True nếu câu lệnh chạy thành công (kể cả khi dữ liệu không đổi).
     */
    public function updateProfile(int $userId = 0, ?string $name = null, ?string $phone = null, ?string $address = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE `users` SET `name` = :name, `phone` = :phone, `address` = :address '
            . 'WHERE `id` = :id AND `is_active` = 1'
        );
        // PARAM_NULL để MySQL lưu NULL thật, không lưu chuỗi rỗng; PARAM_STR cho chuỗi bình thường.
        $statement->bindValue(':name', $name, $name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':phone', $phone, $phone === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':address', $address, $address === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);

        // Không dùng rowCount() để kết luận: MySQL trả 0 dòng khi giá trị mới giống hệt giá trị cũ.
        return $statement->execute();
    }

    /**
     * Tăng token_version nếu JWT hiện tại vẫn là phiên bản mới nhất.
     *
     * @param int $userId ID user trong JWT.
     * @param int $tokenVersion Phiên bản token đang dùng.
     * @return bool True nếu thu hồi được token phiên bản hiện tại.
     */
    public function revokeTokenVersion(int $userId = 0, int $tokenVersion = 0): bool
    {
        if ($userId < 1 || $tokenVersion < 0) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE `users` SET `token_version` = `token_version` + 1 '
            . 'WHERE `id` = :id AND `token_version` = :token_version AND `is_active` = 1'
        );
        $statement->bindValue(':id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':token_version', $tokenVersion, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() === 1;
    }
}