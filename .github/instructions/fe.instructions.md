---
applyTo: "FE/**"
---

# FE Instructions

Dự án môn học: Vue 3 + Vite + Vue Router + Axios + Bootstrap 5. Ưu tiên đơn giản, đúng pattern hiện có.

## 1. Phạm vi và nguyên tắc
- Chỉ sửa `FE/` khi task thuộc FE. Không sửa `BE/`, contract API, schema DB hay cấu hình deploy để giao diện chạy được.
- Trước khi sửa: chạy `git status`, đọc đúng component/service/router liên quan. Không ghi đè, revert hay format hàng loạt thay đổi chưa commit của người khác.
- Mỗi file chỉ một owner tại một thời điểm. Vùng dùng chung (cần phân công trước): `App.vue`, `router/index.js`, `services/*`, `style.css`.
- Sửa tối thiểu. Không đổi framework, không thêm dependency, không tạo service HTTP mới, không refactor diện rộng nếu task không yêu cầu.

## 2. File KHÔNG ĐƯỢC SỬA
- `FE/src/services/api.js`: giữ nguyên hoàn toàn (base URL, interceptor, xử lý 401). Chỉ import để dùng, không chỉnh, không "dọn", không refactor, kể cả khi thấy hardcode. Cần đổi thì phải có yêu cầu trực tiếp từ chủ dự án.
- Không bắt chước cách `api.js` xác định base URL ở nơi khác. Mọi nơi khác chỉ gọi `api.get/post/...`.
- `services/auth.js` (cách lưu token trong `localStorage`, key `auth_token`/`auth_user`): không đổi nếu task không yêu cầu trực tiếp.

## 3. Cấu trúc và pattern
- `src/router/index.js` (route), `src/services/` (api, auth, toast), `src/views/` (trang), `src/views/auth/` (nhóm auth), `src/components/` (tái sử dụng), `src/helpers/` (hàm tiện ích như `formatPrice`).
- Dùng Composition API với `<script setup>`, `ref`/`computed`. Không dùng `this`, không dùng Options API. Ngoại lệ: trang tĩnh không có logic (như `NotFound.vue`).
- Bootstrap 5 đã import trong `main.js`: dùng class có sẵn, không import lại, không thêm UI library. CSS riêng dùng `<style scoped>`; `style.css` chỉ cho style toàn cục thật sự.
- Thông báo cho người dùng dùng `services/toast.js`, theo cách các view auth đang dùng.
- Bất kỳ giá tiền nào hiển thị trong UI/UX đều phải đi qua `helpers/formatPrice.js`; không tự định dạng hoặc nối đơn vị tiền ở template.
- Tái sử dụng component/service sẵn có. Không nhồi logic màn hình vào `App.vue`.

## 4. Tích hợp API
- Mọi request đi qua `import api from '@/services/api'`. Không dùng `fetch`, không `axios.create`, không hardcode host/URL.
- Gọi theo query action của router PHP, ví dụ `api.get('/?action=api-products', { params })`, `api.post('/?action=api-login', body)`. Không đoán action, method, field, kiểu dữ liệu, phân trang hay yêu cầu đăng nhập.
- Endpoint mới/thay đổi: xác nhận contract với người phụ trách BE trước (action, method, query/body, auth, response thành công, lỗi, mã HTTP). Chưa xác nhận thì không tự dựng contract giả: làm phần UI cô lập hoặc hỏi đúng thông tin còn thiếu.
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

## 6. Phong cách code và comment cho người mới
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
  - Route guard, `meta.requiresAuth`, Bearer token trong interceptor (đọc hiểu, không sửa `api.js`).
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
import api from '@/services/api'

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

## 7. Hiện trạng đã biết (KHÔNG tự sửa khi không có task riêng)
- `api.js` hardcode base URL theo localhost/port dev (xem mục 2: không được sửa).
- Token lưu trong `localStorage`.
- `TestView.vue` là trang thử nghiệm.
- Chưa có test tự động.

Phát hiện thêm vấn đề tương tự: báo lại, không tự sửa ngầm.

## 8. Phối hợp để tránh xung đột
1. Trước khi làm: nêu file/phạm vi mình sở hữu, xác nhận không ai đang sửa cùng vùng.
2. Task FE-BE: thống nhất contract trước, chia owner theo file, không để hai người sửa cùng một file.
3. Yêu cầu mới xung đột code/contract hiện có: dừng đúng phần xung đột, mô tả bằng chứng (request/response mong đợi vs thực tế), thống nhất quyết định.
4. Khi xong: báo file đã sửa, contract đã dùng, việc BE/FE cần làm tiếp, lệnh kiểm tra đã chạy. Không tuyên bố pass nếu chưa chạy.

## 9. Kiểm tra trước khi bàn giao
- Chạy `npm run build` trong `FE/`. Dự án chưa có test script: không bịa lệnh test.
- Chạy thử màn hình bằng `npm run dev` với các trường hợp: có dữ liệu, rỗng, lỗi API, chưa đăng nhập (với route bảo vệ).
- Xem diff: không có file ngoài phạm vi, không sửa `api.js`, không có URL cứng mới, không đổi contract ngoài ý muốn.
- Mọi hàm, API call, route, `ref/computed/watch` mới đều có comment tiếng Việt theo mục 6; chỗ khó hiểu có giải thích lý do.
