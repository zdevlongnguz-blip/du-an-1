<?php
// Script dòng lệnh: tạo (hoặc tạo lại) vector cho các biến thể sản phẩm, lưu vào product_variant_embeddings.
// Chỉ biến thể có đoạn văn thay đổi (so text_hash) mới được gửi sang server Python.
// Truyền --force để tạo lại toàn bộ (bắt buộc khi đổi model ở server Python).
// Admin tạo/sửa sản phẩm đã tự đồng bộ; script này dùng cho lần đầu, sau khi chạy seed,
// hoặc khi server Python bị tắt lúc admin lưu sản phẩm.
//
// Cần bật server Python embed-service trước (xem embed-service/README.md). Cách chạy từ thư mục gốc repo:
// & 'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe' BE/database/embed_products.php
// & 'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe' BE/database/embed_products.php --force

// Chỉ cho chạy bằng dòng lệnh (CLI), mở bằng trình duyệt thì trả 404.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../configs/config.php';
require_once __DIR__ . '/../models/BaseModel.php';
require_once __DIR__ . '/../models/ProductVariantEmbedding.php';
require_once __DIR__ . '/../embedServices/EmbedService.php';
require_once __DIR__ . '/../embedServices/ProductEmbedText.php';
require_once __DIR__ . '/../embedServices/ProductEmbedSync.php';

$force = in_array('--force', $argv ?? [], true);

try {
    // Mảng rỗng = đồng bộ mọi sản phẩm.
    $result = ProductEmbedSync::sync([], $force);
} catch (Throwable $e) {
    fwrite(STDERR, 'Lỗi: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($result['texts'] as $variantId => $text) {
    echo '- Biến thể #' . $variantId . ': ' . $text . PHP_EOL;
}

echo PHP_EOL . 'Đã tạo vector: ' . $result['saved'] . ', bỏ qua (không đổi): ' . $result['skipped'] . PHP_EOL;
