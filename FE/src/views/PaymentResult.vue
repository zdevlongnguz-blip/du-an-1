<script setup>
// Trang kết quả điều hướng từ SePay; không xác nhận giao dịch vì IPN chưa được tích hợp.
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

// Nội dung chỉ phản ánh URL quay về; trạng thái thật của đơn vẫn lấy từ backend.
const result = computed(() => {
  const results = {
    success: {
      title: 'Đã quay về từ SePay',
      message: 'Backend chưa xác nhận thanh toán vì IPN chưa được bật. Đơn hàng có thể vẫn hiển thị chưa thanh toán.',
      type: 'info',
    },
    error: {
      title: 'Thanh toán chưa hoàn tất',
      message: 'SePay đã quay về trang báo lỗi. Đơn hàng chưa được cập nhật là đã thanh toán.',
      type: 'warning',
    },
    cancel: {
      title: 'Bạn đã hủy thanh toán',
      message: 'Đơn hàng vẫn ở trạng thái chưa thanh toán.',
      type: 'secondary',
    },
  }
  return results[route.meta.paymentResult] || results.error
})
</script>

<template>
  <section class="container py-4">
    <div class="card">
      <div class="card-body">
        <h1 class="h3 mb-3">{{ result.title }}</h1>
        <div class="alert" :class="`alert-${result.type}`" role="status">
          {{ result.message }}
        </div>
        <router-link class="btn btn-primary" to="/payment">Quay lại danh sách đơn</router-link>
      </div>
    </div>
  </section>
</template>
