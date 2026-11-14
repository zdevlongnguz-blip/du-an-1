<script setup>
// Khung trình bày dùng chung cho các màn hình tài khoản.
const props = defineProps({
  title: {
    type: String,
    required: true
  },
  description: {
    type: String,
    required: true
  },
  variant: {
    type: String,
    default: 'plain'
  }
})
</script>

<template>
  <div class="auth-page">
    <!-- Bố cục chia đôi theo Stitch: hero thương hiệu bên trái, form bên phải. -->
    <div class="auth-shell">
      <!-- Hero navy chỉ để trang trí, ẩn trên màn hình nhỏ để form hiện ngay. -->
      <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-brand">
          <span class="auth-hero-mark">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
              <circle cx="6" cy="6" r="3" />
              <circle cx="6" cy="18" r="3" />
              <path d="M8.1 8.1 20 20M8.1 15.9 20 4" />
            </svg>
          </span>
          <span class="auth-hero-brand-text">
            <strong>FULE BABERSHOP</strong>
          </span>
        </div>

        <span class="auth-hero-rule"></span>
      </aside>

      <!-- Vùng form: thẻ trắng nằm giữa nền kem. -->
      <div class="auth-main">
        <section class="auth-panel" :class="`auth-panel--${props.variant}`">
          <!-- Tiêu đề và nội dung của từng màn hình auth. -->
          <header class="auth-panel-header">
            <p class="auth-eyebrow">Cổng khách hàng</p>
            <h1>{{ props.title }}</h1>
            <p class="auth-panel-description">{{ props.description }}</p>
          </header>

          <slot />

          <!-- Liên kết phụ tùy theo nghiệp vụ của màn hình. -->
          <div v-if="$slots.footer" class="auth-panel-footer">
            <slot name="footer" />
          </div>
        </section>

        <!-- Ghi chú pháp lý chỉ xuất hiện ở màn hình có nội dung được cung cấp. -->
        <p v-if="$slots.note" class="auth-note">
          <slot name="note" />
        </p>
      </div>
    </div>
  </div>
</template>
