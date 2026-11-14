<script setup>
// Khung thẻ dùng chung cho các trang đơn hàng/checkout: cùng kiểu với thẻ .pd-card của trang chi tiết sản phẩm
// (viền #4e4639, bo 4px, nền #1b1b1d, đổ bóng). Nội dung đặt trong slot.
defineProps({
  // Thẻ HTML thực tế: dùng 'section', 'article' hoặc 'aside' cho đúng ngữ nghĩa.
  tag: { type: String, default: 'section' },
})
</script>

<template>
  <component :is="tag" class="order-panel">
    <slot />
  </component>
</template>

<style scoped>
.order-panel {
  padding: 24px;
  border: 1px solid #4e4639;
  border-radius: 4px;
  background: #1b1b1d;
  box-shadow: 0 4px 16px rgb(0 0 0 / 50%);
}

/* Điện thoại: thu padding như .pd-card. */
@media (max-width: 575.98px) {
  .order-panel {
    padding: 16px;
  }
}
</style>
