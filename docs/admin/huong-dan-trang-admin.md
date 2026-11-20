# Hướng dẫn làm trang quản trị (khu `/admin`)

Dành cho người làm các trang admin tiếp theo (danh mục, đơn hàng, bình luận, banner...). Trang **Sản phẩm** (PR #85) là mẫu đầy đủ nhất, nên đọc code của nó kèm tài liệu này.

Cập nhật: 20/11/2026.

## 1. Khu admin hoạt động thế nào

- Mọi trang admin là **route con** của `/admin` trong `FE/src/router/index.js`. Route cha dùng `components/admin/AdminLayout.vue` (menu bên trái + vùng nội dung) và có `meta: { requiresAuth: true, requiresAdmin: true, layout: 'admin' }`, nên route con tự thừa hưởng.
- `App.vue` thấy `meta.layout === 'admin'` thì ẩn header, footer, khung chat của shop. Toast vẫn dùng chung. **Không cần sửa `App.vue`** khi thêm trang.
- Khi vào `/admin`, router hỏi BE `api-admin-me` (1 lần mỗi lần tải trang). BE trả 403 thì về trang chủ, 401 thì về `/login`.
- **Quyền thật do BE kiểm tra**: mọi route `api-admin-*` trong `BE/routes/index.php` gọi `(new AdminMiddleware())->requireAdmin()` (token hợp lệ, khớp DB, role admin). Chặn ở FE chỉ để trải nghiệm.
- Đổi role trong DB thì phải **đăng nhập lại** (token cũ bị 401).

## 2. Thêm một trang admin mới

1. Tạo view trong `FE/src/views/admin/<nhóm>/`, ví dụ `views/admin/categories/AdminCategoryList.vue`.
2. Thêm route con vào `children` của nhóm `/admin` trong `router/index.js`, dùng import động:
   ```js
   {
     path: "categories",
     name: "admin-categories",
     component: () => import("../views/admin/categories/AdminCategoryList.vue"),
   },
   ```
3. Bật mục menu: trong `components/admin/AdminSidebar.vue` (mảng `ADMIN_MENU`) và thẻ ở `views/admin/AdminDashboard.vue` (mảng `SECTIONS`), đổi `ready: true` và điền `to: { name: 'admin-categories' }`. Mục menu tự sáng cho mọi trang có đường dẫn bắt đầu bằng đường dẫn của mục (ví dụ `/admin/categories/5/edit`).
4. Trang sửa dùng tham số trên URL thì đặt `path: "categories/:id(\\d+)/edit"`. `AdminLayout` có `RouterView :key="route.path"` nên đi từ `/5/edit` sang `/6/edit` sẽ tạo lại trang, không giữ dữ liệu cũ.

## 3. Bảng dùng chung `AdminTable`

`FE/src/components/admin/AdminTable.vue`: không tự gọi API, trang cha tải dữ liệu rồi truyền vào. Tự lo đang tải / lỗi có "Thử lại" / rỗng bằng `StateBlock`, bảng rộng thì cuộn ngang trong khung.

```vue
<AdminTable
  :columns="[{ key: 'name', label: 'Tên' }, { key: 'actions', label: 'Thao tác', align: 'center' }]"
  :rows="items" row-key="id"
  :loading="loading" :error="errorMessage" :on-retry="loadItems"
  empty-text="Chưa có danh mục nào."
>
  <!-- Cột có slot #cell-<key> thì tự vẽ; không có slot thì in thẳng row[key] -->
  <template #cell-actions="{ row }">...</template>
</AdminTable>
```

Phân trang đặt ở trang cha bằng `components/PaginationBar.vue`. Tìm kiếm / lọc / số trang nên ghi lên URL (`?search=&page=`) để F5 không mất (xem `AdminProductList.vue`).

## 4. Tải ảnh lên (multipart)

- `FE/src/helpers/api/client.js` **mặc định gửi `Content-Type: application/json`**. Với header đó axios tự đổi `FormData` thành JSON và **file bị mất** (BE nhận `"image": []`). Vì vậy request có file phải ghi đè header (không sửa `client.js`):
  ```js
  await api.post('/?action=api-admin-xxx-create', formData, { headers: { 'Content-Type': 'multipart/form-data' } })
  ```
- Kiểm tra file trước khi gửi bằng `FE/src/helpers/media/imageUpload.js` (`validateImageFile`, `IMAGE_ACCEPT`): tối đa 2MB, jpg/png/gif/webp, khớp `validate_upload` của BE.
- BE: nhận file bằng `validate_upload` + `save_uploaded_file` (tên file ngẫu nhiên `cyber_<12 hex>_<time>.<đuôi>`). DB **chỉ lưu tên file**, FE ghép URL bằng `resolveImageUrl`.
- Lưu DB lỗi (trùng mã, dữ liệu sai...) thì **xóa file vừa tải lên** để không thành file rác (xem `ProductController::adminCreate`).

## 5. Xóa file ảnh

- Xóa / thay ảnh thì xóa file **sau khi** đã xóa / sửa bản ghi trong DB.
- Sản phẩm dùng `ProductController::deleteImageIfUnused`: chỉ xóa file khi không còn bản ghi nào dùng (`Product::isImageInUse` kiểm tra `products.image`, `product_images.image_path`, `banners.image`). Lý do: dữ liệu mẫu có file dùng chung (`default.jpg` cho 4 sản phẩm). Ảnh tải lên qua API luôn có tên ngẫu nhiên nên không trùng.
- Không ghép đường dẫn từ dữ liệu người dùng: tên file có `/` thì bỏ qua (`basename($name) !== $name`).

## 6. Khóa nghiệp vụ (mẫu từ trang Sản phẩm)

Nguyên tắc: **lịch sử là bất biến** (đơn hàng lưu ảnh chụp tên, SKU, giá nên sửa thông tin thoải mái), **có lịch sử thì không xóa** (dùng ẩn / tắt), **số lượng không ghi đè khi vừa bị đổi**, **mã định danh không đổi**.

- **Lỗi nghiệp vụ dùng chung:** model ném `BusinessConflictException` (`BE/extensions/BusinessConflictException.php`) kèm lý do tiếng Việt và dữ liệu thêm; controller bắt và trả **409** (`ProductController::respondConflict`). FE hiện `err.response.data.message` bằng toast.
- **Kiểm tra trong transaction có khóa dòng** (`SELECT ... FOR UPDATE`), để không có thao tác khác chen vào giữa lúc "kiểm tra" và "ghi". Giữ **cùng thứ tự khóa** với luồng đặt hàng (khóa `product_variants` trước, rồi mới tới `products`), nếu ngược thứ tự có thể deadlock.
- **Cờ cho FE khóa nút trước:** API chi tiết admin trả `order_count`, `can_delete`; FE khóa nút Xóa kèm `title` giải thích. BE vẫn kiểm tra lại, FE không tự suy luận quyền.
- **Chống ghi đè số lượng (khóa theo phiên bản):** FE gửi kèm giá trị lúc mở form (`expected_stock`), BE so với giá trị hiện tại; khác thì 409 kèm giá trị mới (`current_stock`), FE đặt lại ô và báo admin nhập lại. FE **chỉ gửi trường đó khi admin có đổi** để không chặn oan.
- **Lỗi DB thường gặp:** 1062 (trùng UNIQUE) → 409 "đã tồn tại"; 3819 (vi phạm CHECK) → 422 có thông báo dễ hiểu. Đừng gộp mọi `SQLSTATE 23000` thành "trùng mã" (23000 còn gồm lỗi khóa ngoại).

## 7. Form trong trang admin

- Validate phía FE, lỗi hiện ngay dưới ô (theo `FE/CLAUDE.md` mục 6); lỗi từ BE hiện bằng toast. Khóa nút trong lúc gửi.
- Form sửa không bọc toàn bộ trong `StateBlock` khi dữ liệu có thể tải lại trong lúc admin đang gõ. Khối con tự lưu (như bảng biến thể, ảnh slide) nên **tự tải dữ liệu riêng** để không ghi đè các ô admin đang gõ dở.
- Form đang mở thì khóa các thao tác nhanh trên cùng dữ liệu (ví dụ nút "Tắt bán" ở bảng biến thể), tránh lần lưu form đè ngược thao tác đó.

## 8. Kiểm tra trước khi tạo PR

- `php -l` cho mọi file PHP đã sửa; `npm run build` trong `FE/`.
- Thử API: hợp lệ, thiếu/sai dữ liệu (422), không token (401), user thường (403), sai method (405), không tồn tại (404), vi phạm khóa nghiệp vụ (409). Ghi kết quả thật vào `BE/test-api.md`.
- Thử trên trình duyệt với tài khoản admin: desktop và màn hình ~400px (không cuộn ngang trang).
- Test có tạo dữ liệu thì dọn **qua API** (để BE dọn cả file ảnh), đừng xóa bằng SQL.
