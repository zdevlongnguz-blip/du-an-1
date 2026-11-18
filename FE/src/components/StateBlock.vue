<script setup>
// Component dùng chung để hiển thị trạng thái đang tải, lỗi (có nút thử lại) hoặc không có dữ liệu.
// Nội dung chính của trang được đưa vào slot mặc định khi không có trạng thái cần hiển thị.
// Giao diện theo Stitch (thẻ trắng viền kem, lỗi nền đỏ nhạt, nút viền navy), không dùng class Bootstrap gắn cứng
// để trang nào cũng hiển thị giống nhau mà không phải ghi đè style.
const props = defineProps({
  // Đang tải dữ liệu; trạng thái này được ưu tiên hiển thị đầu tiên.
  loading: { type: Boolean, default: false },
  // Nội dung lỗi từ trang; chuỗi rỗng nghĩa là không có lỗi.
  error: { type: String, default: '' },
  // Cho biết danh sách hoặc dữ liệu hiện tại có trống hay không.
  empty: { type: Boolean, default: false },
  // Nội dung hiển thị trong lúc chờ dữ liệu.
  loadingText: { type: String, default: 'Đang tải...' },
  // Nội dung hiển thị khi không có dữ liệu.
  emptyText: { type: String, default: 'Không có dữ liệu.' },
  // Hàm tải lại do trang cha truyền vào; không truyền thì ẩn nút thử lại.
  onRetry: { type: Function, default: null },
})

// Bấm "Thử lại": gọi hàm của trang cha KHÔNG kèm tham số. Nếu viết @click="props.onRetry" thì Vue sẽ truyền
// sự kiện click vào hàm, ví dụ loadOrders(event) sẽ hiểu nhầm event là số trang.
function handleRetry() {
  props.onRetry?.()
}
</script>

<template>
  <div class="state-block">
    <!-- Ưu tiên loading trước để tránh hiện lỗi/trống trong lúc request đang chạy.
         role="status": trình đọc màn hình đọc thông báo này mà không làm gián đoạn người dùng. -->
    <div v-if="props.loading" class="state-block__panel state-block__loading" role="status">
      {{ props.loadingText }}
    </div>

    <!-- Lỗi luôn được ưu tiên hơn trạng thái trống để không che giấu lỗi API.
         role="alert": trình đọc màn hình đọc ngay khi lỗi xuất hiện. -->
    <div v-else-if="props.error" class="state-block__panel state-block__error" role="alert">
      <p class="state-block__message">{{ props.error }}</p>
      <button v-if="props.onRetry" type="button" class="state-block__retry" @click="handleRetry">
        Thử lại
      </button>
    </div>

    <!-- Không có dữ liệu: thông báo và slot "empty-action" cho nút/liên kết như "Xem sản phẩm". -->
    <div v-else-if="props.empty" class="state-block__panel state-block__empty">
      <p class="state-block__message">{{ props.emptyText }}</p>
      <slot name="empty-action" />
    </div>

    <!-- Khi có dữ liệu, hiển thị nguyên nội dung do trang cha cung cấp. -->
    <slot v-else />
  </div>
</template>

<style scoped>
/* min-width: 0 để nội dung bên trong (bảng, lưới) không làm tràn bố cục flex/grid của trang cha. */
.state-block {
  min-width: 0;
}

/* Khung chung của 3 trạng thái: thẻ trắng, viền kem, bo 8px, chữ phụ, căn giữa (bảng màu Stitch). */
.state-block__panel {
  display: grid;
  justify-items: center;
  gap: 14px;
  padding: 48px 24px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  color: #6b6560;
  text-align: center;
}

.state-block__message {
  max-width: 420px;
  margin: 0;
}

/* Lỗi: viền đỏ, nền đỏ nhạt, chữ đỏ đậm. */
.state-block__error {
  border-color: #ba1a1a;
  background: #fdf1f0;
  color: #93000a;
}

/* Trống: thẻ hẹp hơn, đổ bóng nhẹ, nằm giữa trang. */
.state-block__empty {
  max-width: 640px;
  margin: 0 auto;
  box-shadow: 0 4px 16px rgba(28, 42, 58, 0.06);
}

/* Nút "Thử lại": nút phụ viền navy theo Stitch, cao 44px cho dễ bấm trên điện thoại. */
.state-block__retry {
  display: inline-flex;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  padding: 0 18px;
  border: 1px solid #1c2a3a;
  border-radius: 6px;
  background: transparent;
  color: #1c2a3a;
  cursor: pointer;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  transition: background-color 140ms ease, color 140ms ease;
}

.state-block__retry:hover {
  background: #1c2a3a;
  color: #ffffff;
}

.state-block__retry:focus-visible {
  outline: 2px solid #b8893e;
  outline-offset: 2px;
}
</style>
