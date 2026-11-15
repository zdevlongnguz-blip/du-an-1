# 02. Luồng nghiệp vụ

Phần này là các sơ đồ quan trọng nhất. Mỗi luồng được vẽ theo đúng code hiện tại.

## 2.1. Luồng tổng: một lượt hỏi đáp

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant FE as Popup (FE)
    participant RT as Router
    participant CT as ChatController
    participant DB as MySQL
    participant SV as Gemini service
    participant GM as Gemini API
    participant TL as Tool

    K->>FE: Gõ câu hỏi, bấm gửi
    FE->>RT: POST api-ai-chat {message, conversation_id?} + Bearer
    RT->>RT: requireAuth() kiểm tra JWT
    alt Token sai hoặc thiếu
        RT-->>FE: 401
    end
    RT->>CT: chat(payload)
    CT->>CT: Validate message (1-2000 ký tự), conversation_id (số nguyên dương)
    alt conversation_id có nhưng không thuộc user
        CT-->>FE: 404
    end
    opt Có conversation_id
        CT->>DB: Kiểm tra cuộc hội thoại thuộc user
        CT->>DB: Đọc tối đa 29 lượt gần nhất
    end
    CT->>SV: chat(message, history, {user_id})
    loop Tối đa 6 lượt gọi API (1 câu hỏi gốc + tối đa 5 vòng tool)
        SV->>GM: generateContent (lịch sử + system prompt + khai báo tool)
        GM-->>SV: text, hoặc functionCall
        alt Có functionCall
            SV->>TL: execute(args, context)
            TL-->>SV: kết quả (có thể kèm _action)
            SV->>SV: Tách _action ra khỏi kết quả
        end
    end
    SV->>SV: Tách [[GOI_Y: ...]] khỏi text
    SV-->>CT: text, tool_calls, actions, suggestions, history đầy đủ
    CT->>DB: Lưu lượt mới trong 1 transaction (tạo hội thoại nếu chưa có)
    CT-->>FE: 200 {conversation_id, reply, tool_calls, actions, suggestions}
    FE->>FE: Hiện câu trả lời, chip gợi ý, chip đã tra cứu
    alt actions có lệnh hợp lệ
        FE->>FE: Kiểm tra route trong danh sách cho phép
        FE->>FE: router.push
    end
```

## 2.2. Luồng quyết định của server sau khi nhận request

```mermaid
flowchart TD
    A([Nhận POST api-ai-chat]) --> B{Có Bearer token hợp lệ?}
    B -- Không --> E401[401 Chưa đăng nhập]
    B -- Có --> C{Method = POST?}
    C -- Không --> E405[405]
    C -- Có --> D{Body là JSON có message là chuỗi?}
    D -- Không --> E400[400]
    D -- Có --> F{message rỗng hoặc > 2000 ký tự?}
    F -- Có --> E422[422]
    F -- Không --> G{conversation_id có mà không phải số nguyên dương?}
    G -- Có --> E400b[400]
    G -- Không --> H{conversation_id có?}
    H -- Có --> I{Thuộc user này?}
    I -- Không --> E404[404]
    I -- Có --> J[Đọc 29 lượt gần nhất từ DB]
    H -- Không --> K[Lịch sử rỗng]
    J --> L[Gọi Gemini + vòng lặp tool]
    K --> L
    L --> M{Gemini lỗi?}
    M -- Thiếu API key --> E500a[500 chưa cấu hình]
    M -- Lỗi khác --> E502[502 đang gặp sự cố]
    M -- Không --> N{Lưu DB thành công?}
    N -- Không --> E500b[500 không lưu được lịch sử]
    N -- Có --> OK([200 success])
```

Điểm quan trọng: **cuộc hội thoại mới chỉ được tạo sau khi Gemini trả lời thành công.** Nếu Gemini lỗi, không có bản ghi rỗng nào được để lại.

## 2.3. Luồng gọi tool bên trong Gemini service

```mermaid
flowchart TD
    S([Bắt đầu chat]) --> H["Làm sạch lịch sử, thêm câu hỏi mới"]
    H --> R0["Vòng 0: gọi Gemini"]
    R0 --> P{Phản hồi có parts?}
    P -- Không --> EX[Ném lỗi: bị chặn hoặc rỗng]
    P -- Có --> FC{Có functionCall?}
    FC -- Không --> TXT["Tách [[GOI_Y]] khỏi text<br/>trả kết quả cuối"]
    FC -- Có --> RUN["Chạy từng tool theo thứ tự"]
    RUN --> ACT{Kết quả có _action?}
    ACT -- Có --> SAVE["Lưu lệnh, bỏ _action khỏi kết quả<br/>(chỉ giữ lệnh của lần cuối)"]
    ACT -- Không --> SEND
    SAVE --> SEND["Gửi functionResponse cho Gemini<br/>(role user)"]
    SEND --> CNT{Đã đủ 5 vòng tool?}
    CNT -- Chưa --> R0
    CNT -- Rồi --> ERR[Ném lỗi: gọi tool quá 5 vòng]
```

Lưu ý: functionResponse gửi lại cho AI **không còn** `_action`, nên AI không biết và không thể sửa lệnh điều hướng.

## 2.4. Nghiệp vụ: hỏi tra cứu sản phẩm

Ví dụ khách hỏi "Vinh Râu".

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant AI as Gemini
    participant T as search_product
    participant DB as v_product_catalog
    participant FE as Popup

    K->>AI: "Vinh Râu"
    AI-->>T: functionCall search_product {keyword: "Vinh Râu"}
    T->>T: Kiểm tra keyword (chuỗi, 1-100 ký tự)
    T->>DB: LIKE trên name, code, description (có escape % _ \)
    DB-->>T: tối đa 5 dòng, còn hàng xếp trước
    alt 0 dòng
        T-->>AI: products=[], message "chưa có/chưa cập nhật"
        T-->>FE: action catalog (không tham số)
    else 1 dòng
        T-->>AI: products=[1 sản phẩm]
        T-->>FE: action product-detail {slug}
    else nhiều dòng
        T-->>AI: products=[n sản phẩm]
        T-->>FE: action catalog {search: "Vinh Râu"}
    end
    AI-->>K: Trả lời đầy đủ: tên, giá, còn hàng, nhắc trang vừa mở
    FE->>FE: Chip "Đã tra cứu sản phẩm: Vinh Râu"
    FE->>FE: Mở trang tương ứng
```

## 2.5. Nghiệp vụ: yêu cầu hát

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách
    participant AI as Gemini
    participant T as fule_sing
    participant FE as Popup
    participant SG as /fule-sing

    K->>AI: "hát 1 bài đi"
    AI-->>T: functionCall fule_sing {song: rỗng}
    T->>T: Chọn ngẫu nhiên trong PLAYLIST
    T-->>AI: found=true, song="Vì con"
    T-->>FE: action fule-sing {song, video}
    AI-->>K: Nói vui, mời bấm "Nghe Louis hát"
    FE->>SG: router.push, query song + video
    SG->>SG: resolveSongVideoUrl(video) kiểm tra tên file
    K->>SG: Bấm "Nghe Louis hát"
    SG->>SG: video.play() (trình duyệt chỉ cho phát sau thao tác của người dùng)
```

Trường hợp khách nhờ bài không có trong playlist:

```mermaid
flowchart LR
    Q["Khách: hát bài Lạc trôi"] --> T["fule_sing song='Lạc trôi'"]
    T --> F{Khớp playlist?}
    F -- Không --> R["found=false, playlist=[các bài có]"]
    R --> A["AI nói Louis chưa thuộc bài đó<br/>và kể các bài đang có"]
    A --> N["Không có action, trang không mở"]
    F -- Có --> OK["found=true + action fule-sing"]
```

## 2.6. Nghiệp vụ: hội thoại tiếp nối

```mermaid
sequenceDiagram
    autonumber
    participant FE as Popup
    participant CT as ChatController
    participant DB as MySQL

    Note over FE: Lần đầu: conversation_id = null
    FE->>CT: message="Có sáp không?"
    CT->>CT: Gọi Gemini xong mới lưu
    CT->>DB: INSERT ai_conversations + ai_messages (1 transaction)
    CT-->>FE: conversation_id = 12
    Note over FE: Lưu conversation_id trong state
    FE->>CT: message="Giá bao nhiêu?", conversation_id=12
    CT->>DB: Kiểm tra user sở hữu cuộc 12
    CT->>DB: Đọc 29 lượt gần nhất
    CT->>CT: Gọi Gemini với lịch sử đã đọc
    CT->>DB: Lưu 2-4 lượt mới, cập nhật updated_at
    CT-->>FE: conversation_id = 12
    Note over FE: Nếu 404 (không còn thuộc user): xóa conversation_id để lần sau tạo mới
```

## 2.7. Nghiệp vụ: gợi ý theo ngữ cảnh

```mermaid
flowchart LR
    AI["AI viết câu trả lời<br/>+ dòng [[GOI_Y: a | b | c]]"] --> SP["Gemini::splitSuggestions"]
    SP --> CL["Làm sạch: bỏ rỗng, bỏ trùng,<br/>tối đa 3 câu, mỗi câu 40 ký tự"]
    CL --> TX["text: bỏ dòng gợi ý"]
    CL --> SG["suggestions: mảng chuỗi"]
    SG --> FE{FE có gợi ý?}
    FE -- Có --> CHIP["Hiện chip do AI sinh"]
    FE -- Không --> DEF["Hiện 3 chip mặc định"]
```

Dòng gợi ý được **giữ nguyên trong lịch sử DB** để lượt sau AI thấy đúng định dạng đã dùng.

## 2.8. Nghiệp vụ: xử lý lỗi ở FE

```mermaid
stateDiagram-v2
    [*] --> Rảnh
    Rảnh --> DangGui: Gửi câu hỏi
    DangGui --> ThanhCong: 200 success
    DangGui --> LoiTamThoi: 500/502/mạng
    DangGui --> HetCuocHoi: 404
    DangGui --> ChuaDangNhap: 401 (interceptor chuyển /login)
    ThanhCong --> Rảnh: Hiện reply, chip, điều hướng
    LoiTamThoi --> Rảnh: Tin nhắn có nút "Thử lại"
    HetCuocHoi --> Rảnh: Xóa conversation_id, lần sau tạo mới
    ChuaDangNhap --> [*]
```

## 2.9. Nghiệp vụ: đăng nhập và quay lại

```mermaid
sequenceDiagram
    autonumber
    actor K as Khách chưa đăng nhập
    participant P as Popup
    participant RU as returnUrl.js
    participant LG as Trang Login

    K->>P: Mở popup
    P-->>K: Hiện "Đăng nhập để trò chuyện cùng Louis"
    K->>P: Bấm "Đăng nhập"
    P->>RU: saveReturnUrl(đường dẫn hiện tại)
    RU->>RU: Lưu trong localStorage (chỉ URL cùng origin)
    P->>LG: Chuyển sang /login
    K->>LG: Đăng nhập thành công
    LG->>RU: pushToReturnUrl() đọc và xóa đường dẫn đã lưu
    LG-->>K: Quay về đúng trang cũ
```

## 2.10. Vòng đời trạng thái của một lượt chat trong popup

```mermaid
stateDiagram-v2
    [*] --> ChuaCoHoiThoai
    ChuaCoHoiThoai --> DangCho: sendMessage()
    DangCho --> CoCauTraLoi: 200
    DangCho --> LoiCoThuLai: lỗi
    LoiCoThuLai --> DangCho: retryMessage()
    CoCauTraLoi --> DangCho: sendMessage() tiếp
    CoCauTraLoi --> ChuaCoHoiThoai: resetChat() (nút cuộc mới hoặc đăng xuất)
    CoCauTraLoi --> CoCauTraLoi: điều hướng nếu có action
```
