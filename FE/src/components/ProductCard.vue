//hoàng đẹp trai

<script setup>
// Import hàm định dạng tiền từ helper
import { formatPrice } from '@/helpers/format/price';
import { resolveImageUrl } from '@/helpers/media/imageUrl';

// Khai báo dữ liệu sản phẩm được truyền từ component cha
const props = defineProps({
    product: {
        type: Object,
        required: true
    }
});
</script>

<template>
    <!-- Khung chứa thông tin một sản phẩm -->
    <div class="product-card">

        <!-- Hiển thị hình ảnh sản phẩm -->
        <img v-if="product.image"
            :src="resolveImageUrl(product.image)"
            :alt="product.name"
        >

        <!-- Hiển thị tên sản phẩm -->
        <h3>{{ product.name }}</h3>

        <!-- Hiển thị giá sản phẩm -->
        <p>{{ formatPrice(product.price) }}</p>

    </div>
</template>

<style scoped>
/* CSS chỉ áp dụng trong component này */
.product-card {
    border: 1px solid #4e4639;
    padding: 10px;
    margin: 10px;
}

.product-card img {
    width: 150px;
    height: 150px;
    object-fit: cover;
}
</style>