<script setup>
import { getUser, clearAuthSession } from '@/helpers/auth/session' // import hàm getUser và clearAuth từ file auth.js
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/helpers/ui/toast'
import api from '@/helpers/api/client'
import AuthPage from '@/components/AuthPage.vue'

const user = getUser() || {}
const router = useRouter()
const {showToast} = useToast()
const loading = ref(false)

// JWT stateless nên luôn xóa session cục bộ, kể cả khi request logout thất bại.
const handleLogout = async () => {
    if(!confirm('Bạn có chắc chắn muốn đăng xuất không?')) return
    try{
        loading.value = true
        const response = await api.post('?action=api-logout')
        showToast(response.data?.message || 'Đăng xuất thành công', 'success')
    }catch(err){
        console.error('Error during logout:', err)
		showToast('Có lỗi xảy ra khi đăng xuất', 'danger')
    }finally{
        loading.value = false
        clearAuthSession()
        router.push('/')
    }
}

</script>

<template>
	<AuthPage title="Hồ sơ của bạn" description="Thông tin tài khoản FuLe BaberShop.">
		<!-- Thông tin tài khoản đang có trong session FE. -->
		<dl class="profile-details">
			<div class="profile-row">
				<dt>ID</dt>
				<dd>{{ user.id || '-' }}</dd>
			</div>
			<div class="profile-row">
				<dt>Email</dt>
				<dd>{{ user.email || '-' }}</dd>
			</div>
			<div class="profile-row">
				<dt>Vai trò</dt>
				<dd>{{ user.role || '-' }}</dd>
			</div>
		</dl>

		<!-- Giữ thao tác đổi mật khẩu và đăng xuất hiện có. -->
		<div class="profile-actions">
			<router-link to="/change-password" class="auth-submit">Đổi mật khẩu</router-link>
			<!-- Lối tắt tới trang Đơn hàng của tôi (danh sách đơn, thanh toán, hủy đơn). -->
			<router-link to="/orders" class="auth-secondary-action">Đơn hàng của tôi</router-link>
			<button
				type="button"
				class="auth-secondary-action auth-danger-action"
				:disabled="loading"
				@click="handleLogout"
			>
				{{ loading ? 'Đang xử lý...' : 'Đăng xuất' }}
			</button>
		</div>

		<template #footer>
			<p><router-link class="auth-inline-link" to="/">Về trang chủ</router-link></p>
		</template>
	</AuthPage>
</template>
