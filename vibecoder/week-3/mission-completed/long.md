# Mission completed - Long (BE) - Tuần 3

Ngày ghi: 2026-11-13. Ghi tự động vì không có công cụ kết nối vibe coder.

Tham chiếu: `vibecoder/Week 2/Long.MD` (phần BE).

## Mission
- Đổi cấu trúc DB cho product thành có biến thể.
- Viết lại các API endpoint cần thiết cho catalog, detail, cart.
- Viết test kiểm thử.

## Trạng thái

| Hạng mục | Trạng thái | Ghi chú |
| --- | --- | --- |
| DB có biến thể (`product_variants`, `cart_items.variant_id`) | Hoàn thành | Migration 03 đã áp dụng |
| API catalog (`api-products`, top 5) | Hoàn thành | Đã sửa cho schema biến thể, đã merge (PR #41) |
| API detail (`api-product-detail`) | Hoàn thành | `Product::getBySlug`, `ProductController::detail`, đã merge (PR #42) |
| API cart (`api-cart`, add, update, delete) | Hoàn thành, chưa commit | Nhánh `feature-cart`. `Cart.php` và `CartController.php` đã viết lại theo `variant_id` |
| Checkout và hủy đơn theo biến thể | Chưa làm | `BE/models/Order.php` còn đọc `product_id`, `products.price/stock` |
| Test kiểm thử tự động | Chưa làm | Mới kiểm tra bằng `php -l` và curl |

## Kiểm tra đã làm (cart)
- `php -l` cho `Cart.php` và `CartController.php`: OK.
- `GET api-cart` không token: 401.
- Chưa test với JWT hợp lệ: thêm trùng biến thể, vượt tồn kho, item của user khác.

## Còn lại
1. Test API cart với JWT hợp lệ (201, 200, 404, 409, 422).
2. Commit và tạo PR cho `feature-cart`.
3. Sửa `Order.php` checkout và hủy đơn theo biến thể.
4. Viết test kiểm thử cho BE.

Chi tiết: [cart.md](/C:/laragon/www/du-an-1/fix%20code/cart.md).

## Ghi công bổ sung (ngày 2026-11-14)

Người thực hiện: Long. Làm việc cùng Claude Code. Phần này làm thêm ngoài mission ban đầu, chưa commit, chưa tạo PR.

### Frontend: giao diện theo thiết kế Stitch
- Đổi giao diện sáng Navy & Brass cho header, footer, toast, các trang auth, danh sách sản phẩm, chi tiết sản phẩm, giỏ hàng, thanh toán, kết quả thanh toán, đơn hàng, trang chủ và 404.
- Đổi font sang Inter và Playfair Display (có bộ ký tự tiếng Việt), thay DM Sans và DM Serif Display.
- Sửa toast (căn theo nội dung trang, không dính header), menu mobile (hover, badge giỏ hàng), trạng thái active của nút giỏ hàng, kích thước lưới Top 5, nút Xóa trong giỏ.
- Giữ nguyên phần script và binding trong template; đã kiểm tra bằng so sánh với bản commit.

### Validate form đăng nhập
- FE: đổi kiểm tra mật khẩu của form đăng nhập sang cùng quy tắc form đăng ký (8–72 byte).
- BE: `api-login` thêm kiểm tra độ dài mật khẩu, trả 422 cùng thông báo với đăng ký. Trước đó trả 401.
- Lưu ý: tài khoản có mật khẩu dưới 8 ký tự sẽ không đăng nhập được nữa.

### Chi tiết sản phẩm: tồn kho trừ số đã có trong giỏ
- Khi đã đăng nhập, trang đọc giỏ hiện tại (`api-cart`, không đổi API) để trừ số đã có trong giỏ khỏi tồn kho.
- Khi đủ số lượng: hiện "Đã đủ số lượng trong giỏ", khóa nút thêm giỏ.
- BE vẫn kiểm tra lại khi thêm. Giỏ hàng không giữ kho.

### Cấu hình local
- `BE/.env`: bỏ comment `APP_URL` (địa chỉ ngrok), để trống 3 biến `SEPAY_*_URL`. File này không commit.

## Đã kiểm tra
- `npm run build` (FE): OK sau mỗi đợt sửa.
- `php -l` cho `AuthController.php`: OK.
- Trình duyệt (Chrome, qua ngrok): trang chủ, danh sách, đăng nhập, chi tiết sản phẩm, đăng nhập tài khoản test, thêm sản phẩm vào giỏ, xem giỏ; trạng thái tồn kho trên trang chi tiết khi giỏ đã đủ.
- API: login mật khẩu 3 ký tự trả 422 đúng thông báo.

## Đã test

## Ghi công bổ sung (ngày 2026-11-15)

Người thực hiện: Long. Làm việc cùng Claude Code. Phần này làm thêm ngoài mission ban đầu.

### Backend: dựng khung service Gemini chat có tool call
- Tạo thư mục `BE/geminiServices/` và autoload cho thư mục này trong `BE/index.php`.
- `Gemini.php`: gọi Gemini API bằng cURL, vòng lặp tool call (tối đa 5 vòng), giữ và cắt lịch sử hội thoại, xử lý lỗi tool và trường hợp bị chặn nội dung.
- `GeminiPrompt.php`: file riêng cho system prompt của trợ lý FuLe BarberShop.
- `GeminiTool.php`: lớp cha cho tool nghiệp vụ (`name`, `description`, `parameters`, `execute`).
- `ChatController.php` + route `api-ai-chat` (POST, body `message` + `history`), tạm thời chưa bắt đăng nhập.
- Cấu hình: `GEMINI_API_KEY`, `GEMINI_MODEL` đọc từ `BE/.env`, thêm tên biến vào `.env.example`.
- Ban đầu tách 5 file (Config, Client, History, Tool, Gemini); sau đó gộp còn 3 file cho dễ đọc.
- Tài liệu: [huong-dan-gemini-chat-tools.md](../../../docs/huong-dan-gemini-chat-tools.md).

### Đã kiểm tra
- `php -l` cho các file trong `BE/geminiServices/`, `ChatController.php`, `config.php`, `routes/index.php`: OK.
- Client giả lập (không gọi Google): gọi nhiều tool một lượt, tool lỗi, tool không tồn tại, hỏi tiếp với history lưu qua JSON, quá 5 vòng, bị chặn nội dung, câu hỏi rỗng, prompt mặc định và prompt riêng: đúng.
- curl qua Laragon `api-ai-chat`: GET 405, body sai 400, message rỗng 422, history sai kiểu 400, chưa có key 500.
- Chưa test với Gemini thật vì chưa có `GEMINI_API_KEY`.

### Còn lại
1. Viết tool nghiệp vụ (`GeminiToolSearchProduct`, `GeminiToolPrepareOrder`).
2. Bắt đăng nhập cho `api-ai-chat`, lưu history ở server.
3. Tool điều hướng (`navigate` + trường `actions`), cần thống nhất contract với FE.

## Ghi công bổ sung (ngày 2026-11-15, phần chat AI)

Người thực hiện: Long. Làm việc cùng Claude Code. Phần này làm thêm ngoài mission ban đầu. Tài liệu chi tiết: [docs/chat-ai/README.md](../../../docs/chat-ai/README.md).

### Backend: chat AI "Louis Dé Fule"
- Migration `20261115_01_create_ai_chat_tables.sql`: hai bảng `ai_conversations` (chủ sở hữu là user đã đăng nhập) và `ai_messages` (lưu nguyên văn `parts` của Gemini, có `UNIQUE (conversation_id, seq)`).
- Model mới `AiConversation`, `AiMessage` (đọc lịch sử, lưu lượt trong transaction, dùng `utf8mb4` cho emoji).
- `ChatController`: yêu cầu đăng nhập, kiểm tra quyền sở hữu hội thoại (404 nếu không thuộc user), tạo hội thoại sau khi Gemini trả lời thành công, trả `conversation_id`, `reply`, `tool_calls`, `actions`, `suggestions`. Bỏ `history` ở request và response.
- Tool `search_product` (`GeminiToolSearchProduct`) và `Product::searchByKeyword`: tìm tối đa 5 sản phẩm, trả giá và tình trạng hàng, tự chọn điều hướng: 1 kết quả thì trang chi tiết, nhiều thì danh sách, 0 thì danh sách trống.
- Tool `fule_sing` (`GeminiToolFuleSing`): hát ngẫu nhiên hoặc theo tên bài trong playlist, trả điều hướng tới `/fule-sing`.
- `Gemini.php`: tách lệnh điều hướng (`_action`) khỏi kết quả tool, tách dòng gợi ý `[[GOI_Y: ...]]` khỏi câu trả lời, giới hạn 3 gợi ý.
- `GeminiPrompt.php`: danh xưng "Louis Dé Fule", quy tắc gọi tool bắt buộc, cách trả lời khi có kết quả, cấm nói đã thêm giỏ hàng hay đặt hàng.

### Frontend: popup chat và trang sân khấu
- `ChatAssistant.vue`: popup nhúng ở `App.vue`, launcher tròn có nhãn "Chat with Fule", panel 340px ngay dưới thanh điều hướng, chip gợi ý, trạng thái chưa đăng nhập, thử lại khi lỗi, mở và đóng bằng nút thu nhỏ hoặc Esc.
- `chatAssistant.js`: state dùng chung ở cấp module, gọi API, lọc lệnh điều hướng theo danh sách route cho phép.
- `FuleSing.vue` (`/fule-sing`): đĩa nhạc xoay có ảnh avatar, khung video, bố cục desktop và mobile, nút "Nghe Louis hát" vì trình duyệt chặn tự phát.
- `videoUrl.js`, `resolveSystemImageUrl` trong `imageUrl.js`: dựng URL ảnh và video từ BE, kiểm tra tên file.

### Kiểm tra đã làm
- `php -l` cho các file PHP đã sửa: OK.
- `npm run build` (FE): OK sau mỗi đợt sửa.
- Lưu và đọc lịch sử trên DB local, có emoji; lỗi giữa chừng không để lại hội thoại rỗng.
- Tool và tách gợi ý với Gemini giả lập; gọi Gemini thật cho luồng hát: model gọi đúng `fule_sing` sau khi sửa prompt.
- Chưa test: endpoint qua HTTP với JWT thật, giao diện trên trình duyệt đầy đủ, chế độ mobile.

### Còn lại
1. API lấy lại lịch sử hội thoại khi tải lại trang.
2. Rate limit cho `api-ai-chat` (gói Gemini miễn phí giới hạn 5 request mỗi phút).
3. Tool giỏ hàng và đặt hàng (hiện chỉ hướng dẫn khách bấm nút trên trang).
4. Kiểm tra dữ liệu giá sản phẩm id 5 (có phiên bản giá 300đ).

## Ghi công bổ sung (ngày 2026-11-16, nhánh `feature/user-profile-fields`)

Người thực hiện: Long (admin). Làm việc cùng Claude Code. Chưa commit, chưa tạo PR.

### Vai trò admin
- Lên ý tưởng và yêu cầu tính năng: hồ sơ người dùng (họ tên, SĐT, địa chỉ), điền sẵn checkout từ hồ sơ, lời chào trợ lý có tên, icon giỏ hàng và đơn hàng trên menu desktop, quy tắc SĐT di động Việt Nam, xoay vòng nhiều Gemini API key.
- Chia đầu việc BE/FE cho nhóm và quản lý git (tạo nhánh `feature/user-profile-fields` từ `dev`, chia PR gợi ý).
- Kiểm thử: chạy kiểm tra API và luồng trình duyệt trong phiên, đối chiếu kết quả với yêu cầu.

### Backend (phần chính)
- Migration `20261116_add_user_profile_fields.sql`: thêm `name`, `phone`, `address` (NULL), chạy lặp được. Cập nhật `database-schema.md`.
- Seed `users_profiles.sql`: dữ liệu hồ sơ mẫu, đặt lại mật khẩu mẫu.
- `User.php`: `findProfileById`, `updateProfile`. `ProfileController.php` (mới): `GET api-profile`, `PUT api-profile-update`. Route thêm vào `routes/index.php`.
- `extensions/VietnamPhone.php`: quy tắc SĐT di động Việt Nam dùng chung cho đặt hàng và hồ sơ, lưu dạng `0xxxxxxxxx`.
- Xoay vòng nhiều Gemini API key trong `Gemini.php` và `config.php`: luân phiên, tự chuyển key khi 429/401/403, log không ghi key. Cập nhật `.env.example`.
- Tài liệu: `README.md`, `docs/huong-dan-gemini-chat-tools.md`, `docs/chat-ai/` (01, 04, 07, 08, 09).

### Đã kiểm tra (BE)
- `php -l` cho các file PHP đã sửa: OK.
- API thật với tài khoản test: `api-profile` 200/401/405; `api-profile-update` lưu hợp lệ, xóa trường bằng null, email/role gửi kèm không đổi, SĐT sai 422, sai method 405, thiếu token 401.
- Quy tắc SĐT: 33 trường hợp chạy giống nhau ở PHP và JS, không lệch.
- Xoay vòng Gemini: 11 kiểm tra với client giả lập, đạt. Gọi Gemini thật thất bại vì key hiện có đã hết hạn mức miễn phí.

### Còn lại
1. Commit và tạo PR theo 3 nhóm (hồ sơ người dùng; xoay vòng key Gemini; icon menu).
2. Thêm key Gemini thuộc project khác, rồi kiểm tra lại chat thật.
3. Viết test tự động cho BE (hiện chưa có).

## Ghi công bổ sung (ngày 2026-11-17)

Người thực hiện: Long (BE). Làm việc cùng Claude Code. Phần này làm thêm ngoài mission, chưa commit, chưa tạo PR. Phân công phần sơ đồ và FE xem `vinh.md`.

### Tool lên đơn nháp và API xác nhận đơn
- `BE/geminiServices/GeminiToolCreateOrderDraft.php` (mới): tool `create_order_draft`. Chỉ lo món hàng: kiểm tra SKU đang bán, tồn kho, số lượng 1-99, gộp SKU trùng. Trả `_action` loại `checkout-draft` (chỉ món và tổng tiền). Không đọc hồ sơ, không nhận thông tin cá nhân.
- `BE/geminiServices/GeminiPrompt.php`: quy tắc dạy trợ lý khi nào lên đơn, đổi món, bỏ món, không hỏi thông tin cá nhân trong chat; có đoạn `checkoutDraftContext()` gắn đơn nháp đang mở vào system prompt của từng lượt.
- `BE/controllers/ChatController.php`: đăng ký tool; đọc `checkout_draft` (chỉ `items`), tra SKU trong DB rồi đưa vào prompt; `history()` cho `GET api-ai-history`.
- `BE/controllers/OrderController.php` và `BE/models/Order.php`: `api-order-checkout-items` (`checkoutItems`). Khóa dòng `FOR UPDATE`, tính giá từ DB, đối chiếu `expected_total` (lệch thì 409, không tạo đơn), ghi đơn qua `insertOrderRows()` dùng chung với checkout từ giỏ.
- `BE/models/Product.php`: `getActiveVariantsBySkus()`.
- `BE/models/AiConversation.php`, `BE/models/AiMessage.php`, `BE/geminiServices/Gemini.php`: `findLatestIdByUser`, `getRecentWithTime`, `toDisplayMessages` (ẩn lượt gọi tool, tách gợi ý, bỏ tham số tool lên đơn khỏi lịch sử hiển thị).
- `BE/routes/index.php`: đăng ký `api-order-checkout-items` và `api-ai-history`, cả hai yêu cầu đăng nhập.

### Đã kiểm tra (BE)
- `php -l` cho các file PHP đã sửa: OK.
- Chạy tool và model trực tiếp trên DB local bằng script: đơn nháp đúng giá, có giá gốc khi giảm; SKU sai và vượt tồn kho báo đúng; tham số sai kiểu bị từ chối; giá đổi trả `price_changed`; tồn kho và số đơn không đổi sau các nhánh rollback.
- Gọi `api-order-checkout-items` không token: 401.
- Lọc lịch sử trên DB: không sót dòng gợi ý, ẩn tham số tool lên đơn.

### Chưa làm
- Test đặt đơn thật (COD và SePay) qua API với JWT hợp lệ.
- Test chat thật với Gemini cho các kịch bản đổi số lượng và thiếu hồ sơ.
- Test tự động cho BE (hiện chưa có).

## Ghi công bổ sung (ngày 2026-11-22)

Người thực hiện: Long (BE). Làm việc cùng Claude Code, nhánh `chore/dev-sync-20261122`. Chưa commit, chưa tạo PR. Phần tích hợp kiểm duyệt vào chức năng bình luận (BE + FE) và sửa layout trang chi tiết do Vinh, xem `vinh.md`.

### Tìm hiểu 2 model AI kiểm duyệt bình luận và dựng dịch vụ trên Google Colab
- Tìm hiểu và chọn 2 model tiếng Việt, chạy nối tiếp (model 2 chỉ chạy khi model 1 cho qua):

  | # | Model | Nhiệm vụ | Nhãn |
  | --- | --- | --- | --- |
  | 1 | `visolex/visobert-hsd` | Kiểm duyệt: phát hiện ngôn từ xúc phạm hoặc thù ghét (HSD = Hate Speech Detection) | `CLEAN` / `OFFENSIVE` / `HATE` |
  | 2 | `5CD-AI/Vietnamese-Sentiment-visobert` | Gắn nhãn cảm xúc của bình luận | `POS` / `NEG` / `NEU` |

- Dựng server FastAPI trên Google Colab, mở ra ngoài bằng tunnel `trycloudflare` để lấy endpoint: `GET /` (kiểm tra sống, ngưỡng độc hại 0.7), `POST /analyze` (1 câu), `POST /analyze-batch` (nhiều câu).

### Cấu hình và service gọi Colab
- `BE/.env.example`: biến `COMMENT_GUARD_URL`, `COMMENT_GUARD_KEY`, `COMMENT_GUARD_TIMEOUT`.
- `BE/configs/config.php`: đọc 3 biến trên thành hằng; `PATH_COMMENT_GUARD_SERVICE`.
- `BE/index.php`: thêm thư mục `commentGuardServices/` vào autoload.
- `BE/commentGuardServices/CommentGuard.php` (mới, thư mục service riêng như `geminiServices/`): `analyze()` chấm 1 câu, `analyzeBatch()` chấm tối đa 50 câu, gọi Colab bằng cURL, kiểm tra và chuẩn hóa kết quả trả về.

### API test `api-comment-guard` (giữ lại, không xóa)
- `BE/controllers/CommentGuardController.php` (mới) và case `api-comment-guard` trong `BE/routes/index.php`: `POST`, yêu cầu đăng nhập, body `{ "text": "..." }`, trả nguyên kết quả AI (nhãn, `toxic_prob`, `needs_review`, cảm xúc). Dùng để thử model, không phải API cho FE.

### Migration thêm cột nhãn AI vào bảng `comments`
- `BE/database/migrations/20261122_01_add_comments_ai_labels.sql` (mới): thêm `moderation_label VARCHAR(20)`, `sentiment VARCHAR(10)`, `toxic_prob DECIMAL(5,4)`, đều cho phép NULL (NULL = chưa chấm). Viết để chạy lặp được.
- Đã chạy trên DB local bằng `php BE/database/run.php`, chạy lại lần 2 không lỗi.

### Đã kiểm tra
- `php -l` cho các file PHP đã sửa: OK.
- Thử model qua service: 26 câu (khen, trung tính, chê lịch sự, chê gay gắt, chửi tục, viết tắt, không dấu, chèn ký tự, mỉa mai, tiếng lóng, tiếng Anh, spam, emoji). Model 1 chặn đúng 7/7 câu chửi tục tiếng Việt; có 1 câu chặn nhầm ("Hàng như rác, phí tiền"), chửi tiếng Anh "fuck this shop" chỉ bị gắn cờ chờ duyệt, không lọc spam. Model 2 gắn sai 2/18 câu (câu trung tính ngắn, câu mỉa mai).
- Gọi API test qua Postman với token user: "Như cặc" ra `HIDDEN` / `OFFENSIVE` / `toxic_prob` 0.9973.
- API test không token / token sai: 401. Service khi chưa cấu hình URL: báo lỗi đúng.
- Tài liệu: `docs/comment-guard/README.md` (2 model, endpoint Colab, cấu hình, API test, bảng 26 câu thử nguyên văn, nhận xét chất lượng model).

### Lưu ý
- URL `trycloudflare` đổi mỗi lần chạy lại Colab, phải sửa `COMMENT_GUARD_URL` trong `BE/.env`.
- Colab hiện chưa kiểm tra header `x-api-key`. `BE/.env.example` chỉ ghi URL mẫu, URL thật để trong `BE/.env`.

## Ghi công bổ sung (ngày 2026-11-23)

Người thực hiện: Long (plan + BE + database). Làm việc cùng Claude Code, nhánh `feature/fule-product-filter`. Phần FE và kiểm thử trên trình duyệt do Văn Hoàng, xem `vanhoang.md`.

### Lên kế hoạch
- Kế hoạch dựng lại bộ lọc catalog và cấp dữ liệu cho trợ lý Fule, ghi trong `docs/trang-thai-du-an.md`: tìm kiếm + danh mục + khoảng giá + độ giữ nếp (1-3) + độ bóng (0-3) + tag + sắp xếp mới nhất / cũ nhất; tool `search_product` của Fule dùng chung đúng bộ lọc đó. Luồng làm: BE -> FE -> test API -> test trên Chrome qua ngrok.

### Database
- `BE/database/migrations/20261123_01_variant_levels_and_product_metadata.sql` (mới):
  - `product_variants`: thêm `hold_level` (CHECK 1-3) và `shine_level` (CHECK 0-3); chuyển giá trị chữ cũ ("rất mạnh", "bóng lộn", "không bóng, mờ tự nhiên"...) sang số rồi bỏ 2 khóa `do_giu_nep`, `do_bong` khỏi JSON `attributes`.
  - `products`: thêm cột `metadata` (gom thuộc tính các biến thể đang bán, dùng cho tìm kiếm và lọc tag).
  - Tạo lại view `v_product_catalog` có `metadata`.
- Cập nhật 3 file seed (`catalog_product_variants.sql`, `pomade_replace_hair_care.sql`, `products_variant_attributes.sql`) theo cấu trúc mới, dùng chung một câu SQL làm mới metadata.
- Đã chạy trên DB local; sao lưu thuộc tính cũ trước khi chạy.

### BE
- `BE/extensions/CatalogFilter.php` (mới): kiểm tra tham số lọc dùng chung cho `api-products` và tool của Fule (sai thì báo lỗi rõ ràng), danh sách kiểu sắp xếp, giới hạn đầu vào, câu mô tả bộ lọc.
- `BE/extensions/VariantAttributes.php`: thang giữ nếp / độ bóng và nhãn, thứ tự hiển thị thuộc tính.
- `BE/models/Product.php`: `getFiltered()` lọc theo mọi điều kiện; ô tìm kiếm khớp tên, mã, mô tả, metadata, SKU và tên phiên bản đang bán; còn hàng xếp trước; làm mới metadata khi admin sửa biến thể.
- `BE/controllers/ProductController.php` + `BE/routes/index.php`: `api-products` dùng bộ lọc mới; API mới `api-product-filters` (thang mức, khoảng giá, tag gợi ý, kiểu sắp xếp); admin tạo / sửa biến thể có giữ nếp, độ bóng.
- `BE/models/Cart.php`: giỏ hàng hiện thuộc tính theo thứ tự mới.
- Trợ lý Fule:
  - `GeminiToolSearchProduct.php`: thêm tham số lọc giống catalog, trả `total_found`, `applied_filters`; mở catalog kèm đúng bộ lọc.
  - `GeminiToolSummary.php` (mới): câu mô tả cho chip "trợ lý đã làm gì" của mọi tool.
  - `GeminiPrompt.php` + mô tả tham số `keyword`: ép AI chỉ lấy thực thể cốt lõi đã sửa chính tả ("…được dùng bởi Sơn Tùng MTP" -> `Sơn Tùng MTP`, "sáp KervinMurphy" -> `Kevin Murphy`), không ra kết quả thì tự thử lại.
- Tài liệu: `README.md`, `docs/chat-ai/02, 04, 05, 06, 09`, `docs/trang-thai-du-an.md`.

### Đã kiểm tra
- `php -l` cho các file PHP đã sửa: OK.
- Test API bộ lọc: 76/76 PASS (lọc 27, phạm vi tìm kiếm 11, sửa sau review 11, hồi quy chi tiết / giỏ hàng 9, admin biến thể 18).
- Metadata tạo bằng migration và bằng code giống nhau ở 11/11 sản phẩm.
- Chat thật với Gemini: lọc theo nhu cầu đúng; 4/4 câu chọn keyword đúng ngay lần gọi đầu.
