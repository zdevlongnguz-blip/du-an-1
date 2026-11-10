<script setup>
// Trang Catalog gọi api-products và api-categories, giữ bộ lọc/phân trang trong URL.
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import ProductCardCatalog from '@/components/ProductCardCatalog.vue'

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
        <header class="catalog-heading">
            <p class="catalog-eyebrow">FuLe BaberShop</p>
            <h1 id="catalog-title">Danh sách sản phẩm</h1>
        </header>

        <!-- Toolbar tìm kiếm, chọn danh mục và xóa bộ lọc đang dùng. -->
        <form class="catalog-toolbar" @submit.prevent="applyFilters">
            <div class="catalog-toolbar-search">
                <label class="visually-hidden" for="catalog-search">Tìm kiếm sản phẩm</label>
                <input
                    id="catalog-search"
                    v-model="searchInput"
                    class="catalog-control"
                    type="search"
                    placeholder="Tìm kiếm sản phẩm..."
                >
            </div>

            <div class="catalog-toolbar-category">
                <label class="visually-hidden" for="catalog-category">Lọc theo danh mục</label>
                <select id="catalog-category" v-model="categorySlug" class="catalog-control" :disabled="categories.length === 0">
                    <option value="">Tất cả danh mục</option>
                    <option v-for="category in categories" :key="category.id" :value="category.slug">
                        {{ category.name }}
                    </option>
                </select>
            </div>

            <div class="catalog-toolbar-actions">
                <button class="catalog-submit" type="submit">Tìm kiếm</button>
                <button
                    v-if="searchInput || categorySlug"
                    class="catalog-outline-button catalog-clear-button"
                    type="button"
                    @click="clearFilters"
                >
                    Xóa bộ lọc
                </button>
            </div>
        </form>

        <p v-if="categoryError" class="catalog-category-error" role="status">{{ categoryError }}</p>

        <!-- Khi đang tải, placeholder giữ nguyên tỷ lệ 3:4 của card trong thiết kế. -->
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
            <!-- Grid responsive: 4 cột desktop, 3 tablet, 2 mobile. -->
            <div class="catalog-grid" :aria-busy="loading">
                <ProductCardCatalog v-for="product in products" :key="product.id" :product="product" />
            </div>

            <!-- Pagination dùng chung; ở mobile hai nút chia đều hai bên chỉ báo trang. -->
            <nav class="catalog-pagination" aria-label="Phân trang sản phẩm">
                <button
                    class="catalog-outline-button catalog-page-button"
                    type="button"
                    :disabled="pagination.current_page <= 1"
                    @click="changePage(Number(pagination.current_page) - 1)"
                >
                    Trang trước
                </button>
                <span class="catalog-page-indicator">{{ pagination.current_page }} / {{ pagination.total_pages }}</span>
                <button
                    class="catalog-outline-button catalog-page-button"
                    type="button"
                    :disabled="pagination.current_page >= pagination.total_pages"
                    @click="changePage(Number(pagination.current_page) + 1)"
                >
                    Trang sau
                </button>
            </nav>
        </template>
    </section>
</template>

<style scoped>
.catalog-page {
    width: 100%;
    padding: 40px 24px 60px;
    background: #ffffff;
}

.catalog-heading {
    margin-bottom: 24px;
}

.catalog-eyebrow {
    margin: 0 0 6px;
    color: #6c757d;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.catalog-heading h1 {
    margin: 0;
    color: #212529;
    font-size: 24px;
    font-weight: 700;
    line-height: 1.25;
}

.catalog-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 8px;
    margin-bottom: 24px;
}

.catalog-control {
    width: 100%;
    height: 44px;
    padding: 0 12px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    background: #ffffff;
    color: #212529;
    font: inherit;
    font-size: 14px;
}

.catalog-control::placeholder {
    color: #6c757d;
    opacity: 1;
}

.catalog-control:focus-visible {
    border-color: #212529;
    outline: 2px solid rgb(33 37 41 / 14%);
    outline-offset: 1px;
}

.catalog-control:disabled {
    background: #f8f9fa;
    color: #6c757d;
}

.catalog-toolbar-actions {
    display: flex;
    gap: 8px;
}

.catalog-toolbar-actions > button {
    flex: 1;
}

.catalog-submit,
.catalog-outline-button {
    min-height: 44px;
    padding: 0 16px;
    border: 1px solid #212529;
    border-radius: 4px;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    transition: background-color 160ms ease, color 160ms ease, border-color 160ms ease;
}

.catalog-submit {
    background: #212529;
    color: #ffffff;
}

.catalog-submit:hover {
    border-color: #44474a;
    background: #44474a;
}

.catalog-outline-button {
    background: transparent;
    color: #212529;
}

.catalog-outline-button:hover:not(:disabled) {
    background: #f3f4f5;
}

.catalog-submit:focus-visible,
.catalog-outline-button:focus-visible {
    outline: 3px solid rgb(33 37 41 / 20%);
    outline-offset: 2px;
}

.catalog-outline-button:disabled {
    border-color: #e9ecef;
    background: #f8f9fa;
    color: #adb5bd;
    cursor: not-allowed;
}

.catalog-category-error {
    margin: -12px 0 16px;
    color: #6c757d;
    font-size: 12px;
}

.catalog-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.catalog-skeleton {
    aspect-ratio: 3 / 4;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    background: linear-gradient(100deg, #f8f9fa 30%, #e9ecef 45%, #f8f9fa 60%);
    background-size: 220% 100%;
    animation: catalog-skeleton-shimmer 1.4s ease-in-out infinite;
}

.catalog-state {
    display: flex;
    min-height: 260px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin: 64px 0;
    color: #6c757d;
    text-align: center;
}

.catalog-state p {
    margin: 0;
    font-size: 15px;
}

.catalog-state--error {
    color: #a42d2d;
}

.catalog-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin-top: 40px;
}

.catalog-page-indicator {
    flex-shrink: 0;
    color: #212529;
    font-size: 14px;
    font-weight: 500;
}

@keyframes catalog-skeleton-shimmer {
    to {
        background-position-x: -220%;
    }
}

@media (min-width: 768px) {
    .catalog-page {
        padding: 32px 24px 48px;
    }

    .catalog-toolbar {
        grid-template-columns: repeat(12, minmax(0, 1fr));
        column-gap: 16px;
        row-gap: 16px;
    }

    .catalog-toolbar-search {
        grid-column: 1 / -1;
    }

    .catalog-toolbar-category {
        grid-column: span 8;
    }

    .catalog-toolbar-actions {
        grid-column: span 4;
    }

    .catalog-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (min-width: 1200px) {
    .catalog-page {
        padding: 40px 24px 60px;
    }

    .catalog-toolbar {
        column-gap: 24px;
        row-gap: 0;
    }

    .catalog-toolbar-search {
        grid-column: span 5;
    }

    .catalog-toolbar-category {
        grid-column: span 3;
    }

    .catalog-toolbar-actions {
        grid-column: span 2;
    }

    .catalog-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 24px;
    }
}

@media (max-width: 767.98px) {
    .catalog-page {
        padding: 24px 16px 40px;
    }

    .catalog-heading h1 {
        font-size: 22px;
    }

    .catalog-pagination {
        width: 100%;
        justify-content: space-between;
        gap: 8px;
    }

    .catalog-page-button {
        flex: 1;
        padding: 0 8px;
    }

    .catalog-page-indicator {
        padding: 0 8px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .catalog-skeleton {
        animation: none;
    }
}
</style>