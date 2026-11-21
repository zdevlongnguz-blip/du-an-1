<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { clearAuthSession } from '@/helpers/auth/session'
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
    // Đổi mật khẩu thành công thì BE thu hồi MỌI phiên đăng nhập của tài khoản (tăng token_version),
    // kể cả token đang dùng ở máy này. Vì vậy xóa phiên cục bộ rồi đưa về trang đăng nhập, thay vì để
    // request kế tiếp bị 401 và bị đá ra mà người dùng không hiểu vì sao.
    // Sau khi đổi trang, App.vue thấy đã đăng xuất nên khung chat tự xóa hội thoại và đơn nháp trên màn hình.
    clearAuthSession()
    showToast('Đổi mật khẩu thành công. Vui lòng đăng nhập lại bằng mật khẩu mới.', 'success')
    router.push('/login')
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