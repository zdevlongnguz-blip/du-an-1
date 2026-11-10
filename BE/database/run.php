<?php
// Chạy các migration theo thứ tự tên file; seeder chỉ chạy khi truyền --seed.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../configs/config.php';

/**
 * Tách nội dung SQL thành từng câu lệnh, không tách dấu chấm phẩy trong chuỗi hoặc comment.
 *
 * @param string $sql Nội dung của một file SQL.
 * @return array Danh sách câu lệnh SQL đã tách.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $statement = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $nextCharacter = $sql[$index + 1] ?? '';

        if ($lineComment) {
            if ($character === "\n") {
                $lineComment = false;
                $statement .= $character;
            }
            continue;
        }

        if ($blockComment) {
            if ($character === '*' && $nextCharacter === '/') {
                $blockComment = false;
                $statement .= ' ';
                $index++;
            }
            continue;
        }

        if ($quote !== null) {
            $statement .= $character;

            if ($character === '\\' && $quote !== '`' && $index + 1 < $length) {
                $statement .= $sql[++$index];
                continue;
            }

            if ($character === $quote) {
                if ($nextCharacter === $quote) {
                    $statement .= $sql[++$index];
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($character === "'" || $character === '"' || $character === '`') {
            $quote = $character;
            $statement .= $character;
            continue;
        }

        if ($character === '#') {
            $lineComment = true;
            $statement .= ' ';
            continue;
        }

        if ($character === '-' && $nextCharacter === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) {
            $lineComment = true;
            $statement .= ' ';
            $index++;
            continue;
        }

        if ($character === '/' && $nextCharacter === '*') {
            $blockComment = true;
            $statement .= ' ';
            $index++;
            continue;
        }

        if ($character === ';') {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
            $statement = '';
            continue;
        }

        $statement .= $character;
    }

    if ($quote !== null || $blockComment) {
        throw new RuntimeException('File SQL có chuỗi hoặc comment chưa đóng.');
    }

    if (trim($statement) !== '') {
        $statements[] = trim($statement);
    }

    return $statements;
}

/**
 * Đọc và chạy lần lượt các câu lệnh trong một file SQL.
 *
 * @param PDO $pdo Kết nối đến cơ sở dữ liệu.
 * @param string $filePath Đường dẫn file SQL cần chạy.
 * @return void
 */
function executeSqlFile(PDO $pdo, string $filePath): void
{
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Không đọc được file SQL: ' . basename($filePath));
    }

    foreach (splitSqlStatements($sql) as $statement) {
        $pdo->exec($statement);
    }
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, DB_OPTIONS);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `schema_migrations` ('
        . '`migration` VARCHAR(255) NOT NULL, '
        . '`applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'PRIMARY KEY (`migration`)'
        . ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $migrationFiles = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($migrationFiles, SORT_STRING);
    $appliedMigrations = $pdo->query('SELECT `migration` FROM `schema_migrations`')->fetchAll(PDO::FETCH_COLUMN);
    $appliedLookup = array_fill_keys($appliedMigrations, true);
    $migrationInsert = $pdo->prepare('INSERT INTO `schema_migrations` (`migration`) VALUES (:migration)');
    $pendingCount = 0;

    foreach ($migrationFiles as $migrationFile) {
        $migrationName = basename($migrationFile);
        if (isset($appliedLookup[$migrationName])) {
            continue;
        }

        executeSqlFile($pdo, $migrationFile);
        $migrationInsert->execute(['migration' => $migrationName]);
        $appliedLookup[$migrationName] = true;
        $pendingCount++;
        echo 'Đã chạy migration: ' . $migrationName . PHP_EOL;
    }

    if ($pendingCount === 0) {
        echo 'Không có migration mới.' . PHP_EOL;
    }

    if (in_array('--seed', $argv ?? [], true)) {
        $seedFiles = glob(__DIR__ . '/seed/*.sql') ?: [];
        sort($seedFiles, SORT_STRING);

        foreach ($seedFiles as $seedFile) {
            executeSqlFile($pdo, $seedFile);
            echo 'Đã chạy seeder: ' . basename($seedFile) . PHP_EOL;
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Lỗi chạy database: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

// & 'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe' BE/database/run.php
// & 'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe' BE/database/run.php --seed