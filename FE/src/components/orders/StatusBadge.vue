<script setup>
// Huy hiệu trạng thái nhỏ (ví dụ "Chờ xác nhận", "Đã thanh toán") dùng chung cho các trang đơn hàng.
// Kiểu bám huy hiệu giảm giá của trang chi tiết sản phẩm: viền mảnh, nền tối pha màu, chữ in hoa.
defineProps({
  // Chữ hiển thị trong huy hiệu.
  label: { type: String, required: true },
  // Sắc thái màu: pending | confirmed | shipping | success | danger | neutral.
  tone: { type: String, default: 'neutral' },
})
</script>

<template>
  <span class="status-badge" :class="`status-badge--${tone}`">
    <!-- Chấm tròn nhỏ báo hiệu trạng thái đang chờ xử lý. -->
    <span v-if="tone === 'pending'" class="status-badge__dot" aria-hidden="true"></span>
    {{ label }}
  </span>
</template>

<style scoped>
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
  border: 1px solid #4e4639;
  border-radius: 4px;
  background: #2a2a2c;
  color: #d1c5b4;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  line-height: 1.3;
  text-transform: uppercase;
  white-space: nowrap;
}

.status-badge__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}

/* Chờ xác nhận: vàng nhạt (cùng bảng màu với alert-warning của hệ thống). */
.status-badge--pending {
  border-color: #c5a059;
  background: #2a2415;
  color: #f5ce72;
}

/* Đã xác nhận: viền vàng, nền tối. */
.status-badge--confirmed {
  border-color: #c5a059;
  background: #1f1f21;
  color: #e9c176;
}

/* Đang giao: viền xám sáng, chữ sáng. */
.status-badge--shipping {
  border-color: #9a8f80;
  background: #1f1f21;
  color: #ede0d4;
}

/* Thành công / đã thanh toán: xanh lá. */
.status-badge--success {
  border-color: #2f5a3d;
  background: #1b3824;
  color: #7de098;
}

/* Đã hủy / lỗi: đỏ, giống huy hiệu giảm giá của trang chi tiết sản phẩm. */
.status-badge--danger {
  border-color: #93000a;
  background: #2a1516;
  color: #ffb4ab;
}
</style>
