<script setup>
// Phân trang dùng chung: chỉ phát sự kiện change khi trang đích hợp lệ và khác trang hiện tại.
import { ref, watch } from 'vue'

const props = defineProps({
    currentPage: { type: Number, required: true },
    totalPages: { type: Number, required: true },
    label: { type: String, default: 'Phân trang' },
})

const emit = defineEmits(['change'])

// Ô nhập luôn khớp trang hiện tại; nhập sai thì trả về trang đang xem.
const pageInput = ref(props.currentPage)
watch(
    () => props.currentPage,
    (page) => {
        pageInput.value = page
    },
)

function goTo(page) {
    if (page < 1 || page > props.totalPages || page === props.currentPage) return
    emit('change', page)
}

function submitPage() {
    const page = Math.trunc(Number(pageInput.value))

    if (!Number.isFinite(page) || page < 1 || page > props.totalPages) {
        pageInput.value = props.currentPage
        return
    }

    goTo(page)
}
</script>

<template>
    <nav class="pagination" :aria-label="label">
        <button
            class="pagination-nav"
            type="button"
            aria-label="Trang trước"
            :disabled="currentPage <= 1"
            @click="goTo(currentPage - 1)"
        >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="15 18 9 12 15 6" />
            </svg>
        </button>

        <form class="pagination-jump" novalidate @submit.prevent="submitPage">
            <input
                v-model="pageInput"
                class="pagination-input"
                type="number"
                inputmode="numeric"
                min="1"
                :max="totalPages"
                aria-label="Nhập số trang"
            >
            <span class="pagination-total">/ {{ totalPages }}</span>
            <button class="pagination-go" type="submit">Đi</button>
        </form>

        <button
            class="pagination-nav"
            type="button"
            aria-label="Trang sau"
            :disabled="currentPage >= totalPages"
            @click="goTo(currentPage + 1)"
        >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polyline points="9 18 15 12 9 6" />
            </svg>
        </button>
    </nav>
</template>

<style scoped>
.pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: fit-content;
    max-width: 100%;
    margin: 40px auto 0;
    padding: 8px 10px;
    border: 1px solid #c5a059;
    border-radius: 10px;
    background: linear-gradient(180deg, #2f2f32 0%, #232325 100%);
    box-shadow: 0 0 0 1px rgb(233 193 118 / 15%), 0 10px 28px rgb(0 0 0 / 45%);
}

.pagination-nav {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: 1px solid #e9c176;
    border-radius: 8px;
    background: linear-gradient(180deg, #3a3a3d 0%, #2a2a2c 100%);
    color: #f5ce72;
    cursor: pointer;
    transition: background-color 160ms ease, color 160ms ease;
}

.pagination-nav:hover:not(:disabled) {
    background: #e9c176;
    color: #4e3700;
}

.pagination-nav:disabled {
    border-color: #5a5042;
    color: #7d7265;
    background: #232325;
    cursor: not-allowed;
}

.pagination-nav:focus-visible,
.pagination-go:focus-visible,
.pagination-input:focus-visible {
    outline: 3px solid rgb(233 193 118 / 35%);
    outline-offset: 2px;
}

.pagination-jump {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pagination-input {
    width: 72px;
    height: 40px;
    padding: 0 6px;
    border: 1px solid #c5a059;
    border-radius: 8px;
    background: #131315;
    color: #fff4e0;
    font-size: 15px;
    font-weight: 600;
    text-align: center;
}

.pagination-input:focus {
    outline: none;
    border-color: #e9c176;
}

.pagination-total {
    flex-shrink: 0;
    color: #ede0d4;
    font-size: 14px;
    white-space: nowrap;
}

.pagination-go {
    height: 40px;
    padding: 0 18px;
    border: 1px solid #f5ce72;
    border-radius: 8px;
    background: linear-gradient(180deg, #f5ce72 0%, #c5a059 100%);
    color: #3a2800;
    cursor: pointer;
    font-size: 14px;
    font-weight: 700;
    box-shadow: 0 2px 10px rgb(233 193 118 / 25%);
    transition: filter 160ms ease;
}

.pagination-go:hover {
    filter: brightness(1.08);
}

@media (max-width: 575.98px) {
    .pagination {
        gap: 8px;
        padding: 6px;
    }
}
</style>
