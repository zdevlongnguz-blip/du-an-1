# Hướng dẫn service Gemini chat có tool call

Tài liệu cho thành viên BE: service Gemini gồm những file nào, chạy theo luồng nào, cách gọi API chat và cách viết thêm tool nghiệp vụ.

Trạng thái hiện tại: **mới có khung**. Endpoint chat chạy được, chưa đăng ký tool nghiệp vụ nào, chưa bắt đăng nhập.

## 1. Khái niệm

- **Gemini**: mô hình AI của Google, gọi qua REST API (`generateContent`).
- **System prompt**: đoạn chỉ dẫn gửi kèm mọi request, người dùng không nhìn thấy. Nó quy định AI là ai, được trả lời gì.
- **Tool call (function calling)**: Gemini không tự đọc database. Khi cần dữ liệu, nó trả về yêu cầu "gọi hàm X với tham số Y". Server chạy hàm đó, gửi kết quả lại, Gemini mới viết câu trả lời.
- **History**: Gemini không nhớ các lượt trước. Mỗi request phải gửi lại toàn bộ lịch sử hội thoại.

## 2. Cấu trúc file

```
BE/
├── controllers/ChatController.php   # Nhận request HTTP, validate, gọi service, trả JSON
└── geminiServices/
    ├── Gemini.php                   # Lõi: cấu hình, gọi API, vòng lặp tool call
    ├── GeminiPrompt.php             # Nơi duy nhất sửa system prompt
    ├── GeminiTool.php               # Lớp cha cho mọi tool nghiệp vụ
    └── Test.php                     # Route thử nghiệm api-test-gemini (không dùng làm mẫu)
```

Các thay đổi đi kèm:

- `BE/configs/config.php`: thêm `PATH_GEMINI_SERVICE`, `GEMINI_API_KEY`, `GEMINI_MODEL`.
- `BE/index.php`: autoload thêm thư mục `geminiServices/`.
- `BE/routes/index.php`: thêm `api-test-gemini` và `api-ai-chat`.
- `BE/.env.example`: thêm tên biến `GEMINI_API_KEY`, `GEMINI_MODEL`.

### Vị trí trong kiến trúc

Service nằm **dưới controller**, ngang cấp với model. Router không gọi thẳng service.

```
routes/index.php → ChatController → Gemini (service) → GeminiToolXxx → Model → MySQL
                                         ↕
                                  Google Gemini API
```

- Controller lo phần HTTP: method, validate body, lấy user từ JWT, đổi exception thành mã HTTP, `response_json`.
- Service không biết gì về HTTP: nhận tham số, trả mảng, ném exception khi lỗi.

## 3. Cấu hình

Thêm vào `BE/.env` (không commit file này):

```
GEMINI_API_KEY=<key lấy tại https://aistudio.google.com/apikey>
GEMINI_MODEL=
```

- `GEMINI_MODEL` để trống thì dùng `gemini-2.5-flash`.
- Các giới hạn khác là hằng trong `Gemini.php`:

| Hằng | Giá trị | Ý nghĩa |
| --- | --- | --- |
| `TIMEOUT_SECONDS` | 30 | Thời gian chờ tối đa một request lên Google |
| `MAX_TOOL_ROUNDS` | 5 | Số vòng gọi tool tối đa, chặn vòng lặp vô hạn |
| `MAX_HISTORY` | 30 | Số lượt lịch sử gửi lên Gemini, tránh tốn token |

Đổi system prompt: sửa `GeminiPrompt::system()` trong `GeminiPrompt.php`.

## 4. API chat

**`POST ?action=api-ai-chat`**, tạm thời không cần đăng nhập.

Body JSON:

| Trường | Kiểu | Bắt buộc | Ghi chú |
| --- | --- | --- | --- |
| `message` | string | Có | 1 đến 2000 ký tự |
| `history` | array | Không | Lấy từ `history` của response lượt trước, tối đa 60 phần tử |

Thành công (200):

```json
{
  "status": "success",
  "reply": "Câu trả lời của trợ lý",
  "tool_calls": [{ "name": "search_product", "args": { "keyword": "sáp" } }],
  "history": [ ... ]
}
```

Lỗi:

| Mã | Khi nào |
| --- | --- |
| 405 | Không phải POST |
| 400 | Body không phải JSON, thiếu `message`, `history` sai kiểu hoặc quá dài |
| 422 | `message` rỗng hoặc quá 2000 ký tự |
| 500 | Chưa cấu hình `GEMINI_API_KEY` |
| 502 | Gemini lỗi, bị chặn nội dung, hoặc gọi tool quá 5 vòng |

Chi tiết lỗi 500/502 chỉ ghi vào log PHP (dòng bắt đầu bằng `[ChatController]`), không trả cho client.

### Test bằng Thunder Client

1. **POST** `http://localhost/du-an-1/BE/?action=api-ai-chat`
2. Body → JSON: `{ "message": "Xin chào, cửa hàng bán gì?" }`
3. Hỏi tiếp: copy mảng `history` trong response vào body lượt sau.

Lưu ý: gửi bằng `curl` trong Git Bash có thể làm hỏng chữ có dấu (trả 400). Khi đó ghi body ra file UTF-8 rồi dùng `--data-binary @body.json`.

## 5. Luồng tool call

Ví dụ khách hỏi *"Có sáp vuốt tóc nào dưới 200k không?"*:

1. `Gemini::chat()` gửi lên Google: lịch sử + câu hỏi, system prompt, danh sách tool (`functionDeclarations`).
2. Gemini trả về `functionCall`: `search_product { "keyword": "sáp vuốt tóc", "max_price": 200000 }`.
3. Server lưu lượt đó vào history, chạy `execute()` của tool. Tool gọi Model, lấy dữ liệu từ DB.
4. Server thêm kết quả vào history dưới dạng `functionResponse`, rồi gửi lại toàn bộ cho Gemini.
5. Gemini đọc kết quả và trả về câu trả lời dạng chữ. `chat()` trả `text`, `tool_calls`, `history` cho controller.

Gemini có thể gọi tiếp tool khác sau bước 4. Vòng lặp chỉ dừng khi Gemini trả về chữ, hoặc quá `MAX_TOOL_ROUNDS`.

Các trường hợp đặc biệt đã xử lý trong `Gemini.php`:

| Tình huống | Xử lý |
| --- | --- |
| Gọi nhiều tool cùng lúc | Chạy hết, gửi kết quả trong một lượt, đúng thứ tự |
| Tool ném `InvalidArgumentException` | Gửi message lỗi cho Gemini để nó giải thích lại cho khách |
| Tool lỗi hệ thống (DB...) | Ghi log, gửi Gemini thông báo chung, request vẫn trả 200 |
| Gemini gọi tool không tồn tại | Trả `{"error": "Tool không tồn tại"}` cho Gemini |
| `args` rỗng bị PHP đổi thành `[]` | Tự đổi về `{}` trước khi gửi lại, tránh Gemini báo sai kiểu |
| Kết quả tool là danh sách `[...]` | Tự bọc thành `{"result": [...]}` vì `functionResponse` phải là object |

Lưu ý: server luôn gửi đủ kết quả tool cho Gemini, nhưng câu trả lời vẫn do AI viết nên có thể diễn đạt sai. Dữ liệu quan trọng (giá, tổng tiền) FE nên lấy từ dữ liệu thật, không chỉ dựa vào câu chữ.

## 6. Viết tool mới

Tạo file `BE/geminiServices/GeminiToolXxx.php`, kế thừa `GeminiTool`, viết 4 method:

| Method | Trả về |
| --- | --- |
| `name()` | Tên tool, chỉ chữ, số, gạch dưới. Ví dụ `search_product` |
| `description()` | Mô tả **khi nào** nên gọi. Gemini dựa vào đây để quyết định |
| `parameters()` | JSON Schema của tham số. Trả `[]` nếu không có tham số |
| `execute($args, $context)` | Mảng kết quả gửi lại cho Gemini |

Ví dụ (minh họa, cần kiểm tra lại tên cột khi viết thật):

```php
class GeminiToolSearchProduct extends GeminiTool
{
    public function name() { return 'search_product'; }

    public function description()
    {
        return 'Tìm sản phẩm của cửa hàng theo từ khóa. Dùng khi khách hỏi có bán gì, giá bao nhiêu.';
    }

    public function parameters()
    {
        return [
            'type' => 'object',
            'properties' => ['keyword' => ['type' => 'string', 'description' => 'Từ khóa, ví dụ: sáp, gôm']],
            'required' => ['keyword'],
        ];
    }

    public function execute($args = [], $context = [])
    {
        $keyword = trim((string) ($args['keyword'] ?? ''));
        if ($keyword === '') {
            throw new InvalidArgumentException('Thiếu từ khóa tìm kiếm');
        }

        // Dùng lại hàm có sẵn của Model, không viết SQL trong tool
        $result = (new Product())->getFiltered($keyword, null, 1, 5);

        // Chỉ trả các cột cần thiết
        return array_map(fn($p) => ['name' => $p['name'], 'price' => $p['price']], $result['data']);
    }
}
```

Đăng ký tool trong `ChatController::chat()`:

```php
$result = (new Gemini())
    ->addTool(new GeminiToolSearchProduct())
    ->chat($message, $history, ['user_id' => $payload['sub']]);
```

### Quy tắc khi viết tool

- **Dùng lại hàm của Model.** Tool chỉ là lớp bọc. Model chưa có hàm phù hợp thì thêm method vào Model theo pattern sẵn có.
- **Không cho Gemini gọi thẳng method của Model.** `Product` có `deleteAdmin`, `BaseModel` có `truncate`, `query`. Chỉ tool đã `addTool()` mới gọi được, đây là danh sách cho phép.
- **`$args` do AI sinh ra**, phải validate như dữ liệu người dùng nhập.
- **User ID lấy từ `$context`** (`sub` trong JWT), không lấy từ `$args`.
- **Trả dữ liệu gọn**: chỉ vài cột cần thiết, không trả cả bản ghi DB.
- **Lỗi do tham số sai** thì ném `InvalidArgumentException` với message dễ hiểu. Lỗi khác sẽ bị thay bằng thông báo chung.

## 7. Hướng phát triển đã bàn

### Tool điều hướng (ví dụ "mở giỏ hàng của tôi")

Server không điều hướng được trình duyệt. Hướng làm:

- Tool `navigate` chỉ nhận tên trang trong danh sách cho phép (`enum` trong schema + `in_array` trong `execute`), không nhận URL tự do.
- `Gemini.php` gom các lệnh này vào trường `actions` của response, ví dụ `[{ "type": "navigate", "page": "cart" }]`.
- FE đọc `actions` và gọi `router.push`. Route guard và `requireAuth()` vẫn là lớp phân quyền.
- Đây là **thay đổi contract**, cần thống nhất với FE trước khi làm.

## 8. Việc còn lại và lưu ý

- [ ] Viết tool nghiệp vụ đầu tiên (`GeminiToolSearchProduct`, `GeminiToolPrepareOrder`).
- [ ] Bắt đăng nhập cho `api-ai-chat` (`requireAuth()` ở router, truyền `sub` vào `$context`).
- [ ] Lưu history ở server (session hoặc DB). Hiện client gửi history lên, nên có thể tự chèn kết quả tool giả. Với tool chỉ đọc thì chấp nhận được, với `PrepareOrder` thì không.
- [ ] Chưa có rate limit cho `api-ai-chat`. Mỗi request tốn phí gọi Google.
- [ ] Chưa test với Gemini thật (chưa có `GEMINI_API_KEY` trên máy test). Luồng tool call mới được thử bằng client giả lập.
- Comment của route `api-guns` trong `BE/routes/index.php` có từ ngữ xúc phạm, cần sửa ở task riêng.
