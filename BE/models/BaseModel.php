<?php
abstract class BaseModel
{
    protected $table;
    protected $pdo;
    // kết nối CSDL
    public function __construct()
    {
        // charset=utf8mb4: mỗi ký tự tối đa 4 byte, đủ cho emoji (😀) và chữ đặc biệt như 𝓟.
        // Không dùng "utf8" (trong MySQL chỉ tối đa 3 byte): bảng của dự án là utf8mb4, nên khi
        // so sánh (LIKE, =) với chuỗi chứa ký tự 4 byte, MySQL báo lỗi "Illegal mix of collations"
        // và API sập; khi đọc/ghi thì emoji bị đổi thành "?".
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        try {
            $this->pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, DB_OPTIONS);
        } catch (PDOException $e) {
            // xử lý lỗi kết nối
            die("Kết nối cơ sở dữ liệu thất bại: {$e->getMessage()}. Vui lòng thử lại sau");
        }
    }


    // --- Phương thức CRUD dùng chung ---

    public function all()
    {
        $sql = "SELECT * FROM {$this->table}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function save($data)
    {
        $keys = implode(", ", array_keys($data));
        $placeholders = ":" . implode(", :", array_keys($data));

        $sql = "INSERT INTO {$this->table} ($keys) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($data);
    }

    public function update($id, $data)
    {
        $fields = "";
        foreach ($data as $key => $value) {
            $fields .= "`$key` = :$key, ";
        }
        $fields = rtrim($fields, ", ");

        $query = "UPDATE `{$this->table}` SET $fields WHERE `id` = :id";
        $stmt = $this->pdo->prepare($query);

        $data['id'] = $id; // Gán ID vào mảng để bindValue tự động
        return $stmt->execute($data);
    }

    public function delete($id)
    {
        $query = "DELETE FROM `{$this->table}` WHERE `id` = :id";
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute(['id' => $id]);
    }

    // Clear Data

    public function truncate()
    {
        // 1. Tắt kiểm tra khóa ngoại
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

        // 2. Thực hiện xóa dữ liệu
        $sql = "TRUNCATE TABLE `{$this->table}`";
        $result = $this->pdo->exec($sql);

        // 3. Bật lại kiểm tra khóa ngoại
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        return $result;
    }

    //Join các bảng

    public function query($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Hủy kết nối CSDL
    public function __destruct()
    {
        $this->pdo = null;
    }
}
