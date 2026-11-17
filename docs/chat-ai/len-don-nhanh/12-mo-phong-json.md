# 12. Mô phỏng JSON theo từng bước

File này đi **trọn luồng** của tool lên đơn qua 3 kịch bản. Mỗi bước có một cây JSON và ghi rõ **ai gửi → ai nhận**, trường nào bị tách, bị ẩn, bị lưu.

Quy ước:

- Sản phẩm, giá, tồn kho, SKU lấy **đúng từ DB local** lúc viết tài liệu (`CLAYMATION`, `GOT2B-GLUED`).
- Tên, SĐT, địa chỉ là **dữ liệu giả**.
- `"..."` là chỗ rút gọn cho dễ đọc (ví dụ toàn văn system prompt).
- `thoughtSignature` là chuỗi mã hóa Gemini có thể gắn vào lượt gọi tool; server lưu nguyên văn, không đọc.

Dữ liệu nền:

| SKU | Sản phẩm | Phiên bản | Giá niêm yết | Giá bán (`final_price`) | Tồn |
| --- | --- | --- | --- | --- | --- |
| `CLAYMATION` | Sáp Vuốt Tóc Hanz de Fuko Claymation | Hũ 56g | 480.000 | 450.000 | 46 |
| `GOT2B-GLUED` | Gel Vuốt Tóc Got2b Glued Spiking Wax | Mặc định | 250.000 | 250.000 | 30 |

---

## Kịch bản 1: Lên đơn thành công và xác nhận COD

Khách (user ID 6, hồ sơ đủ thông tin) đang ở cuộc chat số 22, nhắn: **"lấy cho anh 2 hũ Claymation"**.

### Bước 1.1 — FE → BE: `POST api-ai-chat`

```http
POST /BE/?action=api-ai-chat
Authorization: Bearer eyJhbGciOi...   (interceptor tự gắn)
Content-Type: application/json
```

```json
{
  "message": "lấy cho anh 2 hũ Claymation",
  "conversation_id": 22
}
```

Chưa có đơn nháp nên **không** có `checkout_draft`. Router gọi `requireAuth()` → payload JWT `{ "sub": 6, "role": "user", ... }`. `ChatController` lấy `userId = 6` từ `sub`.

### Bước 1.2 — BE → Gemini: request lượt đầu

`Gemini::buildPayload()` dựng:

```json
{
  "contents": [
    { "role": "user", "parts": [{ "text": "...câu hỏi cũ trong cuộc 22..." }] },
    { "role": "model", "parts": [{ "text": "...câu trả lời cũ..." }] },
    { "role": "user", "parts": [{ "text": "lấy cho anh 2 hũ Claymation" }] }
  ],
  "systemInstruction": {
    "parts": [{ "text": "Bạn là Louis Dé Fule, trợ lý tư vấn của FuLe BarberShop... Lên đơn nhanh (tool create_order_draft): ..." }]
  },
  "tools": [{
    "functionDeclarations": [
      { "name": "search_product", "description": "Tìm sản phẩm...", "parameters": { "...": "..." } },
      { "name": "fule_sing", "description": "...", "parameters": { "...": "..." } },
      {
        "name": "create_order_draft",
        "description": "Lên đơn nháp cho khách khi khách muốn mua... Tool KHÔNG tạo đơn thật...",
        "parameters": {
          "type": "object",
          "properties": {
            "ops": { "type": "array", "items": { "type": "object", "properties": { "op": { "type": "string", "enum": ["add", "set", "subtract", "remove"] }, "sku": { "type": "string" }, "quantity": { "type": "integer" } }, "required": ["op", "sku"] } }
          },
          "required": ["ops"]
        }
      }
    ]
  }]
}
```

Không có `user_id` ở bất kỳ đâu trong payload: Gemini không biết và không chọn được khách. Tool cũng **không có** tham số tên/SĐT/địa chỉ.

### Bước 1.3 — Gemini → BE: muốn tra sản phẩm trước

Gemini chưa có SKU nên gọi `search_product`:

```json
{
  "candidates": [{
    "content": {
      "role": "model",
      "parts": [{
        "functionCall": { "name": "search_product", "args": { "keyword": "Claymation" } },
        "thoughtSignature": "CiQB...(chuỗi mã hóa)"
      }]
    }
  }]
}
```

### Bước 1.4 — Tool `search_product` trả kết quả (trong server)

```json
{
  "products": [{
    "id": 1,
    "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation",
    "code": "CLAYMATION",
    "category": "Gel, Sáp vuốt tóc",
    "price_from": 450000,
    "price_to": 740000,
    "in_stock": true,
    "variant_count": 2,
    "variants": [
      { "name": "Hũ 56g", "sku": "CLAYMATION", "price": 450000, "original_price": 480000, "stock": 46, "is_default": true },
      { "name": "Hũ 113g", "sku": "CLAYMATION-113G", "price": 740000, "original_price": 790000, "stock": 17, "is_default": false }
    ]
  }],
  "_action": {
    "type": "navigate",
    "route": "product-detail",
    "query": {},
    "params": { "slug": "sap-vuot-toc-hanz-de-fuko-claymation" }
  }
}
```

`Gemini::chat` **tách `_action`**:

- `actions` tạm thời = `[ { "type": "navigate", ... } ]`
- Phần còn lại (không có `_action`) được gửi cho Gemini ở bước sau.

### Bước 1.5 — BE → Gemini: gửi kết quả tra cứu

Hai lượt mới được nối vào `contents`:

```json
[
  { "role": "model", "parts": [{ "functionCall": { "name": "search_product", "args": { "keyword": "Claymation" } }, "thoughtSignature": "CiQB..." }] },
  { "role": "user", "parts": [{ "functionResponse": { "name": "search_product", "response": { "products": [ "...như bước 1.4, KHÔNG có _action..." ] } } }] }
]
```

### Bước 1.6 — Gemini → BE: gọi tool lên đơn

Khách nói "Claymation" không nói phiên bản → Gemini chọn bản `is_default` (Hũ 56g), số lượng 2:

```json
{
  "candidates": [{
    "content": {
      "role": "model",
      "parts": [{
        "functionCall": {
          "name": "create_order_draft",
          "args": { "ops": [{ "op": "add", "sku": "CLAYMATION", "quantity": 2 }] }
        },
        "thoughtSignature": "CiQB..."
      }]
    }
  }]
}
```

Chỉ có `ops` (lệnh `add` vì chưa có đơn nháp): tool không nhận thông tin cá nhân từ AI, tự lấy từ trang/hồ sơ.

### Bước 1.7 — Tool `create_order_draft` chạy (trong server)

Đầu vào thật của `execute()`:

```json
{
  "args": { "ops": [{ "op": "add", "sku": "CLAYMATION", "quantity": 2 }] },
  "context": { "user_id": 6, "checkout_draft": [], "checkout_draft_dropped": 0 }
}
```

Đọc DB:

```json
{
  "getActiveVariantsBySkus": {
    "CLAYMATION": { "sku": "CLAYMATION", "variant_name": "Hũ 56g", "price": "480000.00", "sale_price": "450000.00", "final_price": "450000.00", "stock": 46, "product_name": "Sáp Vuốt Tóc Hanz de Fuko Claymation", "image": "default.jpg" }
  }
}
```

Tool **không đọc hồ sơ**. Thông tin người nhận không đi vào đây.

Kết quả tool:

```json
{
  "ready": true,
  "items": [
    { "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g", "quantity": 2, "line_total": 900000 }
  ],
  "total_amount": 900000,
  "message": "Đơn nháp đã mở trên website với ĐÚNG các món và số lượng trong items (báo khách theo số này). Website tự điền thông tin nhận hàng từ hồ sơ của khách; khách kiểm tra, điền ô còn thiếu nếu có, có thể chỉnh số lượng, chọn cách thanh toán rồi tự bấm \"Xác nhận đặt hàng\". Đơn CHƯA được tạo. Không hỏi thông tin cá nhân trong chat.",
  "_action": {
    "type": "checkout-draft",
    "draft": {
      "items": [{
        "sku": "CLAYMATION",
        "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation",
        "variant_name": "Hũ 56g",
        "image": "default.jpg",
        "quantity": 2,
        "price": 450000,
        "original_price": 480000,
        "stock": 46
      }],
      "total_amount": 900000
    }
  }
}
```

`Gemini::chat` tách `_action` lần nữa. Vì chỉ giữ lệnh của **lần gọi tool cuối**, `actions` bị **thay** từ `navigate` sang:

```json
[
  {
    "type": "checkout-draft",
    "draft": {
      "items": [{
        "sku": "CLAYMATION", "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation", "variant_name": "Hũ 56g",
        "image": "default.jpg", "quantity": 2, "price": 450000, "original_price": 480000, "stock": 46
      }],
      "total_amount": 900000
    }
  }
]
```

### Bước 1.8 — BE → Gemini: gửi kết quả lên đơn

```json
{
  "role": "user",
  "parts": [{
    "functionResponse": {
      "name": "create_order_draft",
      "response": {
        "ready": true,
        "items": [{ "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g", "quantity": 2, "line_total": 900000 }],
        "total_amount": 900000,
        "message": "Đơn nháp đã mở trên website... Đơn CHƯA được tạo."
      }
    }
  }]
}
```

**Không có** tên, SĐT, địa chỉ, `_action`.

### Bước 1.9 — Gemini → BE: câu trả lời cuối

```json
{
  "candidates": [{
    "content": {
      "role": "model",
      "parts": [{ "text": "Louis Dé Fule đã lên đơn nháp 2 hũ Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g, tổng 900.000đ. Đơn nháp vừa mở trên trang \"Lên đơn nhanh\", quý ông kiểm tra rồi bấm \"Xác nhận đặt hàng\" để hoàn tất ạ.\n[[GOI_Y: Đổi thành 3 hũ | Thêm một sản phẩm khác | Có phiên bản lớn hơn không?]]" }]
    }
  }]
}
```

### Bước 1.10 — BE ghi `ai_messages` (cuộc 22)

`ChatController` lưu **6 lượt mới** (cột `parts` lưu nguyên văn JSON):

| seq | role | parts |
| --- | --- | --- |
| n+1 | user | `[{"text":"lấy cho anh 2 hũ Claymation"}]` |
| n+2 | model | `[{"functionCall":{"name":"search_product","args":{"keyword":"Claymation"}},"thoughtSignature":"CiQB..."}]` |
| n+3 | user | `[{"functionResponse":{"name":"search_product","response":{"products":[...]}}}]` |
| n+4 | model | `[{"functionCall":{"name":"create_order_draft","args":{"ops":[{"op":"add","sku":"CLAYMATION","quantity":2}]}},"thoughtSignature":"CiQB..."}]` |
| n+5 | user | `[{"functionResponse":{"name":"create_order_draft","response":{"ready":true,"items":[...],"total_amount":900000,"message":"..."}}}]` |
| n+6 | model | `[{"text":"Louis Dé Fule đã lên đơn nháp...\n[[GOI_Y: ...]]"}]` |

Không lượt nào chứa `_action` hay SĐT/địa chỉ của hồ sơ.

### Bước 1.11 — BE → FE: response `api-ai-chat`

```json
{
  "status": "success",
  "conversation_id": 22,
  "reply": "Louis Dé Fule đã lên đơn nháp 2 hũ Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g, tổng 900.000đ. Đơn nháp vừa mở trên trang \"Lên đơn nhanh\", quý ông kiểm tra rồi bấm \"Xác nhận đặt hàng\" để hoàn tất ạ.",
  "tool_calls": [
    { "name": "search_product", "args": { "keyword": "Claymation" } },
    { "name": "create_order_draft", "args": { "ops": [{ "op": "add", "sku": "CLAYMATION", "quantity": 2 }] } }
  ],
  "actions": [{
    "type": "checkout-draft",
    "draft": {
      "items": [{ "sku": "CLAYMATION", "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation", "variant_name": "Hũ 56g", "image": "default.jpg", "quantity": 2, "price": 450000, "original_price": 480000, "stock": 46 }],
            "total_amount": 900000
    }
  }],
  "suggestions": ["Đổi thành 3 hũ", "Thêm một sản phẩm khác", "Có phiên bản lớn hơn không?"]
}
```

`reply` đã bỏ dòng `[[GOI_Y: ...]]`; các gợi ý nằm trong `suggestions`.

### Bước 1.12 — FE: `setCheckoutDraft()` lưu vào kho (sessionStorage)

`checkoutDraft.value` (và `sessionStorage` khóa `ai_checkout_draft`) sau khi kiểm tra. Người nhận chưa có: `profileLoaded` là `false`.

```json
{
  "userId": "6",
  "draft": {
    "items": [{
      "sku": "CLAYMATION", "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation", "variant_name": "Hũ 56g",
      "image": "default.jpg", "quantity": 2, "price": 450000, "original_price": 480000, "stock": 46
    }],
    "recipient": { "name": "", "phone": "", "address": "" },
    "profileLoaded": false
  }
}
```

Ngay sau đó trang gọi `ensureDraftRecipient()`: gọi `api-profile` một lần, điền các ô trống, rồi đặt `profileLoaded: true` và ghi lại storage.

`total_amount` không giữ: trang tự tính `subtotal` theo số lượng hiện tại. Sau đó `router.push({ name: "ai-checkout" })` → URL chỉ là `/ai-checkout`.

### Bước 1.13 — FE → BE: khách bấm "Xác nhận đặt hàng" (COD)

```http
POST /BE/?action=api-order-checkout-items
Authorization: Bearer eyJhbGciOi...
```

```json
{
  "items": [{ "sku": "CLAYMATION", "quantity": 2 }],
  "recipient_name": "Trần Minh Khôi",
  "recipient_phone": "0912345678",
  "shipping_address": "12 Lý Tự Trọng, Phường Bến Nghé, Quận 1",
  "payment_method": "cod",
  "expected_total": 900000
}
```

Không có giá từng món. Trong server: `BEGIN` → `SELECT ... WHERE sku IN ('CLAYMATION') ORDER BY id FOR UPDATE` → tổng = 2 × 45.000.000 xu = 90.000.000 xu → khớp `expected_total` → `insertOrderRows` → `COMMIT`.

### Bước 1.14 — BE → FE: 201

```json
{
  "status": "success",
  "message": "Đặt hàng thành công",
  "order": {
    "id": 77,
    "total_amount": "900000.00",
    "order_status": "pending",
    "payment_status": "unpaid",
    "payment_method": "cod"
  }
}
```

FE: `clearCheckoutDraft()` → `checkoutDraft.value = null`, toast thành công, mở `/orders/77`. Trong DB: 1 dòng `orders`, 1 dòng `order_details` (chụp tên, SKU, "Hũ 56g", đơn giá 450000.00), `stock` CLAYMATION 46 → 44, `sales_count` +2.

### Bước 1.15 — Về sau: `GET api-ai-history` trả cuộc 22

```json
{
  "status": "success",
  "conversation_id": 22,
  "messages": [
    { "role": "user", "text": "lấy cho anh 2 hũ Claymation", "created_at": "2026-11-16 22:40:01", "tool_calls": [], "suggestions": [] },
    {
      "role": "assistant",
      "text": "Louis Dé Fule đã lên đơn nháp 2 hũ ...",
      "created_at": "2026-11-16 22:40:01",
      "tool_calls": [
        { "name": "search_product", "args": { "keyword": "Claymation" } },
        { "name": "create_order_draft", "args": {} }
      ],
      "suggestions": ["Đổi thành 3 hũ", "Thêm một sản phẩm khác", "Có phiên bản lớn hơn không?"]
    }
  ]
}
```

Lượt `functionCall`/`functionResponse` không hiện thành tin nhắn. `args` của `create_order_draft` bị thay bằng `{}`. Lịch sử không có lệnh `checkout-draft`, nên tải lại không mở lại trang.

---

## Kịch bản 2: Hồ sơ thiếu SĐT và địa chỉ → khách tự điền trên trang

Khách user ID 7, hồ sơ chỉ có tên. Nhắn: **"mua 1 chai Got2b"**. (Bỏ qua bước `search_product`, giống kịch bản 1.) Louis **không hỏi** SĐT, địa chỉ trong chat.

### Bước 2.1 — Gemini gọi tool

```json
{ "functionCall": { "name": "create_order_draft", "args": { "ops": [{ "op": "add", "sku": "GOT2B-GLUED", "quantity": 1 }] } } }
```

### Bước 2.2 — Tool: chỉ kiểm tra món (không đọc hồ sơ)

```json
{
  "args": { "ops": [{ "op": "add", "sku": "GOT2B-GLUED", "quantity": 1 }] },
  "context": { "user_id": 7, "checkout_draft": [], "checkout_draft_dropped": 0 }
}
```

Kết quả tool:

```json
{
  "ready": true,
  "items": [{ "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax - Mặc định", "quantity": 1, "line_total": 250000 }],
  "total_amount": 250000,
  "message": "Đơn nháp đã mở trên website... Website tự điền người nhận từ hồ sơ. Không hỏi thông tin cá nhân trong chat.",
  "_action": {
    "type": "checkout-draft",
    "draft": {
      "items": [{ "sku": "GOT2B-GLUED", "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax", "variant_name": "Mặc định", "image": "got2b.jpg", "quantity": 1, "price": 250000, "original_price": null, "stock": 30 }],
            "total_amount": 250000
    }
  }
}
```

Gemini chỉ nhận món và tổng tiền. Không biết hồ sơ thiếu gì, cũng không thấy tên "Lê Văn An".

### Bước 2.3 — BE → FE: response `api-ai-chat`

```json
{
  "status": "success",
  "conversation_id": 31,
  "reply": "Louis Dé Fule đã lên đơn nháp 1 chai Gel Vuốt Tóc Got2b Glued Spiking Wax, tổng 250.000đ. Hồ sơ của quý ông chưa có số điện thoại và địa chỉ, quý ông điền giúp Louis ngay trên trang \"Lên đơn nhanh\" rồi bấm \"Xác nhận đặt hàng\" nhé.",
  "tool_calls": [{ "name": "create_order_draft", "args": { "ops": [{ "op": "add", "sku": "GOT2B-GLUED", "quantity": 1 }] } }],
  "actions": [{
    "type": "checkout-draft",
    "draft": {
      "items": [{ "sku": "GOT2B-GLUED", "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax", "variant_name": "Mặc định", "image": "got2b.jpg", "quantity": 1, "price": 250000, "original_price": null, "stock": 30 }],
            "total_amount": 250000
    }
  }],
  "suggestions": ["Đổi thành 2 chai", "Thêm một sản phẩm khác"]
}
```

Trang **vẫn mở**. Louis không cần biết hồ sơ thiếu gì.

### Bước 2.4 — Trang: gọi `api-profile`, điền ô trống, khách tự điền phần còn thiếu

- `ensureDraftRecipient()` gọi `GET api-profile` → `{ name: "Lê Văn An", phone: null, address: null }`. Chỉ điền tên; SĐT và địa chỉ để trống.
- Hiệu ứng gõ tên "Lê Văn An", **bỏ qua** ô SĐT và địa chỉ.
- Sau khi đóng dấu: 2 ô trống viền đồng nét đứt, dưới ô có "Hồ sơ chưa có số điện thoại, quý ông điền giúp Louis nhé."; dòng trạng thái màu đồng "Quý ông điền nốt các ô còn trống phía trên để hoàn tất đơn."
- Khách điền → `syncCheckoutDraft` ghi vào kho (và sessionStorage):

```json
{  }
```

### Bước 2.5 — Khách bấm xác nhận

Body `api-order-checkout-items` giống bước 1.13 với SĐT, địa chỉ khách vừa điền. Thông tin đi thẳng **form → API**, không qua Gemini, không lưu `ai_messages`.

### Bước 2.6 — Nếu khách vẫn tự gõ SĐT vào chat

Khách nhắn "số anh là 0987654321": prompt dặn Louis không nhắc lại, không dùng, mời khách điền trên trang. Tool không có tham số nhận SĐT nên dù AI muốn cũng không chuyển vào đơn được. Tuy nhiên câu khách gõ **vẫn được gửi cho Gemini và lưu** trong `ai_messages`, vì đó là tin nhắn của khách. Xem [08-bao-mat.md, mục 3](08-bao-mat.md).

---

## Kịch bản 3: Sửa trên trang → nhắn đổi số lượng → giá đổi lúc xác nhận

Đang có đơn nháp (user ID 6): **Claymation × 2 + Got2b × 1**, giao về "12 Lý Tự Trọng...".

### Bước 3.1 — Khách sửa trên trang

Khách bấm − ở Claymation (2 → 1) và sửa địa chỉ. `watch([quantities, form])` gọi:

```json
{
  "syncCheckoutDraft": {
    "quantities": [1, 1],
    "recipient": { "name": "Trần Minh Khôi", "phone": "0912345678", "address": "Tầng 5, 1 Lê Duẩn, Quận 1" }
  }
}
```

`checkoutDraft.value` sau khi đồng bộ (sửa tại chỗ, không thay object):

```json
{
  "items": [
    { "sku": "CLAYMATION", "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation", "variant_name": "Hũ 56g", "image": "default.jpg", "quantity": 1, "price": 450000, "original_price": 480000, "stock": 46 },
    { "sku": "GOT2B-GLUED", "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax", "variant_name": "Mặc định", "image": "got2b.jpg", "quantity": 1, "price": 250000, "original_price": null, "stock": 30 }
  ],
  "recipient": { "name": "Trần Minh Khôi", "phone": "0912345678", "address": "Tầng 5, 1 Lê Duẩn, Quận 1" },
  "profileLoaded": true
}
```

### Bước 3.2 — FE → BE: khách nhắn "thêm 1 chai Got2b nữa"

`getCheckoutDraftForChat()` sinh `checkout_draft`:

```json
{
  "message": "thêm 1 chai Got2b nữa",
  "conversation_id": 22,
  "checkout_draft": {
    "items": [
      { "sku": "CLAYMATION", "quantity": 1 },
      { "sku": "GOT2B-GLUED", "quantity": 1 }
    ]
  }
}
```

### Bước 3.3 — BE: `readCheckoutDraft` + `describeDraftItems`

```json
{
  "readCheckoutDraft": {
    "items": [{ "sku": "CLAYMATION", "quantity": 1 }, { "sku": "GOT2B-GLUED", "quantity": 1 }]
  },
  "describeDraftItems": [
    { "sku": "CLAYMATION", "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g", "quantity": 1 },
    { "sku": "GOT2B-GLUED", "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax - Mặc định", "quantity": 1 }
  ]
}
```

Ba đường, cùng một danh sách `describeDraftItems`:

```json
{
  "systemInstruction_noi_them": "\n\nĐơn nháp khách ĐANG MỞ trên trang \"Lên đơn nhanh\" (...):\n- SKU CLAYMATION: Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g x 1\n- SKU GOT2B-GLUED: Gel Vuốt Tóc Got2b Glued Spiking Wax - Mặc định x 1\n\nQuy tắc khi có đơn nháp đang mở:\n- Đây là trạng thái MỚI NHẤT của đơn...\n- Khách muốn sửa đơn: gọi create_order_draft với lệnh CHỈ cho phần khách vừa nhắc (add/set/subtract/remove)... ",
  "cau_gui_gemini": "thêm 1 chai Got2b nữa\n\n[Website tự gửi kèm, khách không gõ dòng này] Đơn nháp trên trang LÚC NÀY: SKU CLAYMATION (Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g) x 1; SKU GOT2B-GLUED (Gel Vuốt Tóc Got2b Glued Spiking Wax - Mặc định) x 1. Số này mới hơn mọi số trong lịch sử chat. Sửa đơn thì chỉ gửi lệnh cho phần khách vừa nhắc, không tự cộng trừ.",
  "context_cho_tool": {
    "user_id": 6,
    "checkout_draft": [
      { "sku": "CLAYMATION", "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g", "quantity": 1 },
      { "sku": "GOT2B-GLUED", "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax - Mặc định", "quantity": 1 }
    ],
    "checkout_draft_dropped": 0
  }
}
```

- `systemInstruction_noi_them`: chỉ có món, không lưu.
- `cau_gui_gemini`: câu khách + ghi chú, **chỉ** trong request gửi Gemini. Trước khi ghi `ai_messages`, `ChatController` trả lại đúng câu `"thêm 1 chai Got2b nữa"`.
- `context_cho_tool`: không gửi cho Gemini, chỉ tool đọc.

### Bước 3.4 — Gemini gọi tool với MỘT lệnh

Gemini chỉ dịch câu khách thành lệnh. Khách chỉ nhắc Got2b nên chỉ có một lệnh. Claymation **không** cần gửi: server tự giữ (1 trên trang, không phải 2 như lịch sử chat):

```json
{
  "functionCall": {
    "name": "create_order_draft",
    "args": {
      "ops": [
        { "op": "add", "sku": "GOT2B-GLUED", "quantity": 1 }
      ]
    }
  }
}
```

### Bước 3.5 — Tool áp lệnh; người nhận vẫn nằm ở FE

`applyOps` lấy gốc từ `context_cho_tool.checkout_draft` = [Claymation 1, Got2b 1], áp `add Got2b 1` → [Claymation 1, Got2b **2**].

Tool không đọc người nhận. Đơn mới từ Louis chỉ thay danh sách món; `recipient` và `profileLoaded` của đơn cũ được giữ ở FE. Kết quả:

```json
{
  "ready": true,
  "items": [
    { "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation - Hũ 56g", "quantity": 1, "line_total": 450000 },
    { "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax - Mặc định", "quantity": 2, "line_total": 500000 }
  ],
  "total_amount": 950000,
  "message": "Đơn nháp đã mở trên website với ĐÚNG các món và số lượng trong items (báo khách theo số này)... Đơn CHƯA được tạo.",
  "_action": {
    "type": "checkout-draft",
    "draft": {
      "items": [
        { "sku": "CLAYMATION", "name": "Sáp Vuốt Tóc Hanz de Fuko Claymation", "variant_name": "Hũ 56g", "image": "default.jpg", "quantity": 1, "price": 450000, "original_price": 480000, "stock": 46 },
        { "sku": "GOT2B-GLUED", "name": "Gel Vuốt Tóc Got2b Glued Spiking Wax", "variant_name": "Mặc định", "image": "got2b.jpg", "quantity": 2, "price": 250000, "original_price": null, "stock": 30 }
      ],
      "total_amount": 950000
    }
  }
}
```

`items` gửi Gemini là số cuối cùng, AI đọc lại cho khách. `_action` được `Gemini::chat` tách ra cho FE.

Địa chỉ đã sửa trên trang được giữ ở FE, không đi qua server. FE nhận `checkout-draft` mới → `setCheckoutDraft` thay object → `watch(checkoutDraft)` của trang thấy object mới → diễn lại hiệu ứng.

### Bước 3.6 — Admin đổi giá Got2b 250.000 → 270.000, khách bấm xác nhận

```json
{
  "items": [
    { "sku": "CLAYMATION", "quantity": 1 },
    { "sku": "GOT2B-GLUED", "quantity": 2 }
  ],
  "recipient_name": "Trần Minh Khôi",
  "recipient_phone": "0912345678",
  "shipping_address": "Tầng 5, 1 Lê Duẩn, Quận 1",
  "payment_method": "sepay",
  "expected_total": 950000
}
```

Trong server: tổng tính lại = 450.000 + 2 × 270.000 = **990.000** ≠ 950.000 → `ROLLBACK`.

### Bước 3.7 — BE → FE: 409

```json
{
  "status": "error",
  "message": "Giá sản phẩm vừa thay đổi. Tổng mới là 990.000đ, quý khách vui lòng kiểm tra lại trước khi đặt.",
  "total_amount": "990000.00"
}
```

FE: `checkoutError` = message → khối đỏ + toast, **không** gọi SePay, đơn nháp vẫn còn. Không có dòng nào được ghi vào `orders`, tồn kho không đổi. Khách bấm "Nhờ Louis sửa đơn" để lên lại với giá mới.

---

## Tóm tắt: trường nào đi đâu

| Dữ liệu | FE → BE | BE → Gemini | Lưu `ai_messages` | BE → FE |
| --- | --- | --- | --- | --- |
| `user_id` | Không (nằm trong JWT) | **Không** | Không | Không |
| SKU + số lượng đơn đang mở | `checkout_draft.items` | Có (system prompt của lượt + ghi chú sau câu khách, tên từ DB) | **Không** (câu khách được trả về bản gốc trước khi lưu) | — |
| Số lượng trên trang cho tool (`context.checkout_draft`) | — | **Không** (chỉ tool đọc) | Không | — |
| Tên/SĐT/địa chỉ khách sửa trên trang | Không đi (ở FE, sessionStorage) | **Không** | **Không** | — |
| Tên/SĐT/địa chỉ từ hồ sơ | `GET api-profile` (FE tự gọi, 1 lần/đơn) | **Không** | **Không** | Có (response `api-profile`) |
| Tên/SĐT/địa chỉ khách tự gõ trong chat (Louis không hỏi, không dùng) | `message` | Có | **Có** | — |
| `_action` (chỉ món + tổng) | — | **Không** | **Không** | Có (`actions`) |
| Giá từng món | **Không bao giờ** | Có (kết quả tool) | Có (kết quả tool) | Có (đơn nháp, chỉ để xem) |
| `expected_total` | Có (lúc xác nhận) | — | — | — |
