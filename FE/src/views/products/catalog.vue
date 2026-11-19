<script setup>
// Trang Catalog gọi api-products và api-categories, giữ bộ lọc/phân trang trong URL.
// Có 2 chế độ tìm kiếm: thường (SQL, có sắp xếp + phân trang) và thông minh (search_mode=vector,
// trả danh sách đề xuất theo mức liên quan, không phân trang). Danh mục và khoảng giá dùng chung cho cả hai.
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { formatPrice } from '@/helpers/format/price'
import { resolveSystemImageUrl } from '@/helpers/media/imageUrl'
import ProductCardCatalog from '@/components/ProductCardCatalog.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import StateBlock from '@/components/StateBlock.vue'

const route = useRoute()
const router = useRouter()

// Các kiểu sắp xếp api-products chấp nhận (chỉ áp dụng cho tìm kiếm thường). Giá trị phải khớp BE.
const SORT_OPTIONS = [
    { value: 'newest', label: 'Mới nhất' },
    { value: 'price_asc', label: 'Giá tăng dần' },
    { value: 'price_desc', label: 'Giá giảm dần' },
    { value: 'views', label: 'Xem nhiều' },
    { value: 'sales', label: 'Bán chạy' },
]

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
// Ô "Tìm kiếm thông minh": MẶC ĐỊNH BẬT. URL không có search_mode = bật (gửi search_mode=vector);
// khách bỏ tick thì URL ghi search_mode=keyword để F5 vẫn giữ lựa chọn tắt.
const smartSearch = ref(true)
// Khoảng giá đang nhập (chuỗi từ ô input, rỗng = không lọc) và lỗi hiển thị ngay dưới hai ô giá.
const minPriceInput = ref('')
const maxPriceInput = ref('')
const priceError = ref('')
// Kiểu sắp xếp đang chọn; đổi là áp dụng ngay (không cần bấm "Tìm kiếm").
const sortValue = ref('newest')
// Thông tin phân trang do api-products trả về.
const pagination = ref({
    current_page: 1,
    per_page: 8,
    total_pages: 1,
    total_items: 0,
})
// Chế độ BE thực tế đã dùng (keyword | vector). Có thể là keyword dù đã bật tìm kiếm thông minh,
// khi server tìm kiếm thông minh đang tắt/lỗi; lúc đó BE gửi kèm notice để báo khách.
const searchModeUsed = ref('keyword')
const notice = ref('')
// Mã request giúp kết quả tìm kiếm cũ không ghi đè kết quả mới khi đổi bộ lọc nhanh.
let latestProductsRequest = 0

// computed: tự tính lại khi searchModeUsed đổi. true = đang hiện danh sách đề xuất của tìm kiếm thông minh,
// khi đó ẩn phân trang và sắp xếp vì BE trả cả danh sách theo mức liên quan.
const isVectorResult = computed(() => searchModeUsed.value === 'vector')

// Câu khách đã tìm (lấy từ URL, tức là câu đã gửi lên BE, không phải chữ đang gõ dở trong ô).
const searchedText = computed(() => queryString('search').trim())

// Ảnh đại diện của Louis Dé Fule, dùng chung ảnh với khung chat (ChatAssistant.vue) để khách nhận ra cùng một trợ lý.
const louisAvatarUrl = resolveSystemImageUrl('fule.webp')
// true khi ảnh tải lỗi: hiện chữ "L" thay ảnh, giống khung chat.
const louisAvatarFailed = ref(false)

// Đoạn trả lời của tìm kiếm thông minh, nói bằng giọng Louis Dé Fule cho đồng bộ với khung chat.
// Theo quy tắc danh xưng trong BE/geminiServices/GeminiPrompt.php: luôn tự xưng đầy đủ "Louis Dé Fule"
// (không dùng "tôi", "mình" hay chỉ "Louis"), gọi khách là "quý ông".
// Ghép bằng mẫu câu từ dữ liệu API trả về (sản phẩm khớp nhất + đặc điểm trong matched_variant.attributes),
// KHÔNG gọi AI, nên không tốn thêm request. Trả null khi không ở chế độ thông minh hoặc không có kết quả.
const smartReply = computed(() => {
    if (!isVectorResult.value || products.value.length === 0) return null

    const top = products.value[0]
    const variant = top.matched_variant || {}
    const attributes = variant.attributes || {}

    // Chỉ lấy vài đặc điểm khách hay quan tâm; giá trị null/rỗng thì bỏ qua.
    const traits = []
    if (attributes.do_giu_nep) traits.push(`giữ nếp ${attributes.do_giu_nep}`)
    if (attributes.do_bong) traits.push(attributes.do_bong)
    if (attributes.phu_hop) traits.push(`hợp ${attributes.phu_hop}`)

    // Tên biến thể "Mặc định" do hệ thống tự đặt, không có nghĩa với khách nên không hiện.
    const variantName = variant.variant_name && variant.variant_name !== 'Mặc định' ? variant.variant_name : ''
    const price = variant.final_price !== undefined && variant.final_price !== null ? formatPrice(Number(variant.final_price)) : ''
    const others = products.value.slice(1, 3).map((product) => product.name)

    return {
        intro: products.value.length === 1
            ? `Với mô tả “${searchedText.value}”, Louis Dé Fule thấy sản phẩm này hợp với quý ông nhất:`
            : `Với mô tả “${searchedText.value}”, Louis Dé Fule xin gợi ý ${products.value.length} sản phẩm. Hợp với quý ông nhất là:`,
        topName: top.name + (variantName ? ` (${variantName})` : ''),
        details: [traits.join(', '), price ? `giá ${price}` : ''].filter(Boolean).join(' · '),
        outro: others.length > 0
            ? `Quý ông xem thêm ${others.join(', ')} ngay bên dưới nhé.`
            : 'Quý ông xem chi tiết ngay bên dưới nhé.',
    }
})

// Thông báo trống: ở chế độ thông minh cũng nói bằng giọng Louis Dé Fule, có nhắc lại câu khách tìm.
const emptyText = computed(() => (isVectorResult.value
    ? `Louis Dé Fule chưa tìm thấy sản phẩm nào hợp với “${searchedText.value}”. Quý ông thử mô tả khác hoặc bỏ bớt bộ lọc giúp Louis Dé Fule nhé.`
    : 'Không tìm thấy sản phẩm phù hợp.'))

// Có bộ lọc nào khác mặc định không, để hiện nút "Xóa bộ lọc". Tìm kiếm thông minh mặc định bật,
// nên chỉ tính là "đã lọc" khi khách tắt nó.
const hasFilters = computed(() => Boolean(
    searchInput.value || categorySlug.value || !smartSearch.value || minPriceInput.value !== '' || maxPriceInput.value !== '',
))

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

// Đọc một tham số dạng chuỗi trên URL; không phải chuỗi (ví dụ bị lặp ?a=1&a=2) thì coi như rỗng.
function queryString(key) {
    return typeof route.query[key] === 'string' ? route.query[key] : ''
}

// Lấy sản phẩm theo query trên URL và hiện lỗi thay vì giả thành danh sách rỗng.
async function fetchProducts() {
    const requestId = ++latestProductsRequest
    loading.value = true
    errorMessage.value = ''

    try {
        // api-products trả products.data và products.pagination, kèm search_mode_used và notice.
        // Tham số undefined sẽ không được axios gửi đi, nên BE dùng giá trị mặc định của nó.
        const response = await api.get('/?action=api-products', {
            params: {
                search: queryString('search'),
                category: queryString('category'),
                page: route.query.page || 1,
                per_page: route.query.per_page || 8,
                // Mặc định tìm kiếm thông minh; chỉ dùng tìm thường khi URL ghi rõ search_mode=keyword.
                // Ô tìm kiếm trống thì BE tự dùng tìm thường (không có gì để so nghĩa).
                search_mode: queryString('search_mode') === 'keyword' ? 'keyword' : 'vector',
                sort: queryString('sort') || undefined,
                min_price: queryString('min_price') || undefined,
                max_price: queryString('max_price') || undefined,
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
            searchModeUsed.value = response.data.search_mode_used === 'vector' ? 'vector' : 'keyword'
            notice.value = typeof response.data.notice === 'string' ? response.data.notice : ''
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

// Kiểm tra khoảng giá trước khi gửi: số nguyên không âm, giá từ không lớn hơn giá đến.
// BE vẫn kiểm tra lại; phần này chỉ để khách thấy lỗi ngay dưới ô nhập.
function validatePrices() {
    const values = [minPriceInput.value, maxPriceInput.value]
    // Regex ^\d+$: chỉ gồm chữ số (0-9), không dấu âm, không dấu chấm.
    if (values.some((value) => value !== '' && !/^\d+$/.test(String(value)))) {
        priceError.value = 'Giá phải là số nguyên không âm.'
        return false
    }
    if (minPriceInput.value !== '' && maxPriceInput.value !== '' && Number(minPriceInput.value) > Number(maxPriceInput.value)) {
        priceError.value = 'Giá từ không được lớn hơn giá đến.'
        return false
    }
    priceError.value = ''
    return true
}

// Áp dụng bộ lọc vào URL; đổi bộ lọc luôn quay về trang đầu. Watcher bên dưới sẽ tải lại dữ liệu.
function applyFilters() {
    if (!validatePrices()) return

    // Gộp xuống dòng và khoảng trắng thừa thành một dấu cách: ô tìm kiếm là textarea nên khách có thể xuống dòng,
    // mà tìm kiếm thường (LIKE) sẽ không khớp nếu câu còn ký tự xuống dòng.
    const search = searchInput.value.replace(/\s+/g, ' ').trim()

    router.push({
        name: 'catalog',
        query: {
            search: search || undefined,
            category: categorySlug.value || undefined,
            search_mode: smartSearch.value ? undefined : 'keyword',
            sort: sortValue.value !== 'newest' ? sortValue.value : undefined,
            min_price: minPriceInput.value !== '' ? String(minPriceInput.value) : undefined,
            max_price: maxPriceInput.value !== '' ? String(maxPriceInput.value) : undefined,
            page: 1,
        },
    })
}

// Xóa bộ lọc rồi để watcher nạp lại danh sách trang đầu.
function clearFilters() {
    searchInput.value = ''
    categorySlug.value = ''
    smartSearch.value = true
    minPriceInput.value = ''
    maxPriceInput.value = ''
    priceError.value = ''
    sortValue.value = 'newest'
    router.push({ name: 'catalog', query: { page: 1 } })
}

// Đổi sắp xếp: áp dụng ngay, giữ các bộ lọc đang có trên URL và quay về trang đầu.
function changeSort() {
    router.push({
        name: 'catalog',
        query: {
            ...route.query,
            sort: sortValue.value !== 'newest' ? sortValue.value : undefined,
            page: 1,
        },
    })
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

// Đồng bộ input từ URL và tải lại dữ liệu khi bộ lọc hoặc trang đổi.
// immediate: true để chạy ngay lần đầu mở trang (đọc bộ lọc có sẵn trên URL, ví dụ khi F5).
watch(
    () => route.query,
    () => {
        searchInput.value = queryString('search')
        categorySlug.value = queryString('category')
        smartSearch.value = queryString('search_mode') !== 'keyword'
        minPriceInput.value = queryString('min_price')
        maxPriceInput.value = queryString('max_price')
        const sort = queryString('sort')
        sortValue.value = SORT_OPTIONS.some((option) => option.value === sort) ? sort : 'newest'
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
            <!-- Sidebar bộ lọc: tìm kiếm (+ tìm kiếm thông minh), danh mục, khoảng giá; bấm "Tìm kiếm" mới gửi lên URL. -->
            <aside class="catalog-sidebar">
                <form class="catalog-filter" novalidate @submit.prevent="applyFilters">
                    <div class="catalog-filter-head">
                        <h2>Bộ lọc</h2>
                    </div>

                    <div class="catalog-filter-group">
                        <label class="catalog-filter-label" for="catalog-search">Tìm kiếm sản phẩm</label>
                        <!-- Textarea để gõ được câu mô tả dài. Enter = tìm luôn (.exact: chỉ Enter, không kèm phím khác),
                             Shift+Enter = xuống dòng như bình thường. -->
                        <textarea
                            id="catalog-search"
                            v-model="searchInput"
                            class="catalog-control catalog-search-area"
                            rows="2"
                            :placeholder="smartSearch ? 'Ví dụ: pomade không bóng cho tóc dày, giữ nếp cả ngày' : 'Tìm kiếm sản phẩm...'"
                            @keydown.enter.exact.prevent="applyFilters"
                        ></textarea>
                        <!-- Công tắc tìm kiếm thông minh: tìm theo nghĩa thay vì đúng chữ. -->
                        <label class="catalog-smart-toggle" for="catalog-smart-search">
                            <input id="catalog-smart-search" v-model="smartSearch" type="checkbox">
                            <span>Tìm kiếm thông minh</span>
                        </label>
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

                    <!-- Khoảng giá (VND): để trống là không lọc. Lỗi hiện ngay dưới hai ô và mất khi khách sửa. -->
                    <div class="catalog-filter-group">
                        <span class="catalog-filter-label" id="catalog-price-label">Khoảng giá (₫)</span>
                        <div class="catalog-price-row" role="group" aria-labelledby="catalog-price-label">
                            <input
                                v-model="minPriceInput"
                                class="catalog-control"
                                type="number"
                                min="0"
                                step="1000"
                                inputmode="numeric"
                                placeholder="Từ"
                                aria-label="Giá từ"
                                :aria-invalid="Boolean(priceError)"
                                @input="priceError = ''"
                            >
                            <span class="catalog-price-sep" aria-hidden="true">–</span>
                            <input
                                v-model="maxPriceInput"
                                class="catalog-control"
                                type="number"
                                min="0"
                                step="1000"
                                inputmode="numeric"
                                placeholder="Đến"
                                aria-label="Giá đến"
                                :aria-invalid="Boolean(priceError)"
                                @input="priceError = ''"
                            >
                        </div>
                        <p v-if="priceError" class="catalog-category-error" role="alert">{{ priceError }}</p>
                    </div>

                    <div class="catalog-filter-actions">
                        <button class="catalog-submit" type="submit">Tìm kiếm</button>
                        <button
                            v-if="hasFilters"
                            class="catalog-outline-button"
                            type="button"
                            @click="clearFilters"
                        >
                            Xóa bộ lọc
                        </button>
                    </div>
                </form>

            </aside>

            <!-- Cột phải: thanh kết quả + lưới sản phẩm + phân trang. -->
            <div class="catalog-results">
                <!-- Thông báo khi muốn tìm kiếm thông minh nhưng BE phải dùng tìm kiếm thường. -->
                <p v-if="notice && !loading" class="catalog-notice" role="status">{{ notice }}</p>

                <!-- Chế độ thông minh: Louis Dé Fule trả lời (cùng ảnh, cùng tên với khung chat), đặt trên danh sách đề xuất. -->
                <div v-if="!loading && !errorMessage && smartReply" class="catalog-smart-reply" role="status">
                    <span class="catalog-smart-avatar" aria-hidden="true">
                        <img
                            v-if="!louisAvatarFailed"
                            :src="louisAvatarUrl"
                            alt=""
                            class="catalog-smart-avatar-img"
                            @error="louisAvatarFailed = true"
                        >
                        <span v-else>L</span>
                    </span>
                    <div class="catalog-smart-bubble">
                        <span class="catalog-smart-sender">Louis Dé Fule</span>
                        <p>{{ smartReply.intro }}</p>
                        <p>
                            <strong>{{ smartReply.topName }}</strong>
                            <span v-if="smartReply.details"> · {{ smartReply.details }}</span>
                        </p>
                        <p class="catalog-smart-outro">{{ smartReply.outro }}</p>
                    </div>
                </div>

                <!-- Chế độ thường: số sản phẩm + dropdown sắp xếp. Chế độ thông minh không sắp xếp lại nên ẩn thanh này. -->
                <div v-if="!loading && !errorMessage && products.length > 0 && !isVectorResult" class="catalog-toolbar">
                    <p class="catalog-toolbar-text">
                        <span>{{ pagination.total_items }} sản phẩm</span>
                    </p>

                    <label class="catalog-sort">
                        <span>Sắp xếp</span>
                        <select v-model="sortValue" class="catalog-control" @change="changeSort">
                            <option v-for="option in SORT_OPTIONS" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                </div>

                <!-- StateBlock xử lý 3 trạng thái theo thứ tự: đang tải -> lỗi (có nút Thử lại) -> không có sản phẩm.
                     Có dữ liệu thì hiện lưới sản phẩm trong slot mặc định. -->
                <StateBlock
                    :loading="loading"
                    :error="errorMessage"
                    :empty="products.length === 0"
                    loading-text="Đang tải sản phẩm..."
                    :empty-text="emptyText"
                    :on-retry="fetchProducts"
                >
                    <template #empty-action>
                        <button class="catalog-outline-button" type="button" @click="clearFilters">Xóa bộ lọc</button>
                    </template>

                    <!-- Grid responsive: 3 cột desktop, 2 cột tablet và mobile. -->
                    <div class="catalog-grid">
                        <ProductCardCatalog v-for="product in products" :key="product.id" :product="product" />
                    </div>

                    <!-- Phân trang dùng chung; đổi trang vẫn giữ nguyên bộ lọc trên URL.
                         Ẩn ở chế độ thông minh vì BE trả cả danh sách đề xuất trong 1 lần. -->
                    <PaginationBar
                        v-if="!isVectorResult"
                        label="Phân trang sản phẩm"
                        :current-page="Number(pagination.current_page)"
                        :total-pages="Number(pagination.total_pages)"
                        @change="changePage"
                    />
                </StateBlock>
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

/* Ghim cả khung bộ lọc khi cuộn (sticky): dừng cách mép trên 96px, ngay dưới header (~73px) để không bị header che.
   Sticky chỉ ghim được khi khung thấp hơn phần màn hình còn lại, nên bộ lọc được làm gọn
   (khoảng cách nhỏ, ô nhập thấp, 2 nút cùng hàng) để luôn thấy trọn, không cần cuộn bên trong. */
.catalog-sidebar {
    position: sticky;
    top: 96px;
    display: grid;
    gap: 16px;
}

.catalog-filter {
    display: grid;
    gap: 12px;
    padding: 16px;
    border: 1px solid #e6e0d4;
    border-radius: 8px;
    background: #ffffff;
    box-shadow: 0 2px 8px rgba(28, 42, 58, 0.06);
}

.catalog-filter-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 8px;
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
    gap: 6px;
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
    min-height: 38px;
    padding: 7px 10px;
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

/* Ô tìm kiếm dạng textarea: chỉ cho kéo giãn theo chiều dọc để không phá bề rộng sidebar. */
.catalog-search-area {
    min-height: 60px;
    line-height: 1.5;
    resize: vertical;
}

.catalog-control[aria-invalid="true"] {
    border-color: #ba1a1a;
}

/* Công tắc tìm kiếm thông minh: checkbox màu đồng cạnh chữ. */
.catalog-smart-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #1c2a3a;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.catalog-smart-toggle input {
    width: 16px;
    height: 16px;
    accent-color: #b8893e;
    cursor: pointer;
}

/* Hai ô giá cùng hàng, ngăn bằng dấu gạch. */
.catalog-price-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 8px;
}

.catalog-price-sep {
    color: #6b6560;
}

.catalog-category-error {
    margin: 0;
    color: #93000a;
    font-size: 12px;
}

/* Hai nút "Tìm kiếm" và "Xóa bộ lọc" cùng hàng (chỉ có 1 nút thì nút đó chiếm cả hàng) để khung lọc thấp hơn. */
.catalog-filter-actions {
    display: grid;
    grid-auto-columns: minmax(0, 1fr);
    grid-auto-flow: column;
    gap: 8px;
}

.catalog-submit,
.catalog-outline-button {
    min-height: 40px;
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

/* Thông báo lùi về tìm kiếm thường: nền kem đậm, viền đồng. */
.catalog-notice {
    margin: 0;
    padding: 12px 16px;
    border: 1px solid #b8893e;
    border-radius: 6px;
    background: #efeae0;
    color: #4a5565;
    font-size: 13px;
}

/* Đoạn trả lời kiểu trợ lý: biểu tượng tròn navy + bong bóng trắng viền kem, tên sản phẩm màu đồng. */
.catalog-smart-reply {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.catalog-smart-avatar {
    display: grid;
    flex: 0 0 40px;
    width: 40px;
    height: 40px;
    overflow: hidden;
    place-items: center;
    border: 2px solid #b8893e;
    border-radius: 50%;
    background: #1c2a3a;
    color: #b8893e;
    font-family: var(--font-display);
    font-size: 16px;
    font-weight: 700;
}

.catalog-smart-avatar-img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Tên người trả lời, giống nhãn "Louis Dé Fule" trong khung chat. */
.catalog-smart-sender {
    color: #131e2a;
    font-family: var(--font-display);
    font-size: 13px;
    font-weight: 700;
}

.catalog-smart-bubble {
    display: grid;
    min-width: 0;
    gap: 4px;
    padding: 12px 16px;
    border: 1px solid #e6e0d4;
    border-radius: 0 8px 8px 8px;
    background: #ffffff;
    box-shadow: 0 2px 8px rgba(28, 42, 58, 0.06);
    color: #4a5565;
    font-size: 14px;
    line-height: 1.55;
}

.catalog-smart-bubble p {
    margin: 0;
}

.catalog-smart-bubble strong {
    color: #9e7432;
}

.catalog-smart-outro {
    color: #6b6560;
}

/* Thanh kết quả: số sản phẩm bên trái, sắp xếp bên phải; xuống dòng trên màn hình hẹp. */
.catalog-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.catalog-toolbar-text {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 4px 10px;
    margin: 0;
    color: #6b6560;
    font-size: 13px;
}

.catalog-sort {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #6b6560;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.catalog-sort .catalog-control {
    width: auto;
    min-width: 170px;
    text-transform: none;
    letter-spacing: normal;
    font-weight: 400;
}

.catalog-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 16px;
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

    .catalog-sort,
    .catalog-sort .catalog-control {
        width: 100%;
    }
}
</style>
