<?php
/** Middleware chỉ cho phép user có role admin đi vào API quản trị. */
class AdminMiddleware
{
    /**
     * Xác thực JWT rồi từ chối user không có role admin.
     * Token thiếu/sai trả HTTP 401; role không đủ trả HTTP 403.
     *
     * @return array Payload JWT đã xác minh để controller dùng danh tính admin.
     */
    public function requireAdmin(): array
    {
        $authPayload = (new AuthMiddleware())->requireAuth();
        if (($authPayload['role'] ?? '') !== 'admin') {
            response_json([
                'status' => 'error',
                'message' => 'Bạn không có quyền thực hiện thao tác này',
            ], 403);
        }

        return $authPayload;
    }
}