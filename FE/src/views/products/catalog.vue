<script setup>
// Trang Catalog gọi api-products và api-categories, giữ bộ lọc/phân trang trong URL.
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import ProductCardCatalog from '@/components/ProductCardCatalog.vue'
import PaginationBar from '@/components/PaginationBar.vue'

const route = useRoute()
const router = useRouter()

// Danh sách sản phẩm hiển thị trên trang hiện tại.
const products = ref([])
// Danh mục API dùng để lọc sản phẩm.
const categories = ref([])
// Tách trạng thái tải, lỗi sản phẩm và lỗi danh mục để không nhầm lỗi với danh sách rỗng.
const loading = ref(true)
const errorMessage = ref('')
const categoryError = ref('')
// Lưu dữ liệu đang nhập trong toolbar; chỉ gửi khi bấm nút tìm kiếm.
const searchInput = ref('')
const categorySlug = ref('')
// Thông tin phân trang do api-products trả về.
const pagination = ref({
    current_page: 1,
    per_page: 8,
    total_pages: 1,
    total_items: 0,
})
// Mã request giúp kết quả tìm kiếm cũ không ghi đè kết quả mới khi đổi bộ lọc nhanh.
let latestProductsRequest = 0

// Lấy danh mục cho select; lỗi được hiển thị riêng và không làm mất danh sách sản phẩm.
async function fetchCategories() {
    categoryError.value = ''

    try {
        // api-categories trả status và mảng categories; lỗi HTTP lấy message từ BE.
        const response = await api.get('/?action=api-categories')

        if (response.data?.status !== 'success' || !Array.isArray(response.data.categories)) {
            throw new Error('Dữ liệu danh mục không hợp lệ.')
        }

        categories.value = response.data.categories
    } catch (error) {
        categoryError.value = error.response?.data?.message || error.message || 'Không tải được danh mục.'
    }
}

// Lấy một trang sản phẩm theo query trên URL và hiện lỗi thay vì giả thành danh sách rỗng.
async function fetchProducts() {
    const requestId = ++latestProductsRequest
    loading.value = true
    errorMessage.value = ''

    try {
        // api-products trả products.data và products.pagination theo contract hiện có.
        const response = await api.get('/?action=api-products', {
            params: {
                search: route.query.search || '',
                category: route.query.category || '',
                page: route.query.page || 1,
                per_page: route.query.per_page || 8,
            },
        })

        const productResult = response.data?.products
        if (
            response.data?.status !== 'success'
            || !Array.isArray(productResult?.data)
            || !productResult.pagination
        ) {
            throw new Error('Dữ liệu sản phẩm không hợp lệ.')
        }

        if (requestId === latestProductsRequest) {
            products.value = productResult.data
            pagination.value = productResult.pagination
        }
    } catch (error) {
        if (requestId === latestProductsRequest) {
            errorMessage.value = error.response?.data?.message || error.message || 'Không thể tải danh sách sản phẩm.'
        }
    } finally {
        // Chỉ request mới nhất được tắt loading, tránh request cũ làm nháy trạng thái.
        if (requestId === latestProductsRequest) loading.value = false
    }
}

// Áp dụng từ khóa/danh mục vào URL; đổi bộ lọc luôn quay về trang đầu.
function applyFilters() {
    router.push({
        name: 'catalog',
        query: {
            search: searchInput.value || undefined,
            category: categorySlug.value || undefined,
            page: 1,
        },
    })
}

// Xóa bộ lọc rồi để watcher nạp lại danh sách trang đầu.
function clearFilters() {
    searchInput.value = ''
    categorySlug.value = ''
    router.push({ name: 'catalog', query: { page: 1 } })
}

// Chuyển trang nhưng vẫn giữ nguyên các bộ lọc hiện tại trên URL.
function changePage(page) {
    router.push({
        name: 'catalog',
        query: {
            ...route.query,
            page,
        },
    })
}

// Đồng bộ input từ URL và tải lại dữ liệu khi tìm kiếm, danh mục hoặc trang đổi.
watch(
    () => route.query,
    () => {
        searchInput.value = typeof route.query.search === 'string' ? route.query.search : ''
        categorySlug.value = typeof route.query.category === 'string' ? route.query.category : ''
        fetchProducts()
    },
    { immediate: true },
)

// Danh mục chỉ cần tải một lần khi Catalog được mở.
onMounted(() => fetchCategories())
</script>

<template>
    <section class="catalog-page" aria-labelledby="catalog-title">
        <!-- Tiêu đề trang: dòng phụ màu đồng + tiêu đề serif theo Stitch. -->
        <header class="catalog-heading">
            <div class="catalog-heading-copy">
                <p class="catalog-eyebrow">FuLe BaberShop</p>
                <h1 id="catalog-title">Danh sách sản phẩm</h1>
            </div>
        </header>

        <!-- Bố cục 2 cột: sidebar bộ lọc bên trái, danh sách sản phẩm bên phải. -->
        <div class="catalog-layout">
            <!-- Sidebar bộ lọc: tìm kiếm + danh mục; bấm "Tìm kiếm" mới gửi lên URL. -->
            <aside class="catalog-sidebar">
                <form class="catalog-filter" @submit.prevent="applyFilters">
                    <div class="catalog-filter-head">
                        <h2>Bộ lọc</h2>
                    </div>

                    <div class="catalog-filter-group">
                        <label class="catalog-filter-label" for="catalog-search">Tìm kiếm sản phẩm</label>
                        <input
                            id="catalog-search"
                            v-model="searchInput"
                            class="catalog-control"
                            type="search"
                            placeholder="Tìm kiếm sản phẩm..."
                        >
                    </div>

                    <div class="catalog-filter-group">
                        <label class="catalog-filter-label" for="catalog-category">Lọc theo danh mục</label>
                        <select id="catalog-category" v-model="categorySlug" class="catalog-control" :disabled="categories.length === 0">
                            <option value="">Tất cả danh mục</option>
                            <option v-for="category in categories" :key="category.id" :value="category.slug">
                                {{ category.name }}
                            </option>
                        </select>
                        <p v-if="categoryError" class="catalog-category-error" role="status">{{ categoryError }}</p>
                    </div>

                    <div class="catalog-filter-actions">
                        <button class="catalog-submit" type="submit">Tìm kiếm</button>
                        <button
                            v-if="searchInput || categorySlug"
                            class="catalog-outline-button"
                            type="button"
                            @click="clearFilters"
                        >
                            Xóa bộ lọc
                        </button>
                    </div>
                </form>

            </aside>

            <!-- Cột phải: lưới sản phẩm + phân trang. -->
            <div class="catalog-results">

                <!-- Khi đang tải, placeholder giữ nguyên tỷ lệ của card trong thiết kế. -->
                <div v-if="loading" class="catalog-grid" role="status" aria-label="Đang tải sản phẩm" aria-busy="true">
                    <div v-for="placeholderIndex in 8" :key="placeholderIndex" class="catalog-skeleton" aria-hidden="true"></div>
                </div>

                <!-- Lỗi API được tách khỏi trạng thái không có sản phẩm và có thể thử lại. -->
                <div v-else-if="errorMessage" class="catalog-state catalog-state--error" role="alert">
                    <p>{{ errorMessage }}</p>
                    <button class="catalog-outline-button" type="button" @click="fetchProducts">Thử lại</button>
                </div>

                <!-- Empty state thay grid/pagination và cho phép xóa bộ lọc. -->
                <div v-else-if="products.length === 0" class="catalog-state" role="status">
                    <p>Không tìm thấy sản phẩm phù hợp.</p>
                    <button class="catalog-outline-button" type="button" @click="clearFilters">Xóa bộ lọc</button>
                </div>

                <template v-else>
                    <!-- Grid responsive: 3 cột desktop, 2 cột tablet và mobile. -->
                    <div class="catalog-grid" :aria-busy="loading">
                        <ProductCardCatalog v-for="product in products" :key="product.id" :product="product" />
                    </div>

                    <!-- Phân trang dùng chung; đổi trang vẫn giữ nguyên bộ lọc trên URL. -->
                    <PaginationBar
                        label="Phân trang sản phẩm"
                        :current-page="Number(pagination.current_page)"
                        :total-pages="Number(pagination.total_pages)"
                        @change="changePage"
                    />
                </template>
            </div>
        </div>
    </section>
</template>

<style scoped>
/* Trang danh sách sản phẩm theo Stitch: nền kem, sidebar trắng, lưới card trắng, nhấn đồng. */
.catalog-page {
    display: grid;
    gap: 24px;
}

.catalog-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
    padding-bottom: 24px;
    border-bottom: 1px solid #e6e0d4;
}

.catalog-heading-copy {
    max-width: 640px;
}

.catalog-eyebrow {
    margin: 0 0 6px;
    color: #b8893e;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}

.catalog-heading h1 {
    margin: 0 0 10px;
    color: #131e2a;
    font-family: var(--font-display);
    font-size: clamp(28px, 4vw, 40px);
    font-weight: 600;
    line-height: 1.15;
    text-transform: uppercase;
}

/* Lưới 2 cột: sidebar cố định 280px, cột phải co giãn. */
.catalog-layout {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    align-items: start;
    gap: 24px;
}

.catalog-sidebar {
    position: sticky;
    top: 96px;
    display: grid;
    gap: 16px;
}

.catalog-filter {
    display: grid;
    gap: 18px;
    padding: 20px;
    border: 1px solid #e6e0d4;
    border-radius: 8px;
    background: #ffffff;
    box-shadow: 0 2px 8px rgba(28, 42, 58, 0.06);
}

.catalog-filter-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 12px;
    border-bottom: 1px solid #e6e0d4;
}

.catalog-filter-head h2 {
    margin: 0;
    color: #131e2a;
    font-family: var(--font-display);
    font-size: 16px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.catalog-filter-group {
    display: grid;
    gap: 8px;
}

.catalog-filter-label {
    color: #6b6560;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.catalog-control {
    width: 100%;
    min-height: 44px;
    padding: 10px 12px;
    border: 1px solid #e6e0d4;
    border-radius: 6px;
    background: #ffffff;
    color: #131e2a;
    font-size: 14px;
    outline: none;
    transition: border-color 140ms ease, box-shadow 140ms ease;
}

.catalog-control:focus {
    border-color: #b8893e;
    box-shadow: 0 0 0 3px rgb(184 137 62 / 18%);
}

.catalog-control:disabled {
    background: #f7f4ee;
    color: #8a8f98;
}

.catalog-category-error {
    margin: 0;
    color: #93000a;
    font-size: 12px;
}

.catalog-filter-actions {
    display: grid;
    gap: 8px;
}

.catalog-submit,
.catalog-outline-button {
    min-height: 44px;
    padding: 0 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    transition: background-color 140ms ease, color 140ms ease;
}

/* Nút chính màu đồng, nút phụ viền navy như Stitch. */
.catalog-submit {
    border: 1px solid #b8893e;
    background: #b8893e;
    color: #ffffff;
}

.catalog-submit:hover {
    border-color: #9e7432;
    background: #9e7432;
}

.catalog-outline-button {
    border: 1px solid #1c2a3a;
    background: transparent;
    color: #1c2a3a;
}

.catalog-outline-button:hover {
    background: #1c2a3a;
    color: #ffffff;
}

.catalog-submit:focus-visible,
.catalog-outline-button:focus-visible {
    outline: 3px solid #b8893e;
    outline-offset: 2px;
}

.catalog-results {
    display: grid;
    min-width: 0;
    gap: 16px;
}

.catalog-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
}

/* Skeleton: khối nhấp nháy nhẹ giữ chỗ cho card khi đang tải. */
.catalog-skeleton {
    aspect-ratio: 4 / 6;
    border: 1px solid #e6e0d4;
    border-radius: 8px;
    background: linear-gradient(100deg, #ffffff 30%, #efeae0 45%, #ffffff 60%);
    background-size: 200% 100%;
    animation: catalog-shimmer 1.4s linear infinite;
}

@keyframes catalog-shimmer {
    to {
        background-position: -200% 0;
    }
}

.catalog-state {
    display: grid;
    min-height: 260px;
    place-content: center;
    justify-items: center;
    gap: 12px;
    padding: 32px;
    border: 1px solid #e6e0d4;
    border-radius: 8px;
    background: #ffffff;
    color: #6b6560;
    text-align: center;
}

.catalog-state p {
    margin: 0;
}

.catalog-state--error {
    border-color: #ba1a1a;
    background: #fdf1f0;
    color: #93000a;
}

@media (max-width: 1199.98px) {
    .catalog-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* Tablet/mobile: sidebar lên trên, không dính khi cuộn. */
@media (max-width: 991.98px) {
    .catalog-layout {
        grid-template-columns: minmax(0, 1fr);
    }

    .catalog-sidebar {
        position: static;
    }

}

@media (max-width: 575.98px) {
    .catalog-grid {
        gap: 12px;
    }

    .catalog-heading {
        padding-bottom: 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .catalog-skeleton {
        animation: none;
    }
}
</style>
