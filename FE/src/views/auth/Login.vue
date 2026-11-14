<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { setAuthSession } from '@/helpers/auth/session'
import { useToast } from '@/helpers/ui/toast'
import AuthPage from '@/components/AuthPage.vue'
import { validateEmail, validateNewPassword } from '@/helpers/auth/validation'
import { pushToReturnUrl } from '@/helpers/auth/returnUrl'

const router = useRouter()
const { showToast } = useToast()
const loading = ref(false)
const form = ref({
  email: '',
  password: ''
})
// Lưu lỗi FE theo từng trường để đặt ngay dưới input tương ứng.
const errors = ref({ email: '', password: '' })

// Kiểm tra email trước khi gửi request đăng nhập.
const validateEmailField = () => {
  errors.value.email = validateEmail(form.value.email)
  return !errors.value.email
}

// Kiểm tra mật khẩu giống form đăng ký (8-72 byte); đúng/sai vẫn do BE xác nhận.
const validatePasswordField = () => {
  errors.value.password = validateNewPassword(form.value.password, 'mật khẩu')
  return !errors.value.password
}

// Lưu JWT và thông tin user từ BE trước khi chuyển trang để route guard nhận diện phiên đăng nhập.
const fetchApiLogin = async () => {
  const isEmailValid = validateEmailField()
  const isPasswordValid = validatePasswordField()
  if (!isEmailValid || !isPasswordValid) return

  try {
    loading.value = true

    const response = await api.post('?action=api-login', {
      email: form.value.email,
      password: form.value.password
    })

    const token = response.data?.token
    const user = response.data?.user

    setAuthSession(token, user)
    showToast(response.data?.message || 'Đăng nhập thành công', 'success')
    await pushToReturnUrl(router, '/')
  } catch (error) {
    showToast(error.response?.data?.message || 'Lỗi đăng nhập', 'danger')
    console.error('Login failed:', error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthPage title="Đăng nhập tài khoản" description="Đăng nhập vào tài khoản FuLe BaberShop để tiếp tục.">
    <!-- Form đăng nhập giữ nguyên endpoint và session JWT hiện có. -->
    <form class="auth-form" novalidate @submit.prevent="fetchApiLogin">
      <div class="auth-field">
        <label for="email">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          class="auth-input"
          autocomplete="email"
          :aria-invalid="Boolean(errors.email)"
          :aria-describedby="errors.email ? 'login-email-error' : undefined"
          @blur="validateEmailField"
          @input="errors.email && validateEmailField()"
          required
        />
        <p v-if="errors.email" id="login-email-error" class="auth-field-error" aria-live="polite">
          {{ errors.email }}
        </p>
      </div>

      <div class="auth-field">
        <label for="password">Mật khẩu</label>
        <input
          id="password"
          v-model="form.password"
          type="password"
          class="auth-input"
          autocomplete="current-password"
          :aria-invalid="Boolean(errors.password)"
          :aria-describedby="errors.password ? 'login-password-error' : undefined"
          @blur="validatePasswordField"
          @input="errors.password && validatePasswordField()"
          required
        />
        <p v-if="errors.password" id="login-password-error" class="auth-field-error" aria-live="polite">
          {{ errors.password }}
        </p>
        <div class="auth-forgot-row">
          <router-link class="auth-inline-link" to="/forgot-password">Quên mật khẩu?</router-link>
        </div>
      </div>

      <button type="submit" class="auth-submit" :disabled="loading">
        {{ loading ? 'Đang đăng nhập...' : 'Đăng nhập' }}
      </button>
    </form>

    <template #footer>
      <p>Chưa có tài khoản?<router-link class="auth-inline-link" to="/register">Đăng ký ngay</router-link></p>
    </template>
  </AuthPage>
</template>
