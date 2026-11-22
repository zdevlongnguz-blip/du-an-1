<script setup>
// Trang hồ sơ: hiển thị và cho sửa họ tên, SĐT, địa chỉ ngay tại trang.
// Gọi API api-profile (lấy hồ sơ) và api-profile-update (lưu hồ sơ) qua helper profile.js; giữ nút đổi mật khẩu, đơn hàng, đăng xuất.
import { getUser, clearAuthSession } from '@/helpers/auth/session' // import hàm getUser và clearAuth từ file auth.js
import {
    fetchProfile,
    updateProfile,
    validateProfileName,
    validateProfilePhone,
    validateProfileAddress
} from '@/helpers/auth/profile'
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/helpers/ui/toast'
import { pushToReturnUrl } from '@/helpers/auth/returnUrl'
import api from '@/helpers/api/client'
import AuthPage from '@/components/AuthPage.vue'

// Hồ sơ đang hiển thị. ref: biến "phản ứng", khi giá trị đổi thì giao diện tự cập nhật.
// Lấy trước từ localStorage để trang có dữ liệu ngay, sau đó thay bằng dữ liệu mới từ server.
const user = ref(getUser() || {})
const router = useRouter()
const {showToast} = useToast()
const loading = ref(false)

// true khi đang chờ server trả hồ sơ, dùng để hiện "Đang tải hồ sơ..." và khóa nút sửa.
const profileLoading = ref(false)
// Lỗi khi tải hồ sơ; rỗng = không lỗi. Tách riêng để lỗi không bị hiểu thành "chưa cập nhật".
const profileError = ref('')
// true khi đang ở chế độ sửa (hiện form thay cho phần hiển thị họ tên, SĐT, địa chỉ).
const isEditing = ref(false)
// true khi đang gửi request lưu, dùng để khóa nút Lưu/Hủy tránh gửi lặp.
const saving = ref(false)
// Dữ liệu các ô input; v-model gắn 2 chiều: gõ vào ô thì biến đổi theo và ngược lại.
const form = ref({ name: '', phone: '', address: '' })
// Lỗi FE riêng cho từng ô, hiện ngay dưới ô tương ứng.
const errors = ref({ name: '', phone: '', address: '' })

// Tải hồ sơ mới nhất từ server. Gọi khi trang mở và khi bấm "Thử lại".
const loadProfile = async () => {
    profileLoading.value = true
    profileError.value = ''
    try {
        // GET api-profile: trả { status, user: { id, email, role, name, phone, address } }; helper tự lưu vào localStorage.
        user.value = await fetchProfile()
    } catch (err) {
        // Giữ dữ liệu cũ đang hiển thị nhưng báo rõ là chưa tải được bản mới.
        profileError.value = err.response?.data?.message || 'Không tải được hồ sơ, vui lòng thử lại.'
        console.error('Load profile failed:', err)
    } finally {
        // finally luôn chạy dù thành công hay lỗi, nên tắt trạng thái tải ở đây.
        profileLoading.value = false
    }
}

// Mở form sửa, điền sẵn giá trị hiện tại (null thành chuỗi rỗng để ô input hiển thị trống).
const startEditing = () => {
    form.value = {
        name: user.value.name ?? '',
        phone: user.value.phone ?? '',
        address: user.value.address ?? ''
    }
    errors.value = { name: '', phone: '', address: '' }
    isEditing.value = true
}

// Đóng form, bỏ các thay đổi chưa lưu.
const cancelEditing = () => {
    isEditing.value = false
    errors.value = { name: '', phone: '', address: '' }
}

// Các hàm validate từng ô; gọi khi rời ô (blur), khi gõ lại sau lỗi, và trước khi lưu.
const validateNameField = () => {
    errors.value.name = validateProfileName(form.value.name)
    return !errors.value.name
}

const validatePhoneField = () => {
    errors.value.phone = validateProfilePhone(form.value.phone)
    return !errors.value.phone
}

const validateAddressField = () => {
    errors.value.address = validateProfileAddress(form.value.address)
    return !errors.value.address
}

// Lưu hồ sơ khi submit form. Validate FE trước, rồi gọi PUT api-profile-update.
const saveProfile = async () => {
    // Gọi đủ 3 hàm (không dùng &&) để hiện lỗi của mọi ô cùng lúc.
    const isNameValid = validateNameField()
    const isPhoneValid = validatePhoneField()
    const isAddressValid = validateAddressField()
    if (!isNameValid || !isPhoneValid || !isAddressValid) return

    try {
        saving.value = true
        // PUT api-profile-update: trả { status, message, user }; lỗi 400/422 có message để hiện toast.
        const result = await updateProfile(form.value)
        user.value = result.user
        isEditing.value = false
        showToast(result.message || 'Cập nhật hồ sơ thành công', 'success')
    } catch (err) {
        showToast(err.response?.data?.message || 'Không thể cập nhật hồ sơ.', 'danger')
        console.error('Update profile failed:', err)
    } finally {
        saving.value = false
    }
}

// onMounted: chạy 1 lần khi trang vừa hiện ra, lúc này mới gọi API lấy hồ sơ.
onMounted(() => loadProfile())

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
        // Quay về trang khách đang xem trước khi vào Hồ sơ (navbar đã lưu bằng saveReturnUrl).
        // Phải gọi SAU clearAuthSession: helper kiểm tra "đã đăng xuất chưa" để không quay lại trang cần đăng nhập
        // (giỏ hàng, đơn hàng...); khi đó về trang chủ.
        pushToReturnUrl(router, '/')
    }
}

</script>

<template>
	<AuthPage title="Hồ sơ của bạn" description="Thông tin tài khoản FuLe BaberShop.">
		<!-- Trạng thái tải hồ sơ: đang tải hoặc lỗi kèm nút thử lại. -->
		<div v-if="profileLoading" class="auth-status profile-status" aria-live="polite">
			<p>Đang tải hồ sơ...</p>
		</div>
		<div v-else-if="profileError" class="profile-load-error" role="alert">
			<p>{{ profileError }}</p>
			<button type="button" class="auth-secondary-action" @click="loadProfile">Thử lại</button>
		</div>

		<!-- Cụm 1: thông tin tài khoản (ID, email, vai trò), chỉ xem, không sửa được ở đây. -->
		<section class="profile-section" aria-labelledby="profile-account-title">
			<h2 id="profile-account-title" class="profile-section-title">Thông tin tài khoản</h2>
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
		</section>

		<!-- Cụm 2: thông tin cá nhân (họ tên, SĐT, địa chỉ), xem hoặc sửa ngay tại chỗ. -->
		<section class="profile-section" aria-labelledby="profile-personal-title">
			<div class="profile-section-head">
				<h2 id="profile-personal-title" class="profile-section-title">Thông tin cá nhân</h2>
				<!-- Nút mở form sửa; khóa khi đang tải hoặc tải lỗi để không sửa trên dữ liệu cũ. -->
				<button
					v-if="!isEditing"
					type="button"
					class="auth-secondary-action profile-edit-button"
					:disabled="profileLoading || Boolean(profileError)"
					@click="startEditing"
				>
					Chỉnh sửa
				</button>
			</div>

			<!-- Ghi chú cho user biết thông tin cá nhân được dùng vào việc gì, khuyến khích cập nhật đầy đủ. -->
			<p class="profile-section-note">
				*Thông tin này được dùng để điền sẵn khi đặt hàng, để cửa hàng liên hệ và giao hàng cho bạn,
				và để trợ lý Louis Dé Fule xưng hô đúng tên. Bạn nên cập nhật đầy đủ và chính xác.
			</p>

			<!-- Chế độ xem: trường null hiện "Chưa cập nhật". -->
			<dl v-if="!isEditing" class="profile-details">
				<div class="profile-row">
					<dt>Họ tên</dt>
					<dd :class="{ 'profile-empty': !user.name }">{{ user.name || 'Chưa cập nhật' }}</dd>
				</div>
				<div class="profile-row">
					<dt>Số điện thoại</dt>
					<dd :class="{ 'profile-empty': !user.phone }">{{ user.phone || 'Chưa cập nhật' }}</dd>
				</div>
				<div class="profile-row">
					<dt>Địa chỉ</dt>
					<dd :class="{ 'profile-empty': !user.address }">{{ user.address || 'Chưa cập nhật' }}</dd>
				</div>
			</dl>

		<!-- Form sửa hồ sơ: cả 3 trường không bắt buộc; để trống = xóa thông tin đó. -->
		<form v-else class="auth-form profile-form" novalidate @submit.prevent="saveProfile">
			<div class="auth-field">
				<label for="profile-name">Họ tên</label>
				<input
					id="profile-name"
					v-model="form.name"
					type="text"
					class="auth-input"
					autocomplete="name"
					placeholder="Ví dụ: Nguyễn Văn A"
					:aria-invalid="Boolean(errors.name)"
					:aria-describedby="errors.name ? 'profile-name-error' : undefined"
					@blur="validateNameField"
					@input="errors.name && validateNameField()"
				/>
				<p v-if="errors.name" id="profile-name-error" class="auth-field-error" aria-live="polite">
					{{ errors.name }}
				</p>
			</div>

			<div class="auth-field">
				<label for="profile-phone">Số điện thoại</label>
				<input
					id="profile-phone"
					v-model="form.phone"
					type="tel"
					class="auth-input"
					maxlength="20"
					autocomplete="tel"
					placeholder="Ví dụ: 0901234567"
					:aria-invalid="Boolean(errors.phone)"
					:aria-describedby="errors.phone ? 'profile-phone-error' : undefined"
					@blur="validatePhoneField"
					@input="errors.phone && validatePhoneField()"
				/>
				<p v-if="errors.phone" id="profile-phone-error" class="auth-field-error" aria-live="polite">
					{{ errors.phone }}
				</p>
			</div>

			<div class="auth-field">
				<label for="profile-address">Địa chỉ</label>
				<textarea
					id="profile-address"
					v-model="form.address"
					class="auth-input profile-textarea"
					rows="3"
					autocomplete="street-address"
					placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành phố"
					:aria-invalid="Boolean(errors.address)"
					:aria-describedby="errors.address ? 'profile-address-error' : undefined"
					@blur="validateAddressField"
					@input="errors.address && validateAddressField()"
				></textarea>
				<p v-if="errors.address" id="profile-address-error" class="auth-field-error" aria-live="polite">
					{{ errors.address }}
				</p>
			</div>

			<!-- Nút Lưu (gửi form) và Hủy (bỏ thay đổi); khóa cả hai khi đang lưu. -->
			<div class="profile-form-actions">
				<button type="submit" class="auth-submit" :disabled="saving">
					{{ saving ? 'Đang lưu...' : 'Lưu' }}
				</button>
				<button type="button" class="auth-secondary-action" :disabled="saving" @click="cancelEditing">
					Hủy
				</button>
			</div>
		</form>
		</section>

		<!-- Cụm 3: lối tắt tài khoản; ẩn khi đang sửa hồ sơ để màn hình chỉ còn form và nút Lưu/Hủy.
		     Cả hai nút dùng kiểu viền để nút vàng duy nhất trên trang là nút Lưu của form. -->
		<section v-if="!isEditing" class="profile-section" aria-labelledby="profile-links-title">
			<h2 id="profile-links-title" class="profile-section-title">Tài khoản &amp; đơn hàng</h2>
			<div class="profile-actions">
				<router-link to="/change-password" class="auth-secondary-action">Đổi mật khẩu</router-link>
				<!-- Lối tắt tới trang Đơn hàng của tôi (danh sách đơn, thanh toán, hủy đơn). -->
				<router-link to="/orders" class="auth-secondary-action">Đơn hàng của tôi</router-link>
			</div>
		</section>

		<!-- Cụm 4: đăng xuất tách riêng ở cuối, có đường kẻ phía trên để tránh bấm nhầm; ẩn khi đang sửa hồ sơ. -->
		<div v-if="!isEditing" class="profile-logout">
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

<style scoped>
/* Khoảng cách cho dòng trạng thái tải hồ sơ. */
.profile-status {
	margin-bottom: 16px;
}

/* Hộp báo lỗi tải hồ sơ: màu lỗi theo bảng màu dự án, kèm nút thử lại. */
.profile-load-error {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
	padding: 12px 14px;
	border: 1px solid #ba1a1a;
	border-radius: 6px;
	background: #fdf1f0;
	color: #93000a;
	font-size: 14px;
}

.profile-load-error p {
	margin: 0;
}

/* Trường chưa có dữ liệu: chữ nhạt, không in đậm, để phân biệt với dữ liệu thật. */
.profile-row dd.profile-empty {
	color: #6b6560;
	font-weight: 400;
	font-style: italic;
}

/* Mỗi cụm cách nhau rõ ràng để mắt tách được từng nhóm thông tin/nút. */
.profile-section {
	margin-bottom: 32px;
}

/* Tiêu đề nhỏ của cụm: chữ in hoa, màu đồng đậm, giống nhãn "Cổng khách hàng" phía trên. */
.profile-section-title {
	margin: 0 0 10px;
	color: #9e7432;
	font-family: inherit;
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

/* Hàng tiêu đề cụm "Thông tin cá nhân": tiêu đề bên trái, nút "Chỉnh sửa" nhỏ bên phải. */
.profile-section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 10px;
}

.profile-section-head .profile-section-title {
	margin: 0;
}

/* Ghi chú nhỏ dưới tiêu đề cụm: chữ phụ, nghiêng, không lấn át dữ liệu chính. */
.profile-section-note {
	margin: 0 0 12px;
	color: #6b6560;
	font-size: 13px;
	font-style: italic;
	line-height: 1.5;
}

/* Bảng thông tin trong cụm: bỏ khoảng trống dưới của style chung vì cụm đã có khoảng cách riêng. */
.profile-section .profile-details {
	margin: 0;
}

.profile-edit-button {
	min-height: 34px;
	padding: 6px 14px;
	font-size: 13px;
}

.profile-edit-button:disabled {
	cursor: not-allowed;
	opacity: 0.6;
}

/* Ghi đè bố cục flex-wrap chung (style.css) chỉ trong trang này: 2 nút lối tắt chia đều 2 cột. */
.profile-actions {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 10px;
}

.profile-actions .auth-secondary-action {
	width: 100%;
	min-width: 0;
	min-height: 48px;
}

/* Đăng xuất tách khỏi các cụm trên bằng đường kẻ, nút rộng hết khung. */
.profile-logout {
	padding-top: 24px;
	border-top: 1px solid #e6e0d4;
}

.profile-logout .auth-danger-action {
	width: 100%;
	min-height: 48px;
}

/* Form sửa nằm trong khung nền kem có viền, để nút Lưu/Hủy rõ ràng thuộc về form. */
.profile-form {
	gap: 18px;
	padding: 20px;
	border: 1px solid #d9d4ca;
	border-radius: 8px;
	background: #f7f4ee;
}

/* Ô địa chỉ nhiều dòng: chỉ cho kéo giãn theo chiều dọc để không vỡ bố cục. */
.profile-textarea {
	resize: vertical;
	line-height: 1.5;
}

/* Lưu và Hủy chia 2 cột bằng nhau, cùng chiều cao với các nút khác trong trang. */
.profile-form-actions {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 10px;
}

.profile-form-actions .auth-submit,
.profile-form-actions .auth-secondary-action {
	width: 100%;
	min-width: 0;
	min-height: 48px;
}

/* Điện thoại: các nhóm nút xếp 1 cột để chữ không bị ép. */
@media (max-width: 575.98px) {
	.profile-actions,
	.profile-form-actions {
		grid-template-columns: 1fr;
	}
}
</style>
