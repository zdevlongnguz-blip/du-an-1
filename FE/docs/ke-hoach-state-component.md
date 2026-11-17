# Kế hoạch: gộp trạng thái loading / lỗi / trống thành component dùng chung

> Dành cho: thành viên làm FE, đã đọc `FE/CLAUDE.md`.
> Trạng thái: chưa làm. Đây là kế hoạch, không phải thay đổi code.

## 1. Mục tiêu

Hiện mỗi trang tự viết khối loading, lỗi và trống. Gộp lại để:
- Giao diện 3 trạng thái nhất quán giữa các trang.
- Sửa một chỗ thay cho nhiều chỗ.
- Giữ đúng quy tắc `FE/CLAUDE.md` mục 4 và 6: lỗi API không bị hiển thị thành "trống".

Không làm: đổi logic API, đổi `<script setup>` của trang, đổi contract BE, đổi `client.js`.

## 2. Hiện trạng

| Trang | File | Loading | Lỗi | Trống |
|---|---|---|---|---|
| Danh sách đơn | `FE/src/views/orders/OrderList.vue` (dòng ~118-133) | `text-center py-5 text-secondary` | `alert alert-danger ...` + nút "Thử lại" | `text-center py-5`, có nút "Xem sản phẩm" |
| Chi tiết đơn | `FE/src/views/orders/OrderDetail.vue` (dòng ~145-153) | `text-center py-5 text-secondary` (chỉ khi `loading && !order`) | `alert alert-danger ...` + "Thử lại" | Không có (đơn không tồn tại đã báo lỗi 404) |
| Thanh toán | `FE/src/views/payment/CheckoutView.vue` (dòng ~219-231) | `text-center py-5 text-secondary` | `alert alert-danger ...` + "Thử lại" | `text-center py-5`, có nút "Xem sản phẩm" |
| Giỏ hàng | `FE/src/views/cart/CartView.vue` (dòng ~150-164) | `cart-state` | `cart-state cart-state--error`, `role="alert"` | `cart-empty` |

Ngoài 4 trang trên, còn `ProductDetail.vue`, `HomeView.vue`, `auth/Profile.vue`, `catalog.vue`. Các trang này có class và bố cục riêng, **để sau**.

Các điểm không nhất quán cần xử lý:
- Loading: có chỗ `role="status"`, chỗ không có.
- Lỗi: có chỗ `role="alert"`, có chỗ nằm trong thẻ `<p>`.
- Class CSS của Cart (`cart-state`, `cart-empty`) khác với Bootstrap ở ba trang còn lại.

## 3. Thiết kế đề xuất

Tạo `FE/src/components/StateBlock.vue`.

**Props:**

| Prop | Kiểu | Ý nghĩa |
|---|---|---|
| `loading` | Boolean | Đang tải, hiện thông báo chờ. |
| `error` | String | Thông báo lỗi. Rỗng = không lỗi. |
| `empty` | Boolean | Không có dữ liệu. |
| `loadingText` | String | Mặc định "Đang tải..." |
| `emptyText` | String | Nội dung trống. |
| `onRetry` | Function | Có thì hiện nút "Thử lại". |

**Thứ tự ưu tiên (quan trọng):** `loading` → `error` → `empty` → slot mặc định (có dữ liệu).
Lỗi phải được ưu tiên hơn trống, để không hiện "chưa có đơn" khi thực tế là API hỏng.

**Slot:**
- `empty-action`: nút hoặc link phía dưới thông báo trống (ví dụ "Xem sản phẩm").
- Slot mặc định: nội dung khi có dữ liệu.

**Thuộc tính:**
- Loading dùng `role="status"`, lỗi dùng `role="alert"`, thống nhất ở mọi trang.
- Dùng Bootstrap 5 có sẵn (`text-center py-5`, `alert alert-danger`, `btn btn-outline-danger`). Không thêm thư viện.
- CSS riêng dùng `<style scoped>`.

Ví dụ cách dùng:

```vue
<StateBlock
  :loading="loading"
  :error="loadError"
  :empty="orders.length === 0"
  loading-text="Đang tải đơn hàng..."
  empty-text="Bạn chưa có đơn hàng nào."
  :on-retry="() => loadOrders(pagination.current_page)"
>
  <template #empty-action>
    <RouterLink class="btn btn-primary" to="/catalog">Xem sản phẩm</RouterLink>
  </template>

  <!-- Nội dung khi có dữ liệu -->
  <ul class="list-unstyled orders-list">...</ul>
</StateBlock>
```

## 4. Các bước thực hiện

1. **Tạo component.** Viết `StateBlock.vue` theo mục 3. Đầu `<script setup>` có comment tiếng Việt 1-3 dòng, mỗi prop có comment.
2. **Áp dụng cho OrderList** trước vì đơn giản nhất. Giữ nguyên `loadOrders`, `loading`, `loadError`, `orders`.
3. **Áp dụng cho OrderDetail.** Lưu ý điều kiện `loading && !order`: truyền `:loading="loading && !order"`. Trang này không có nhánh trống.
4. **Áp dụng cho CheckoutView.** Khối lỗi chung `checkoutError` (có nút thanh toán lại) **không** đưa vào `StateBlock`, vì nó có logic riêng. Chỉ gộp khối `cartError` và `cartItems.length === 0`.
5. **Áp dụng cho CartView.** Giữ class `cart-*` nếu cần để không lệch giao diện. Có thể đổi sang Bootstrap sau khi đối chiếu màn hình.
6. **Dọn class cũ** trong `<style scoped>` của từng trang nếu không còn dùng. Không xóa class đang được dùng ở nơi khác.

Mỗi bước là một commit riêng để dễ revert.

## 5. Ràng buộc (theo `FE/CLAUDE.md`)

- Chỉ sửa trong `FE/`. Không sửa `BE/`, contract API, `client.js`, `services/auth.js`.
- Không thêm dependency, không tạo `axios.create`, không dùng `fetch`.
- Không đổi `router/index.js`, `App.vue`, `style.css`. Đây là vùng dùng chung, cần xác nhận owner trước nếu phải sửa.
- Thêm component vào `components/` không thuộc vùng dùng chung. Nếu muốn đặt vào `helpers/ui/` thì hỏi owner trước.
- Comment tiếng Việt có dấu, theo mục 8 của `FE/CLAUDE.md`.
- Không tự thêm logic mới hay thay đổi nội dung thông báo người dùng nếu không cần.

## 6. Kiểm tra trước khi bàn giao

- [ ] Chạy `npm run build` trong `FE/`. Ghi kết quả thật, không ghi "pass" nếu chưa chạy.
- [ ] Chạy `npm run dev` và kiểm tra thủ công từng trang đã sửa với 4 trường hợp:
  - Có dữ liệu.
  - Rỗng (tài khoản mới, giỏ trống).
  - API lỗi (tắt BE hoặc chặn request trong DevTools). Phải hiện lỗi và nút "Thử lại", **không** hiện "trống".
  - Chưa đăng nhập (với trang có `meta.requiresAuth`).
- [ ] Bấm "Thử lại" và xác nhận dữ liệu tải lại được.
- [ ] Kiểm tra giao diện ở bề rộng điện thoại (~400px) và máy tính.
- [ ] Giữ nguyên chữ hiển thị theo ngôn ngữ hiện có của từng trang (tiếng Việt), không đổi nội dung thông báo.
- [ ] `git diff`: chỉ có file trong danh sách mục 4. Không có URL cứng mới.
- [ ] Nếu không chạy được giao diện trên máy, ghi rõ trong báo cáo bàn giao để người phụ trách tự kiểm tra.

## 7. Tiêu chí hoàn thành

- `StateBlock.vue` tồn tại, có comment tiếng Việt.
- 4 trang trong mục 4 dùng component, không còn khối `alert alert-danger` lặp lại cho loading/lỗi/trống (trừ `checkoutError`).
- Lỗi API không bao giờ hiển thị là trạng thái trống.
- Checklist mục 6 đã đánh dấu, phần chưa làm được ghi rõ.

## 8. Ngoài phạm vi

- `ProductDetail.vue`, `HomeView.vue`, `Profile.vue`, `catalog.vue`: để task riêng sau.
- Thông báo toast (`services/toast.js`): không thay đổi.
- Đổi thông điệp tiếng Việt của các trang: không thuộc task này.
