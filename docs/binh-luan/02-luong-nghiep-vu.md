# 02. Luồng nghiệp vụ

## 1. Khách xem bình luận (công khai)

```mermaid
sequenceDiagram
    participant K as Khách
    participant FE
    participant BE as CommentController
    participant DB

    K->>FE: Mở trang chi tiết sản phẩm
    FE->>BE: GET api-product-comments?slug=...&page=1&per_page=10
    BE->>DB: Tìm sản phẩm theo slug
    alt Không có sản phẩm
        BE-->>FE: 404
    else Có
        BE->>DB: Đếm + lấy bình luận WHERE status = 1
        BE-->>FE: 200 { comments: { data, pagination } }
    end
```

Chỉ bình luận `status = 1` được trả về. Bình luận bị chặn hoặc chờ duyệt không bao giờ lộ ra API công khai.

## 2. Tạo bình luận (có chốt chặn AI)

```mermaid
flowchart TD
    A[POST api-comment-create] --> B{Có token hợp lệ?}
    B -->|Không| E401[401]
    B -->|Có| C{Method POST?}
    C -->|Không| E405[405]
    C -->|Có| D{Body là JSON?}
    D -->|Không| E400[400]
    D -->|Có| F{product_id nguyên dương?}
    F -->|Không| E422a[422]
    F -->|Có| G{content là chuỗi,<br/>1-2000 ký tự?}
    G -->|Không phải chuỗi| E400b[400]
    G -->|Rỗng/quá dài| E422b[422]
    G -->|Hợp lệ| X{Sản phẩm tồn tại?}
    X -->|Không| E404a[404, không gọi AI]
    X -->|Có| H{COMMENT_GUARD_ENABLED?}
    H -->|Tắt| P0[status 0, pending<br/>nhãn NULL]
    H -->|Bật| I[Gọi Colab /analyze]
    I -->|Lỗi mạng/timeout/sai dữ liệu| P0
    I -->|HIDDEN| BL[422 blocked<br/>KHÔNG lưu]
    I -->|VISIBLE + needs_review| PR[status 0, pending<br/>lưu nhãn]
    I -->|VISIBLE| AP[status 1, approved<br/>lưu nhãn]
    P0 --> S[INSERT nếu sản phẩm tồn tại]
    PR --> S
    AP --> S
    S -->|Không có sản phẩm| E404[404]
    S -->|OK| R201[201 + message theo moderation]
```

Thứ tự có chủ ý:
1. **Validate và kiểm tra sản phẩm trước, gọi AI sau**: dữ liệu sai hoặc sản phẩm không tồn tại thì trả lỗi ngay, không tốn lượt gọi Colab. Lúc INSERT vẫn kiểm tra sản phẩm lần nữa, phòng admin xóa sản phẩm trong lúc đang chờ AI.
2. **Gọi AI trước khi INSERT**: để lưu `status` và 3 nhãn trong cùng một câu lệnh, không phải INSERT rồi UPDATE.
3. **Câu bị AI chặn không được lưu** (từ 22/11/2026): trả 422, FE giữ nguyên ô nhập để khách sửa rồi gửi lại. Nếu lưu thì mỗi lần khách thử lại sẽ để một dòng rác bị ẩn trong DB. Nội dung và nhãn vẫn có trong log API.
4. **Câu không bị chặn luôn được lưu**, kể cả khi AI lỗi: AI chỉ quyết định `status` (hiện ngay hay chờ duyệt).

## 3. Sửa bình luận

```mermaid
flowchart TD
    A[PUT api-comment-update?id=...] --> B[Kiểm tra token, method, id, body, content]
    B --> C{Bình luận thuộc user này?<br/>findOwned}
    C -->|Không| E404[404]
    C -->|Có| D{Nội dung mới = nội dung cũ?}
    D -->|Giống| K[Không gọi AI<br/>200 'Nội dung bình luận không thay đổi'<br/>trả status đang lưu]
    D -->|Khác| M[Chấm lại bằng AI<br/>cùng bảng quyết định như khi tạo]
    M -->|blocked| B422[422, KHÔNG ghi đè<br/>bình luận cũ giữ nguyên]
    M -->|approved / pending| U[UPDATE content, status, 3 nhãn]
    U --> R200[200 + message theo moderation]
```

- Kiểm tra quyền sở hữu **trước** khi gọi AI: bình luận của người khác trả 404 luôn, không tốn lượt gọi.
- Sửa câu đã được duyệt thành câu chửi: bị từ chối (422), bình luận cũ vẫn hiện với nội dung cũ. Sửa câu đang chờ duyệt thành câu lịch sự: được hiện.
- Đây là lý do không thể "đăng câu sạch cho được duyệt rồi sửa thành câu chửi".

## 4. Xóa bình luận (user)

`DELETE api-comment-delete?id=...`: xóa cứng nếu bình luận thuộc user (`WHERE id = :id AND user_id = :user_id`). Không gọi AI. Không thuộc user hoặc không tồn tại: 404.

## 5. Admin duyệt và xóa

```mermaid
sequenceDiagram
    participant A as Admin
    participant BE
    participant DB

    A->>BE: GET api-admin-comments?status=0
    BE->>DB: Lấy bình luận chờ duyệt/bị chặn + 3 nhãn AI
    BE-->>A: 200 { comments: { data, pagination } }
    alt Duyệt
        A->>BE: PUT api-admin-comment-approve?id=...
        BE->>DB: UPDATE status = 1
        BE-->>A: 200 "Đã duyệt bình luận"
    else Xóa
        A->>BE: DELETE api-admin-comment-delete?id=...
        BE->>DB: DELETE
        BE-->>A: 200 "Đã xóa bình luận"
    end
```

- Admin **có quyền duyệt cả câu bị AI chặn**. AI chỉ là bước lọc tự động, quyết định cuối cùng thuộc về người. Khi duyệt, 3 nhãn AI được giữ nguyên để sau này biết câu đó từng bị AI chặn.
- Admin phân biệt "bị AI chặn" và "chờ duyệt" bằng `moderation_label`: `OFFENSIVE`/`HATE` là bị chặn; `CLEAN` là AI không chắc; `NULL` là chưa chấm (AI lỗi hoặc đang tắt, hoặc bình luận từ trước khi có AI).
