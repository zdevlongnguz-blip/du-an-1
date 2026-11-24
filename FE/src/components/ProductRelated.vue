<script setup>
// Khối "Cùng danh mục bán chạy" ở trang chi tiết sản phẩm: gọi api-products-top theo danh mục,
// bỏ sản phẩm đang xem, hiện tối đa 5 sản phẩm bán chạy nhất bằng thẻ có sẵn ProductCardTop5.
import { ref, watch } from 'vue'
import api from '@/helpers/api/client'
import ProductCardTop5 from '@/components/ProductCardTop5.vue'
import StateBlock from '@/components/StateBlock.vue'

const props = defineProps({
  // ID danh mục của sản phẩm đang xem (product.category_id từ api-product-detail).
  categoryId: { type: Number, required: true },
  // ID sản phẩm đang xem, để BE bỏ nó ra khỏi danh sách.
  excludeId: { type: Number, required: true },
})

// Khối này luôn xếp theo lượt bán (đúng tên "bán chạy"). Card cũng dùng giá trị này để tô đậm ô "Lượt bán".
const SORT_BY = 'sales'

// Danh sách sản phẩm cùng danh mục do API trả về.
const products = ref([])
// Bắt đầu ở trạng thái tải để không nháy chữ "chưa có sản phẩm" trước request đầu tiên.
const loading = ref(true)
// Lỗi tải danh sách; chuỗi rỗng = không lỗi. Lỗi luôn hiện thành lỗi, không giả thành danh sách rỗng.
const errorMessage = ref('')
// Số thứ tự request: khách bấm sang sản phẩm khác liên tục thì chỉ nhận kết quả của request mới nhất,
// tránh kết quả cũ về sau ghi đè danh sách của sản phẩm đang xem.
let latestRequest = 0

// Gọi API lấy top sản phẩm cùng danh mục. Chạy khi khối vừa hiện, khi đổi sản phẩm và khi bấm "Thử lại".
async function fetchRelated() {
  const requestId = ++latestRequest
  loading.value = true
  errorMessage.value = ''

  try {
    // api-products-top trả { status, sort_by, products: [...] } (tối đa 5 sản phẩm).
    // category_id: chỉ lấy cùng danh mục; exclude_id: bỏ sản phẩm đang xem. Sai định dạng thì BE trả 400 kèm message.
    const response = await api.get('/?action=api-products-top', {
      params: { sort_by: SORT_BY, category_id: props.categoryId, exclude_id: props.excludeId },
    })
    if (requestId !== latestRequest) return

    if (response.data?.status !== 'success' || !Array.isArray(response.data.products)) {
      throw new Error('Dữ liệu sản phẩm cùng danh mục không hợp lệ.')
    }
    products.value = response.data.products
  } catch (error) {
    if (requestId !== latestRequest) return
    // Có message từ BE thì hiện nguyên văn; mất mạng / server tắt thì axios chỉ có "Network Error" (tiếng Anh),
    // nên dùng câu tiếng Việt thay thế.
    errorMessage.value = error.response?.data?.message || 'Không tải được sản phẩm cùng danh mục.'
  } finally {
    // finally luôn chạy dù thành công hay lỗi; chỉ request mới nhất mới được tắt trạng thái tải.
    if (requestId === latestRequest) loading.value = false
  }
}

// watch + immediate: tải ngay khi khối vừa hiện, và tải lại khi bấm sang sản phẩm khác.
// Cần watch vì trang chi tiết dùng lại component khi chỉ đổi slug, nên khối này không được tạo lại.
watch(() => [props.categoryId, props.excludeId], fetchRelated, { immediate: true })
</script>

<template>
  <section class="related" aria-labelledby="related-title">
    <h2 id="related-title" class="related-title">Cùng danh mục bán chạy</h2>

    <!-- StateBlock: đang tải -> lỗi có nút "Thử lại" -> rỗng (danh mục không còn sản phẩm khác) -> lưới sản phẩm. -->
    <StateBlock
      :loading="loading"
      :error="errorMessage"
      :empty="products.length === 0"
      loading-text="Đang tải sản phẩm cùng danh mục..."
      empty-text="Danh mục này chưa có sản phẩm nào khác."
      :on-retry="fetchRelated"
    >
      <!-- Lưới thẻ: số hạng theo lượt bán trong danh mục; bấm thẻ sẽ sang trang chi tiết sản phẩm đó. -->
      <div class="related-grid">
        <ProductCardTop5
          v-for="(item, index) in products"
          :key="item.id"
          :product="item"
          :rank="index + 1"
          :sort-by="SORT_BY"
        />
      </div>
    </StateBlock>
  </section>
</template>

<style scoped>
.related {
  margin-top: 8px;
  padding-top: 28px;
  border-top: 1px solid #e6e0d4;
}

.related-title {
  margin: 0 0 16px;
  color: #1f1f1f;
  font-family: var(--font-display);
  font-size: clamp(20px, 2.4vw, 24px);
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

/* Điện thoại: 2 cột (thẻ tự thu gọn nhờ container query trong ProductCardTop5).
   Màn rộng hơn: tự chia cột, mỗi thẻ rộng tối thiểu 200px, tối đa 5 thẻ một hàng. */
.related-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

@media (min-width: 768px) {
  .related-grid {
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
  }
}
</style>
