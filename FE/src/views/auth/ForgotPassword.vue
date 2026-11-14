<script setup>
import { ref } from 'vue'
import api from '@/helpers/api/client'
import { useToast } from '@/helpers/ui/toast'
import AuthPage from '@/components/AuthPage.vue'
import { validateEmail } from '@/helpers/auth/validation'

const { showToast } = useToast()
const email = ref('')
const loading = ref(false)
// Lưu lỗi định dạng email để hiển thị ngay dưới ô nhập.
const emailError = ref('')

// Kiểm tra email trước khi gửi yêu cầu quên mật khẩu.
const validateEmailField = () => {
  emailError.value = validateEmail(email.value)
  return !emailError.value
}

// BE luôn trả thông báo chung để không tiết lộ email có đăng ký hay không.
const requestReset = async () => {
  if (!validateEmailField()) return

  try {
    loading.value = true
    const response = await api.post('?action=api-forgot-password', { email: email.value })
    showToast(response.data?.message || 'Nếu email được đăng ký, hướng dẫn sẽ được gửi.', 'success')
  } catch (error) {
    showToast(error.response?.data?.message || 'Không thể gửi yêu cầu đặt lại mật khẩu.', 'danger')
    console.error('Forgot password request failed:', error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthPage
    title="Quên mật khẩu?"
    description="Nhập email đã đăng ký. Nếu tài khoản phù hợp, hướng dẫn đặt lại mật khẩu sẽ được gửi."
  >
    <!-- Form yêu cầu reset giữ nguyên API và phản hồi chung từ BE. -->
    <form class="auth-form" novalidate @submit.prevent="requestReset">
      <div class="auth-field">
        <label for="email">Email</label>
        <input
          id="email"
          v-model="email"
          type="email"
          class="auth-input"
          autocomplete="email"
          :aria-invalid="Boolean(emailError)"
          :aria-describedby="emailError ? 'forgot-email-error' : undefined"
          @blur="validateEmailField"
          @input="emailError && validateEmailField()"
          required
        />
        <p v-if="emailError" id="forgot-email-error" class="auth-field-error" aria-live="polite">
          {{ emailError }}
        </p>
      </div>

      <button type="submit" class="auth-submit" :disabled="loading">
        {{ loading ? 'Đang gửi...' : 'Gửi liên kết đặt lại' }}
      </button>
    </form>

    <template #footer>
      <p><router-link class="auth-inline-link" to="/login">Quay lại đăng nhập</router-link></p>
    </template>
  </AuthPage>
</template>