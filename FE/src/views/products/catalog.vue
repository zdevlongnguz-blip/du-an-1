//hoàng đẹp trai

<script setup>
// Import ref và watch từ Vue để quản lý dữ liệu và theo dõi thay đổi
import { ref, watch } from 'vue'

// Import useRoute và useRouter để đọc và thay đổi URL
import { useRoute, useRouter } from 'vue-router'

// Import helper API có sẵn của dự án
import api from '@/services/api'

// Import component thẻ sản phẩm đã tạo
import ProductCard from '@/components/ProductCard.vue'

// Khởi tạo đối tượng quản lý URL hiện tại
const route = useRoute()

// Khởi tạo đối tượng dùng để điều hướng URL
const router = useRouter()

// Danh sách sản phẩm lấy từ Backend
const products = ref([])

// Danh sách danh mục lấy từ Backend
const categories = ref([])

// Trạng thái đang tải dữ liệu
const loading = ref(false)

// Thông báo lỗi nếu không lấy được dữ liệu
const error = ref('')

// Nội dung ô tìm kiếm
const searchInput = ref('')

// Danh mục đang được lựa chọn
const categoryId = ref('')

// Thông tin phân trang
const pagination = ref({
    current_page: 1,
    total_pages: 1,
    total_items: 0
})

// Hàm lấy danh sách danh mục từ Backend
const fetchCategories = async () => {
    try {
        // Gọi API lấy danh sách categories
        const response = await api.get('?action=api-categories')

        // Lưu danh sách danh mục vào biến categories
        categories.value = response.data.categories || []

    } catch (err) {
        // Hiển thị lỗi nếu API không hoạt động
        console.error('Lỗi lấy danh mục:', err)
    }
}

// Bắt đầu tải danh mục ngay, giữ lại promise để chờ khi cần đổi slug sang id
const categoriesReady = fetchCategories()

// Đổi category_slug trên URL (ví dụ link từ banner) sang category_id mà Backend hỗ trợ
const resolveCategoryId = async () => {
    // Ưu tiên category_id nếu URL đã có sẵn
    if (route.query.category_id) {
        return route.query.category_id
    }

    // Không có slug thì không lọc theo danh mục
    if (!route.query.category_slug) {
        return ''
    }

    // Chờ danh mục tải xong rồi tìm danh mục có slug trùng khớp
    await categoriesReady
    const category = categories.value.find((item) => item.slug === route.query.category_slug)

    return category ? String(category.id) : ''
}

// Hàm lấy danh sách sản phẩm từ Backend
const fetchProducts = async () => {
    // Bật trạng thái loading
    loading.value = true

    // Xóa thông báo lỗi cũ
    error.value = ''

    try {
        // Xác định danh mục cần lọc (từ category_id hoặc category_slug)
        const resolvedCategoryId = await resolveCategoryId()

        // Đồng bộ ô chọn danh mục với danh mục đang lọc
        categoryId.value = resolvedCategoryId

        // Đọc các tham số lọc từ URL
        const response = await api.get('?action=api-products', {
            params: {
                search: route.query.search || '',
                category_id: resolvedCategoryId,
                page: route.query.page || 1,
                per_page: route.query.per_page || 8
            }
        })

        // Lấy danh sách sản phẩm từ kết quả API
        products.value = response.data.products.data || []

        // Lấy thông tin phân trang từ Backend
        pagination.value = response.data.products.pagination

    } catch (err) {
        // Hiển thị thông báo khi lấy sản phẩm thất bại
        error.value = 'Không thể tải danh sách sản phẩm.'

        console.error('Lỗi lấy sản phẩm:', err)

    } finally {
        // Tắt trạng thái loading dù thành công hay thất bại
        loading.value = false
    }
}

// Hàm áp dụng bộ lọc và cập nhật URL
const applyFilters = () => {
    router.push({
        name: 'catalog',
        query: {
            search: searchInput.value || undefined,
            category_id: categoryId.value || undefined,
            page: 1
        }
    })
}

// Hàm chuyển trang
const changePage = (page) => {
    router.push({
        name: 'catalog',
        query: {
            ...route.query,
            page: page
        }
    })
}

// Theo dõi URL: khi query thay đổi thì tải lại sản phẩm
watch(
    () => route.query,
    () => {
        searchInput.value = route.query.search || ''
        categoryId.value = route.query.category_id || ''

        fetchProducts()
    },
    { immediate: true }
)

// Theo dõi URL để đồng bộ dữ liệu bộ lọc
watch(
    () => route.query.category_id,
    (newValue) => {
        categoryId.value = newValue || ''
    }
)

</script>

<template>
    <div class="catalog">

        <!-- Tiêu đề trang sản phẩm -->
        <h2>Danh sách sản phẩm</h2>

        <!-- Khu vực tìm kiếm và lọc danh mục -->
        <div class="filter">

            <!-- Ô nhập tên sản phẩm cần tìm -->
            <input
                v-model="searchInput"
                type="text"
                placeholder="Tìm kiếm sản phẩm..."
                @keyup.enter="applyFilters"
            >

            <!-- Danh sách danh mục lấy từ API Categories -->
            <select v-model="categoryId">
                <option value="">Tất cả danh mục</option>

                <option
                    v-for="category in categories"
                    :key="category.id"
                    :value="category.id"
                >
                    {{ category.name }}
                </option>
            </select>

            <!-- Nút áp dụng bộ lọc -->
            <button @click="applyFilters">
                Tìm kiếm
            </button>

        </div>

        <!-- Hiển thị trạng thái đang tải -->
        <p v-if="loading">
            Đang tải sản phẩm...
        </p>

        <!-- Hiển thị lỗi nếu API thất bại -->
        <p v-else-if="error">
            {{ error }}
        </p>

        <!-- Hiển thị danh sách sản phẩm -->
        <div v-else class="product-list">

            <!-- Tái sử dụng component ProductCard -->
            <ProductCard
                v-for="product in products"
                :key="product.id"
                :product="product"
            />

            <!-- Thông báo nếu không có sản phẩm -->
            <p v-if="products.length === 0">
                Không tìm thấy sản phẩm.
            </p>

        </div>

        <!-- Khu vực phân trang -->
        <div class="pagination">

            <!-- Nút trang trước -->
            <button
                :disabled="pagination.current_page <= 1"
                @click="changePage(pagination.current_page - 1)"
            >
                Trang trước
            </button>

            <!-- Hiển thị số trang hiện tại -->
            <span>
                {{ pagination.current_page }} /
                {{ pagination.total_pages }}
            </span>

            <!-- Nút trang tiếp theo -->
            <button
                :disabled="pagination.current_page >= pagination.total_pages"
                @click="changePage(pagination.current_page + 1)"
            >
                Trang sau
            </button>

        </div>

    </div>
</template>

//làm đẹp 
<style scoped>
/* Khoảng cách tổng thể của trang */
.catalog {
    padding: 20px;
}

/* Khu vực tìm kiếm và lọc */
.filter {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

/* Danh sách sản phẩm */
.product-list {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
}

/* Khu vực phân trang */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 20px;
}

/* Điều chỉnh khi màn hình nhỏ */
@media (max-width: 768px) {
    .product-list {
        grid-template-columns: repeat(2, 1fr);
    }

    .filter {
        flex-wrap: wrap;
    }
}
</style>