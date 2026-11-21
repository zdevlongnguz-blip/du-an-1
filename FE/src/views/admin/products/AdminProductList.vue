<script setup>
// Trang quản trị: danh sách sản phẩm dạng bảng (/admin/products).
// Gọi api-products (cùng API với catalog: tìm kiếm, lọc danh mục, phân trang) và api-categories (ô lọc danh mục).
// Tìm kiếm / danh mục / trang được ghi lên URL (?search=&category=&page=) để F5 hoặc gửi link vẫn giữ nguyên.
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { showToast } from '@/helpers/ui/toast'
import { formatPrice } from '@/helpers/format/price'
import { resolveImageUrl } from '@/helpers/media/imageUrl'
import AdminTable from '@/components/admin/AdminTable.vue'
import PaginationBar from '@/components/PaginationBar.vue'

const route = useRoute()
const router = useRouter()

// Số sản phẩm mỗi trang của bảng admin.
const PER_PAGE = 10

// Cột của bảng. Cột có slot #cell-<key> bên dưới thì tự vẽ (ảnh, tên, giá...), còn lại in thẳng giá trị.
const columns = [
  { key: 'image', label: 'Ảnh', width: '72px' },
  { key: 'code', label: 'Mã' },
  { key: 'name', label: 'Tên sản phẩm' },
  { key: 'category_name', label: 'Danh mục' },
  { key: 'price', label: 'Giá bán', align: 'right' },
  { key: 'stock', label: 'Tồn kho', align: 'right' },
  { key: 'variant_count', label: 'Biến thể', align: 'center' },
  { key: 'sales_count', label: 'Đã bán', align: 'right' },
  { key: 'actions', label: 'Thao tác', align: 'center' },
]

// Dữ liệu bảng và trạng thái tải.
const products = ref([])
const loading = ref(false)
const errorMessage = ref('')
const pagination = ref({ current_page: 1, total_pages: 1, total_items: 0 })

// Danh mục cho ô lọc. Lỗi tải danh mục chỉ báo nhỏ, không chặn bảng sản phẩm.
const categories = ref([])
const categoryError = ref('')

// Ô tìm kiếm đang gõ (chỉ áp dụng khi bấm Tìm / Enter, tránh gọi API mỗi lần gõ phím).
const searchInput = ref('')

// computed: bộ lọc hiện tại đọc từ URL, là nguồn duy nhất để gọi API.
const filters = computed(() => ({
  search: typeof route.query.search === 'string' ? route.query.search : '',
  category: typeof route.query.category === 'string' ? route.query.category : '',
  page: Math.max(1, Number.parseInt(route.query.page, 10) || 1),
}))

// Mã của lần tải mới nhất. Đổi bộ lọc liên tục thì chỉ nhận kết quả của lần cuối,
// tránh kết quả cũ về sau ghi đè kết quả mới (race condition).
let latestRequestId = 0

// Tải danh sách sản phẩm theo bộ lọc trên URL. Gọi khi mở trang và mỗi khi URL đổi.
async function loadProducts() {
  const requestId = ++latestRequestId
  loading.value = true
  errorMessage.value = ''
  try {
    // api-products trả { products: { data: [...], pagination: { current_page, per_page, total_items, total_pages } } }.
    const response = await api.get('/?action=api-products', {
      params: {
        search: filters.value.search || undefined,
        category: filters.value.category || undefined,
        page: filters.value.page,
        per_page: PER_PAGE,
      },
    })
    if (requestId !== latestRequestId) return
    products.value = response.data?.products?.data ?? []
    pagination.value = response.data?.products?.pagination ?? pagination.value
  } catch (error) {
    if (requestId !== latestRequestId) return
    // Hiện lỗi thật (kèm "Thử lại") thay vì bảng rỗng, để không nhầm với "chưa có sản phẩm".
    products.value = []
    errorMessage.value = error.response?.data?.message || 'Không tải được danh sách sản phẩm.'
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên tắt loading ở đây (chỉ với lần tải mới nhất).
    if (requestId === latestRequestId) loading.value = false
  }
}

// Tải danh mục cho ô lọc (api-categories trả { categories: [{ id, name, slug }] }).
async function loadCategories() {
  try {
    const response = await api.get('/?action=api-categories')
    categories.value = response.data?.categories ?? []
  } catch (error) {
    categoryError.value = 'Không tải được danh mục để lọc.'
  }
}

// Ghi bộ lọc mới lên URL; watch bên dưới sẽ tự tải lại. Đổi tìm kiếm / danh mục thì về trang 1.
function updateQuery(next) {
  const query = { ...filters.value, ...next }
  router.push({
    query: {
      search: query.search || undefined,
      category: query.category || undefined,
      page: query.page > 1 ? String(query.page) : undefined,
    },
  })
}

// Bấm "Tìm" hoặc Enter trong ô tìm kiếm.
function submitSearch() {
  updateQuery({ search: searchInput.value.trim(), page: 1 })
}

// Đổi danh mục trong ô lọc.
function changeCategory(event) {
  updateQuery({ category: event.target.value, page: 1 })
}

// Xóa toàn bộ bộ lọc, về trang 1.
function clearFilters() {
  searchInput.value = ''
  router.push({ query: {} })
}

// Khoảng giá bán của sản phẩm (giá thấp nhất - cao nhất giữa các biến thể đang bán).
function priceText(product) {
  const min = product.min_final_price
  const max = product.max_final_price
  if (min === null || min === undefined) return '—'
  if (max !== null && Number(max) !== Number(min)) return `${formatPrice(Number(min))} – ${formatPrice(Number(max))}`
  return formatPrice(Number(min))
}

// ID sản phẩm đang xóa (khóa nút Xóa của dòng đó trong lúc chờ); null = không xóa gì.
const deletingId = ref(null)

// Bấm "Xóa": hỏi xác nhận rồi gọi DELETE api-admin-product-delete&id=.
// KHÓA NGHIỆP VỤ ở BE: chỉ xóa được sản phẩm CHƯA có trong đơn hàng nào (xóa hẳn cùng biến thể và ảnh).
// Sản phẩm đã có đơn: BE trả 409 kèm lý do (hiện bằng toast), sản phẩm giữ nguyên; muốn ngừng bán thì vào
// "Xem / Sửa" và tắt bán biến thể. Xóa xong tải lại trang hiện tại; nếu trang đó hết dòng thì lùi về trang trước.
async function deleteProduct(product) {
  if (deletingId.value !== null) return
  const ok = window.confirm(
    `Xóa hẳn sản phẩm "${product.name}"?\n`
    + 'Chỉ xóa được sản phẩm chưa có trong đơn hàng nào. Các biến thể và ảnh cũng bị xóa, không khôi phục được.\n'
    + 'Sản phẩm đã có đơn hàng sẽ bị từ chối; khi đó hãy vào "Xem / Sửa" để tắt bán biến thể.'
  )
  if (!ok) return

  deletingId.value = product.id
  try {
    const response = await api.delete('/?action=api-admin-product-delete', { params: { id: product.id } })
    showToast(response.data?.message || 'Đã xóa sản phẩm.', 'success')
    if (products.value.length === 1 && filters.value.page > 1) {
      updateQuery({ page: filters.value.page - 1 })
    } else {
      await loadProducts()
    }
  } catch (error) {
    // 404: sản phẩm đã bị xóa ở nơi khác -> tải lại để bảng khớp với DB.
    showToast(error.response?.data?.message || 'Không xóa được sản phẩm.', 'danger')
    if (error.response?.status === 404) await loadProducts()
  } finally {
    deletingId.value = null
  }
}

// computed: đang có bộ lọc nào không (để hiện nút "Xóa bộ lọc" và chữ trạng thái rỗng phù hợp).
const hasFilter = computed(() => Boolean(filters.value.search || filters.value.category))

// watch: URL đổi (tìm kiếm, danh mục, trang, nút Back của trình duyệt) thì đồng bộ ô tìm kiếm và tải lại.
watch(() => route.query, () => {
  searchInput.value = filters.value.search
  loadProducts()
})

// onMounted: lần đầu mở trang thì tải danh mục và sản phẩm theo URL hiện tại.
onMounted(() => {
  searchInput.value = filters.value.search
  loadCategories()
  loadProducts()
})
</script>

<template>
  <section class="admin-products" aria-labelledby="admin-products-title">
    <!-- Tiêu đề + tổng số sản phẩm (theo bộ lọc hiện tại) + nút thêm mới -->
    <header class="admin-page-head">
      <div>
        <h1 id="admin-products-title">Sản phẩm</h1>
        <p>{{ pagination.total_items }} sản phẩm{{ hasFilter ? ' khớp bộ lọc' : '' }}</p>
      </div>
      <RouterLink class="admin-btn admin-btn--primary" :to="{ name: 'admin-product-new' }">+ Thêm sản phẩm</RouterLink>
    </header>

    <!-- Thanh lọc: tìm theo tên / mã / mô tả, lọc theo danh mục, xóa bộ lọc -->
    <form class="admin-filters" role="search" @submit.prevent="submitSearch">
      <input
        v-model="searchInput"
        type="search"
        class="admin-input"
        placeholder="Tìm theo tên, mã, mô tả..."
        aria-label="Tìm sản phẩm"
      />
      <select class="admin-input" :value="filters.category" aria-label="Lọc theo danh mục" @change="changeCategory">
        <option value="">Tất cả danh mục</option>
        <option v-for="category in categories" :key="category.id" :value="category.slug">{{ category.name }}</option>
      </select>
      <button type="submit" class="admin-btn admin-btn--primary">Tìm</button>
      <button v-if="hasFilter" type="button" class="admin-btn admin-btn--outline" @click="clearFilters">Xóa bộ lọc</button>
    </form>
    <p v-if="categoryError" class="admin-note">{{ categoryError }}</p>

    <!-- Bảng sản phẩm (AdminTable dùng chung): StateBlock lo đang tải / lỗi có "Thử lại" / rỗng -->
    <AdminTable
      :columns="columns"
      :rows="products"
      row-key="id"
      caption="Danh sách sản phẩm"
      :loading="loading"
      :error="errorMessage"
      :on-retry="loadProducts"
      loading-text="Đang tải sản phẩm..."
      :empty-text="hasFilter ? 'Không có sản phẩm nào khớp bộ lọc.' : 'Chưa có sản phẩm nào.'"
    >
      <!-- Ảnh đại diện; chưa có ảnh thì hiện chữ cái đầu -->
      <template #cell-image="{ row }">
        <img v-if="row.image" class="admin-thumb" :src="resolveImageUrl(row.image)" :alt="row.name" loading="lazy" />
        <span v-else class="admin-thumb admin-thumb--empty" aria-hidden="true">{{ row.name.charAt(0) }}</span>
      </template>
      <template #cell-code="{ row }">
        <code class="admin-code">{{ row.code }}</code>
      </template>
      <!-- Tên + slug (đường dẫn trang chi tiết) -->
      <template #cell-name="{ row }">
        <strong class="admin-name">{{ row.name }}</strong>
        <small class="admin-slug">/{{ row.slug }}</small>
      </template>
      <template #cell-category_name="{ row }">{{ row.category_name || '—' }}</template>
      <template #cell-price="{ row }"><span class="admin-price">{{ priceText(row) }}</span></template>
      <!-- Tồn kho: 0 thì tô đỏ để admin thấy ngay món hết hàng -->
      <template #cell-stock="{ row }">
        <span :class="{ 'admin-out': Number(row.stock) <= 0 }">{{ Number(row.stock) <= 0 ? 'Hết hàng' : row.stock }}</span>
      </template>
      <!-- Thao tác: "Xem / Sửa" (một nút, mở form quản trị: thông tin chung, biến thể, ảnh slide), Xóa (hỏi xác nhận).
           Không dẫn sang trang chi tiết bên shop: trang đó không có đường quay về khu quản trị. -->
      <template #cell-actions="{ row }">
        <div class="admin-actions">
          <RouterLink class="admin-link" :to="{ name: 'admin-product-edit', params: { id: row.id } }">Xem / Sửa</RouterLink>
          <button
            type="button"
            class="admin-link admin-link--danger"
            :disabled="deletingId !== null"
            @click="deleteProduct(row)"
          >{{ deletingId === row.id ? 'Đang xóa...' : 'Xóa' }}</button>
        </div>
      </template>
    </AdminTable>

    <!-- Phân trang (component có sẵn), chỉ hiện khi nhiều hơn 1 trang -->
    <PaginationBar
      v-if="!loading && !errorMessage && pagination.total_pages > 1"
      class="admin-pagination"
      :current-page="pagination.current_page"
      :total-pages="pagination.total_pages"
      label="Phân trang sản phẩm"
      @change="(page) => updateQuery({ page })"
    />
  </section>
</template>

<style scoped>
.admin-page-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.admin-page-head .admin-btn {
  display: inline-flex;
  align-items: center;
  text-decoration: none;
}

.admin-actions {
  display: inline-flex;
  gap: 12px;
  white-space: nowrap;
}

.admin-link--danger {
  padding: 0;
  border: 0;
  background: none;
  color: #ba1a1a;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.admin-link--danger:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.admin-page-head h1 {
  margin: 0 0 4px;
  color: #131e2a;
  font-family: var(--font-display);
  font-size: 28px;
}

.admin-page-head p {
  margin: 0 0 20px;
  color: #6b6560;
}

.admin-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 16px;
}

.admin-input {
  min-height: 40px;
  padding: 8px 12px;
  border: 1px solid #d9d4ca;
  border-radius: 6px;
  background: #ffffff;
  color: #1c2a3a;
  font-size: 14px;
}

.admin-filters input {
  flex: 1 1 260px;
}

.admin-input:focus-visible {
  border-color: #b8893e;
  outline: 3px solid rgb(184 137 62 / 25%);
}

.admin-btn {
  min-height: 40px;
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.admin-btn--primary {
  border: 1px solid #b8893e;
  background: #b8893e;
  color: #ffffff;
}

.admin-btn--primary:hover {
  border-color: #9e7432;
  background: #9e7432;
}

.admin-btn--outline {
  border: 1px solid #1c2a3a;
  background: #ffffff;
  color: #1c2a3a;
}

.admin-btn:focus-visible,
.admin-link:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

.admin-note {
  margin: -6px 0 12px;
  color: #93000a;
  font-size: 13px;
}

.admin-thumb {
  display: inline-flex;
  width: 48px;
  height: 48px;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  object-fit: cover;
}

.admin-thumb--empty {
  background: #efeae0;
  color: #6b6560;
  font-weight: 700;
}

/* Mã sản phẩm dài (POMADE-REUZEL-BLUE-340G): chữ nhỏ, chỉ xuống dòng tại dấu gạch nối cho dễ đọc. */
.admin-code {
  color: #4a5565;
  font-size: 12px;
  overflow-wrap: anywhere;
}

/* Khoảng giá giữ trên một dòng mỗi số, tránh "đ" bị tách khỏi con số. */
.admin-price {
  white-space: nowrap;
}

.admin-name {
  display: block;
  color: #1c2a3a;
}

.admin-slug {
  color: #6b6560;
  font-size: 12px;
}

.admin-out {
  color: #ba1a1a;
  font-weight: 600;
}

.admin-link {
  color: #9e7432;
  font-weight: 600;
  text-decoration: none;
}

/* :not(:disabled): nút Xóa đang khóa (đang xóa dòng khác) thì không gạch chân, tránh trông như bấm được. */
.admin-link:hover:not(:disabled) {
  text-decoration: underline;
}

.admin-pagination {
  margin-top: 16px;
}
</style>
