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
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #efeae0;
  color: #4a5565;
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
  border-color: #b8893e;
  background: #fff7e6;
  color: #7a5200;
}

/* Đã xác nhận: viền vàng, nền tối. */
.status-badge--confirmed {
  border-color: #b8893e;
  background: #ffffff;
  color: #8c6a32;
}

/* Đang giao: viền xám sáng, chữ sáng. */
.status-badge--shipping {
  border-color: #cfc8b8;
  background: #ffffff;
  color: #131e2a;
}

/* Thành công / đã thanh toán: xanh lá. */
.status-badge--success {
  border-color: #2f7d4f;
  background: #edf7f0;
  color: #1b5e3a;
}

/* Đã hủy / lỗi: đỏ, giống huy hiệu giảm giá của trang chi tiết sản phẩm. */
.status-badge--danger {
  border-color: #ba1a1a;
  background: #fdf1f0;
  color: #93000a;
}
</style>
