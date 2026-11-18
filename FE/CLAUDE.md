# FE/CLAUDE.md

Quy tắc khi làm việc trong thư mục `FE/`.

Dự án môn học: Vue 3 + Vite + Vue Router + Axios + Bootstrap 5. Ưu tiên đơn giản, đúng pattern hiện có.

## 1. Phạm vi và nguyên tắc
- Chỉ sửa `FE/` khi task thuộc FE. Không sửa `BE/`, contract API, schema DB hay cấu hình deploy để giao diện chạy được.
- Trước khi sửa: chạy `git status`, đọc đúng component/service/router liên quan. Không ghi đè, revert hay format hàng loạt thay đổi chưa commit của người khác.
- Mỗi file chỉ một owner tại một thời điểm. Vùng dùng chung (cần phân công trước): `App.vue`, `router/index.js`, `helpers/*`, `style.css`.
- Sửa tối thiểu. Không đổi framework, không thêm dependency (`npm install` gói mới), không tạo service HTTP mới, không refactor diện rộng nếu task không yêu cầu.

## 2. File KHÔNG ĐƯỢC SỬA
- `FE/src/helpers/api/client.js`: giữ nguyên hoàn toàn nội dung (base URL, interceptor, xử lý 401). Chỉ import để dùng, không chỉnh, không "dọn", không refactor, kể cả khi thấy hardcode. Cần đổi thì phải có yêu cầu trực tiếp từ chủ dự án.
- Không bắt chước cách `client.js` xác định base URL ở nơi khác. Mọi nơi khác chỉ gọi `api.get/post/...`.
- `services/auth.js` (cách lưu token trong `localStorage`, key `auth_token`/`auth_user`): không đổi nếu task không yêu cầu trực tiếp.
- **2 trang đặt hàng: `FE/src/views/payment/CheckoutView.vue` (đặt hàng tay) và `FE/src/views/ai/AiCheckout.vue` (đặt hàng qua trợ lý AI). TUYỆT ĐỐI KHÔNG SỬA.** (Quyết định của admin, 18/11/2026.)
  - Không sửa vì bất kỳ lý do nào: không áp dụng `StateBlock` (mục 13), không đổi giao diện, không "dọn" hay refactor, không thêm tính năng, kể cả khi thấy code có thể viết gọn hơn.
  - Lý do: hai trang xử lý các trạng thái nghiệp vụ do BE trả về khi đặt hàng (409 hết hàng/ngừng bán, giá đổi qua `expected_total`, thiếu kho, kẹp số lượng đơn nháp, SePay lỗi, tải lại giỏ mà vẫn giữ form khách đang điền). Bản hiện tại đã được admin kiểm thử trên trình duyệt và chạy đúng; sửa lại dễ làm hỏng các luồng này.
  - Chỉ sửa khi **chính admin yêu cầu trực tiếp** cho hai file này. Khi đó phải kiểm thử lại trên trình duyệt trọn luồng của CẢ HAI trang (đặt thành công, COD, SePay lỗi, 409 giá đổi, thiếu kho, ngừng bán, đơn nháp AI tra lại kho) và ghi kết quả thật.
  - Helper mà hai trang đang dùng (`helpers/ai/checkoutDraft.js`, `helpers/ai/chatAssistant.js`, `helpers/orders/orderActions.js`...) là vùng dùng chung: không đổi hành vi nếu không có task riêng được admin duyệt, và đổi thì cũng phải kiểm thử lại cả hai trang như trên.

## 3. Cấu trúc và pattern
- `src/router/index.js` (route), `src/helpers/` (module dùng chung, chia theo nhóm `api/`, `auth/`, `ui/`, `format/`, `media/`, `debug/`), `src/views/` (trang), `src/components/` (tái sử dụng).
- Dùng Composition API với `<script setup>`, `ref`/`computed`. Không dùng `this`, không dùng Options API. Ngoại lệ: trang tĩnh không có logic (như `NotFound.vue`).
- Bootstrap 5 đã import trong `main.js`: dùng class có sẵn, không import lại, không thêm UI library. CSS riêng dùng `<style scoped>`; `style.css` chỉ cho style toàn cục thật sự.
- Thông báo cho người dùng dùng `services/toast.js`, theo cách các view auth đang dùng.
- Bất kỳ giá tiền nào hiển thị trong UI/UX đều phải đi qua hàm `formatPrice` có sẵn trong `src/helpers/` (theo cấu trúc thì thuộc nhóm `format/`). Tìm đúng file trước khi import, không tạo hàm định dạng giá mới, không tự định dạng hoặc nối đơn vị tiền ở template.
- Tái sử dụng component/service sẵn có. Không nhồi logic màn hình vào `App.vue`.

## 4. Tích hợp API
- Mọi request đi qua `import api from '@/helpers/api/client'`. Không dùng `fetch`, không `axios.create`, không hardcode host/URL.
- Gọi theo query action của router PHP, ví dụ `api.get('/?action=api-products', { params })`, `api.post('/?action=api-login', body)`. Không đoán action, method, field, kiểu dữ liệu, phân trang hay yêu cầu đăng nhập.
- Có thể đọc code trong `BE/` (route, controller) để đối chiếu contract, nhưng không sửa.
- Endpoint mới/thay đổi: xác nhận contract với người phụ trách BE trước (action, method, query/body, auth, response thành công, lỗi, mã HTTP). Chưa xác nhận thì không tự dựng contract giả: làm phần UI cô lập hoặc hỏi người dùng đúng thông tin còn thiếu.
- Đọc đúng response thực tế. Shape hiện tại:
  - Thành công: `res.data.status === 'success'` kèm key theo tài nguyên (ví dụ `res.data.products.data` + `res.data.products.pagination`).
  - Lỗi: `err.response?.data?.message` (đã có trong các view auth).

  Không đoán key; chỉ fallback giữa các key khi BE đã quy định như vậy.
- Không nuốt lỗi bằng mảng rỗng khiến lỗi server bị hiểu thành "không có dữ liệu". Tách rõ trạng thái loading / empty / error trên màn hình.
- Request bất đồng bộ trong component: dùng `try/catch/finally`, tắt loading trong `finally`; tránh cập nhật state khi component đã unmount nếu có nguy cơ race (ví dụ tìm kiếm gõ liên tục).
- Endpoint cần đăng nhập dựa vào cơ chế sẵn có (`auth.js`, route guard `meta.requiresAuth`, interceptor gắn Bearer). Không tự gửi user ID/role từ client để thay danh tính đã xác thực. Route guard chỉ là UX, quyền thật do BE kiểm soát.

## 5. Router và component
- Thêm trang: tạo view trong `src/views/` (nhóm auth để trong `views/auth/`), đăng ký route trong `router/index.js` (đặt trước route `/:pathMatch(.*)*`), điều hướng bằng `router-link` hoặc `router`.
- Trang cần đăng nhập: thêm `meta: { requiresAuth: true }`.
- Router là file dùng chung: phối hợp owner trước khi sửa.
- `v-for` luôn có `:key` ổn định (ví dụ `id`), kiểm tra dữ liệu trước khi render, xử lý form/loading/error trong phạm vi feature.
- Form: validate phía FE để trải nghiệm tốt (định dạng email, độ dài mật khẩu 8-72 khớp với BE), nhưng không coi đó là bảo mật.

## 6. Bao phủ đầy đủ luồng giao diện
- Với mỗi thao tác/API được triển khai, xử lý các trạng thái phù hợp: loading, thành công, lỗi, rỗng và thử lại khi có thể. Không để lỗi bị hiểu nhầm thành dữ liệu rỗng hoặc báo thành công giả.
- Trạng thái đang tải / lỗi / rỗng khi **tải dữ liệu để hiển thị** phải dùng component chung `StateBlock` (mục 13), không tự viết khối riêng. Ngoại lệ: 2 trang đặt hàng (mục 2), không được sửa.
- Mọi thao tác gửi form/thay đổi dữ liệu cần có thông báo thành công/lỗi bằng `services/toast.js`; khóa nút trong lúc request để tránh gửi lặp.
- Form phải validate ở FE và hiện lỗi thân thiện ngay dưới từng trường liên quan; xóa/cập nhật lỗi trường khi người dùng sửa dữ liệu.
- Lỗi validation trả về từ BE hiển thị bằng thông báo chung lấy từ `err.response?.data?.message`, không cần ánh xạ thành lỗi từng trường. Không coi validation FE là thay thế cho kiểm tra của BE.
- Tham khảo pattern validate, loading và thông báo của các trang auth hiện có; không dùng `window.alert` nếu luồng thông báo chung của ứng dụng đã có toast.

## 7. Điều kiện database cho task FE tích hợp API
- Nếu task FE phụ thuộc dữ liệu database local/dev, trước khi code hoặc kiểm thử tích hợp phải chạy file SQL đầy đủ được admin cung cấp và xác nhận database sẵn sàng.
- Nếu không có file SQL đầy đủ, thiếu schema/dữ liệu cần thiết hoặc chạy thất bại: dừng phần tích hợp và báo người dùng để xin file đầy đủ từ admin; không tự tạo/chỉnh SQL hay sửa BE để khắc phục.
- Chỉ chạy SQL trên database local/dev được chỉ định; không chạy script có nguy cơ xóa dữ liệu trên môi trường dùng chung/production.
- FE chỉ được đọc/đối chiếu BE contract. Không sửa `BE/`, schema, migration hoặc endpoint nếu task chỉ giao phạm vi FE; khi thấy lỗi BE, báo owner kèm request/response thực tế.

## 8. Phong cách code và comment cho người mới
Nhóm có nhiều thành viên mới học, code do AI sinh ra phải đọc hiểu được mà không cần hỏi lại. Vì vậy bắt buộc comment tiếng Việt theo các quy tắc sau.

### Bắt buộc comment
- **Đầu `<script setup>` của mỗi view/component mới**: 1-3 dòng nói trang/component này làm gì, gọi API nào (nếu có).
- **Mỗi hàm mới** (xử lý submit, gọi API, validate, event handler): comment 1-2 dòng ngay trên hàm, nói hàm làm gì và khi nào được gọi.
- **Mỗi `ref`, `computed`, `watch`** có ý nghĩa không hiển nhiên: ghi biến này lưu gì, vì sao dùng computed/watch.
- **Mỗi lần gọi API**: ghi endpoint dùng để làm gì, response trả về dạng nào, xử lý lỗi ra sao.
- **Route mới trong `router/index.js`**: ghi trang này dùng để làm gì, có cần đăng nhập không (`requiresAuth`).
- **Chỗ khó hiểu với người mới**, giải thích VÌ SAO làm vậy:
  - `ref`/`.value`, `computed`, `watch`, `onMounted`, `v-model`, `v-for` + `:key`.
  - `async/await`, `try/catch/finally` và vì sao tắt loading trong `finally`.
  - Route guard, `meta.requiresAuth`, Bearer token trong interceptor (đọc hiểu, không sửa `client.js`).
  - Chỗ xử lý trạng thái loading / rỗng / lỗi, xử lý race khi gõ tìm kiếm liên tục.
- **Trong template** `<template>`: comment HTML `<!-- ... -->` cho từng khối lớn (form, danh sách, phân trang, thông báo lỗi) để biết khối đó làm gì.
- **Giải thích thuật ngữ** lần đầu xuất hiện trong file bằng 1 câu dễ hiểu. Ví dụ: `// ref: biến "phản ứng", khi giá trị đổi thì giao diện tự cập nhật`.

### Cách viết comment
- Viết tiếng Việt có dấu, câu ngắn, dùng từ đơn giản, không dùng tiếng lóng. Giữ nguyên tên kỹ thuật bằng tiếng Anh (`ref`, `computed`, `axios`) kèm giải thích ngắn.
- Giải thích **ý định và lý do** hơn là dịch lại từng dòng code.
- Không comment dòng hiển nhiên (`loading.value = true`). Comment thừa làm code khó đọc hơn.
- Khi sửa code, cập nhật comment tương ứng. Comment sai còn tệ hơn không có comment.
- Không xóa, không dịch sang tiếng Anh, không viết lại comment tiếng Việt đang có nếu task không yêu cầu.
- Không đưa token, secret hay URL môi trường thật vào comment.
- Không dùng ngôn từ xúc phạm hay meme trong code, giao diện, dữ liệu mẫu.
- Tên component/file view theo pattern hiện có (`PascalCase.vue`); `catalog.vue` đang viết thường, không đổi tên nếu không có task.

### Ví dụ chuẩn
```vue
<script setup>
// Trang danh sách sản phẩm: gọi API api-products, có tìm kiếm và phân trang.
import { ref, onMounted } from 'vue'
import api from '@/helpers/api/client'

// Danh sách sản phẩm đang hiển thị. ref: khi giá trị đổi, giao diện tự cập nhật.
const products = ref([])
// true khi đang chờ server trả dữ liệu, dùng để hiện chữ "Đang tải...".
const loading = ref(false)
// Thông báo lỗi để hiển thị cho người dùng, rỗng = không có lỗi.
const errorMessage = ref('')

// Gọi API lấy sản phẩm theo trang. Được gọi khi trang mở và khi đổi trang.
async function fetchProducts(page = 1) {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get('/?action=api-products', { params: { page } })
    products.value = res.data.products.data
  } catch (err) {
    // Hiện lỗi thật thay vì để danh sách rỗng, tránh nhầm với "chưa có sản phẩm".
    errorMessage.value = err.response?.data?.message || 'Không tải được sản phẩm'
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên tắt loading ở đây.
    loading.value = false
  }
}

// onMounted: chạy 1 lần khi trang vừa hiện ra.
onMounted(() => fetchProducts())
</script>
```

## 9. Hiện trạng đã biết (KHÔNG tự sửa khi không có task riêng)
- `client.js` hardcode base URL theo localhost/port dev (xem mục 2: không được sửa).
- Token lưu trong `localStorage`.
- `TestView.vue` là trang thử nghiệm.
- Chưa có test tự động.

Phát hiện thêm vấn đề tương tự: báo lại cho người dùng trong phần tổng kết, không tự sửa ngầm.

## 10. Phối hợp để tránh xung đột
1. Trước khi làm: nêu file/phạm vi sẽ sửa. Nếu chạm vào vùng dùng chung (mục 1), hỏi người dùng xác nhận không ai đang sửa cùng vùng.
2. Task FE-BE: thống nhất contract trước, chia owner theo file, không để hai người sửa cùng một file.
3. Yêu cầu mới xung đột code/contract hiện có: dừng đúng phần xung đột, mô tả bằng chứng (request/response mong đợi vs thực tế), hỏi người dùng để thống nhất quyết định.
4. Khi xong: báo file đã sửa, contract đã dùng, việc BE/FE cần làm tiếp, lệnh kiểm tra đã chạy. Không tuyên bố pass nếu chưa chạy.

## 11. Kiểm tra trước khi bàn giao
- Chạy `npm run build` trong `FE/`. Dự án chưa có test script: không bịa lệnh test.
- Chạy thử màn hình bằng `npm run dev` với các trường hợp: có dữ liệu, rỗng, lỗi API, chưa đăng nhập (với route bảo vệ). Nếu không tự chạy/xem được giao diện, ghi rõ trong phần tổng kết để người dùng tự kiểm tra.
- Xem diff (`git diff`): không có file ngoài phạm vi, không sửa `client.js`, không có URL cứng mới, không đổi contract ngoài ý muốn.
- Mọi hàm, API call, route, `ref/computed/watch` mới đều có comment tiếng Việt theo mục 8; chỗ khó hiểu có giải thích lý do.

## 12. Thiết kế UI/UX theo Stitch (tham khảo)
- Stitch project "Fule BarberShop Online Store" (id `8760040047758233303`) là nguồn tham khảo màu, bố cục, khoảng cách, font cho mọi task UI/UX FE. Giao diện hiện tại đã theo thiết kế này, nên task mới phải giữ đúng bảng màu và font dưới đây.
- Bảng màu: navy `#131e2a` / `#1c2a3a` / `#25364a` (header, footer, chữ nhấn); kem `#f7f4ee` (nền trang), `#efeae0` (nền phụ); trắng `#ffffff` (thẻ); viền `#d9d4ca` / `#e6e0d4`; đồng `#b8893e` (nhấn, nút chính), đồng đậm `#9e7432` (hover); chữ phụ `#6b6560` / `#4a5565`. Lỗi `#ba1a1a` / `#93000a`, thành công `#2f7d4f`.
- Font: Playfair Display cho tiêu đề (`var(--font-display)`), Inter cho chữ thường. Cả hai có bộ ký tự tiếng Việt. Không dùng font không có tiếng Việt như Bebas Neue, DM Sans, DM Serif Display.
- Bo góc 6–8px; nút chính nền đồng chữ trắng, nút phụ viền navy. Giá tiền đi qua `formatPrice`.
- Không copy HTML/Tailwind từ Stitch; viết lại bằng Bootstrap 5 + `<style scoped>`.
- Phạm vi UI/UX: được sửa `<template>` (cấu trúc, class) và `<style>`. Giữ nguyên mọi binding (`v-model`, `v-if`, `v-for` + `:key`, `@event`, `:prop`, `router-link :to`, slot, emit) và logic trong `<script setup>` trừ khi task yêu cầu đổi logic.
- Sửa file dùng chung (`style.css`, `App.vue`, `router/index.js`) cần xác nhận của người phụ trách trước.
- Thiết kế cần logic/API mới (ví dụ bộ lọc giá, mã giảm giá, theo dõi hành trình): liệt kê để người dùng quyết định, không tự thêm.
- Giữ đủ trạng thái loading / rỗng / lỗi và nút thử lại. Chữ hiển thị giữ theo ngôn ngữ hiện có của trang.

## 13. Trạng thái tải dữ liệu dùng chung (`StateBlock`)
Quyết định của admin (18/11/2026): mọi trạng thái đang tải / lỗi / rỗng khi tải dữ liệu để hiển thị đều dùng một component chung, để giao diện đồng bộ và lỗi API không bao giờ bị hiện thành "rỗng".

- Component: `FE/src/components/StateBlock.vue`. Tài liệu chi tiết: `FE/docs/ke-hoach-state-component.md`.
  - Props: `loading`, `error` (chuỗi, rỗng = không lỗi), `empty`, `loadingText`, `emptyText`, `onRetry` (hàm; có thì hiện nút "Thử lại").
  - Thứ tự ưu tiên cố định: đang tải → lỗi → rỗng → nội dung (slot mặc định). Slot `empty-action` cho nút/link dưới thông báo rỗng (ví dụ "Xem sản phẩm").
  - Giao diện theo Stitch (mục 12), đã có sẵn trong component; trang không ghi đè style của nó.
- **Bắt buộc dùng cho:**
  - Mọi trang FE **mới**, và mọi khối mới trong trang có tải dữ liệu từ API.
  - Các trang FE **cũ** khi có task sửa phần tải hoặc hiển thị dữ liệu của trang đó: `HomeView.vue`, `products/catalog.vue`, `products/ProductDetail.vue`, `auth/Profile.vue`... Khi sửa thì thay khối loading/lỗi/rỗng tự viết bằng `StateBlock`; không tự đi sửa trang cũ khi task không liên quan.
  - Đang dùng: `orders/OrderList.vue`, `orders/OrderDetail.vue`, `cart/CartView.vue`. Đây là mẫu tham khảo.
- **Không dùng cho:**
  - **2 trang đặt hàng** `payment/CheckoutView.vue` và `ai/AiCheckout.vue`: tuyệt đối không sửa (mục 2).
  - Trạng thái của form: nút gửi đang xử lý, lỗi từng ô nhập (các trang đăng nhập, đăng ký, đổi mật khẩu...). Giữ cách làm ở mục 6.
- **Cách dùng đúng:**
  - Truyền `:on-retry` bằng hàm tải lại; cần tham số thì bọc lại: `:on-retry="() => loadOrders(pagination.current_page)"`.
  - Lỗi không thể thử lại (ví dụ 404 không tìm thấy) thì không truyền `onRetry` (xem `OrderDetail.vue`).
  - Giữ nguyên chữ thông báo tiếng Việt của trang qua `loading-text`, `empty-text`.
  - Có comment HTML trên khối `StateBlock` nói trang đang xử lý những trạng thái nào (mục 8).
  - StateBlock ẩn toàn bộ nội dung khi đang tải hoặc lỗi. **Không bọc form khách đang điền** trong StateBlock nếu dữ liệu có thể tải lại trong lúc khách đang điền (form sẽ biến mất rồi hiện lại).
- Cần kiểu hiển thị mà `StateBlock` chưa có (ví dụ khung xương dạng lưới ở catalog, khối nhỏ trong trang như bình luận): hỏi admin trước. Không tự viết khối riêng, không tự sửa `StateBlock.vue`.
- `StateBlock.vue` là vùng dùng chung (mục 1): sửa phải có admin xác nhận, và kiểm thử lại các trang đang dùng nó.
