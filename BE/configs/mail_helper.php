<?php

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** Tạo PHPMailer đã cấu hình SMTP dùng chung cho các email xác thực. */
function create_configured_mailer(string $recipientEmail): PHPMailer
{
    if (MAIL_HOST === '' || MAIL_USERNAME === '' || MAIL_PASSWORD === '') {
        throw new RuntimeException('SMTP configuration is incomplete.');
    }

    $mailer = new PHPMailer(true);

    try {
        $mailer->isSMTP();
        $mailer->Host = MAIL_HOST;
        $mailer->SMTPAuth = true;
        $mailer->Username = MAIL_USERNAME;
        $mailer->Password = MAIL_PASSWORD;
        $mailer->Port = MAIL_PORT;
        $mailer->CharSet = 'UTF-8';

        if (MAIL_ENCRYPTION === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (MAIL_ENCRYPTION === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (MAIL_ENCRYPTION !== '') {
            throw new RuntimeException('Unsupported SMTP encryption setting.');
        }

        $fromAddress = MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : MAIL_USERNAME;
        $mailer->setFrom($fromAddress, MAIL_FROM_NAME);
        $mailer->addAddress($recipientEmail);
        return $mailer;
    } catch (PHPMailerException $exception) {
        throw new RuntimeException('Unable to configure SMTP mailer.', 0, $exception);
    }
}

/** Gửi link xác minh đăng ký; token thô chỉ được đưa vào email. */
function send_verification_email(string $recipientEmail, string $verificationToken): void
{
    try {
        $mailer = create_configured_mailer($recipientEmail);
        $mailer->isHTML(true);
        $mailer->Subject = 'Xác minh địa chỉ email | FuLe BaberShop';

        $verificationUrl = BASE_URL . '?action=api-verify-email&token=' . rawurlencode($verificationToken);
        $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $expiryMinutes = (int) ceil(EMAIL_VERIFICATION_TTL / 60);
        $mailer->Body = '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f5f7" style="background-color:#f3f5f7; padding:32px 12px; font-family:Arial,Helvetica,sans-serif;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; border:1px solid #dfe5eb; background-color:#ffffff;">
                            <tr>
                                <td style="padding:20px 26px; border-bottom:1px solid #e7ebef; color:#17324d; font-size:18px; font-weight:bold;">
                                    FuLe BaberShop
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:32px 26px; color:#263442;">
                                    <h1 style="margin:0 0 18px; color:#17324d; font-size:24px; line-height:1.3;">Xác minh địa chỉ email</h1>
                                    <p style="margin:0 0 24px; color:#526170; font-size:15px; line-height:1.7;">Vui lòng xác minh địa chỉ email để hoàn tất đăng ký tài khoản tại FuLe BaberShop.</p>
                                    <p style="margin:0 0 24px;">
                                        <a href="' . $safeUrl . '" style="display:inline-block; padding:13px 20px; background-color:#174f70; color:#ffffff; font-size:14px; font-weight:bold; text-decoration:none;">Xác minh email</a>
                                    </p>
                                    <p style="margin:0 0 16px; color:#667482; font-size:13px; line-height:1.6;">Liên kết có hiệu lực trong ' . $expiryMinutes . ' phút.</p>
                                    <p style="margin:0; color:#667482; font-size:13px; line-height:1.6;">Nếu bạn không tạo tài khoản, vui lòng bỏ qua email này.</p>
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:16px 26px; border-top:1px solid #e7ebef; color:#7a8793; font-size:12px;">FuLe BaberShop | Email tự động, vui lòng không trả lời.</td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>';
        $mailer->AltBody = "FuLe BaberShop - Xác minh địa chỉ email\n\nVui lòng xác minh địa chỉ email để hoàn tất đăng ký tài khoản:\n{$verificationUrl}\n\nLiên kết có hiệu lực trong {$expiryMinutes} phút. Nếu bạn không tạo tài khoản, vui lòng bỏ qua email này.";
        $mailer->send();
    } catch (PHPMailerException $exception) {
        throw new RuntimeException('Unable to send verification email.', 0, $exception);
    }
}

/** Gửi link đặt lại mật khẩu; token chỉ được lưu dạng hash và hết hạn theo cấu hình. */
function send_password_reset_email(string $recipientEmail, string $resetToken): void
{
    try {
        $mailer = create_configured_mailer($recipientEmail);
        $mailer->isHTML(true);
        $mailer->Subject = 'Đặt lại mật khẩu | FuLe BaberShop';

        $resetUrl = BASE_URL . '?action=reset-password-bridge&token=' . rawurlencode($resetToken);
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $expiryMinutes = (int) ceil(PASSWORD_RESET_TTL / 60);
        $mailer->Body = '
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f5f7" style="background-color:#f3f5f7; padding:32px 12px; font-family:Arial,Helvetica,sans-serif;">
                <tr>
                    <td align="center">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px; border:1px solid #dfe5eb; background-color:#ffffff;">
                            <tr><td style="padding:20px 26px; border-bottom:1px solid #e7ebef; color:#17324d; font-size:18px; font-weight:bold;">FuLe BaberShop</td></tr>
                            <tr>
                                <td style="padding:32px 26px; color:#263442;">
                                    <h1 style="margin:0 0 18px; color:#17324d; font-size:24px; line-height:1.3;">Yêu cầu đặt lại mật khẩu</h1>
                                    <p style="margin:0 0 24px; color:#526170; font-size:15px; line-height:1.7;">Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản FuLe BaberShop của bạn. Liên kết có hiệu lực trong ' . $expiryMinutes . ' phút.</p>
                                    <p style="margin:0 0 24px;"><a href="' . $safeUrl . '" style="display:inline-block; padding:13px 20px; background-color:#174f70; color:#ffffff; font-size:14px; font-weight:bold; text-decoration:none;">Đặt lại mật khẩu</a></p>
                                    <p style="margin:0; color:#667482; font-size:13px; line-height:1.6;">Nếu bạn không yêu cầu thay đổi mật khẩu, vui lòng bỏ qua email này. Mật khẩu hiện tại của bạn sẽ không thay đổi.</p>
                                </td>
                            </tr>
                            <tr><td style="padding:16px 26px; border-top:1px solid #e7ebef; color:#7a8793; font-size:12px;">FuLe BaberShop | Email tự động, vui lòng không trả lời.</td></tr>
                        </table>
                    </td>
                </tr>
            </table>';
        $mailer->AltBody = "FuLe BaberShop - Đặt lại mật khẩu\n\nChúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn. Mở liên kết trong {$expiryMinutes} phút:\n{$resetUrl}\n\nNếu bạn không yêu cầu thay đổi mật khẩu, vui lòng bỏ qua email này. Mật khẩu hiện tại của bạn sẽ không thay đổi.";
        $mailer->send();
    } catch (PHPMailerException $exception) {
        throw new RuntimeException('Unable to send password reset email.', 0, $exception);
    }
}