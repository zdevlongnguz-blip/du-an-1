# 04. Contract API

Điểm vào duy nhất: `http://du-an-1.test/BE/index.php?action=<action>` (hoặc `/BE/?action=...`). Endpoint cần đăng nhập gửi header `Authorization: Bearer <JWT>`. Body JSON gửi kèm `Content-Type: application/json`.

Lỗi luôn có dạng `{ "status": "error", "message": "..." }`.

| # | Action | Method | Quyền | Có gọi AI |
| --- | --- | --- | --- | --- |
| 1 | `api-product-comments` | GET | Công khai | Không |
| 2 | `api-comment-create` | POST | Đăng nhập | **Có** |
| 3 | `api-comment-update` | PUT | Đăng nhập, chủ bình luận | **Có** (khi nội dung đổi) |
| 4 | `api-comment-delete` | DELETE | Đăng nhập, chủ bình luận | Không |
| 5 | `api-admin-comments` | GET | Admin | Không |
| 6 | `api-admin-comment-approve` | PUT | Admin | Không |
| 7 | `api-admin-comment-delete` | DELETE | Admin | Không |

Mọi response và message dưới đây lấy nguyên văn từ lần chạy test ngày 22/11/2026 (xem [06-kiem-thu.md](06-kiem-thu.md)).

---

## 1. `api-product-comments`: xem bình luận của sản phẩm

```text
GET ?action=api-product-comments&slug=<slug>&page=1&per_page=10
```

| Query | Bắt buộc | Quy tắc |
| --- | --- | --- |
| `slug` | Có | Chuỗi không rỗng, tối đa 255 ký tự |
| `page` | Không | Số nguyên ≥ 1, mặc định 1 |
| `per_page` | Không | Số nguyên 1–100, mặc định 10 |

**200**

```json
{
  "status": "success",
  "comments": {
    "data": [
      {
        "id": 16, "product_id": 1, "user_id": 8, "email": "...", "role": "user",
        "content": "tốt", "created_at": "2026-11-17 14:15:04", "updated_at": "2026-11-17 14:15:27"
      }
    ],
    "pagination": { "current_page": 1, "per_page": 2, "total_items": 3, "total_pages": 2 }
  }
}
```

- Chỉ trả bình luận `status = 1`, mới nhất trước.
- **Không** trả `status` và 3 nhãn AI (khách không cần biết).

| Mã | Khi nào | message |
| --- | --- | --- |
| 400 | Thiếu/sai `slug` | Slug sản phẩm không hợp lệ |
| 400 | `page`/`per_page` sai | Thông tin phân trang không hợp lệ |
| 404 | Không có sản phẩm | Không tìm thấy sản phẩm |
| 405 | Không phải GET | Phương thức không được hỗ trợ |

---

## 2. `api-comment-create`: viết bình luận

```text
POST ?action=api-comment-create
Authorization: Bearer <token user>

{ "product_id": 1, "content": "Sản phẩm rất tốt, giao hàng nhanh" }
```

| Trường | Kiểu | Quy tắc |
| --- | --- | --- |
| `product_id` | số nguyên | ≥ 1, sản phẩm phải tồn tại |
| `content` | string | Sau khi `trim` không rỗng, tối đa 2000 ký tự (đếm theo ký tự UTF-8, không theo byte) |

**201**: bình luận đã được lưu. FE xem `comment.moderation` để biết đã hiện hay đang chờ duyệt.

```json
{
  "status": "success",
  "message": "Thêm bình luận thành công!",
  "comment": {
    "id": 28,
    "product_id": 1,
    "content": "Sản phẩm rất tốt, giao hàng nhanh, sẽ ủng hộ shop dài dài",
    "status": 1,
    "moderation": "approved"
  }
}
```

| `moderation` | `status` | `message` |
| --- | --- | --- |
| `approved` | 1 | Thêm bình luận thành công! |
| `pending` | 0 | Thêm bình luận thành công, bình luận đang chờ quản trị viên duyệt. |

**422 bị AI chặn** (từ 22/11/2026): bình luận **không được lưu**. FE giữ nguyên ô nhập để khách sửa lại.

```json
{
  "status": "error",
  "message": "Bình luận chứa ngôn từ không phù hợp. Vui lòng sửa lại nội dung lịch sự hơn.",
  "moderation": "blocked"
}
```

Key `moderation: "blocked"` chỉ có ở lỗi này, giúp FE phân biệt với các lỗi 422 khác (nội dung rỗng, quá dài, `product_id` sai).

Response **không** trả nhãn hay điểm AI, để người cố tình lách luật không dò được ngưỡng chặn.

| Mã | Khi nào | message |
| --- | --- | --- |
| 400 | Body không phải JSON | Dữ liệu bình luận không hợp lệ |
| 400 | `content` không phải chuỗi | Nội dung bình luận không hợp lệ |
| 401 | Thiếu token | Unauthorized: thiếu Bearer token |
| 401 | Token sai | Unauthorized: Chữ ký token không hợp lệ (hoặc thông báo token khác của middleware) |
| 404 | Sản phẩm không tồn tại | Không tìm thấy sản phẩm |
| 405 | Không phải POST | Phương thức không được hỗ trợ |
| 422 | `product_id` thiếu/không phải số nguyên dương | ID sản phẩm không hợp lệ |
| 422 | `content` rỗng hoặc quá 2000 ký tự | Bình luận phải có nội dung và không quá 2.000 ký tự |
| 422 | AI chặn nội dung (kèm `moderation: "blocked"`) | Bình luận chứa ngôn từ không phù hợp. Vui lòng sửa lại nội dung lịch sự hơn. |

Lỗi của Colab **không** trả mã lỗi cho khách: bình luận vẫn được lưu với `moderation = pending`.

---

## 3. `api-comment-update`: sửa bình luận của mình

```text
PUT ?action=api-comment-update&id=31
Authorization: Bearer <token user>

{ "content": "Áo đẹp, vải mát, mặc vừa in" }
```

**200**

```json
{
  "status": "success",
  "message": "Cập nhật bình luận thành công!",
  "comment": { "id": 31, "content": "Áo đẹp, vải mát, mặc vừa in", "status": 1, "moderation": "approved" }
}
```

| Trường hợp | `message` |
| --- | --- |
| Nội dung đổi, `approved` | Cập nhật bình luận thành công! |
| Nội dung đổi, `pending` | Cập nhật bình luận thành công, bình luận đang chờ quản trị viên duyệt. |
| Nội dung **giống hệt** (sau `trim`) | Nội dung bình luận không thay đổi. Không gọi AI; `status`/`moderation` là trạng thái đang lưu |

Nội dung mới bị AI chặn: **422** giống `api-comment-create`, bình luận cũ **giữ nguyên** nội dung và trạng thái (không ghi đè).

Khi nội dung không đổi, `moderation` được suy ra từ DB: `status = 1` thì `approved`; còn lại `pending`. (Dòng bị chặn cũ, có trước ngày 22/11/2026, có thể ra `blocked`: `status = 0` và nhãn khác `CLEAN`.)

| Mã | Khi nào | message |
| --- | --- | --- |
| 400 | Thiếu/sai `id` | ID bình luận không hợp lệ |
| 400 | Body không phải JSON | Dữ liệu bình luận không hợp lệ |
| 400 | `content` không phải chuỗi | Nội dung bình luận không hợp lệ |
| 401 | Thiếu/sai token | Unauthorized: ... |
| 404 | Không tồn tại hoặc không phải của user này | Không tìm thấy bình luận |
| 405 | Không phải PUT | Phương thức không được hỗ trợ |
| 422 | `content` rỗng hoặc quá 2000 ký tự | Bình luận phải có nội dung và không quá 2.000 ký tự |
| 422 | AI chặn nội dung mới (kèm `moderation: "blocked"`), bình luận cũ giữ nguyên | Bình luận chứa ngôn từ không phù hợp. Vui lòng sửa lại nội dung lịch sự hơn. |

---

## 4. `api-comment-delete`: xóa bình luận của mình

```text
DELETE ?action=api-comment-delete&id=28
Authorization: Bearer <token user>
```

**200** `{ "status": "success", "message": "Xóa bình luận thành công" }`

| Mã | Khi nào | message |
| --- | --- | --- |
| 400 | Thiếu/sai `id` | ID bình luận không hợp lệ |
| 401 | Thiếu/sai token | Unauthorized: ... |
| 404 | Không tồn tại hoặc không phải của user này | Không tìm thấy bình luận |
| 405 | Không phải DELETE | Phương thức không được hỗ trợ |

---

## 5. `api-admin-comments`: admin xem danh sách

```text
GET ?action=api-admin-comments&status=0&page=1&per_page=10
Authorization: Bearer <token admin>
```

| Query | Bắt buộc | Quy tắc |
| --- | --- | --- |
| `status` | Không | `0` hoặc `1`. Không gửi = lấy tất cả |
| `page`, `per_page` | Không | Như `api-product-comments` |

**200**

```json
{
  "status": "success",
  "comments": {
    "data": [
      {
        "id": 32, "product_id": 1, "product_name": "Sáp Vuốt Tóc Hanz de Fuko Claymation",
        "user_id": 2, "email": "user@example.com",
        "content": "Thằng chủ shop óc chó", "status": 0,
        "moderation_label": "HATE", "sentiment": null, "toxic_prob": "0.9962",
        "created_at": "2026-11-22 11:10:25", "updated_at": "2026-11-22 11:10:25"
      }
    ],
    "pagination": { "current_page": 1, "per_page": 3, "total_items": 10, "total_pages": 4 }
  }
}
```

- 3 key `moderation_label`, `sentiment`, `toxic_prob` mới thêm ngày 22/11/2026. Key cũ giữ nguyên.
- `toxic_prob` là **chuỗi số** (`"0.9962"`), vì PDO trả `DECIMAL` dạng chuỗi. FE muốn so sánh thì `Number(...)`.

| Mã | Khi nào | message |
| --- | --- | --- |
| 400 | `status` khác 0/1 | status phải là 0 hoặc 1 |
| 400 | Phân trang sai | Thông tin phân trang không hợp lệ |
| 401 | Thiếu/sai token | Unauthorized: ... |
| 403 | Không phải admin | Bạn không có quyền thực hiện thao tác này |
| 405 | Không phải GET | Phương thức không được hỗ trợ |

---

## 6. `api-admin-comment-approve`: admin duyệt

```text
PUT ?action=api-admin-comment-approve&id=32
Authorization: Bearer <token admin>
```

**200** `{ "status": "success", "message": "Đã duyệt bình luận" }`

- Đặt `status = 1`, **giữ nguyên** 3 nhãn AI.
- Duyệt được cả câu bị AI chặn (admin có quyền quyết định cuối).
- Duyệt lại câu đã duyệt vẫn trả 200.

| Mã | Khi nào | message |
| --- | --- | --- |
| 400 | Thiếu/sai `id` | ID bình luận không hợp lệ |
| 401 / 403 | Thiếu token / không phải admin | Unauthorized: ... / Bạn không có quyền thực hiện thao tác này |
| 404 | Không tồn tại | Không tìm thấy bình luận |
| 405 | Không phải PUT | Phương thức không được hỗ trợ |

---

## 7. `api-admin-comment-delete`: admin xóa

```text
DELETE ?action=api-admin-comment-delete&id=33
Authorization: Bearer <token admin>
```

**200** `{ "status": "success", "message": "Đã xóa bình luận" }`. Xóa cứng.

| Mã | Khi nào | message |
| --- | --- | --- |
| 400 | Thiếu/sai `id` | ID bình luận không hợp lệ |
| 401 / 403 | Thiếu token / không phải admin | Unauthorized: ... / Bạn không có quyền thực hiện thao tác này |
| 404 | Không tồn tại | Không tìm thấy bình luận |
| 405 | Không phải DELETE | Phương thức không được hỗ trợ |

---

## 8. Thay đổi contract ngày 22/11/2026

| Endpoint | Thay đổi | Ảnh hưởng FE |
| --- | --- | --- |
| `api-comment-create` | `comment` thêm `status`, `moderation`. `message` đổi theo kết quả. Câu bị AI chặn: 422 kèm `moderation: "blocked"`, không lưu (đổi lại cùng ngày, trước đó là 201 + lưu ẩn) | FE đã cập nhật ở `ProductDetail.vue` |
| `api-comment-update` | Như trên. Thêm trường hợp "Nội dung bình luận không thay đổi" | Như trên |
| `api-admin-comments` | Mỗi dòng thêm `moderation_label`, `sentiment`, `toxic_prob` | Chỉ thêm key, không vỡ code cũ |
| Các endpoint còn lại | Không đổi | – |

Không đổi tên action, không xóa key, không đổi mã HTTP hay yêu cầu đăng nhập.
