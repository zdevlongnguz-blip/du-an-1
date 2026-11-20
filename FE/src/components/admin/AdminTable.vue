<script setup>
// Bảng dữ liệu dùng chung cho các trang quản trị (sản phẩm, danh mục, đơn hàng...). Không tự gọi API:
// trang cha tải dữ liệu rồi truyền vào qua props. Trạng thái tải / lỗi / rỗng dùng StateBlock (mục 13 FE/CLAUDE.md).
//
// Cách dùng:
//   <AdminTable :columns="columns" :rows="products" row-key="id" :loading="loading" :error="error" :on-retry="load">
//     <template #cell-name="{ row }"><strong>{{ row.name }}</strong></template>
//   </AdminTable>
// - columns: [{ key: 'name', label: 'Tên', align: 'left' | 'center' | 'right', width: '120px' }]
// - Cột có slot tên "cell-<key>" thì trang cha tự vẽ ô đó (ảnh, giá, nút thao tác...).
//   Cột không có slot thì in thẳng giá trị row[key].
// Phân trang để ở trang cha (dùng PaginationBar), không nằm trong bảng.
import StateBlock from '@/components/StateBlock.vue'

defineProps({
  // Danh sách cột theo thứ tự hiển thị.
  columns: { type: Array, required: true },
  // Các dòng dữ liệu (mảng object).
  rows: { type: Array, default: () => [] },
  // Tên trường làm khóa duy nhất cho mỗi dòng (dùng cho :key của v-for).
  rowKey: { type: String, default: 'id' },
  // Ba trạng thái chuyển thẳng cho StateBlock.
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  onRetry: { type: Function, default: null },
  loadingText: { type: String, default: 'Đang tải dữ liệu...' },
  emptyText: { type: String, default: 'Chưa có dữ liệu.' },
  // Mô tả bảng cho trình đọc màn hình (thẻ <caption> ẩn).
  caption: { type: String, default: '' },
})
</script>

<template>
  <!-- StateBlock lo thứ tự: đang tải -> lỗi (có "Thử lại" nếu truyền onRetry) -> rỗng -> bảng. -->
  <StateBlock
    :loading="loading"
    :error="error"
    :empty="rows.length === 0"
    :on-retry="onRetry"
    :loading-text="loadingText"
    :empty-text="emptyText"
  >
    <!-- Khung cuộn ngang: bảng rộng hơn màn hình (điện thoại) thì cuộn trong khung, trang không bị tràn. -->
    <div class="admin-table-wrap">
      <table class="admin-table">
        <caption v-if="caption" class="visually-hidden">{{ caption }}</caption>
        <thead>
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
              :style="{ width: column.width, textAlign: column.align || 'left' }"
            >{{ column.label }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row[rowKey]">
            <td v-for="column in columns" :key="column.key" :style="{ textAlign: column.align || 'left' }">
              <!-- slot động: tên slot là "cell-" + key của cột, truyền cả dòng (row) để trang cha tự vẽ. -->
              <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] }}</slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </StateBlock>
</template>

<style scoped>
/* Chỉ cuộn ngang. Khóa chiều dọc: màn hình phóng to (ví dụ 150%) làm chiều cao lệch 1px sinh thanh cuộn dọc thừa. */
.admin-table-wrap {
  overflow-x: auto;
  overflow-y: hidden;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
}

.admin-table {
  width: 100%;
  min-width: 720px;
  border-collapse: collapse;
  font-size: 14px;
}

.admin-table th {
  padding: 12px 14px;
  border-bottom: 1px solid #e6e0d4;
  background: #efeae0;
  color: #4a5565;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
}

.admin-table td {
  padding: 12px 14px;
  border-bottom: 1px solid #efeae0;
  color: #1c2a3a;
  vertical-align: middle;
}

.admin-table tbody tr:last-child td {
  border-bottom: 0;
}

.admin-table tbody tr:hover {
  background: #f7f4ee;
}
</style>
