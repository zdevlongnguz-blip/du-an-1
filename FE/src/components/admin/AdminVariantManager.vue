<script setup>
// Quản lý biến thể của một sản phẩm trong form sửa (trang quản trị). Tự tải danh sách biến thể.
// API: api-admin-product-detail (đọc), api-admin-variant-create / -update / -delete (ghi),
//      api-admin-variant-image (đổi / bỏ ảnh riêng của biến thể, multipart).
// Quy tắc (BE kiểm tra lại): mỗi sản phẩm đúng 1 biến thể mặc định; không tắt bán / không xóa biến thể mặc định
// (đặt biến thể khác làm mặc định trước). Độ giữ nếp / độ bóng chọn từ thang cố định (cột số riêng ở BE);
// thuộc tính còn lại nhập tự do: các khóa mặc định + khóa tự do (BE gom vào metadata để khách lọc theo tag).
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/helpers/api/client'
import { showToast } from '@/helpers/ui/toast'
import { formatPrice } from '@/helpers/format/price'
import { resolveImageUrl } from '@/helpers/media/imageUrl'
import { toSlug } from '@/helpers/format/slug'
import { IMAGE_ACCEPT, validateImageFile } from '@/helpers/media/imageUpload'
import AdminTable from '@/components/admin/AdminTable.vue'

const props = defineProps({
  // ID sản phẩm đang sửa.
  productId: { type: Number, required: true },
})

// Mẫu key của khóa tự do: chữ thường không dấu, số, gạch dưới (khớp VariantAttributes::KEY_PATTERN ở BE).
const KEY_PATTERN = /^[a-z0-9_]{1,50}$/

// Đổi nhãn thuộc tính thành key: "Xuất xứ" -> "xuat_xu" (cùng cách sinh slug, chỉ nối bằng gạch dưới).
// Key chỉ sinh khi thêm mới; khóa đã lưu của biến thể cũ được giữ nguyên để không lệch dữ liệu đã có.
function keyFromLabel(label) {
  return toSlug(label).replace(/-/g, '_')
}
// Key thực tế của một thuộc tính tự do: khóa đã lưu (biến thể cũ) hoặc sinh từ nhãn (thêm mới).
function customKey(custom) {
  return custom.key || keyFromLabel(custom.label)
}

// Phần đuôi ngẫu nhiên của SKU gợi ý, ví dụ FULE-003-AWM: 3 ký tự chữ hoa và số.
const SKU_SUFFIX_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
const SKU_SUFFIX_LENGTH = 3
// Số lần tự sinh SKU khác khi SKU gợi ý bị trùng với sản phẩm khác (BE trả 409).
const SKU_RETRY_LIMIT = 3
// Chọn ngẫu nhiên từng ký tự bằng crypto (không đoán được, khác Math.random).
function randomSkuSuffix() {
  const bytes = new Uint32Array(SKU_SUFFIX_LENGTH)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (n) => SKU_SUFFIX_CHARS[n % SKU_SUFFIX_CHARS.length]).join('')
}

const columns = [
  { key: 'image', label: 'Ảnh', align: 'center' },
  { key: 'variant_name', label: 'Biến thể' },
  { key: 'sku', label: 'SKU' },
  { key: 'price', label: 'Giá', align: 'right' },
  { key: 'stock', label: 'Kho', align: 'right' },
  { key: 'status', label: 'Trạng thái', align: 'center' },
  { key: 'actions', label: 'Thao tác', align: 'center' },
]

const variants = ref([])
// Ảnh đại diện của sản phẩm: hiện mờ ở dòng biến thể chưa có ảnh riêng (khách cũng thấy ảnh này thay thế).
const productImage = ref('')
const loading = ref(false)
const loadError = ref('')
// ID biến thể đang xử lý (đặt mặc định / bật tắt / xóa) để khóa nút; null = rảnh.
const busyId = ref(null)

// Cấu hình thuộc tính do BE gửi trong api-admin-product-detail (product.attribute_config):
// { defaults: [{key, label}], hold_label, shine_label, hold_levels: [{value, label}], shine_levels: [{value, label}] }.
// Nhờ vậy FE không viết cứng số khóa mặc định hay nhãn mức; BE đổi cấu hình thì form tự theo.
const attributeConfig = ref({ defaults: [], hold_label: '', shine_label: '', hold_levels: [], shine_levels: [] })

// computed: các khóa mặc định (key + nhãn), để form có đủ ô với nhãn đúng.
const defaultKeys = computed(() => attributeConfig.value.defaults ?? [])

// Form thêm/sửa biến thể. editingId: null = đang thêm mới; số = đang sửa biến thể đó; editorOpen: có hiện form không.
const editorOpen = ref(false)
const editingId = ref(null)
const saving = ref(false)
// Mã sản phẩm (ví dụ FULE-003) làm tiền tố của SKU gợi ý; lấy từ api-admin-product-detail.
const productCode = ref('')
// true khi ô SKU đang là gợi ý do hệ thống sinh và admin chưa sửa tay: chỉ khi đó mới tự đổi SKU khi bị trùng.
const skuAuto = ref(false)
// Số lần đã tự sinh lại SKU trong lần lưu hiện tại (reset khi mở form thêm mới).
let skuRetries = 0
const editor = reactive({
  variant_name: '', sku: '', price: '', sale_price: '', stock: '', is_active: true,
  // Mức đang chọn trong ô select: '' = chưa rõ; còn lại là số dạng chuỗi ('1', '2'...) vì select luôn trả chuỗi.
  hold_level: '', shine_level: '',
  defaults: {}, // { dung_tich: '113g', ... } giá trị các khóa mặc định
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
    productImage.value = response.data?.product?.image ?? ''
    productCode.value = response.data?.product?.code ?? ''
    if (response.data?.product?.attribute_config) attributeConfig.value = response.data.product.attribute_config
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Không tải được danh sách biến thể.'
  } finally {
    loading.value = false
  }
}

// Sinh SKU gợi ý = mã sản phẩm + "-" + 3 ký tự ngẫu nhiên, không trùng SKU đang có trong sản phẩm này.
// Trùng với sản phẩm khác thì BE trả 409 và FE sinh lại khi lưu (xem saveEditor).
function suggestSku() {
  if (!productCode.value) return ''
  const taken = new Set(variants.value.map((v) => String(v.sku ?? '').toLowerCase()))
  let candidate = ''
  for (let attempt = 0; attempt < 50; attempt++) {
    candidate = `${productCode.value}-${randomSkuSuffix()}`
    if (!taken.has(candidate.toLowerCase())) break
  }
  return candidate
}

// Mở form thêm mới: ô trống, thuộc tính mặc định rỗng, SKU gợi ý tự điền.
function openCreate() {
  editingId.value = null
  originalStock.value = null
  Object.assign(editor, { variant_name: '', sku: '', price: '', sale_price: '', stock: '', is_active: true, hold_level: '', shine_level: '', customs: [] })
  editor.defaults = Object.fromEntries(defaultKeys.value.map((a) => [a.key, '']))
  editor.sku = suggestSku()
  skuAuto.value = true
  skuRetries = 0
  clearErrors()
  editorOpen.value = true
}

// Mở form sửa: đổ dữ liệu biến thể vào form; tách thuộc tính thành khóa mặc định + khóa tự do, kèm 2 mức giữ nếp / độ bóng.
function openEdit(variant) {
  editingId.value = variant.id
  // Biến thể đã có SKU thật: không phải gợi ý nên không tự đổi.
  skuAuto.value = false
  originalStock.value = variant.stock
  originalActive.value = variant.is_active
  Object.assign(editor, {
    variant_name: variant.variant_name,
    sku: variant.sku,
    price: variant.price,
    sale_price: variant.sale_price ?? '',
    stock: variant.stock,
    is_active: variant.is_active,
    hold_level: variant.hold_level === null || variant.hold_level === undefined ? '' : String(variant.hold_level),
    shine_level: variant.shine_level === null || variant.shine_level === undefined ? '' : String(variant.shine_level),
  })
  // Tách thuộc tính theo KHÓA (không theo vị trí): khóa nằm trong danh sách mặc định -> ô mặc định, còn lại -> khóa tự do.
  const defaultKeySet = new Set(defaultKeys.value.map((a) => a.key))
  const attributes = variant.attributes ?? []
  editor.defaults = Object.fromEntries(defaultKeys.value.map((a) => [a.key, attributes.find((item) => item.key === a.key)?.value ?? '']))
  editor.customs = attributes.filter((a) => !defaultKeySet.has(a.key)).map((a) => ({ key: a.key, label: a.label, value: a.value ?? '' }))
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
    if (!String(custom.label).trim()) { editorErrors.customs = 'Mỗi thuộc tính tự do cần có nhãn hiển thị.'; break }
    const key = customKey(custom)
    if (!KEY_PATTERN.test(key)) { editorErrors.customs = `Nhãn "${custom.label}" không tạo được key hợp lệ, hãy đổi nhãn (ví dụ: Xuất xứ).`; break }
    if (seen.has(key)) { editorErrors.customs = `Thuộc tính "${custom.label}" bị trùng key ${key}, hãy đổi nhãn.`; break }
    seen.add(key)
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
    ...editor.customs.map((c) => ({ key: customKey(c), label: String(c.label).trim(), value: toValue(c.value) })),
  ]
  const isCreate = editingId.value === null
  const body = {
    variant_name: String(editor.variant_name).trim(),
    price: editor.price,
    sale_price: editor.sale_price === null ? '' : editor.sale_price,
    // '' (chưa rõ) gửi null; còn lại gửi số. BE kiểm tra lại thang (422 nếu ngoài thang).
    hold_level: editor.hold_level === '' ? null : Number(editor.hold_level),
    shine_level: editor.shine_level === '' ? null : Number(editor.shine_level),
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
  // retrying: true khi đang tự lưu lại với SKU mới; lúc đó không tắt trạng thái đang lưu.
  let retrying = false
  try {
    const response = isCreate
      ? await api.post('/?action=api-admin-variant-create', { product_id: props.productId, ...body })
      : await api.put(`/?action=api-admin-variant-update&id=${editingId.value}`, body)
    showToast(response.data?.message || 'Đã lưu biến thể.', 'success')
    editorOpen.value = false
    await loadVariants()
  } catch (error) {
    const data = error.response?.data
    // SKU gợi ý bị trùng với sản phẩm khác (409): sinh SKU khác rồi lưu lại, tối đa SKU_RETRY_LIMIT lần.
    // Admin đã tự nhập SKU, hoặc lỗi không phải SKU, thì không tự đổi mà chỉ báo lỗi bên dưới.
    if (isCreate && skuAuto.value && error.response?.status === 409 && skuRetries < SKU_RETRY_LIMIT) {
      skuRetries += 1
      editor.sku = suggestSku()
      retrying = true
      saving.value = false
      return saveEditor()
    }
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
    if (!retrying) saving.value = false
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
// Hỏi xác nhận trước vì thao tác đổi ngay biến thể hiện đầu tiên cho khách và biến thể trợ lý AI chọn sẵn.
// (Giá "Từ" là giá thấp nhất của các biến thể đang bán, không phụ thuộc biến thể mặc định.)
function makeDefault(variant) {
  if (rowActionsLocked.value) return
  // Tìm biến thể đang là mặc định để nêu tên trong câu hỏi, giúp admin biết sẽ thay biến thể nào.
  const current = variants.value.find((v) => v.is_default)
  const ok = window.confirm(
    `Đặt "${variant.variant_name}" làm biến thể mặc định?\n`
    + (current ? `Biến thể "${current.variant_name}" sẽ không còn là mặc định.\n` : '')
    + 'Biến thể mới sẽ hiện đầu tiên trên trang sản phẩm và được trợ lý AI chọn khi khách không nói rõ phiên bản.'
    // Lưu ý riêng cho biến thể đang tắt bán: BE bắt buộc biến thể mặc định phải đang bán, nên sẽ tự mở bán lại.
    + (variant.is_active ? '' : '\n\nLưu ý: biến thể này đang tắt bán, nếu đặt làm mặc định sẽ được mở bán trở lại.')
  )
  if (!ok) return
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

// Ô chọn file ảnh dùng chung cho mọi dòng (ẩn), và biến thể đang được đổi ảnh.
const imageInput = ref(null)
const imageTarget = ref(null)

// Bấm "Đổi ảnh" trên một dòng: ghi nhớ biến thể rồi mở hộp chọn file của trình duyệt.
function pickVariantImage(variant) {
  if (busyId.value !== null) return
  imageTarget.value = variant
  imageInput.value?.click()
}

// Gửi ảnh riêng của biến thể lên BE (api-admin-variant-image, POST multipart/form-data).
// body: FormData có file `image` (đổi ảnh) hoặc `remove=1` (bỏ ảnh riêng). Thành công thì tải lại bảng.
// Không khóa theo form sửa đang mở: form sửa không gửi ảnh, nên không bị lần lưu form đè ngược.
async function sendVariantImage(variant, body, fallbackMessage) {
  busyId.value = variant.id
  try {
    // Ghi đè Content-Type: client.js mặc định gửi JSON, axios sẽ làm mất file nếu không đổi header.
    const response = await api.post('/?action=api-admin-variant-image', body, {
      params: { id: variant.id },
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    showToast(response.data?.message || fallbackMessage, 'success')
  } catch (error) {
    showToast(error.response?.data?.message || 'Không cập nhật được ảnh biến thể.', 'danger')
  } finally {
    busyId.value = null
    await loadVariants()
  }
}

// Admin đã chọn file: kiểm tra loại + dung lượng (helper dùng chung, khớp giới hạn BE) rồi tải lên ngay.
async function onVariantImageChange(event) {
  const file = event.target.files?.[0]
  const variant = imageTarget.value
  // Xóa lựa chọn để lần sau chọn lại đúng file đó vẫn kích hoạt sự kiện change.
  event.target.value = ''
  imageTarget.value = null
  if (!file || !variant) return

  const error = validateImageFile(file)
  if (error) {
    showToast(error, 'danger')
    return
  }
  const body = new FormData()
  body.append('image', file)
  await sendVariantImage(variant, body, 'Đã cập nhật ảnh biến thể.')
}

// Bỏ ảnh riêng: biến thể dùng lại ảnh sản phẩm. Hỏi xác nhận vì file ảnh cũ bị xóa khỏi máy chủ.
function removeVariantImage(variant) {
  if (busyId.value !== null) return
  if (!window.confirm(`Bỏ ảnh riêng của biến thể "${variant.variant_name}"?
Biến thể sẽ dùng ảnh đại diện của sản phẩm.`)) return
  const body = new FormData()
  body.append('remove', '1')
  sendVariantImage(variant, body, 'Đã bỏ ảnh riêng của biến thể.')
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
      Biến thể mặc định là phiên bản hiện đầu tiên trên trang sản phẩm và được trợ lý AI chọn khi khách không nói rõ phiên bản. Không tắt bán hoặc xóa được biến thể mặc định.
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
      <!-- Ảnh: ảnh riêng của biến thể; chưa có thì hiện mờ ảnh sản phẩm (khách thấy ảnh đó thay thế) -->
      <template #cell-image="{ row }">
        <div class="variant-image">
          <img
            v-if="row.image || productImage"
            :src="resolveImageUrl(row.image || productImage)"
            :alt="`Ảnh ${row.variant_name}`"
            :class="{ 'variant-image--fallback': !row.image }"
            :title="row.image ? '' : 'Chưa có ảnh riêng, đang dùng ảnh sản phẩm'"
            loading="lazy"
          />
          <span v-else class="variant-image-empty" aria-hidden="true">—</span>
          <button type="button" class="admin-link" :disabled="busyId !== null" @click="pickVariantImage(row)">
            {{ busyId === row.id ? 'Đang lưu...' : (row.image ? 'Đổi ảnh' : 'Thêm ảnh') }}
          </button>
          <button v-if="row.image" type="button" class="admin-link admin-link--danger" :disabled="busyId !== null" @click="removeVariantImage(row)">Bỏ ảnh</button>
        </div>
      </template>
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
    <!-- Ô chọn file ẩn, dùng chung cho nút "Đổi ảnh" / "Thêm ảnh" của mọi dòng -->
    <input ref="imageInput" type="file" class="d-none" :accept="IMAGE_ACCEPT" @change="onVariantImageChange" />

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
          <input v-model="editor.sku" type="text" maxlength="100" placeholder="POMADE-ABC-113G" :readonly="editingId !== null" @input="skuAuto = false" />
          <small v-if="editorErrors.sku" class="admin-error">{{ editorErrors.sku }}</small>
          <small v-else-if="editingId !== null" class="admin-hint">Không đổi được SKU sau khi tạo.</small>
          <small v-else-if="skuAuto" class="admin-hint">Gợi ý từ mã sản phẩm, bạn có thể sửa.</small>
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

      <!-- Thuộc tính: 2 tiêu chí lọc dạng chọn (độ giữ nếp, độ bóng; lưu ở cột số riêng)
           + các khóa mặc định (nhãn cố định) + khóa tự do (key không dấu, nhãn có dấu).
           Khóa mặc định và thang mức lấy từ attribute_config do BE gửi, FE không viết cứng. -->
      <h4>Thuộc tính <small class="admin-hint">(để trống nếu chưa rõ)</small></h4>
      <div class="variant-grid">
        <label class="admin-field">
          <span>{{ attributeConfig.hold_label || 'Độ giữ nếp' }}</span>
          <select v-model="editor.hold_level">
            <option value="">Chưa rõ</option>
            <option v-for="level in attributeConfig.hold_levels" :key="level.value" :value="String(level.value)">
              {{ level.value }} - {{ level.label }}
            </option>
          </select>
        </label>
        <label class="admin-field">
          <span>{{ attributeConfig.shine_label || 'Độ bóng' }}</span>
          <select v-model="editor.shine_level">
            <option value="">Chưa rõ</option>
            <option v-for="level in attributeConfig.shine_levels" :key="level.value" :value="String(level.value)">
              {{ level.value }} - {{ level.label }}
            </option>
          </select>
        </label>
        <label v-for="attribute in defaultKeys" :key="attribute.key" class="admin-field">
          <span>{{ attribute.label }}</span>
          <input v-model="editor.defaults[attribute.key]" type="text" maxlength="255" />
        </label>
      </div>
      <div v-for="(custom, index) in editor.customs" :key="index" class="variant-custom">
        <div class="variant-custom-label">
          <input v-model="custom.label" type="text" maxlength="100" placeholder="Nhãn (vd: Xuất xứ)" aria-label="Nhãn thuộc tính" />
          <!-- Key tự sinh từ nhãn, chỉ để xem; admin không nhập key nữa. -->
          <small v-if="custom.label" class="admin-hint">Key: {{ customKey(custom) }}</small>
        </div>
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
/* Rê chuột vào nút thao tác thì gạch chân, giống trang danh sách sản phẩm; nút đang khóa thì không. */
.admin-link:hover:not(:disabled) { text-decoration: underline; }
.admin-link:disabled { opacity: 0.45; cursor: not-allowed; }
.admin-link--danger { color: #ba1a1a; }
.variant-image { display: grid; justify-items: center; gap: 4px; }
.variant-image img { width: 48px; height: 48px; object-fit: cover; border: 1px solid #e6e0d4; border-radius: 6px; background: #f7f4ee; }
/* Ảnh sản phẩm dùng thay thế: làm mờ để admin nhận ra biến thể này chưa có ảnh riêng. */
.variant-image img.variant-image--fallback { opacity: 0.45; border-style: dashed; }
.variant-image-empty { display: grid; place-items: center; width: 48px; height: 48px; border: 1px dashed #d9d4ca; border-radius: 6px; color: #6b6560; }
.variant-editor { display: grid; gap: 12px; padding: 16px; border-radius: 8px; background: #f7f4ee; }
.variant-editor h3 { margin: 0; color: #1c2a3a; font-size: 15px; }
.variant-editor h4 { margin: 4px 0 0; color: #1c2a3a; font-size: 14px; }
.variant-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); align-items: end; }
.admin-field { display: grid; gap: 6px; font-size: 14px; }
.admin-field > span { color: #4a5565; font-weight: 600; }
.admin-field input, .admin-field select, .variant-custom input { width: 100%; min-height: 38px; padding: 6px 10px; border: 1px solid #d9d4ca; border-radius: 6px; background: #ffffff; font: inherit; }
.admin-check { display: flex; align-items: center; gap: 8px; min-height: 38px; font-weight: 600; color: #4a5565; }
.variant-custom { display: grid; gap: 8px; grid-template-columns: 1fr 1.5fr auto; align-items: center; }
.variant-custom-label { display: grid; gap: 4px; }
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
