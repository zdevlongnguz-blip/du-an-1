<script setup>
// Trang quản trị: form TẠO sản phẩm (/admin/products/new) và SỬA sản phẩm (/admin/products/:id/edit).
// API: api-categories (ô danh mục), api-admin-product-detail (tải dữ liệu khi sửa),
//      api-admin-product-create / api-admin-product-update (lưu, gửi FormData để kèm được ảnh đại diện).
// Giá, giá khuyến mãi, tồn kho trên form là của BIẾN THỂ MẶC ĐỊNH (theo contract BE hiện tại).
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { showToast } from '@/helpers/ui/toast'
import { resolveImageUrl } from '@/helpers/media/imageUrl'
import { IMAGE_ACCEPT, validateImageFile } from '@/helpers/media/imageUpload'
import { SLUG_PATTERN, toSlug } from '@/helpers/format/slug'
import StateBlock from '@/components/StateBlock.vue'
import AdminVariantManager from '@/components/admin/AdminVariantManager.vue'
import AdminProductImages from '@/components/admin/AdminProductImages.vue'

const route = useRoute()
const router = useRouter()


// computed: ID sản phẩm khi đang SỬA (lấy từ URL), null khi đang TẠO MỚI.
const productId = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEdit = computed(() => productId.value !== null)

// Giá trị các ô của form. reactive: object "phản ứng", sửa thuộc tính nào thì ô đó tự cập nhật.
const form = reactive({
  category_id: '',
  name: '',
  slug: '',
  code: '',
  description: '',
  price: '',
  sale_price: '',
  stock: '',
})
// Lỗi hiện ngay dưới từng ô (rỗng = không lỗi).
const fieldErrors = reactive({ category_id: '', name: '', slug: '', code: '', price: '', sale_price: '', stock: '', image: '' })

// true khi admin đã tự sửa ô slug; từ đó thôi tự điền slug theo tên.
const slugTouched = ref(false)
// Ảnh mới admin chọn (File) và link xem trước; ảnh hiện tại của sản phẩm khi sửa.
const imageFile = ref(null)
const imagePreview = ref('')
const currentImage = ref('')

const categories = ref([])
const categoryError = ref('')
// Trạng thái tải dữ liệu sản phẩm khi sửa (dùng StateBlock).
const loading = ref(false)
const loadError = ref('')
// true trong lúc đang gửi form, khóa nút Lưu để tránh gửi lặp.
const saving = ref(false)

// watch: đang tạo mới và admin chưa tự sửa slug thì slug đi theo tên sản phẩm.
watch(() => form.name, (name) => {
  if (!slugTouched.value) form.slug = toSlug(name)
})

// Admin gõ vào ô slug: đánh dấu đã tự sửa, và xóa lỗi của ô.
function onSlugInput() {
  slugTouched.value = true
  fieldErrors.slug = ''
}

// Tải danh mục cho ô chọn (api-categories trả { categories: [{ id, name, slug }] }).
async function loadCategories() {
  try {
    const response = await api.get('/?action=api-categories')
    categories.value = response.data?.categories ?? []
  } catch (error) {
    categoryError.value = 'Không tải được danh mục. Tải lại trang để thử lại.'
  }
}

// Khi SỬA: tải chi tiết sản phẩm và đổ vào form.
// api-admin-product-detail trả { product: { ...thông tin chung, variants: [...], images: [...] } }.
async function loadProduct() {
  loading.value = true
  loadError.value = ''
  try {
    const response = await api.get('/?action=api-admin-product-detail', { params: { id: productId.value } })
    const product = response.data?.product
    const defaultVariant = product.variants.find((variant) => variant.is_default) ?? product.variants[0] ?? {}
    form.category_id = product.category_id ?? ''
    form.name = product.name ?? ''
    form.slug = product.slug ?? ''
    form.code = product.code ?? ''
    form.description = product.description ?? ''
    form.price = defaultVariant.price ?? ''
    form.sale_price = defaultVariant.sale_price ?? ''
    form.stock = defaultVariant.stock ?? ''
    currentImage.value = product.image ?? ''
    // Đang sửa: giữ slug cũ, không tự đổi theo tên (đổi slug làm hỏng link cũ đã chia sẻ).
    slugTouched.value = true
  } catch (error) {
    loadError.value = error.response?.status === 404
      ? 'Không tìm thấy sản phẩm.'
      : (error.response?.data?.message || 'Không tải được sản phẩm.')
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên tắt loading ở đây.
    loading.value = false
  }
}

// Bỏ ảnh mới đang chọn (nếu có) và giải phóng link xem trước, quay về hiển thị ảnh hiện tại của sản phẩm.
function clearSelectedImage() {
  imageFile.value = null
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
  imagePreview.value = ''
}

// Admin chọn ảnh: kiểm tra loại và dung lượng (helper dùng chung) trước khi gửi, rồi tạo link xem trước.
// File không hợp lệ thì BỎ LUÔN ảnh đã chọn trước đó: ô chọn file đã trống, không được âm thầm gửi ảnh cũ
// (nếu không, bấm Lưu vẫn thay ảnh đại diện bằng ảnh admin tưởng đã bỏ).
function onImageChange(event) {
  fieldErrors.image = ''
  const file = event.target.files?.[0] ?? null
  if (!file) {
    clearSelectedImage()
    return
  }
  const error = validateImageFile(file)
  if (error) {
    fieldErrors.image = error
    event.target.value = ''
    clearSelectedImage()
    return
  }
  clearSelectedImage()
  imageFile.value = file
  // URL.createObjectURL: tạo link tạm trỏ tới file trong máy để xem trước, chưa tải lên server.
  imagePreview.value = URL.createObjectURL(file)
}

// Rời trang: giải phóng link xem trước, tránh giữ file ảnh trong bộ nhớ trình duyệt.
onBeforeUnmount(clearSelectedImage)

// Kiểm tra form phía FE (BE vẫn kiểm tra lại). Trả true nếu hợp lệ; lỗi hiện dưới từng ô.
function validate() {
  for (const key of Object.keys(fieldErrors)) if (key !== 'image') fieldErrors[key] = ''
  if (!form.category_id) fieldErrors.category_id = 'Chọn danh mục.'
  if (!form.name.trim()) fieldErrors.name = 'Nhập tên sản phẩm.'
  else if (form.name.trim().length > 255) fieldErrors.name = 'Tên tối đa 255 ký tự.'
  if (!SLUG_PATTERN.test(form.slug)) fieldErrors.slug = 'Slug chỉ gồm chữ thường không dấu, số và dấu "-" (ví dụ: sap-vuot-toc).'
  if (!form.code.trim()) fieldErrors.code = 'Nhập mã sản phẩm.'
  else if (form.code.trim().length > 100) fieldErrors.code = 'Mã tối đa 100 ký tự.'

  // Giá / kho chỉ có ở form TẠO MỚI (tạo biến thể mặc định). Khi SỬA, giá / kho quản lý ở khối Biến thể.
  if (!isEdit.value) {
    const price = Number(form.price)
    if (form.price === '' || !Number.isFinite(price) || price < 0) fieldErrors.price = 'Giá phải là số không âm.'
    if (form.sale_price !== '' && form.sale_price !== null) {
      const salePrice = Number(form.sale_price)
      if (!Number.isFinite(salePrice) || salePrice < 0) fieldErrors.sale_price = 'Giá khuyến mãi phải là số không âm.'
      else if (!fieldErrors.price && salePrice >= price) fieldErrors.sale_price = 'Giá khuyến mãi phải thấp hơn giá gốc.'
    }
    const stock = Number(form.stock)
    if (form.stock === '' || !Number.isInteger(stock) || stock < 0) fieldErrors.stock = 'Tồn kho phải là số nguyên không âm.'
  }

  return Object.values(fieldErrors).every((message) => !message)
}

// Bấm Lưu: kiểm tra rồi gửi lên BE.
// Gửi FormData (multipart, POST) cho cả tạo và sửa: BE nhận được cả hai, và kèm được file ảnh.
async function submit() {
  if (saving.value || !validate()) return
  saving.value = true
  try {
    const body = new FormData()
    body.append('category_id', String(form.category_id))
    body.append('name', form.name.trim())
    body.append('slug', form.slug)
    body.append('code', form.code.trim())
    body.append('description', form.description)
    // Chỉ gửi giá / kho khi TẠO MỚI. Khi SỬA không gửi, để không ghi đè giá / kho admin vừa chỉnh ở khối Biến thể.
    if (!isEdit.value) {
      body.append('price', String(form.price))
      // Rỗng = không khuyến mãi (BE đổi chuỗi rỗng thành null).
      body.append('sale_price', form.sale_price === null ? '' : String(form.sale_price))
      body.append('stock', String(form.stock))
    }
    if (imageFile.value) body.append('image', imageFile.value)

    // Tạo: api-admin-product-create trả 201. Sửa: api-admin-product-update&id= trả 200.
    // Lỗi: 422 dữ liệu sai, 409 trùng mã/slug, 404 không thấy sản phẩm (khi sửa); 401/403 do quyền.
    //
    // QUAN TRỌNG: client.js đặt mặc định Content-Type: application/json. Với header đó, axios tự đổi
    // FormData thành JSON và file ảnh bị mất (BE nhận "image": []). Vì vậy ghi đè header thành
    // multipart/form-data cho riêng request này (không sửa client.js); trình duyệt tự thêm "boundary".
    const uploadConfig = { headers: { 'Content-Type': 'multipart/form-data' } }
    const response = isEdit.value
      ? await api.post(`/?action=api-admin-product-update&id=${productId.value}`, body, uploadConfig)
      : await api.post('/?action=api-admin-product-create', body, uploadConfig)
    showToast(response.data?.message || 'Đã lưu sản phẩm.', 'success')
    router.push({ name: 'admin-products' })
  } catch (error) {
    // Lỗi từ BE hiện bằng toast chung (mục 6 FE/CLAUDE.md), form giữ nguyên để admin sửa lại.
    showToast(error.response?.data?.message || 'Không lưu được sản phẩm.', 'danger')
  } finally {
    saving.value = false
  }
}

// onMounted: tải danh mục; nếu đang sửa thì tải thêm dữ liệu sản phẩm.
onMounted(() => {
  loadCategories()
  if (isEdit.value) loadProduct()
})
</script>

<template>
  <section class="admin-form-page" aria-labelledby="admin-form-title">
    <!-- Tiêu đề + quay lại danh sách -->
    <header class="admin-page-head">
      <RouterLink class="admin-back" :to="{ name: 'admin-products' }">← Danh sách sản phẩm</RouterLink>
      <h1 id="admin-form-title">{{ isEdit ? 'Sửa sản phẩm' : 'Thêm sản phẩm' }}</h1>
    </header>

    <!-- Khi sửa: StateBlock lo đang tải / lỗi (404 không có "Thử lại"). Khi tạo mới: hiện form ngay. -->
    <StateBlock
      :loading="loading"
      :error="loadError"
      :on-retry="loadError && loadError !== 'Không tìm thấy sản phẩm.' ? loadProduct : null"
      loading-text="Đang tải sản phẩm..."
    >
      <form class="admin-form" novalidate @submit.prevent="submit">
        <!-- Cột trái: thông tin chung -->
        <div class="admin-card">
          <h2>Thông tin chung</h2>

          <label class="admin-field">
            <span>Tên sản phẩm *</span>
            <input v-model="form.name" type="text" maxlength="255" @input="fieldErrors.name = ''" />
            <small v-if="fieldErrors.name" class="admin-error">{{ fieldErrors.name }}</small>
          </label>

          <div class="admin-row">
            <label class="admin-field">
              <span>Slug (đường dẫn) *</span>
              <input v-model="form.slug" type="text" maxlength="255" @input="onSlugInput" />
              <small v-if="fieldErrors.slug" class="admin-error">{{ fieldErrors.slug }}</small>
              <small v-else class="admin-hint">/product-detail/{{ form.slug || '...' }}</small>
            </label>
            <label class="admin-field">
              <span>Mã sản phẩm *</span>
              <input v-model="form.code" type="text" maxlength="100" @input="fieldErrors.code = ''" />
              <small v-if="fieldErrors.code" class="admin-error">{{ fieldErrors.code }}</small>
            </label>
          </div>

          <label class="admin-field">
            <span>Danh mục *</span>
            <select v-model="form.category_id" @change="fieldErrors.category_id = ''">
              <option value="" disabled>Chọn danh mục</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
            <small v-if="fieldErrors.category_id" class="admin-error">{{ fieldErrors.category_id }}</small>
            <small v-if="categoryError" class="admin-error">{{ categoryError }}</small>
          </label>

          <label class="admin-field">
            <span>Mô tả</span>
            <textarea v-model="form.description" rows="6"></textarea>
          </label>
        </div>

        <!-- Cột phải: giá, kho (biến thể mặc định) và ảnh đại diện -->
        <div class="admin-side">
          <!-- Giá và tồn kho: chỉ khi TẠO MỚI (thành biến thể mặc định). Khi sửa, quản lý ở khối "Biến thể" bên dưới. -->
          <div v-if="!isEdit" class="admin-card">
            <h2>Giá và tồn kho</h2>
            <p class="admin-note">Giá và tồn kho này tạo thành <strong>biến thể mặc định</strong>. Sau khi tạo, vào "Sửa" để thêm biến thể khác và ảnh slide.</p>
            <label class="admin-field">
              <span>Giá gốc (đ) *</span>
              <input v-model="form.price" type="number" min="0" step="1000" inputmode="numeric" @input="fieldErrors.price = ''" />
              <small v-if="fieldErrors.price" class="admin-error">{{ fieldErrors.price }}</small>
            </label>
            <label class="admin-field">
              <span>Giá khuyến mãi (đ)</span>
              <input v-model="form.sale_price" type="number" min="0" step="1000" inputmode="numeric" placeholder="Để trống nếu không giảm" @input="fieldErrors.sale_price = ''" />
              <small v-if="fieldErrors.sale_price" class="admin-error">{{ fieldErrors.sale_price }}</small>
            </label>
            <label class="admin-field">
              <span>Tồn kho *</span>
              <input v-model="form.stock" type="number" min="0" step="1" inputmode="numeric" @input="fieldErrors.stock = ''" />
              <small v-if="fieldErrors.stock" class="admin-error">{{ fieldErrors.stock }}</small>
            </label>
          </div>

          <div class="admin-card">
            <h2>Ảnh đại diện</h2>
            <!-- Xem trước: ảnh mới chọn, nếu chưa chọn thì ảnh hiện tại của sản phẩm -->
            <img v-if="imagePreview || currentImage" class="admin-preview" :src="imagePreview || resolveImageUrl(currentImage)" alt="Ảnh đại diện" />
            <label class="admin-field">
              <span>{{ currentImage ? 'Đổi ảnh' : 'Chọn ảnh' }} (jpg, png, gif, webp, tối đa 2MB)</span>
              <input type="file" :accept="IMAGE_ACCEPT" @change="onImageChange" />
              <small v-if="fieldErrors.image" class="admin-error">{{ fieldErrors.image }}</small>
            </label>
          </div>

          <!-- Nút lưu: khóa trong lúc gửi -->
          <div class="admin-actions">
            <RouterLink class="admin-btn admin-btn--outline" :to="{ name: 'admin-products' }">Hủy</RouterLink>
            <button type="submit" class="admin-btn admin-btn--primary" :disabled="saving">
              {{ saving ? 'Đang lưu...' : (isEdit ? 'Lưu thông tin chung' : 'Tạo sản phẩm') }}
            </button>
          </div>
        </div>
      </form>

      <!-- Khi SỬA: quản lý biến thể và ảnh slide. Mỗi khối tự lưu ngay khi thao tác (không cần bấm "Lưu thông tin chung"),
           và tự tải dữ liệu riêng nên không ghi đè các ô admin đang gõ dở ở form trên. -->
      <div v-if="isEdit" class="admin-extra">
        <AdminVariantManager :product-id="productId" />
        <AdminProductImages :product-id="productId" />
      </div>
    </StateBlock>
  </section>
</template>

<style scoped>
.admin-back {
  color: #9e7432;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
}

/* Rê chuột vào link quay lại thì gạch chân, giống nút thao tác ở trang danh sách sản phẩm. */
.admin-back:hover {
  text-decoration: underline;
}

.admin-page-head h1 {
  margin: 6px 0 20px;
  color: #131e2a;
  font-family: var(--font-display);
  font-size: 28px;
}

.admin-form {
  display: grid;
  gap: 16px;
  grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
  align-items: start;
}

.admin-side {
  display: grid;
  gap: 16px;
}

.admin-card {
  display: grid;
  gap: 14px;
  padding: 20px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
}

.admin-card h2 {
  margin: 0;
  color: #1c2a3a;
  font-size: 16px;
  font-weight: 700;
}

/* align-items: start: ô có dòng gợi ý bên dưới (slug) không kéo giãn ô bên cạnh (mã sản phẩm). */
.admin-row {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  align-items: start;
}

.admin-field {
  display: grid;
  gap: 6px;
  font-size: 14px;
}

.admin-field > span {
  color: #4a5565;
  font-weight: 600;
}

.admin-field input,
.admin-field select,
.admin-field textarea {
  width: 100%;
  min-height: 40px;
  padding: 8px 12px;
  border: 1px solid #d9d4ca;
  border-radius: 6px;
  background: #ffffff;
  color: #1c2a3a;
  font: inherit;
}

.admin-field input[type='file'] {
  padding: 6px;
}

.admin-field input:focus-visible,
.admin-field select:focus-visible,
.admin-field textarea:focus-visible {
  border-color: #b8893e;
  outline: 3px solid rgb(184 137 62 / 25%);
}

.admin-error {
  color: #ba1a1a;
  font-size: 13px;
}

.admin-hint {
  color: #6b6560;
  font-size: 12px;
  overflow-wrap: anywhere;
}

.admin-note {
  margin: 0;
  padding: 10px 12px;
  border-radius: 6px;
  background: #fef3d6;
  color: #8a5a0a;
  font-size: 13px;
}

.admin-preview {
  width: 100%;
  max-height: 240px;
  border-radius: 6px;
  object-fit: contain;
  background: #f7f4ee;
}

.admin-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

/* Khối biến thể + ảnh slide nằm dưới form, chiếm toàn chiều ngang. */
.admin-extra {
  display: grid;
  gap: 16px;
  margin-top: 16px;
}

.admin-btn {
  display: inline-flex;
  min-height: 42px;
  align-items: center;
  justify-content: center;
  padding: 8px 18px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}

.admin-btn--primary {
  border: 1px solid #b8893e;
  background: #b8893e;
  color: #ffffff;
}

.admin-btn--primary:hover:not(:disabled) {
  border-color: #9e7432;
  background: #9e7432;
}

.admin-btn--primary:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.admin-btn--outline {
  border: 1px solid #1c2a3a;
  background: #ffffff;
  color: #1c2a3a;
}

.admin-btn:focus-visible,
.admin-back:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

@media (max-width: 991.98px) {
  .admin-form {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (max-width: 575.98px) {
  .admin-row {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
