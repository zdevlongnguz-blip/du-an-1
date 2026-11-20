<script setup>
// Quản lý ảnh slide (album) của một sản phẩm trong form sửa (trang quản trị). Tự tải danh sách ảnh.
// API: api-admin-product-detail (đọc product.images), api-admin-product-image-create (tải lên, multipart),
//      api-admin-product-image-update (đổi sort_order), api-admin-product-image-delete (xóa).
// Trang chi tiết sản phẩm hiện ảnh đại diện trước, rồi đến các ảnh slide theo sort_order tăng dần.
import { onMounted, ref } from 'vue'
import api from '@/helpers/api/client'
import { showToast } from '@/helpers/ui/toast'
import { resolveImageUrl } from '@/helpers/media/imageUrl'
import { IMAGE_ACCEPT, validateImageFile } from '@/helpers/media/imageUpload'
import StateBlock from '@/components/StateBlock.vue'

const props = defineProps({
  // ID sản phẩm đang sửa.
  productId: { type: Number, required: true },
})

const images = ref([])
const loading = ref(false)
const loadError = ref('')
// true khi đang tải lên / đổi thứ tự / xóa, khóa các nút để tránh thao tác chồng nhau.
const busy = ref(false)
const fileError = ref('')
// Tham chiếu tới ô chọn file để xóa lựa chọn sau khi tải lên.
const fileInput = ref(null)

// Tải danh sách ảnh slide (BE đã sắp theo sort_order, id).
async function loadImages() {
  loading.value = true
  loadError.value = ''
  try {
    const response = await api.get('/?action=api-admin-product-detail', { params: { id: props.productId } })
    images.value = response.data?.product?.images ?? []
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Không tải được ảnh slide.'
  } finally {
    loading.value = false
  }
}

// Admin chọn file: kiểm tra loại + dung lượng rồi tải lên ngay, đặt cuối danh sách (sort_order lớn nhất + 1).
// Gửi FormData với header multipart/form-data: client.js mặc định gửi JSON, axios sẽ làm mất file nếu không ghi đè.
async function onFileChange(event) {
  fileError.value = ''
  const file = event.target.files?.[0]
  if (!file) return
  // Kiểm tra loại + dung lượng bằng helper dùng chung (khớp giới hạn của BE).
  const error = validateImageFile(file)
  if (error) { fileError.value = error; event.target.value = ''; return }

  busy.value = true
  try {
    const nextOrder = images.value.reduce((max, image) => Math.max(max, image.sort_order), 0) + 1
    const body = new FormData()
    body.append('product_id', String(props.productId))
    body.append('sort_order', String(Math.min(nextOrder, 10000)))
    body.append('image', file)
    const response = await api.post('/?action=api-admin-product-image-create', body, { headers: { 'Content-Type': 'multipart/form-data' } })
    showToast(response.data?.message || 'Đã thêm ảnh.', 'success')
  } catch (error) {
    showToast(error.response?.data?.message || 'Không tải lên được ảnh.', 'danger')
  } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
    await loadImages()
  }
}

// Đổi chỗ ảnh ở vị trí index với ảnh bên cạnh (direction: -1 lên trước, +1 ra sau).
// Sau khi đổi chỗ, đánh lại sort_order 1, 2, 3... theo thứ tự mới và chỉ gửi những ảnh có số thay đổi
// (thường là 2 ảnh). Cách này cũng sửa luôn trường hợp nhiều ảnh trùng sort_order.
async function move(index, direction) {
  const target = index + direction
  if (busy.value || target < 0 || target >= images.value.length) return
  const reordered = [...images.value]
  ;[reordered[index], reordered[target]] = [reordered[target], reordered[index]]

  busy.value = true
  try {
    const changes = reordered
      .map((image, position) => ({ image, order: position + 1 }))
      .filter(({ image, order }) => image.sort_order !== order)
    for (const { image, order } of changes) {
      await api.put(`/?action=api-admin-product-image-update&id=${image.id}`, { sort_order: order })
    }
  } catch (error) {
    showToast(error.response?.data?.message || 'Không đổi được thứ tự ảnh.', 'danger')
  } finally {
    busy.value = false
    await loadImages()
  }
}

// Xóa ảnh slide (hỏi xác nhận). BE chỉ xóa file khi không còn chỗ nào khác dùng.
async function removeImage(image) {
  if (busy.value || !window.confirm('Xóa ảnh này khỏi slide?')) return
  busy.value = true
  try {
    const response = await api.delete('/?action=api-admin-product-image-delete', { params: { id: image.id } })
    showToast(response.data?.message || 'Đã xóa ảnh.', 'success')
  } catch (error) {
    showToast(error.response?.data?.message || 'Không xóa được ảnh.', 'danger')
  } finally {
    busy.value = false
    await loadImages()
  }
}

onMounted(loadImages)
</script>

<template>
  <section class="admin-card" aria-labelledby="images-title">
    <!-- Tiêu đề + nút tải ảnh lên -->
    <div class="images-head">
      <h2 id="images-title">Ảnh slide ({{ images.length }})</h2>
      <label class="admin-btn admin-btn--primary" :class="{ 'is-disabled': busy }">
        {{ busy ? 'Đang xử lý...' : '+ Tải ảnh lên' }}
        <input ref="fileInput" class="visually-hidden" type="file" :accept="IMAGE_ACCEPT" :disabled="busy" @change="onFileChange" />
      </label>
    </div>
    <p class="admin-hint">Trang sản phẩm hiện ảnh đại diện trước, rồi đến các ảnh dưới đây theo thứ tự. Mỗi ảnh tối đa 2MB.</p>
    <small v-if="fileError" class="admin-error">{{ fileError }}</small>

    <!-- Lưới ảnh slide: StateBlock lo đang tải / lỗi có "Thử lại" / chưa có ảnh -->
    <StateBlock :loading="loading" :error="loadError" :empty="images.length === 0" :on-retry="loadImages" loading-text="Đang tải ảnh..." empty-text="Chưa có ảnh slide.">
      <ol class="images-grid">
        <li v-for="(image, index) in images" :key="image.id" class="images-item">
          <img :src="resolveImageUrl(image.image_path)" :alt="`Ảnh slide ${index + 1}`" loading="lazy" />
          <div class="images-bar">
            <span class="images-order">#{{ index + 1 }}</span>
            <button type="button" :disabled="busy || index === 0" aria-label="Đưa ảnh lên trước" @click="move(index, -1)">↑</button>
            <button type="button" :disabled="busy || index === images.length - 1" aria-label="Đưa ảnh ra sau" @click="move(index, 1)">↓</button>
            <button type="button" class="images-delete" :disabled="busy" @click="removeImage(image)">Xóa</button>
          </div>
        </li>
      </ol>
    </StateBlock>
  </section>
</template>

<style scoped>
.admin-card { display: grid; gap: 14px; padding: 20px; border: 1px solid #e6e0d4; border-radius: 8px; background: #ffffff; }
.images-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
.images-head h2 { margin: 0; color: #1c2a3a; font-size: 16px; font-weight: 700; }
.admin-hint { margin: 0; color: #6b6560; font-size: 13px; }
.admin-error { color: #ba1a1a; font-size: 13px; }
.admin-btn { display: inline-flex; min-height: 38px; align-items: center; padding: 6px 16px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
.admin-btn--primary { border: 1px solid #b8893e; background: #b8893e; color: #ffffff; }
.admin-btn.is-disabled { opacity: 0.6; cursor: not-allowed; }
.admin-btn:focus-within { outline: 3px solid #b8893e; outline-offset: 2px; }
.images-grid { display: grid; gap: 12px; margin: 0; padding: 0; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); list-style: none; }
.images-item { overflow: hidden; border: 1px solid #e6e0d4; border-radius: 8px; background: #f7f4ee; }
.images-item img { display: block; width: 100%; aspect-ratio: 1 / 1; object-fit: cover; }
.images-bar { display: flex; align-items: center; gap: 6px; padding: 6px 8px; background: #ffffff; }
.images-order { margin-right: auto; color: #4a5565; font-size: 12px; font-weight: 700; }
.images-bar button { min-width: 30px; min-height: 30px; padding: 2px 6px; border: 1px solid #d9d4ca; border-radius: 6px; background: #ffffff; color: #1c2a3a; font-size: 13px; cursor: pointer; }
.images-bar button:disabled { opacity: 0.4; cursor: not-allowed; }
.images-bar .images-delete { border-color: #f1c7c7; color: #ba1a1a; }
.images-bar button:focus-visible { outline: 3px solid #b8893e; outline-offset: 1px; }
</style>
