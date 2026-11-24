<script setup>
// Trang Catalog: gọi api-products (danh sách đã lọc), api-categories (danh mục) và api-product-filters
// (thang giữ nếp / độ bóng, khoảng giá, tag gợi ý). Mọi bộ lọc nằm trên URL để chia sẻ link được và để
// trợ lý AI mở đúng trang đã lọc (xem helpers/ai/chatAssistant.js).
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { formatPrice } from '@/helpers/format/price'
import ProductCardCatalog from '@/components/ProductCardCatalog.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import StateBlock from '@/components/StateBlock.vue'

const route = useRoute()
const router = useRouter()

// Số tag gợi ý hiện sẵn dưới ô nhập tag (chỉ là cách hiển thị của trang, không phải luật nghiệp vụ).
const SUGGESTED_TAG_COUNT = 8
// Các tham số URL thuộc khung lọc (không gồm sort, page). Đổi một trong số này mới đồng bộ lại khung lọc từ URL.
const FILTER_QUERY_KEYS = ['search', 'category', 'min_price', 'max_price', 'hold', 'shine', 'tags']

// Danh sách sản phẩm hiển thị trên trang hiện tại.
const products = ref([])
// Danh mục API dùng để lọc sản phẩm.
const categories = ref([])
// Tách trạng thái tải, lỗi sản phẩm và lỗi danh mục để không nhầm lỗi với danh sách rỗng.
const loading = ref(true)
const errorMessage = ref('')
const categoryError = ref('')
// Dữ liệu dựng ô lọc từ api-product-filters: { hold_levels, shine_levels, sorts, price_range, popular_tags, ... }.
// null = chưa tải xong hoặc lỗi (khi đó ô giữ nếp / độ bóng bị khóa, các ô khác vẫn dùng được).
const filterOptions = ref(null)
const filterOptionsError = ref('')

// Giá trị đang nhập trong khung lọc; chỉ gửi lên URL khi bấm "Áp dụng" (riêng sắp xếp thì áp dụng ngay).
const searchInput = ref('')
const categorySlug = ref('')
const minPriceInput = ref('')
const maxPriceInput = ref('')
// '' = không lọc; còn lại là giá trị số dạng chuỗi ('1', '2'...) vì ô select luôn trả chuỗi.
const holdLevel = ref('')
const shineLevel = ref('')
// Các tag đã chọn (tối đa maxTags, lấy từ BE) và chữ đang gõ trong ô thêm tag.
const selectedTags = ref([])
const tagInput = ref('')
// Lỗi kiểm tra phía FE, hiện ngay dưới ô liên quan.
const priceError = ref('')
const tagError = ref('')

// Thông tin phân trang do api-products trả về.
const pagination = ref({
    current_page: 1,
    per_page: 8,
    total_pages: 1,
    total_items: 0,
})
// Mã request giúp kết quả tìm kiếm cũ không ghi đè kết quả mới khi đổi bộ lọc nhanh.
let latestProductsRequest = 0

// computed: kiểu sắp xếp đang áp dụng, lấy nguyên từ URL. Không có trên URL thì '' (= mặc định của BE).
// Không viết cứng danh sách kiểu sắp xếp ở FE: BE kiểm tra (sai thì 400) và gửi danh sách qua api-product-filters.
const currentSort = computed(() => queryString('sort'))

// computed: danh sách kiểu sắp xếp từ api-product-filters (nguồn duy nhất là CatalogFilter::SORTS ở BE).
const sortOptions = computed(() => filterOptions.value?.sorts ?? [])

// computed: giới hạn đầu vào từ BE (CatalogFilter::limits), để FE kiểm tra sớm cùng con số với BE.
// Chưa tải được thì không chặn ở FE (BE vẫn kiểm tra lại và trả lỗi rõ ràng).
const maxTags = computed(() => filterOptions.value?.limits?.max_tags ?? Infinity)
const maxTagLength = computed(() => filterOptions.value?.limits?.max_tag_length ?? Infinity)

// computed: có bộ lọc nào đang áp dụng trên URL không (để hiện nút "Xóa bộ lọc").
const hasActiveFilters = computed(() => FILTER_QUERY_KEYS.some((key) => queryString(key) !== ''))

// computed: "chữ ký" của phần bộ lọc trên URL (không gồm sort, page). Chỉ khi chữ ký này đổi (bấm Áp dụng, Xóa lọc,
// trợ lý AI mở trang đã lọc) mới ghi đè khung lọc. Đổi trang / đổi sắp xếp không đụng tới khung lọc,
// nên những gì khách đang nhập dở mà chưa bấm "Áp dụng" không bị mất.
const appliedFilterKey = computed(() => FILTER_QUERY_KEYS.map((key) => queryString(key)).join('\u0001'))

// computed: các bộ lọc ĐANG ÁP DỤNG (đọc từ URL, không phải ô đang gõ dở) đổi thành chữ ngắn,
// để làm câu dẫn "Tìm thấy N sản phẩm khớp với ...". Nhãn lấy từ dữ liệu BE (tên danh mục, thang giữ nếp / độ bóng),
// chưa tải xong thì dùng tạm giá trị trên URL.
const appliedFilterLabels = computed(() => {
    const labels = []
    const search = queryString('search')
    if (search) labels.push(`“${search}”`)

    const slug = queryString('category')
    if (slug) labels.push(categories.value.find((category) => category.slug === slug)?.name ?? slug)

    const minPrice = queryString('min_price')
    const maxPrice = queryString('max_price')
    if (minPrice && maxPrice) labels.push(`giá ${formatPrice(minPrice)} – ${formatPrice(maxPrice)}`)
    else if (minPrice) labels.push(`giá từ ${formatPrice(minPrice)}`)
    else if (maxPrice) labels.push(`giá đến ${formatPrice(maxPrice)}`)

    // Mức giữ nếp / độ bóng: "Độ giữ nếp: Mạnh". So sánh dạng chuỗi vì URL luôn là chuỗi.
    const levelLabel = (key, title, levels) => {
        const value = queryString(key)
        if (!value) return
        const level = (levels ?? []).find((item) => String(item.value) === value)
        labels.push(`${title}: ${level?.label ?? value}`)
    }
    levelLabel('hold', filterOptions.value?.hold_label ?? 'Độ giữ nếp', filterOptions.value?.hold_levels)
    levelLabel('shine', filterOptions.value?.shine_label ?? 'Độ bóng', filterOptions.value?.shine_levels)

    const tags = queryString('tags')
    if (tags) labels.push(`tag: ${tags.split(',').map((tag) => tag.trim()).filter(Boolean).join(', ')}`)

    return labels
})

// computed: tag gợi ý chưa được chọn, tối đa SUGGESTED_TAG_COUNT tag phổ biến nhất.
const suggestedTags = computed(() => {
    const chosen = new Set(selectedTags.value.map((tag) => tag.toLowerCase()))
    return (filterOptions.value?.popular_tags ?? [])
        .map((item) => item.tag)
        .filter((tag) => !chosen.has(tag.toLowerCase()))
        .slice(0, SUGGESTED_TAG_COUNT)
})

// Đọc một giá trị chuỗi trên URL (query lặp lại thành mảng thì bỏ qua), không có thì trả ''.
function queryString(key) {
    return typeof route.query[key] === 'string' ? route.query[key] : ''
}

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

// Lấy dữ liệu dựng ô lọc (thang giữ nếp / độ bóng, khoảng giá, tag gợi ý) từ api-product-filters.
// Lỗi thì khóa 2 ô chọn mức và báo lỗi, các bộ lọc khác vẫn dùng bình thường.
async function fetchFilterOptions() {
    filterOptionsError.value = ''
    try {
        const response = await api.get('/?action=api-product-filters')
        if (response.data?.status !== 'success' || !response.data.filters) {
            throw new Error('Dữ liệu bộ lọc không hợp lệ.')
        }
        filterOptions.value = response.data.filters
    } catch (error) {
        filterOptionsError.value = error.response?.data?.message || error.message || 'Không tải được bộ lọc.'
    }
}

// Lấy một trang sản phẩm theo query trên URL và hiện lỗi thay vì giả thành danh sách rỗng.
async function fetchProducts() {
    const requestId = ++latestProductsRequest
    loading.value = true
    errorMessage.value = ''

    try {
        // api-products trả products.data và products.pagination; bộ lọc gửi đúng như trên URL
        // (BE kiểm tra lại, sai thì trả 400 kèm message, hiện ở khối lỗi bên phải).
        const response = await api.get('/?action=api-products', {
            params: {
                search: queryString('search'),
                category: queryString('category'),
                min_price: queryString('min_price'),
                max_price: queryString('max_price'),
                hold: queryString('hold'),
                shine: queryString('shine'),
                tags: queryString('tags'),
                sort: currentSort.value || undefined,
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

// Kiểm tra 2 ô giá phía FE (BE vẫn kiểm tra lại): rỗng = không lọc; có nhập thì phải là số >= 0 và thấp <= cao.
function validatePrices() {
    priceError.value = ''
    const values = [minPriceInput.value, maxPriceInput.value].map((value) => String(value ?? '').trim())
    for (const value of values) {
        if (value !== '' && (!Number.isFinite(Number(value)) || Number(value) < 0)) {
            priceError.value = 'Giá phải là số từ 0 trở lên.'
            return false
        }
    }
    if (values[0] !== '' && values[1] !== '' && Number(values[0]) > Number(values[1])) {
        priceError.value = 'Giá thấp nhất không được lớn hơn giá cao nhất.'
        return false
    }
    return true
}

// Tạo query URL từ khung lọc: bỏ giá trị rỗng để URL gọn; đổi bộ lọc luôn quay về trang 1.
function buildQuery(overrides = {}) {
    const query = {
        search: searchInput.value.trim() || undefined,
        category: categorySlug.value || undefined,
        min_price: String(minPriceInput.value ?? '').trim() || undefined,
        max_price: String(maxPriceInput.value ?? '').trim() || undefined,
        hold: holdLevel.value || undefined,
        shine: shineLevel.value || undefined,
        // Nhiều tag gửi trong một tham số, cách nhau bằng dấu phẩy (đúng contract api-products).
        tags: selectedTags.value.length > 0 ? selectedTags.value.join(',') : undefined,
        sort: currentSort.value || undefined,
        page: 1,
        ...overrides,
    }
    return query
}

// Áp dụng khung lọc lên URL; watcher bên dưới sẽ tải lại danh sách.
// Chữ còn trong ô tag mà khách chưa bấm "Thêm" cũng được thêm vào, để không bị bỏ qua âm thầm.
function applyFilters() {
    if (tagInput.value.trim()) {
        addTagFromInput()
        if (tagError.value) return
    }
    if (!validatePrices()) return
    router.push({ name: 'catalog', query: buildQuery() })
}

// Đổi kiểu sắp xếp: áp dụng ngay, giữ nguyên các bộ lọc ĐÃ áp dụng trên URL.
// Khung lọc đang nhập dở không bị mất (watcher chỉ ghi đè khung lọc khi phần bộ lọc trên URL đổi).
function changeSort(event) {
    router.push({
        name: 'catalog',
        query: { ...route.query, sort: event.target.value || undefined, page: 1 },
    })
}

// Xóa mọi bộ lọc (giữ kiểu sắp xếp) rồi để watcher nạp lại danh sách trang đầu.
function clearFilters() {
    priceError.value = ''
    tagError.value = ''
    tagInput.value = ''
    router.push({
        name: 'catalog',
        query: { sort: currentSort.value || undefined, page: 1 },
    })
}

// Thêm tag vào danh sách đã chọn (bỏ trùng không phân biệt hoa thường, tối đa maxTags từ BE).
// Chuỗi có dấu phẩy được tách thành nhiều tag, giống hệt cách BE chuẩn hóa (CatalogFilter::readTags),
// vì dấu phẩy là ký tự nối tag trên URL. Chỉ đổi khung lọc, chưa gửi lên URL cho tới khi bấm "Áp dụng".
function addTag(rawTag) {
    tagError.value = ''
    const tags = String(rawTag ?? '').split(',').map((part) => part.trim().replace(/\s+/g, ' ')).filter(Boolean)
    for (const tag of tags) {
        if (tag.length > maxTagLength.value) {
            tagError.value = `Mỗi tag tối đa ${maxTagLength.value} ký tự.`
            return
        }
        if (selectedTags.value.some((item) => item.toLowerCase() === tag.toLowerCase())) continue
        if (selectedTags.value.length >= maxTags.value) {
            tagError.value = `Tối đa ${maxTags.value} tag.`
            return
        }
        selectedTags.value = [...selectedTags.value, tag]
    }
}

// Thêm tag từ ô nhập khi bấm Enter, nút "Thêm" hoặc "Áp dụng", rồi xóa ô nhập.
function addTagFromInput() {
    addTag(tagInput.value)
    if (!tagError.value) tagInput.value = ''
}

// Bỏ một tag đã chọn (bấm dấu × trên chip).
function removeTag(tag) {
    tagError.value = ''
    selectedTags.value = selectedTags.value.filter((item) => item !== tag)
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

// URL là nguồn sự thật duy nhất của bộ lọc ĐÃ áp dụng. Hai watcher tách riêng hai việc:
// 1. watch + immediate trên appliedFilterKey: chỉ khi phần bộ lọc trên URL đổi (mở trang, Áp dụng, Xóa lọc,
//    trợ lý AI mở trang đã lọc) mới ghi khung lọc theo URL. Đổi trang / sắp xếp không ghi đè chữ khách đang nhập dở.
watch(
    appliedFilterKey,
    () => {
        searchInput.value = queryString('search')
        categorySlug.value = queryString('category')
        minPriceInput.value = queryString('min_price')
        maxPriceInput.value = queryString('max_price')
        holdLevel.value = queryString('hold')
        shineLevel.value = queryString('shine')
        selectedTags.value = queryString('tags').split(',').map((tag) => tag.trim()).filter(Boolean)
        priceError.value = ''
        tagError.value = ''
    },
    { immediate: true },
)

// 2. watch + immediate trên toàn bộ query: URL đổi bất kỳ (lọc, sắp xếp, trang) thì tải lại danh sách.
watch(() => route.query, fetchProducts, { immediate: true })

// Danh mục và dữ liệu ô lọc chỉ cần tải một lần khi Catalog được mở.
onMounted(() => {
    fetchCategories()
    fetchFilterOptions()
})
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
            <!-- Sidebar bộ lọc: bấm "Áp dụng" mới gửi lên URL (sắp xếp nằm ở cột phải, đổi là áp dụng ngay). -->
            <aside class="catalog-sidebar">
                <form class="catalog-filter" @submit.prevent="applyFilters">
                    <div class="catalog-filter-head">
                        <h2>Bộ lọc</h2>
                    </div>

                    <!-- Tìm rộng: tên, mã, mô tả, đặc điểm (metadata), SKU / tên phiên bản (BE so khớp). -->
                    <div class="catalog-filter-group">
                        <label class="catalog-filter-label" for="catalog-search">Tìm kiếm sản phẩm</label>
                        <input
                            id="catalog-search"
                            v-model="searchInput"
                            class="catalog-control"
                            type="search"
                            :maxlength="filterOptions?.limits?.max_search_length"
                            placeholder="Tên, mã, phiên bản, đặc điểm..."
                        >
                    </div>

                    <!-- Danh mục (select). -->
                    <div class="catalog-filter-group">
                        <label class="catalog-filter-label" for="catalog-category">Danh mục</label>
                        <select id="catalog-category" v-model="categorySlug" class="catalog-control" :disabled="categories.length === 0">
                            <option value="">Tất cả danh mục</option>
                            <option v-for="category in categories" :key="category.id" :value="category.slug">
                                {{ category.name }}
                            </option>
                        </select>
                        <p v-if="categoryError" class="catalog-category-error" role="status">{{ categoryError }}</p>
                    </div>

                    <!-- Khoảng giá: 2 ô số; gợi ý khoảng giá đang có lấy từ api-product-filters. -->
                    <div class="catalog-filter-group">
                        <span class="catalog-filter-label" id="catalog-price-label">Khoảng giá (VND)</span>
                        <div class="catalog-price-row" role="group" aria-labelledby="catalog-price-label">
                            <input
                                v-model="minPriceInput"
                                class="catalog-control"
                                type="number"
                                min="0"
                                step="1000"
                                inputmode="numeric"
                                placeholder="Từ"
                                aria-label="Giá thấp nhất"
                                :aria-invalid="Boolean(priceError)"
                                @input="priceError && validatePrices()"
                            >
                            <span class="catalog-price-dash" aria-hidden="true">–</span>
                            <input
                                v-model="maxPriceInput"
                                class="catalog-control"
                                type="number"
                                min="0"
                                step="1000"
                                inputmode="numeric"
                                placeholder="Đến"
                                aria-label="Giá cao nhất"
                                :aria-invalid="Boolean(priceError)"
                                @input="priceError && validatePrices()"
                            >
                        </div>
                        <p v-if="priceError" class="catalog-category-error" role="alert">{{ priceError }}</p>
                        <p v-else-if="filterOptions?.price_range?.min != null" class="catalog-filter-hint">
                            Đang có từ {{ formatPrice(filterOptions.price_range.min) }} đến {{ formatPrice(filterOptions.price_range.max) }}
                        </p>
                    </div>

                    <!-- Độ giữ nếp / độ bóng: select, nhãn lấy từ api-product-filters (không viết cứng ở FE). -->
                    <div class="catalog-filter-group">
                        <label class="catalog-filter-label" for="catalog-hold">{{ filterOptions?.hold_label || 'Độ giữ nếp' }}</label>
                        <select id="catalog-hold" v-model="holdLevel" class="catalog-control" :disabled="!filterOptions">
                            <option value="">Tất cả</option>
                            <option v-for="level in filterOptions?.hold_levels ?? []" :key="level.value" :value="String(level.value)">
                                {{ level.label }}
                            </option>
                        </select>
                    </div>

                    <div class="catalog-filter-group">
                        <label class="catalog-filter-label" for="catalog-shine">{{ filterOptions?.shine_label || 'Độ bóng' }}</label>
                        <select id="catalog-shine" v-model="shineLevel" class="catalog-control" :disabled="!filterOptions">
                            <option value="">Tất cả</option>
                            <option v-for="level in filterOptions?.shine_levels ?? []" :key="level.value" :value="String(level.value)">
                                {{ level.label }}
                            </option>
                        </select>
                        <p v-if="filterOptionsError" class="catalog-category-error" role="status">{{ filterOptionsError }}</p>
                    </div>

                    <!-- Tag: so khớp với thuộc tính sản phẩm (loại tóc, kiểu tóc, dung tích, mùi hương...).
                         Chip đã chọn có dấu × để bỏ; bên dưới là tag gợi ý phổ biến, bấm để thêm. -->
                    <div class="catalog-filter-group">
                        <label class="catalog-filter-label" for="catalog-tag">Đặc điểm (tag)</label>
                        <div v-if="selectedTags.length > 0" class="catalog-tags" aria-label="Tag đã chọn">
                            <button
                                v-for="tag in selectedTags"
                                :key="tag"
                                type="button"
                                class="catalog-tag is-selected"
                                :aria-label="`Bỏ tag ${tag}`"
                                @click="removeTag(tag)"
                            >
                                {{ tag }} <span aria-hidden="true">×</span>
                            </button>
                        </div>
                        <div class="catalog-tag-input">
                            <input
                                id="catalog-tag"
                                v-model="tagInput"
                                class="catalog-control"
                                type="text"
                                :maxlength="filterOptions?.limits?.max_tag_length"
                                placeholder="Ví dụ: tóc dày, quiff, 113g"
                                :aria-invalid="Boolean(tagError)"
                                @keydown.enter.prevent="addTagFromInput"
                            >
                            <button type="button" class="catalog-tag-add" :disabled="!tagInput.trim()" @click="addTagFromInput">Thêm</button>
                        </div>
                        <p v-if="tagError" class="catalog-category-error" role="alert">{{ tagError }}</p>
                        <div v-if="suggestedTags.length > 0" class="catalog-tags" aria-label="Tag gợi ý">
                            <button
                                v-for="tag in suggestedTags"
                                :key="tag"
                                type="button"
                                class="catalog-tag"
                                @click="addTag(tag)"
                            >
                                + {{ tag }}
                            </button>
                        </div>
                    </div>

                    <div class="catalog-filter-actions">
                        <button class="catalog-submit" type="submit">Áp dụng</button>
                        <button
                            v-if="hasActiveFilters"
                            class="catalog-outline-button"
                            type="button"
                            @click="clearFilters"
                        >
                            Xóa bộ lọc
                        </button>
                    </div>
                </form>

            </aside>

            <!-- Cột phải: thanh số kết quả + sắp xếp, lưới sản phẩm, phân trang. -->
            <div class="catalog-results">
                <!-- Sắp xếp: đổi là áp dụng ngay, giữ nguyên bộ lọc. -->
                <div class="catalog-toolbar">
                    <p class="catalog-count" role="status">
                        <!-- Có bộ lọc: câu dẫn ngắn nói rõ đang lọc theo gì; không lọc: chỉ hiện tổng số. -->
                        <template v-if="!loading && !errorMessage">
                            <template v-if="appliedFilterLabels.length && pagination.total_items > 0">
                                Tìm thấy <strong>{{ pagination.total_items }}</strong> sản phẩm khớp với
                                <strong>{{ appliedFilterLabels.join(' · ') }}</strong>
                            </template>
                            <template v-else-if="appliedFilterLabels.length">
                                Không có sản phẩm nào khớp với <strong>{{ appliedFilterLabels.join(' · ') }}</strong>
                            </template>
                            <template v-else>{{ pagination.total_items }} sản phẩm</template>
                        </template>
                    </p>
                    <label class="catalog-sort">
                        <span>Sắp xếp</span>
                        <!-- Danh sách từ api-product-filters; chưa tải xong thì khóa ô (BE vẫn xếp theo mặc định).
                             value '' = chưa chọn trên URL, hiển thị là lựa chọn đầu tiên (mặc định của BE). -->
                        <select
                            class="catalog-control"
                            :value="currentSort || sortOptions[0]?.value || ''"
                            :disabled="sortOptions.length === 0"
                            @change="changeSort"
                        >
                            <option v-if="sortOptions.length === 0" value="">Mặc định</option>
                            <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                </div>

                <!-- Trạng thái danh sách sản phẩm (StateBlock): đang tải → lỗi có Thử lại → rỗng có Xóa bộ lọc.
                     Lỗi API ưu tiên hơn "không có sản phẩm" để không báo nhầm khi server lỗi.
                     Không dùng khung xương lưới: StateBlock chưa hỗ trợ kiểu đó (FE/CLAUDE.md mục 13). -->
                <StateBlock
                    :loading="loading"
                    :error="errorMessage"
                    :empty="products.length === 0"
                    loading-text="Đang tải sản phẩm..."
                    empty-text="Không tìm thấy sản phẩm phù hợp."
                    :on-retry="fetchProducts"
                >
                    <template #empty-action>
                        <button class="catalog-outline-button" type="button" @click="clearFilters">Xóa bộ lọc</button>
                    </template>

                    <!-- Grid responsive: 3 cột desktop, 2 cột tablet và mobile. -->
                    <div class="catalog-grid">
                        <ProductCardCatalog v-for="product in products" :key="product.id" :product="product" />
                    </div>

                    <!-- Phân trang dùng chung; đổi trang vẫn giữ nguyên bộ lọc trên URL. -->
                    <PaginationBar
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

/* Sidebar dính khi cuộn (sticky). Khung lọc có thể cao hơn màn hình, mà phần tử sticky không tự cuộn theo trang,
   nên giới hạn chiều cao = chiều cao màn hình trừ phần header phía trên (96px) và lề dưới (16px),
   phần thừa cuộn BÊN TRONG sidebar. 100dvh: chiều cao màn hình thật, tính cả thanh địa chỉ co giãn trên điện thoại.
   overscroll-behavior: contain: cuộn hết sidebar thì không kéo theo cả trang. */
.catalog-sidebar {
    position: sticky;
    top: 96px;
    display: grid;
    gap: 16px;
    max-height: calc(100vh - 96px - 16px);
    max-height: calc(100dvh - 96px - 16px);
    overflow-y: auto;
    overscroll-behavior: contain;
    /* Chừa chỗ cho thanh cuộn để không đè lên viền khung lọc. */
    scrollbar-gutter: stable;
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

    /* Tablet/điện thoại: sidebar nằm trên lưới, không dính, cao theo nội dung (bỏ giới hạn chiều cao và thanh cuộn riêng). */
    .catalog-sidebar {
        position: static;
        max-height: none;
        overflow: visible;
        scrollbar-gutter: auto;
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

/* ===== Bộ lọc mở rộng (khoảng giá, tag) và thanh sắp xếp ===== */

/* Dòng gợi ý nhỏ dưới ô lọc (khoảng giá đang có). */
.catalog-filter-hint {
    margin: 0;
    color: #6b6560;
    font-size: 12px;
}

/* 2 ô giá nằm cùng hàng, ở giữa là dấu gạch. */
.catalog-price-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 8px;
}

.catalog-price-dash {
    color: #6b6560;
}

/* Ô nhập tag + nút "Thêm" cùng hàng. */
.catalog-tag-input {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
}

.catalog-tag-add {
    min-height: 44px;
    padding: 0 14px;
    border: 1px solid #1c2a3a;
    border-radius: 6px;
    background: transparent;
    color: #1c2a3a;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.catalog-tag-add:hover:not(:disabled) {
    background: #1c2a3a;
    color: #ffffff;
}

.catalog-tag-add:disabled {
    border-color: #d9d4ca;
    color: #8a8f98;
    cursor: not-allowed;
}

/* Chip tag: gợi ý viền nhạt; đã chọn nền kem viền đồng, có dấu × để bỏ. */
.catalog-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.catalog-tag {
    padding: 5px 10px;
    border: 1px solid #d9d4ca;
    border-radius: 999px;
    background: #ffffff;
    color: #25364a;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.3;
    transition: border-color 140ms ease, background-color 140ms ease;
}

.catalog-tag:hover {
    border-color: #b8893e;
}

.catalog-tag.is-selected {
    border-color: #b8893e;
    background: #f7f0e3;
    color: #9e7432;
}

.catalog-tag:focus-visible,
.catalog-tag-add:focus-visible {
    outline: 3px solid #b8893e;
    outline-offset: 2px;
}

/* Thanh trên lưới: số kết quả bên trái, ô sắp xếp bên phải. */
.catalog-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

/* Câu dẫn có thể dài (nhiều bộ lọc): chiếm phần rộng còn lại, tự xuống dòng; phần nhấn màu navy. */
.catalog-count {
    flex: 1 1 260px;
    min-width: 0;
    margin: 0;
    color: #4a5565;
    font-size: 14px;
    font-weight: 500;
    overflow-wrap: anywhere;
}

.catalog-count strong {
    color: #131e2a;
    font-weight: 700;
}

.catalog-sort {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: #6b6560;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.catalog-sort .catalog-control {
    width: auto;
    min-width: 150px;
    text-transform: none;
    letter-spacing: normal;
}

@media (prefers-reduced-motion: reduce) {
    .catalog-tag {
        transition: none;
    }
}
</style>
