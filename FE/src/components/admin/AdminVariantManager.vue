<script setup>
// Quản lý biến thể của một sản phẩm trong form sửa (trang quản trị). Tự tải danh sách biến thể.
// API: api-admin-product-detail (đọc), api-admin-variant-create / -update / -delete (ghi).
// Quy tắc (BE kiểm tra lại): mỗi sản phẩm đúng 1 biến thể mặc định; không tắt bán / không xóa biến thể mặc định
// (đặt biến thể khác làm mặc định trước). Thuộc tính nhập tự do: 5 khóa mặc định + khóa tự do.
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/helpers/api/client'
import { showToast } from '@/helpers/ui/toast'
import { formatPrice } from '@/helpers/format/price'
import AdminTable from '@/components/admin/AdminTable.vue'

const props = defineProps({
  // ID sản phẩm đang sửa.
  productId: { type: Number, required: true },
})

// Mẫu key của khóa tự do: chữ thường không dấu, số, gạch dưới (khớp VariantAttributes::KEY_PATTERN ở BE).
const KEY_PATTERN = /^[a-z0-9_]{1,50}$/

const columns = [
  { key: 'variant_name', label: 'Biến thể' },
  { key: 'sku', label: 'SKU' },
  { key: 'price', label: 'Giá', align: 'right' },
  { key: 'stock', label: 'Kho', align: 'right' },
  { key: 'status', label: 'Trạng thái', align: 'center' },
  { key: 'actions', label: 'Thao tác', align: 'center' },
]

const variants = ref([])
const loading = ref(false)
const loadError = ref('')
// ID biến thể đang xử lý (đặt mặc định / bật tắt / xóa) để khóa nút; null = rảnh.
const busyId = ref(null)

// computed: 5 khóa mặc định (key + nhãn) lấy từ dữ liệu BE, để form thêm mới có đủ ô với nhãn đúng.
// BE luôn trả 5 khóa mặc định ở đầu mảng attributes của mọi biến thể.
const defaultKeys = computed(() => (variants.value[0]?.attributes ?? []).slice(0, 5).map((a) => ({ key: a.key, label: a.label })))

// Form thêm/sửa biến thể. editingId: null = đang thêm mới; số = đang sửa biến thể đó; editorOpen: có hiện form không.
const editorOpen = ref(false)
const editingId = ref(null)
const saving = ref(false)
const editor = reactive({
  variant_name: '', sku: '', price: '', sale_price: '', stock: '', is_active: true,
  defaults: {}, // { dung_tich: '113g', ... } giá trị 5 khóa mặc định
  customs: [], // [{ key, label, value }] khóa tự do
})
const editorErrors = reactive({ variant_name: '', sku: '', price: '', sale_price: '', stock: '', customs: '' })
// Số kho lúc mở form sửa. Chỉ khi admin đổi số kho mới gửi stock + expected_stock (= số này) để BE chống ghi đè:
// nếu trong lúc sửa có đơn hàng làm kho đổi, BE trả 409 kèm current_stock thay vì ghi đè mất đơn đó.
const originalStock = ref(null)
// Trạng thái bán lúc mở form sửa: chỉ gửi is_active khi admin đổi ô "Đang bán", để form không đè ngược
// trạng thái mới nhất trên DB.
const originalActive = ref(null)

// Tải danh sách biến thể (api-admin-product-detail trả product.variants, gồm cả biến thể đã tắt).
async function loadVariants() {
  loading.value = true
  loadError.value = ''
  try {
    const response = await api.get('/?action=api-admin-product-detail', { params: { id: props.productId } })
    variants.value = response.data?.product?.variants ?? []
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Không tải được danh sách biến thể.'
  } finally {
    loading.value = false
  }
}

// Mở form thêm mới: ô trống, thuộc tính mặc định rỗng.
function openCreate() {
  editingId.value = null
  originalStock.value = null
  Object.assign(editor, { variant_name: '', sku: '', price: '', sale_price: '', stock: '', is_active: true, customs: [] })
  editor.defaults = Object.fromEntries(defaultKeys.value.map((a) => [a.key, '']))
  clearErrors()
  editorOpen.value = true
}

// Mở form sửa: đổ dữ liệu biến thể vào form; tách thuộc tính thành 5 khóa mặc định + khóa tự do.
function openEdit(variant) {
  editingId.value = variant.id
  originalStock.value = variant.stock
  originalActive.value = variant.is_active
  Object.assign(editor, {
    variant_name: variant.variant_name,
    sku: variant.sku,
    price: variant.price,
    sale_price: variant.sale_price ?? '',
    stock: variant.stock,
    is_active: variant.is_active,
  })
  const attributes = variant.attributes ?? []
  editor.defaults = Object.fromEntries(attributes.slice(0, 5).map((a) => [a.key, a.value ?? '']))
  editor.customs = attributes.slice(5).map((a) => ({ key: a.key, label: a.label, value: a.value ?? '' }))
  clearErrors()
  editorOpen.value = true
}

function clearErrors() {
  for (const key of Object.keys(editorErrors)) editorErrors[key] = ''
}

// Kiểm tra form phía FE (BE vẫn kiểm tra lại). Trả true nếu hợp lệ.
function validateEditor() {
  clearErrors()
  if (!String(editor.variant_name).trim()) editorErrors.variant_name = 'Nhập tên biến thể (ví dụ: Hũ 113g).'
  if (!String(editor.sku).trim()) editorErrors.sku = 'Nhập SKU.'
  const price = Number(editor.price)
  if (editor.price === '' || !Number.isFinite(price) || price < 0) editorErrors.price = 'Giá phải là số không âm.'
  if (editor.sale_price !== '' && editor.sale_price !== null) {
    const sale = Number(editor.sale_price)
    if (!Number.isFinite(sale) || sale < 0) editorErrors.sale_price = 'Giá khuyến mãi phải là số không âm.'
    else if (!editorErrors.price && sale >= price) editorErrors.sale_price = 'Giá khuyến mãi phải thấp hơn giá gốc.'
  }
  const stock = Number(editor.stock)
  if (editor.stock === '' || !Number.isInteger(stock) || stock < 0) editorErrors.stock = 'Kho phải là số nguyên không âm.'

  const seen = new Set(defaultKeys.value.map((a) => a.key))
  for (const custom of editor.customs) {
    if (!KEY_PATTERN.test(custom.key)) { editorErrors.customs = `Key "${custom.key}" chỉ gồm chữ thường không dấu, số, gạch dưới (ví dụ: xuat_xu).`; break }
    if (seen.has(custom.key)) { editorErrors.customs = `Key "${custom.key}" bị trùng.`; break }
    if (!String(custom.label).trim()) { editorErrors.customs = 'Mỗi thuộc tính tự do cần có nhãn hiển thị.'; break }
    seen.add(custom.key)
  }
  return Object.values(editorErrors).every((message) => !message)
}

// Gửi thêm mới (POST api-admin-variant-create) hoặc sửa (PUT api-admin-variant-update&id=), body JSON.
// attributes gửi dạng mảng [{ key, label, value }]; giá trị rỗng gửi null (= chưa rõ).
// Lỗi: 422 dữ liệu sai, 409 trùng SKU/tên biến thể, 404 không thấy biến thể/sản phẩm.
async function saveEditor() {
  if (saving.value || !validateEditor()) return
  saving.value = true
  const toValue = (value) => (String(value ?? '').trim() === '' ? null : String(value).trim())
  const attributes = [
    ...defaultKeys.value.map((a) => ({ key: a.key, label: a.label, value: toValue(editor.defaults[a.key]) })),
    ...editor.customs.map((c) => ({ key: c.key, label: String(c.label).trim(), value: toValue(c.value) })),
  ]
  const isCreate = editingId.value === null
  const body = {
    variant_name: String(editor.variant_name).trim(),
    price: editor.price,
    sale_price: editor.sale_price === null ? '' : editor.sale_price,
    attributes,
  }
  // Trạng thái bán: tạo mới thì gửi; sửa thì chỉ gửi khi admin đổi ô "Đang bán".
  if (isCreate || editor.is_active !== originalActive.value) body.is_active = editor.is_active
  // SKU chỉ gửi khi tạo mới: BE khóa đổi SKU sau khi tạo (đơn hàng, đơn nháp AI dùng SKU để đối chiếu).
  if (isCreate) body.sku = String(editor.sku).trim()
  // Tồn kho: tạo mới thì gửi; sửa thì CHỈ gửi khi admin đổi số kho, kèm expected_stock (số kho lúc mở form).
  if (isCreate) {
    body.stock = editor.stock
  } else if (Number(editor.stock) !== Number(originalStock.value)) {
    body.stock = editor.stock
    body.expected_stock = originalStock.value
  }
  try {
    const response = isCreate
      ? await api.post('/?action=api-admin-variant-create', { product_id: props.productId, ...body })
      : await api.put(`/?action=api-admin-variant-update&id=${editingId.value}`, body)
    showToast(response.data?.message || 'Đã lưu biến thể.', 'success')
    editorOpen.value = false
    await loadVariants()
  } catch (error) {
    const data = error.response?.data
    // 409 kèm current_stock: kho vừa bị đơn hàng khác thay đổi. Giữ form mở, ĐẶT LẠI ô kho theo số hiện tại
    // (không giữ số admin gõ theo mốc cũ, nếu không bấm Lưu lần nữa sẽ ghi đè đúng đơn hàng vừa chen vào),
    // và cập nhật mốc so sánh. Admin nhập lại số kho mới dựa trên số hiện tại rồi lưu.
    if (error.response?.status === 409 && Number.isInteger(data?.current_stock)) {
      editorErrors.stock = `Kho vừa thay đổi, hiện còn ${data.current_stock}. Ô kho đã được đặt lại theo số này, hãy nhập lại số kho rồi lưu.`
      editor.stock = data.current_stock
      originalStock.value = data.current_stock
      await loadVariants()
    }
    showToast(data?.message || 'Không lưu được biến thể.', 'danger')
  } finally {
    saving.value = false
  }
}

// computed: khóa các thao tác nhanh trên bảng (đặt mặc định, bật/tắt bán, xóa) khi đang xử lý một dòng
// HOẶC khi form sửa đang mở: form giữ dữ liệu lúc mở, thao tác nhanh xen giữa sẽ bị lần lưu form đè ngược lại.
const rowActionsLocked = computed(() => busyId.value !== null || editorOpen.value)
const lockedHint = 'Đóng form sửa biến thể trước khi dùng thao tác này'

// Chạy một thao tác nhanh trên dòng (đặt mặc định, bật/tắt bán, xóa): khóa nút, báo kết quả, tải lại bảng.
async function runRowAction(variant, request) {
  if (rowActionsLocked.value) return
  busyId.value = variant.id
  try {
    const response = await request()
    showToast(response.data?.message || 'Đã cập nhật.', 'success')
  } catch (error) {
    showToast(error.response?.data?.message || 'Không thực hiện được.', 'danger')
  } finally {
    busyId.value = null
    await loadVariants()
  }
}

// Đặt làm mặc định: BE tự bỏ mặc định của biến thể cũ và bật bán biến thể này.
function makeDefault(variant) {
  runRowAction(variant, () => api.put(`/?action=api-admin-variant-update&id=${variant.id}`, { is_default: true }))
}

// Bật / tắt bán (không áp dụng cho biến thể mặc định: nút bị khóa).
function toggleActive(variant) {
  runRowAction(variant, () => api.put(`/?action=api-admin-variant-update&id=${variant.id}`, { is_active: !variant.is_active }))
}

// Xóa hẳn biến thể (không áp dụng cho biến thể mặc định). Hỏi xác nhận vì giỏ hàng đang chứa biến thể này bị xóa theo.
function removeVariant(variant) {
  const ok = window.confirm(
    `Xóa hẳn biến thể "${variant.variant_name}"?\n`
    + 'Khách đang có biến thể này trong giỏ sẽ bị mất khỏi giỏ. Đơn hàng cũ vẫn giữ lịch sử.\n'
    + 'Nếu chỉ muốn tạm ngừng bán, hãy dùng "Tắt bán".'
  )
  if (!ok) return
  runRowAction(variant, () => api.delete('/?action=api-admin-variant-delete', { params: { id: variant.id } }))
}

// Lý do không xóa được biến thể (hiện khi rê chuột lên nút Xóa đang bị khóa). Chuỗi rỗng = xóa được.
function deleteBlockedReason(variant) {
  if (variant.is_default) return 'Không xóa được biến thể mặc định'
  if (variant.order_count > 0) return `Đã có trong ${variant.order_count} đơn hàng, không xóa được. Hãy dùng "Tắt bán".`
  return ''
}

// Thêm / bỏ một dòng thuộc tính tự do.
function addCustom() {
  editor.customs.push({ key: '', label: '', value: '' })
}
function removeCustom(index) {
  editor.customs.splice(index, 1)
}

onMounted(loadVariants)
</script>

<template>
  <section class="admin-card" aria-labelledby="variant-title">
    <!-- Tiêu đề + nút thêm biến thể -->
    <div class="variant-head">
      <h2 id="variant-title">Biến thể ({{ variants.length }})</h2>
      <button type="button" class="admin-btn admin-btn--primary" :disabled="editorOpen && editingId === null" @click="openCreate">+ Thêm biến thể</button>
    </div>
    <p class="admin-hint">
      Biến thể mặc định là phiên bản hiện đầu tiên và quyết định giá "Từ" trên trang sản phẩm. Không tắt bán hoặc xóa được biến thể mặc định.
      Biến thể đã có trong đơn hàng thì không xóa được (giữ lịch sử đơn), hãy dùng "Tắt bán".
    </p>

    <!-- Bảng biến thể (AdminTable lo đang tải / lỗi có "Thử lại" / rỗng) -->
    <AdminTable
      :columns="columns"
      :rows="variants"
      row-key="id"
      caption="Danh sách biến thể"
      :loading="loading"
      :error="loadError"
      :on-retry="loadVariants"
      loading-text="Đang tải biến thể..."
      empty-text="Sản phẩm chưa có biến thể."
    >
      <template #cell-variant_name="{ row }">
        <strong>{{ row.variant_name }}</strong>
        <span v-if="row.is_default" class="variant-tag variant-tag--default">Mặc định</span>
      </template>
      <template #cell-sku="{ row }"><code class="variant-sku">{{ row.sku }}</code></template>
      <!-- Giá: có khuyến mãi thì hiện giá bán + gạch giá gốc -->
      <template #cell-price="{ row }">
        <span class="variant-price">{{ formatPrice(row.final_price) }}</span>
        <del v-if="row.sale_price !== null" class="variant-old">{{ formatPrice(row.price) }}</del>
      </template>
      <template #cell-stock="{ row }">
        <span :class="{ 'variant-out': row.stock <= 0 }">{{ row.stock }}</span>
      </template>
      <!-- Trạng thái bán + số đơn hàng đã có biến thể này (đã có đơn thì không xóa được, chỉ tắt bán) -->
      <template #cell-status="{ row }">
        <span class="variant-tag" :class="row.is_active ? 'variant-tag--on' : 'variant-tag--off'">{{ row.is_active ? 'Đang bán' : 'Ngừng bán' }}</span>
        <small v-if="row.order_count > 0" class="variant-orders">{{ row.order_count }} đơn</small>
      </template>
      <!-- Thao tác: nút nào không áp dụng cho biến thể mặc định thì khóa, kèm title giải thích -->
      <template #cell-actions="{ row }">
        <div class="variant-actions">
          <button type="button" class="admin-link" :disabled="busyId !== null" @click="openEdit(row)">Sửa</button>
          <button v-if="!row.is_default" type="button" class="admin-link" :disabled="rowActionsLocked" :title="editorOpen ? lockedHint : ''" @click="makeDefault(row)">Đặt mặc định</button>
          <button
            type="button"
            class="admin-link"
            :disabled="rowActionsLocked || row.is_default"
            :title="row.is_default ? 'Không tắt bán được biến thể mặc định' : (editorOpen ? lockedHint : '')"
            @click="toggleActive(row)"
          >{{ row.is_active ? 'Tắt bán' : 'Bật bán' }}</button>
          <!-- Xóa: khóa theo cờ can_delete của BE (biến thể mặc định, hoặc đã có trong đơn hàng) -->
          <button
            type="button"
            class="admin-link admin-link--danger"
            :disabled="rowActionsLocked || !row.can_delete"
            :title="deleteBlockedReason(row) || (editorOpen ? lockedHint : '')"
            @click="removeVariant(row)"
          >Xóa</button>
        </div>
      </template>
    </AdminTable>

    <!-- Form thêm / sửa biến thể (hiện dưới bảng khi bấm "Thêm biến thể" hoặc "Sửa") -->
    <form v-if="editorOpen" class="variant-editor" novalidate @submit.prevent="saveEditor">
      <h3>{{ editingId === null ? 'Thêm biến thể' : 'Sửa biến thể' }}</h3>
      <div class="variant-grid">
        <label class="admin-field">
          <span>Tên biến thể *</span>
          <input v-model="editor.variant_name" type="text" maxlength="255" placeholder="Hũ 113g" />
          <small v-if="editorErrors.variant_name" class="admin-error">{{ editorErrors.variant_name }}</small>
        </label>
        <!-- SKU: nhập khi tạo; khi sửa thì chỉ đọc (BE khóa đổi SKU sau khi tạo) -->
        <label class="admin-field">
          <span>SKU *</span>
          <input v-model="editor.sku" type="text" maxlength="100" placeholder="POMADE-ABC-113G" :readonly="editingId !== null" />
          <small v-if="editorErrors.sku" class="admin-error">{{ editorErrors.sku }}</small>
          <small v-else-if="editingId !== null" class="admin-hint">Không đổi được SKU sau khi tạo.</small>
        </label>
        <label class="admin-field">
          <span>Giá gốc (đ) *</span>
          <input v-model="editor.price" type="number" min="0" step="1000" />
          <small v-if="editorErrors.price" class="admin-error">{{ editorErrors.price }}</small>
        </label>
        <label class="admin-field">
          <span>Giá khuyến mãi (đ)</span>
          <input v-model="editor.sale_price" type="number" min="0" step="1000" placeholder="Để trống nếu không giảm" />
          <small v-if="editorErrors.sale_price" class="admin-error">{{ editorErrors.sale_price }}</small>
        </label>
        <label class="admin-field">
          <span>Tồn kho *</span>
          <input v-model="editor.stock" type="number" min="0" step="1" />
          <small v-if="editorErrors.stock" class="admin-error">{{ editorErrors.stock }}</small>
        </label>
        <label class="admin-check">
          <input v-model="editor.is_active" type="checkbox" :disabled="editingId !== null && variants.find((v) => v.id === editingId)?.is_default" />
          <span>Đang bán</span>
        </label>
      </div>

      <!-- Thuộc tính: 5 khóa mặc định (nhãn cố định) + khóa tự do (key không dấu, nhãn có dấu) -->
      <h4>Thuộc tính <small class="admin-hint">(để trống nếu chưa rõ)</small></h4>
      <div class="variant-grid">
        <label v-for="attribute in defaultKeys" :key="attribute.key" class="admin-field">
          <span>{{ attribute.label }}</span>
          <input v-model="editor.defaults[attribute.key]" type="text" maxlength="255" />
        </label>
      </div>
      <div v-for="(custom, index) in editor.customs" :key="index" class="variant-custom">
        <input v-model="custom.key" type="text" maxlength="50" placeholder="key (vd: xuat_xu)" aria-label="Key thuộc tính" />
        <input v-model="custom.label" type="text" maxlength="100" placeholder="Nhãn (vd: Xuất xứ)" aria-label="Nhãn thuộc tính" />
        <input v-model="custom.value" type="text" maxlength="255" placeholder="Giá trị" aria-label="Giá trị thuộc tính" />
        <button type="button" class="admin-link admin-link--danger" @click="removeCustom(index)">Bỏ</button>
      </div>
      <small v-if="editorErrors.customs" class="admin-error">{{ editorErrors.customs }}</small>
      <button type="button" class="admin-link variant-add-custom" @click="addCustom">+ Thêm thuộc tính khác</button>

      <div class="variant-editor-actions">
        <button type="button" class="admin-btn admin-btn--outline" @click="editorOpen = false">Hủy</button>
        <button type="submit" class="admin-btn admin-btn--primary" :disabled="saving">{{ saving ? 'Đang lưu...' : 'Lưu biến thể' }}</button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.admin-card { display: grid; gap: 14px; padding: 20px; border: 1px solid #e6e0d4; border-radius: 8px; background: #ffffff; }
.variant-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; }
.variant-head h2 { margin: 0; color: #1c2a3a; font-size: 16px; font-weight: 700; }
.admin-hint { margin: 0; color: #6b6560; font-size: 13px; font-weight: 400; }
.variant-tag { display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; white-space: nowrap; }
.variant-tag--default { background: #b8893e; color: #ffffff; }
.variant-tag--on { background: #e3f1e8; color: #2f7d4f; }
.variant-tag--off { background: #efeae0; color: #6b6560; }
.variant-sku { color: #4a5565; font-size: 12px; overflow-wrap: anywhere; }
.variant-price { white-space: nowrap; }
.variant-old { display: block; color: #6b6560; font-size: 12px; white-space: nowrap; }
.variant-out { color: #ba1a1a; font-weight: 600; }
.variant-orders { display: block; margin-top: 4px; color: #6b6560; font-size: 12px; }
.admin-field input[readonly] { background: #efeae0; color: #6b6560; cursor: not-allowed; }
.variant-actions { display: inline-flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
.admin-link { padding: 0; border: 0; background: none; color: #9e7432; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.admin-link:disabled { opacity: 0.45; cursor: not-allowed; }
.admin-link--danger { color: #ba1a1a; }
.variant-editor { display: grid; gap: 12px; padding: 16px; border-radius: 8px; background: #f7f4ee; }
.variant-editor h3 { margin: 0; color: #1c2a3a; font-size: 15px; }
.variant-editor h4 { margin: 4px 0 0; color: #1c2a3a; font-size: 14px; }
.variant-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); align-items: end; }
.admin-field { display: grid; gap: 6px; font-size: 14px; }
.admin-field > span { color: #4a5565; font-weight: 600; }
.admin-field input, .variant-custom input { width: 100%; min-height: 38px; padding: 6px 10px; border: 1px solid #d9d4ca; border-radius: 6px; background: #ffffff; font: inherit; }
.admin-check { display: flex; align-items: center; gap: 8px; min-height: 38px; font-weight: 600; color: #4a5565; }
.variant-custom { display: grid; gap: 8px; grid-template-columns: 1fr 1fr 1.5fr auto; align-items: center; }
.variant-add-custom { justify-self: start; }
.admin-error { color: #ba1a1a; font-size: 13px; }
.variant-editor-actions { display: flex; justify-content: flex-end; gap: 10px; }
.admin-btn { min-height: 38px; padding: 6px 16px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
.admin-btn--primary { border: 1px solid #b8893e; background: #b8893e; color: #ffffff; }
.admin-btn--primary:disabled { opacity: 0.6; cursor: not-allowed; }
.admin-btn--outline { border: 1px solid #1c2a3a; background: #ffffff; color: #1c2a3a; }
.admin-btn:focus-visible, .admin-link:focus-visible { outline: 3px solid #b8893e; outline-offset: 2px; }
@media (max-width: 575.98px) {
  .variant-custom { grid-template-columns: 1fr 1fr; }
}
</style>
