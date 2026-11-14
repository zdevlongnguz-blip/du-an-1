<template>
  <section class="py-5 text-center" aria-labelledby="not-found-title">
    <p class="mb-2 text-secondary">404</p>
    <h1 id="not-found-title">Không tìm thấy trang</h1>
    <p class="mb-4">Địa chỉ bạn truy cập không tồn tại hoặc đã được thay đổi.</p>
    <router-link to="/" class="btn btn-primary">Về trang chủ</router-link>
  </section>
</template>

<style scoped>
/* Trang 404 theo Stitch: số 404 lớn màu đồng, tiêu đề serif, nút navy (btn-primary global). */
section > p:first-child {
  color: #8c6a32 !important;
  font-family: var(--font-display);
  font-size: clamp(56px, 12vw, 96px);
  line-height: 1;
}

h1 {
  color: var(--color-ink);
  font-family: var(--font-display);
  font-weight: 600;
}

section > p:nth-of-type(2) {
  color: var(--color-muted);
}
</style>