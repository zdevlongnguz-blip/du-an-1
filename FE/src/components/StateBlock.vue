<script setup>
// Component dùng chung để hiển thị trạng thái tải, lỗi hoặc không có dữ liệu.
// Nội dung chính của trang được đưa vào slot mặc định khi không có trạng thái cần hiển thị.
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
</script>

<template>
  <div class="state-block">
    <!-- Ưu tiên loading trước để tránh hiện lỗi/trống trong lúc request đang chạy. -->
    <div v-if="props.loading" class="state-block__loading text-center py-5 text-secondary" role="status">
      {{ props.loadingText }}
    </div>

    <!-- Lỗi luôn được ưu tiên hơn trạng thái trống để không che giấu lỗi API. -->
    <div v-else-if="props.error" class="state-block__error alert alert-danger d-flex flex-wrap align-items-center justify-content-between gap-3" role="alert">
      <span>{{ props.error }}</span>
      <button v-if="props.onRetry" type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" @click="props.onRetry">
        Thử lại
      </button>
    </div>

    <!-- Slot riêng cho hành động như liên kết xem sản phẩm khi dữ liệu rỗng. -->
    <div v-else-if="props.empty" class="state-block__empty text-center py-5">
      <p class="text-secondary mb-3">{{ props.emptyText }}</p>
      <slot name="empty-action" />
    </div>

    <!-- Khi có dữ liệu, hiển thị nguyên nội dung do trang cha cung cấp. -->
    <slot v-else />
  </div>
</template>

<style scoped>
.state-block {
  min-width: 0;
}

.state-block__error {
  margin-bottom: 0;
}
</style>
