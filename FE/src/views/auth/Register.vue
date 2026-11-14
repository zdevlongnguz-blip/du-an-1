<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { useToast } from '@/helpers/ui/toast'
import AuthPage from '@/components/AuthPage.vue'
import {
	validateEmail,
	validateNewPassword,
	validatePasswordConfirmation
} from '@/helpers/auth/validation'

const router = useRouter()
const { showToast } = useToast()
const loading = ref(false)
const form = ref({
	email: '',
	password: '',
	passwordConfirmation: ''
})
// Lưu lỗi FE riêng cho email, mật khẩu và trường xác nhận.
const errors = ref({ email: '', password: '', passwordConfirmation: '' })

// Kiểm tra email và trả lỗi ngay dưới trường email.
const validateEmailField = () => {
	errors.value.email = validateEmail(form.value.email)
	return !errors.value.email
}

// Kiểm tra chính sách mật khẩu giống giới hạn của BE.
const validatePasswordField = () => {
	errors.value.password = validateNewPassword(form.value.password, 'mật khẩu')
	return !errors.value.password
}

// So khớp mật khẩu xác nhận với mật khẩu chính.
const validateConfirmationField = () => {
	errors.value.passwordConfirmation = validatePasswordConfirmation(
		form.value.password,
		form.value.passwordConfirmation
	)
	return !errors.value.passwordConfirmation
}

const register = async () => {
	const isEmailValid = validateEmailField()
	const isPasswordValid = validatePasswordField()
	const isConfirmationValid = validateConfirmationField()
	if (!isEmailValid || !isPasswordValid || !isConfirmationValid) return

	try {
		loading.value = true
		const response = await api.post('?action=api-register', {
			email: form.value.email,
			password: form.value.password
		})

		showToast(response.data?.message || 'Đăng ký thành công. Vui lòng xác minh email.', 'success')
		router.push('/login')
	} catch (error) {
		showToast(error.response?.data?.message || 'Đăng ký thất bại', 'danger')
		console.error('Register failed:', error)
	} finally {
		loading.value = false
	}
}
</script>

<template>
	<AuthPage
		title="Tạo tài khoản"
		description="Tạo tài khoản FuLe BaberShop bằng email và mật khẩu."
		variant="framed"
	>
		<!-- Dữ liệu gửi BE vẫn chỉ gồm email và mật khẩu. -->
		<form class="auth-form" novalidate @submit.prevent="register">
			<div class="auth-field">
				<label for="email">Email</label>
				<input
					id="email"
					v-model="form.email"
					type="email"
					class="auth-input"
					autocomplete="email"
					:aria-invalid="Boolean(errors.email)"
					:aria-describedby="errors.email ? 'register-email-error' : undefined"
					@blur="validateEmailField"
					@input="errors.email && validateEmailField()"
					required
				/>
				<p v-if="errors.email" id="register-email-error" class="auth-field-error" aria-live="polite">
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
					autocomplete="new-password"
					:aria-invalid="Boolean(errors.password)"
					:aria-describedby="errors.password ? 'register-password-error' : undefined"
					@blur="validatePasswordField"
					@input="errors.password && validatePasswordField()"
					required
				/>
				<p v-if="errors.password" id="register-password-error" class="auth-field-error" aria-live="polite">
					{{ errors.password }}
				</p>
			</div>

			<div class="auth-field">
				<label for="password-confirmation">Xác nhận mật khẩu</label>
				<input
					id="password-confirmation"
					v-model="form.passwordConfirmation"
					type="password"
					class="auth-input"
					autocomplete="new-password"
					:aria-invalid="Boolean(errors.passwordConfirmation)"
					:aria-describedby="errors.passwordConfirmation ? 'register-confirmation-error' : undefined"
					@blur="validateConfirmationField"
					@input="errors.passwordConfirmation && validateConfirmationField()"
					required
				/>
				<p v-if="errors.passwordConfirmation" id="register-confirmation-error" class="auth-field-error" aria-live="polite">
					{{ errors.passwordConfirmation }}
				</p>
			</div>

			<button type="submit" class="auth-submit" :disabled="loading">
				{{ loading ? 'Đang đăng ký...' : 'Tạo tài khoản' }}
			</button>
		</form>

		<template #footer>
			<p>Đã có tài khoản?<router-link class="auth-inline-link" to="/login">Đăng nhập</router-link></p>
		</template>
	</AuthPage>
</template>
