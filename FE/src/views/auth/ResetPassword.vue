<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { useToast } from '@/helpers/ui/toast'
import AuthPage from '@/components/AuthPage.vue'
import { validateNewPassword, validatePasswordConfirmation } from '@/helpers/auth/validation'

const route = useRoute()
const router = useRouter()
const { showToast } = useToast()
const token = typeof route.query.token === 'string' ? route.query.token : ''
const checkingToken = ref(true)
const tokenValid = ref(false)
const loading = ref(false)
const form = ref({
  newPassword: '',
  passwordConfirmation: ''
})
// Lưu lỗi FE cho mật khẩu mới và trường xác nhận.
const errors = ref({ newPassword: '', passwordConfirmation: '' })

// Kiểm tra mật khẩu mới theo giới hạn của BE.
const validateNewPasswordField = () => {
  errors.value.newPassword = validateNewPassword(form.value.newPassword)
  return !errors.value.newPassword
}

// Kiểm tra mật khẩu xác nhận đã nhập và trùng với mật khẩu mới.
const validateConfirmationField = () => {
  errors.value.passwordConfirmation = validatePasswordConfirmation(
    form.value.newPassword,
    form.value.passwordConfirmation
  )
  return !errors.value.passwordConfirmation
}

// GET chỉ kiểm tra token; token chỉ bị tiêu thụ khi gửi POST đổi mật khẩu.
onMounted(async () => {
  if (!token) {
    showToast('Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.', 'danger')
    checkingToken.value = false
    return
  }

  try {
    await api.get('?action=api-reset-password', { params: { token } })
    tokenValid.value = true
  } catch (error) {
    showToast(error.response?.data?.message || 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.', 'danger')
  } finally {
    checkingToken.value = false
  }
})

// FE kiểm tra xác nhận, sau đó gửi token cùng mật khẩu mới để BE dùng token một lần.
const resetPassword = async () => {
  const isPasswordValid = validateNewPasswordField()
  const isConfirmationValid = validateConfirmationField()
  if (!isPasswordValid || !isConfirmationValid) return

  try {
    loading.value = true
    const response = await api.post('?action=api-reset-password', {
      token,
      new_password: form.value.newPassword
    })
    showToast(response.data?.message || 'Đặt lại mật khẩu thành công.', 'success')
    router.push('/login')
  } catch (error) {
    showToast(error.response?.data?.message || 'Không thể đặt lại mật khẩu.', 'danger')
    console.error('Reset password failed:', error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthPage
    title="Reset password"
    description="Tạo mật khẩu mới để tiếp tục sử dụng tài khoản FuLe BaberShop."
  >
    <!-- Trạng thái kiểm tra link và form đặt lại mật khẩu. -->
    <div v-if="checkingToken" class="auth-status" role="status">
      <p>Đang kiểm tra liên kết...</p>
    </div>

    <form v-else-if="tokenValid" class="auth-form" novalidate @submit.prevent="resetPassword">
      <div class="auth-field">
        <label for="new-password">New password</label>
        <input
          id="new-password"
          v-model="form.newPassword"
          type="password"
          class="auth-input"
          autocomplete="new-password"
          :aria-invalid="Boolean(errors.newPassword)"
          :aria-describedby="errors.newPassword ? 'reset-password-error' : undefined"
          @blur="validateNewPasswordField"
          @input="errors.newPassword && validateNewPasswordField()"
          required
        />
        <p v-if="errors.newPassword" id="reset-password-error" class="auth-field-error" aria-live="polite">
          {{ errors.newPassword }}
        </p>
      </div>

      <div class="auth-field">
        <label for="password-confirmation">Confirm password</label>
        <input
          id="password-confirmation"
          v-model="form.passwordConfirmation"
          type="password"
          class="auth-input"
          autocomplete="new-password"
          :aria-invalid="Boolean(errors.passwordConfirmation)"
          :aria-describedby="errors.passwordConfirmation ? 'reset-confirmation-error' : undefined"
          @blur="validateConfirmationField"
          @input="errors.passwordConfirmation && validateConfirmationField()"
          required
        />
        <p v-if="errors.passwordConfirmation" id="reset-confirmation-error" class="auth-field-error" aria-live="polite">
          {{ errors.passwordConfirmation }}
        </p>
      </div>

      <button type="submit" class="auth-submit" :disabled="loading">
        {{ loading ? 'Đang cập nhật...' : 'RESET PASSWORD' }}
      </button>
    </form>

    <div v-else class="auth-status">
      <p>Liên kết không hợp lệ hoặc đã hết hạn.</p>
      <router-link to="/forgot-password" class="auth-secondary-action">Yêu cầu liên kết mới</router-link>
    </div>

    <template #footer>
      <p><router-link class="auth-inline-link" to="/login">Back to login</router-link></p>
    </template>
  </AuthPage>
</template>