<?php
$continueUrl = isset($continueUrl) && is_string($continueUrl)
    ? $continueUrl
    : FRONTEND_URL . '/forgot-password';
$isValid = isset($isValid) && $isValid === true;
$safeContinueUrl = htmlspecialchars($continueUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$scriptContinueUrl = json_encode(
    $continueUrl,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>FuLe BaberShop | Khôi phục mật khẩu</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            background: #f3f5f7;
            color: #263442;
        }

        * { box-sizing: border-box; }

        body {
            display: grid;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            place-items: center;
            background: #f3f5f7;
        }

        main {
            width: min(100%, 440px);
            padding: 28px;
            border: 1px solid #dfe5eb;
            background: #ffffff;
            box-shadow: 0 8px 28px #17324d12;
        }

        .eyebrow { color: #174f70; font-size: 14px; font-weight: 700; }
        h1 { margin: 24px 0 10px; color: #17324d; font-size: 24px; }
        p { color: #526170; line-height: 1.6; }

        a {
            display: inline-block;
            margin-top: 12px;
            padding: 12px 16px;
            border: 1px solid #174f70;
            background: #174f70;
            color: #ffffff;
            text-decoration: none;
        }

        a:hover { background: #123d58; }
        .expired h1 { color: #a33b49; }
        .expired a { border-color: #a33b49; background: #a33b49; }
    </style>
</head>
<body>
    <main class="<?= $isValid ? 'verified' : 'expired' ?>">
        <div class="eyebrow">FuLe BaberShop</div>
        <?php if ($isValid): ?>
            <h1>Liên kết đã xác thực</h1>
            <p>Đang chuyển bạn đến biểu mẫu đặt lại mật khẩu trong 8 giây.</p>
            <p>Nếu không tự chuyển, hãy tiếp tục bằng nút bên dưới.</p>
            <a href="<?= $safeContinueUrl ?>">Tiếp tục đặt lại mật khẩu</a>
        <?php else: ?>
            <h1>Liên kết không còn hợp lệ</h1>
            <p>Liên kết có thể đã hết hạn hoặc đã được sử dụng.</p>
            <a href="<?= $safeContinueUrl ?>">Yêu cầu liên kết mới</a>
        <?php endif; ?>
    </main>

    <?php if ($isValid && $scriptContinueUrl !== false): ?>
        <script>
            window.setTimeout(() => window.location.replace(<?= $scriptContinueUrl ?>), 8000)
        </script>
    <?php endif; ?>
</body>
</html>