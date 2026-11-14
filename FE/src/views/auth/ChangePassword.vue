<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { useToast } from '@/helpers/ui/toast'
import AuthPage from '@/components/AuthPage.vue'
import {
  validateNewPassword,
  validatePasswordConfirmation,
  validateRequired
} from '@/helpers/auth/validation'

const router = useRouter()
const { showToast } = useToast()
const loading = ref(false)
const form = ref({
  currentPassword: '',
  newPassword: '',
  passwordConfirmation: ''
})
// Lưu lỗi FE riêng cho mật khẩu hiện tại, mật khẩu mới và xác nhận.
const errors = ref({ currentPassword: '', newPassword: '', passwordConfirmation: '' })

// Chỉ yêu cầu có mật khẩu hiện tại; việc xác minh mật khẩu do BE thực hiện.
const validateCurrentPasswordField = () => {
  errors.value.currentPassword = validateRequired(form.value.currentPassword, 'mật khẩu hiện tại')
  return !errors.value.currentPassword
}

// Kiểm tra mật khẩu mới theo giới hạn của BE.
const validateNewPasswordField = () => {
  errors.value.newPassword = validateNewPassword(form.value.newPassword)
  return !errors.value.newPassword
}

// Kiểm tra trường xác nhận trùng với mật khẩu mới.
const validateConfirmationField = () => {
  errors.value.passwordConfirmation = validatePasswordConfirmation(
    form.value.newPassword,
    form.value.passwordConfirmation
  )
  return !errors.value.passwordConfirmation
}

// api service tự gắn Bearer token; BE xác thực phiên và mật khẩu hiện tại.
const changePassword = async () => {
  const isCurrentPasswordValid = validateCurrentPasswordField()
  const isNewPasswordValid = validateNewPasswordField()
  const isConfirmationValid = validateConfirmationField()
  if (!isCurrentPasswordValid || !isNewPasswordValid || !isConfirmationValid) return

  try {
    loading.value = true
    const response = await api.post('?action=api-change-password', {
      current_password: form.value.currentPassword,
      new_password: form.value.newPassword
    })
    showToast(response.data?.message || 'Đổi mật khẩu thành công.', 'success')
    router.push('/profile')
  } catch (error) {
    showToast(error.response?.data?.message || 'Không thể đổi mật khẩu.', 'danger')
    console.error('Change password failed:', error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthPage title="Đổi mật khẩu" description="Xác nhận mật khẩu hiện tại trước khi cập nhật.">
    <!-- Form đổi mật khẩu giữ nguyên Bearer auth và payload API. -->
    <form class="auth-form" novalidate @submit.prevent="changePassword">
      <div class="auth-field">
        <label for="current-password">Mật khẩu hiện tại</label>
        <input
          id="current-password"
          v-model="form.currentPassword"
          type="password"
          class="auth-input"
          autocomplete="current-password"
          :aria-invalid="Boolean(errors.currentPassword)"
          :aria-describedby="errors.currentPassword ? 'current-password-error' : undefined"
          @blur="validateCurrentPasswordField"
          @input="errors.currentPassword && validateCurrentPasswordField()"
          required
        />
        <p v-if="errors.currentPassword" id="current-password-error" class="auth-field-error" aria-live="polite">
          {{ errors.currentPassword }}
        </p>
      </div>

      <div class="auth-field">
        <label for="new-password">Mật khẩu mới</label>
        <input
          id="new-password"
          v-model="form.newPassword"
          type="password"
          class="auth-input"
          autocomplete="new-password"
          :aria-invalid="Boolean(errors.newPassword)"
          :aria-describedby="errors.newPassword ? 'new-password-error' : undefined"
          @blur="validateNewPasswordField"
          @input="errors.newPassword && validateNewPasswordField()"
          required
        />
        <p v-if="errors.newPassword" id="new-password-error" class="auth-field-error" aria-live="polite">
          {{ errors.newPassword }}
        </p>
      </div>

      <div class="auth-field">
        <label for="password-confirmation">Xác nhận mật khẩu mới</label>
        <input
          id="password-confirmation"
          v-model="form.passwordConfirmation"
          type="password"
          class="auth-input"
          autocomplete="new-password"
          :aria-invalid="Boolean(errors.passwordConfirmation)"
          :aria-describedby="errors.passwordConfirmation ? 'password-confirmation-error' : undefined"
          @blur="validateConfirmationField"
          @input="errors.passwordConfirmation && validateConfirmationField()"
          required
        />
        <p v-if="errors.passwordConfirmation" id="password-confirmation-error" class="auth-field-error" aria-live="polite">
          {{ errors.passwordConfirmation }}
        </p>
      </div>

      <button type="submit" class="auth-submit" :disabled="loading">
        {{ loading ? 'Đang cập nhật...' : 'Cập nhật mật khẩu' }}
      </button>
    </form>

    <template #footer>
      <p><router-link class="auth-inline-link" to="/profile">Quay lại hồ sơ</router-link></p>
    </template>
  </AuthPage>
</template>