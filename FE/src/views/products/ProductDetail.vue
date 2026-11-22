<script setup>
// Trang chi tiết sản phẩm: khi mở trang gọi api-product-detail và api-product-comments theo slug.
// Nút bình luận chỉ ẩn hoặc hiện khu vực, không quyết định thời điểm tải dữ liệu.
// Khu vực mua hàng và bình luận được xử lý trực tiếp tại trang này.
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/helpers/api/client'
import { useTabVisible } from '@/helpers/ui/tabVisible'
import { getUser, isAuthenticated } from '@/helpers/auth/session'
import { refreshCartCount } from '@/helpers/cart/cartCount'
import { useToast } from '@/helpers/ui/toast'
import { formatPrice } from '@/helpers/format/price'
import { resolveImageUrl } from '@/helpers/media/imageUrl'
import { saveReturnUrl } from '@/helpers/auth/returnUrl'
import { listVariantAttributes } from '@/helpers/format/variantAttributes'
import StateBlock from '@/components/StateBlock.vue'

// Số bình luận mỗi trang, khớp với query per_page đã thống nhất với BE.
const COMMENTS_PER_PAGE = 10

const route = useRoute()
const { showToast } = useToast()

// ref: biến "phản ứng", khi giá trị đổi thì giao diện tự cập nhật.
// Dữ liệu sản phẩm từ api-product-detail; null khi đang tải hoặc bị lỗi.
const product = ref(null)
// Bắt đầu ở trạng thái tải để không nháy giao diện rỗng trước request đầu tiên.
const productLoading = ref(true)
// Thông báo lỗi khi không tải được sản phẩm (slug sai, server lỗi...).
const productError = ref('')

// Vị trí ảnh đang hiển thị lớn trong album.
const activeImageIndex = ref(0)
// Vị trí các ảnh tải lỗi, để thay bằng khung "SB" thay vì hiện ảnh hỏng.
const failedImages = ref(new Set())

// Danh sách bình luận. null = chưa từng tải, khác với [] = đã tải nhưng không có bình luận.
const comments = ref(null)
// Thông tin phân trang bình luận do BE trả về (trang hiện tại, tổng số...).
const commentsPagination = ref(null)
const commentsLoading = ref(false)
const commentsError = ref('')
// Khu vực bình luận đang mở hay thu gọn. Ẩn/hiện lại không gọi API lần nữa.
const commentsVisible = ref(false)

// Trạng thái gửi bình luận mới.
const newCommentContent = ref('')
const newCommentError = ref('')
const commentSubmitting = ref(false)

// Trạng thái sửa bình luận inline.
const editingCommentId = ref(null)
const editingCommentContent = ref('')
const editingCommentError = ref('')
const commentUpdatingId = ref(null)
const commentDeletingId = ref(null)

// Số lượng nhập riêng cho từng biến thể, key là id biến thể.
const variantQuantities = ref({})
// ID các biến thể khách đã tích checkbox để thêm vào giỏ bằng nút chung. Dùng Set để thêm/bỏ/kiểm tra nhanh.
// Gán Set MỚI mỗi lần đổi (không sửa Set cũ) để Vue nhận ra thay đổi và vẽ lại checkbox.
const selectedVariantIds = ref(new Set())
// true khi đang gửi các biến thể đã tích lên giỏ; khóa nút chung, checkbox và bộ tăng giảm để tránh gửi lặp.
const addingSelected = ref(false)
// Số lượng mỗi biến thể đang nằm trong giỏ của người dùng, key là id biến thể (chỉ có khi đã đăng nhập).
const cartQuantities = ref({})

// Thẻ <p> chứa mô tả, gắn bằng ref="descriptionEl" trong template để đo chiều cao thật của chữ.
const descriptionEl = ref(null)
// true khi khách bấm "Xem thêm" (đang hiện đủ mô tả). Mặc định thu gọn.
const descriptionExpanded = ref(false)
// true khi mô tả dài hơn khung thu gọn, chỉ lúc đó mới hiện nút "Xem thêm" / "Ẩn bớt".
const descriptionOverflows = ref(false)

// Đo xem mô tả có bị khung thu gọn cắt bớt không: scrollHeight (chiều cao đủ nội dung) lớn hơn
// clientHeight (chiều cao khung đang hiện) nghĩa là còn chữ bị ẩn. Đo bằng chiều cao thật thay vì đếm ký tự,
// vì cùng số ký tự nhưng màn hình rộng/hẹp sẽ ra số dòng khác nhau.
// nextTick: chờ Vue vẽ xong mô tả mới lên trang rồi mới đo, đo sớm hơn sẽ ra số của nội dung cũ.
async function measureDescription() {
  descriptionExpanded.value = false
  descriptionOverflows.value = false
  await nextTick()
  const el = descriptionEl.value
  if (el) descriptionOverflows.value = el.scrollHeight > el.clientHeight + 1
}

// watch: đo lại mỗi khi mô tả đổi (mở trang, hoặc chuyển sang sản phẩm khác).
watch(() => product.value?.description, measureDescription)

// Chỉ dùng để chọn giao diện; BE vẫn xác minh JWT và quyền sở hữu.
const loggedIn = isAuthenticated()
const currentUser = getUser()
const currentUserId = currentUser?.id ?? currentUser?.user_id ?? null

// computed: giá trị tự tính lại mỗi khi dữ liệu nó dùng thay đổi.
// Album ảnh: BE đã đưa ảnh đại diện (product.image) lên đầu product.images; nếu rỗng thì dùng product.image.
// Sau ảnh slide là ảnh riêng của từng biến thể (variant.image), bỏ ảnh trùng để không hiện 2 lần.
const galleryImages = computed(() => {
  if (!product.value) return []

  const images = Array.isArray(product.value.images)
    ? product.value.images.map((item) => item.image_path).filter(Boolean)
    : []

  if (images.length === 0 && product.value.image) {
    images.push(product.value.image)
  }

  const variants = Array.isArray(product.value.variants) ? product.value.variants : []
  for (const variant of variants) {
    if (variant.image && !images.includes(variant.image)) {
      images.push(variant.image)
    }
  }

  return images
})

// Ảnh hiện ở cột trái thẻ biến thể: ảnh riêng của biến thể, chưa có thì dùng ảnh sản phẩm ('' nếu không có ảnh nào).
function variantPhoto(variant) {
  return variant.image || product.value?.image || ''
}

// Vị trí ảnh của biến thể trong album (-1 nếu không có trong album). Dùng chung chỉ số với album
// để đánh dấu ảnh đang xem và để ghi nhớ ảnh tải lỗi (failedImages).
function variantImageIndex(variant) {
  const photo = variantPhoto(variant)
  return photo ? galleryImages.value.indexOf(photo) : -1
}

// Bấm ảnh trên thẻ biến thể: chuyển ảnh lớn của album sang ảnh của biến thể đó.
function showVariantImage(variant) {
  const index = variantImageIndex(variant)
  if (index >= 0) activeImageIndex.value = index
}

const activeImage = computed(() => galleryImages.value[activeImageIndex.value] ?? '')

// Khoảng giá (đã tính giảm giá) của các biến thể đang bán, do BE tổng hợp; null nếu không có biến thể.
const priceRange = computed(() => {
  const min = product.value?.min_final_price
  const max = product.value?.max_final_price
  if (min == null || max == null) return null
  return { min: Number(min), max: Number(max) }
})

// Hết hàng khi tổng tồn kho của mọi biến thể bằng 0.
const isOutOfStock = computed(() => product.value != null && Number(product.value.stock ?? 0) <= 0)

// Số đã nằm trong giỏ của biến thể này (0 nếu chưa có hoặc chưa đăng nhập).
function variantInCart(variant) {
  return Math.max(0, Math.floor(Number(cartQuantities.value[variant.id]) || 0))
}

// Số còn có thể thêm = tồn kho trừ số đã có trong giỏ. Tồn kho là số của lần tải/tra gần nhất, có thể đã cũ,
// nên mỗi lần bấm "+"/"−" đều tra lại kho thật trước (stepVariantQuantity), và BE vẫn kiểm tra lại khi thêm.
function variantMaxQuantity(variant) {
  const stock = Math.max(0, Math.floor(Number(variant.stock) || 0))
  return Math.max(0, stock - variantInCart(variant))
}

// Có giá giảm khi sale_price của biến thể hợp lệ và nhỏ hơn giá gốc.
function variantIsOnSale(variant) {
  const salePrice = Number(variant.sale_price)
  return variant.sale_price != null && salePrice > 0 && salePrice < Number(variant.price)
}

// Số lượng đang chọn, luôn kẹp không vượt số còn có thể thêm (phòng khi giỏ vừa thay đổi).
function getVariantQuantity(variant) {
  const max = variantMaxQuantity(variant)
  if (max <= 0) return 0
  return Math.min(variantQuantities.value[variant.id] ?? 1, max)
}

// Ghi số lượng đang chọn của một biến thể, kẹp trong khoảng 1 đến số còn có thể thêm.
function setVariantQuantity(variant, value) {
  variantQuantities.value = {
    ...variantQuantities.value,
    [variant.id]: Math.min(Math.max(1, value), variantMaxQuantity(variant)),
  }
}

// ID biến thể đang gửi tra kho vì bấm "+"/"−" (null = rảnh). Trong lúc chờ server, mọi bộ tăng giảm trên trang
// đều bị khóa (giống trang Giỏ hàng khóa nút khi đang cập nhật), vì một lần tra cập nhật kho của mọi biến thể.
const checkingStockVariantId = ref(null)

// Tra tồn kho THẬT của mọi biến thể trong sản phẩm đang xem (MỘT request), rồi ghi đè variant.stock trên trang.
// API api-product-stock (GET, công khai). Không dùng api-product-detail vì API đó tăng lượt xem; không dùng
// api-order-draft-items vì API đó kẹp kho tối đa 99 (dành cho đơn nháp AI), làm trang báo sai số còn lại.
// Thành công: { status, variants: [{ id, sku, stock, is_active }] }. Biến thể đã tắt bán coi như kho 0.
// Lỗi mạng/server thì ném lỗi cho nơi gọi xử lý.
async function refreshProductStock() {
  const productId = product.value?.id
  if (!productId) return
  const response = await api.get('/?action=api-product-stock', { params: { id: productId } })
  if (response.data?.status !== 'success' || !Array.isArray(response.data?.variants)) {
    throw new Error('Dữ liệu tồn kho trả về không hợp lệ.')
  }
  // Chống "race": trong lúc chờ, khách có thể đã chuyển sang sản phẩm khác; khi đó bỏ kết quả cũ.
  if (product.value?.id !== productId) return

  const freshById = new Map(response.data.variants.map((item) => [Number(item.id), item]))
  const variants = Array.isArray(product.value.variants) ? product.value.variants : []
  for (const variant of variants) {
    const fresh = freshById.get(Number(variant.id))
    // Không còn trong kết quả = biến thể vừa bị xóa: coi như hết hàng (BE vẫn chặn nếu khách cố thêm).
    // variant là object nằm trong product.value (ref), sửa trực tiếp thì Vue vẫn nhận ra và vẽ lại số "Còn N".
    variant.stock = fresh && fresh.is_active === true ? Math.max(0, Math.floor(Number(fresh.stock) || 0)) : 0
  }
  // Cập nhật luôn tổng tồn kho của sản phẩm (dòng "Còn N sản phẩm" / "Hết hàng" phía trên) theo số mới.
  product.value.stock = variants.reduce((sum, variant) => sum + (Number(variant.stock) || 0), 0)
}

// Khách quay lại tab trang sản phẩm (sau khi xem tab khác): tra lại kho mọi biến thể và số đã có trong giỏ
// trong im lặng (không hiện "đang tải"), để số "Còn N" và giới hạn nút "+" không bị cũ. Cùng cách với trang Giỏ hàng.
// Chỉ chạy khi đã tải xong sản phẩm và không có thao tác nào đang chạy (đang tra kho vì nút "+", đang thêm giỏ)
// để không ghi đè kết quả của thao tác đó. Khách chưa đăng nhập vẫn được cập nhật kho (API kho công khai);
// fetchCartQuantities tự bỏ qua khi chưa đăng nhập.
// Không hẹn giờ gọi liên tục: chỉ gọi đúng lúc khách quay lại xem, tránh tốn request khi tab đang ẩn.
// useTabVisible (helper dùng chung) tự gắn / gỡ bộ lắng nghe đổi tab theo vòng đời của trang.
useTabVisible(() => {
  if (!product.value) return
  if (checkingStockVariantId.value !== null || addingSelected.value) return
  // Lỗi khi tra ngầm thì bỏ qua: giữ số đang hiện, BE vẫn kiểm tra lại khi khách bấm "Thêm vào giỏ".
  Promise.all([refreshProductStock(), fetchCartQuantities()]).catch((error) => {
    console.error('Không tra lại được tồn kho khi quay lại trang:', error)
  })
})

// Bấm "+" (delta = 1) hoặc "−" (delta = -1): giống trang Giỏ hàng, MỖI lần bấm đều hỏi server trước rồi mới đổi số.
// Tra kho thật (api-product-stock) và số đã có trong giỏ cùng lúc (Promise.all), khóa nút tới khi server trả lời,
// nên khoảng cách giữa các lần bấm chính là thời gian chờ server (không bấm dồn được nhiều lần).
// Không chặn khách bằng số kho cũ lúc mở trang: kho có thể đã tăng (admin nhập thêm, khách khác hủy đơn) hoặc đã giảm.
async function stepVariantQuantity(variant, delta) {
  if (checkingStockVariantId.value !== null) return
  checkingStockVariantId.value = variant.id
  try {
    await Promise.all([refreshProductStock(), fetchCartQuantities()])
    // Tính lại theo kho mới: getVariantQuantity đã tự kẹp số đang chọn về số còn thêm được.
    const current = getVariantQuantity(variant)
    const max = variantMaxQuantity(variant)
    if (max <= 0) {
      showToast('Phiên bản này hiện đã hết hàng hoặc bạn đã thêm đủ số còn lại vào giỏ.', 'info')
    } else if (delta > 0 && current >= max) {
      showToast(`Đã kiểm tra lại: phiên bản này hiện chỉ thêm được tối đa ${max}.`, 'info')
    } else {
      // Còn thêm được (hoặc đang giảm): đổi số; setVariantQuantity kẹp trong khoảng 1 đến max.
      setVariantQuantity(variant, current + delta)
    }
  } catch (error) {
    // Tra kho lỗi: giữ nguyên số đang chọn; BE vẫn kiểm tra lại khi khách bấm "Thêm vào giỏ".
    console.error('Không tra lại được tồn kho khi đổi số lượng:', error)
    showToast('Chưa kiểm tra được kho mới nhất, bạn thử lại sau giây lát.', 'warning')
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên mở khóa bộ tăng giảm ở đây.
    checkingStockVariantId.value = null
  }
}

// Người dùng gõ số tay: chỉ xử lý khi rời ô / bấm Enter (sự kiện change), để không ngắt lúc đang gõ.
// Giống nút "+"/"−" và trang Giỏ hàng: KHÔNG kẹp theo số kho cũ trên trang mà hỏi server trước
// (api-product-stock + api-cart), khóa bộ tăng giảm trong lúc chờ, rồi kẹp số khách gõ về 1..số còn thêm được.
// Số gõ không hợp lệ (trống, chữ, nhỏ hơn 1) thì trả ô về số đang chọn, không gọi server.
async function onVariantQuantityChange(variant, event) {
  const input = event.target
  const typed = Math.floor(Number(input.value))
  if (checkingStockVariantId.value !== null || !Number.isFinite(typed) || typed < 1) {
    input.value = getVariantQuantity(variant)
    return
  }

  checkingStockVariantId.value = variant.id
  try {
    await Promise.all([refreshProductStock(), fetchCartQuantities()])
    const max = variantMaxQuantity(variant)
    if (max <= 0) {
      showToast('Phiên bản này hiện đã hết hàng hoặc bạn đã thêm đủ số còn lại vào giỏ.', 'info')
    } else {
      setVariantQuantity(variant, typed)
      if (typed > max) showToast(`Đã kiểm tra lại: phiên bản này hiện chỉ thêm được tối đa ${max}.`, 'info')
    }
  } catch (error) {
    // Tra kho lỗi: vẫn nhận số khách gõ nhưng kẹp theo số trên trang; BE kiểm tra lại khi bấm "Thêm vào giỏ".
    console.error('Không tra lại được tồn kho khi gõ số lượng:', error)
    setVariantQuantity(variant, typed)
    showToast('Chưa kiểm tra được kho mới nhất, bạn thử lại sau giây lát.', 'warning')
  } finally {
    checkingStockVariantId.value = null
    // Ô nhập có thể vẫn hiện số khách gõ (khi số lượng không đổi Vue không vẽ lại), nên ghi lại số thật vào ô.
    input.value = getVariantQuantity(variant)
  }
}

// Thông số của biến thể để hiện trong phần thả xuống: có nhãn, đã bỏ giá trị null/rỗng (helper dùng chung).
// Bỏ thêm thông số mà tên biến thể đã chứa, tránh lặp "Hũ 113g" rồi "Dung tích: 113g".
function variantDetails(variant) {
  const name = String(variant.variant_name || '').toLowerCase()
  return listVariantAttributes(variant.attributes).filter((attribute) => !name.includes(attribute.value.toLowerCase()))
}

// Số biến thể hiện sẵn; sản phẩm có nhiều hơn số này thì hiện nút "Xem thêm" để xổ xuống phần còn lại.
const VARIANTS_PREVIEW_COUNT = 2
// true khi khách đã bấm "Xem thêm" (đang hiện đủ mọi biến thể).
const showAllVariants = ref(false)

// computed: danh sách biến thể đang hiện. Mặc định chỉ VARIANTS_PREVIEW_COUNT biến thể đầu
// (BE đã xếp biến thể mặc định lên đầu), bấm "Xem thêm" thì hiện hết.
const visibleVariants = computed(() => {
  const variants = Array.isArray(product.value?.variants) ? product.value.variants : []
  return showAllVariants.value ? variants : variants.slice(0, VARIANTS_PREVIEW_COUNT)
})

// Số biến thể đang bị ẩn, dùng cho chữ trên nút "Xem thêm N phiên bản" (0 = không cần nút).
const hiddenVariantCount = computed(() => {
  const total = Array.isArray(product.value?.variants) ? product.value.variants.length : 0
  return Math.max(0, total - VARIANTS_PREVIEW_COUNT)
})

const canManageComment = (comment) => currentUserId != null && String(comment.user_id) === String(currentUserId)

// Tổng số bình luận chỉ biết sau khi đã tải; trước đó không hiện số để tránh hiện sai.
const commentCount = computed(() => commentsPagination.value?.total_items ?? null)

// Gọi khi thẻ <img> báo lỗi tải ảnh, để chuyển sang khung thay thế.
function markImageFailed(index) {
  failedImages.value = new Set(failedImages.value).add(index)
}

// Hiện số theo kiểu Việt Nam, ví dụ 4412 -> "4.412".
function formatCount(value) {
  return Number(value ?? 0).toLocaleString('vi-VN')
}

// API bình luận chưa có tên hiển thị, chỉ có email; chỉ hiện phần trước @ để không lộ email.
function commentAuthor(comment) {
  return String(comment.email || '').split('@')[0] || 'Khách hàng'
}

function commentInitials(comment) {
  return commentAuthor(comment).slice(0, 2).toUpperCase()
}

// Đổi "2026-11-09 12:32:18" từ BE sang ngày giờ dạng Việt Nam.
function formatCommentDate(value) {
  if (!value) return ''
  const date = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' })
}

// Gọi api-product-detail để lấy sản phẩm theo slug. Chạy khi mở trang và khi slug trên URL đổi.
// Thành công: res.data.product (kèm mảng images). Lỗi: BE trả message, ví dụ 404 "Không tìm thấy sản phẩm".
async function fetchProduct(slug) {
  productLoading.value = true
  productError.value = ''
  product.value = null

  try {
    const res = await api.get('/?action=api-product-detail', { params: { slug } })
    // Nếu người dùng đã chuyển sang sản phẩm khác trong lúc chờ thì bỏ kết quả cũ.
    if (slug !== route.params.slug) return
    product.value = res.data.product
    variantQuantities.value = {}
    fetchCartQuantities()
  } catch (err) {
    if (slug !== route.params.slug) return
    productError.value = err.response?.data?.message || 'Không tải được thông tin sản phẩm.'
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên tắt loading ở đây.
    if (slug === route.params.slug) productLoading.value = false
  }
}

// Gọi api-product-comments cho slug hiện tại khi mở trang, thử lại hoặc đổi trang bình luận.
// Thành công: res.data.comments.data (danh sách) và res.data.comments.pagination.
async function fetchComments(page = 1) {
  const slug = route.params.slug
  commentsLoading.value = true
  commentsError.value = ''

  try {
    const res = await api.get('/?action=api-product-comments', {
      params: { slug, page, per_page: COMMENTS_PER_PAGE },
    })
    if (slug !== route.params.slug) return
    comments.value = res.data.comments.data
    commentsPagination.value = res.data.comments.pagination
  } catch (err) {
    if (slug !== route.params.slug) return
    // Hiện lỗi thật thay vì danh sách rỗng, để không nhầm với "chưa có bình luận".
    commentsError.value = err.response?.data?.message || 'Không tải được bình luận.'
  } finally {
    if (slug === route.params.slug) commentsLoading.value = false
  }
}



// Kiểm tra nội dung bình luận ở FE để người dùng nhận lỗi ngay tại trường nhập.
function validateComment(content, errorRef) {
  const value = String(content ?? '')
  if (!value.trim()) {
    errorRef.value = 'Vui lòng nhập nội dung bình luận.'
    return false
  }
  if (value.length > 2000) {
    errorRef.value = 'Bình luận không được vượt quá 2.000 ký tự.'
    return false
  }
  errorRef.value = ''
  return true
}

// Kiểm tra lỗi có phải do AI kiểm duyệt chặn nội dung không.
// BE trả 422 kèm moderation = 'blocked' khi bình luận có ngôn từ độc hại (bình luận KHÔNG được lưu),
// để phân biệt với lỗi 422 khác như nội dung rỗng hoặc quá dài.
function isBlockedByModeration(error) {
  return error.response?.status === 422 && error.response?.data?.moderation === 'blocked'
}

// Gửi bình luận mới. Được gọi khi bấm "Gửi bình luận".
// api-comment-create: BE cho AI kiểm duyệt rồi trả 201 với comment.moderation:
// - 'approved': đã hiện công khai, tải lại trang 1 để khách thấy ngay bình luận của mình.
// - 'pending': đã lưu nhưng chờ admin duyệt (AI không chắc, hoặc AI đang lỗi/tắt), chưa hiện.
// Bị AI chặn thì BE trả 422 và không lưu: giữ nguyên nội dung trong ô để khách sửa lại.
async function submitComment() {
  if (!loggedIn || commentSubmitting.value) return
  if (!validateComment(newCommentContent.value, newCommentError)) return

  commentSubmitting.value = true
  try {
    const response = await api.post('/?action=api-comment-create', {
      product_id: product.value.id,
      content: newCommentContent.value,
    })
    // Không có moderation (BE cũ) thì coi như chờ duyệt, an toàn hơn là báo đã hiện.
    const moderation = response.data?.comment?.moderation || 'pending'
    newCommentContent.value = ''
    newCommentError.value = ''
    if (moderation === 'approved') {
      showToast(response.data?.message || 'Thêm bình luận thành công!', 'success')
      // Bình luận mới nhất nằm đầu trang 1 (BE sắp xếp mới nhất trước).
      await fetchComments(1)
    } else {
      // Bình luận chờ duyệt chưa có trong danh sách công khai, nên không tải lại.
      showToast(response.data?.message || 'Bình luận đã được gửi và đang chờ duyệt.', 'info')
    }
  } catch (error) {
    if (isBlockedByModeration(error)) {
      // Không xóa ô nhập: khách sửa lại câu chữ rồi gửi tiếp. Lỗi tự mất khi khách sửa nội dung (xem @input).
      newCommentError.value = error.response.data.message
      showToast(error.response.data.message, 'warning')
    } else {
      showToast(error.response?.data?.message || 'Không thể gửi bình luận.', 'danger')
    }
  } finally {
    commentSubmitting.value = false
  }
}

function startEditComment(comment) {
  editingCommentId.value = comment.id
  editingCommentContent.value = comment.content || ''
  editingCommentError.value = ''
}

function cancelEditComment() {
  editingCommentId.value = null
  editingCommentContent.value = ''
  editingCommentError.value = ''
}

// Lưu nội dung sửa của một bình luận. Được gọi khi bấm "Lưu" trong chế độ sửa.
// api-comment-update: BE chấm lại nội dung mới bằng AI.
// - 'approved': vẫn hiện. 'pending': đã lưu nhưng tạm ẩn chờ duyệt, nên sau khi tải lại bình luận
//   biến khỏi danh sách; toast nói rõ để khách không tưởng bị xóa.
// Bị AI chặn thì BE trả 422, KHÔNG ghi đè bình luận cũ: giữ chế độ sửa để khách sửa tiếp.
async function updateComment(comment) {
  if (commentUpdatingId.value !== null) return
  if (!validateComment(editingCommentContent.value, editingCommentError)) return

  commentUpdatingId.value = comment.id
  try {
    const response = await api.put(`/?action=api-comment-update&id=${encodeURIComponent(comment.id)}`, {
      content: editingCommentContent.value,
    })
    const moderation = response.data?.comment?.moderation || 'pending'
    showToast(
      response.data?.message || 'Cập nhật bình luận thành công.',
      moderation === 'approved' ? 'success' : 'info',
    )
    cancelEditComment()
    await fetchComments(commentsPagination.value?.current_page || 1)
  } catch (error) {
    if (isBlockedByModeration(error)) {
      editingCommentError.value = error.response.data.message
      showToast(error.response.data.message, 'warning')
    } else {
      showToast(error.response?.data?.message || 'Không thể cập nhật bình luận.', 'danger')
    }
  } finally {
    commentUpdatingId.value = null
  }
}

async function deleteComment(comment) {
  if (commentDeletingId.value !== null) return
  if (!window.confirm('Bạn có chắc chắn muốn xóa bình luận này không?')) return

  commentDeletingId.value = comment.id
  try {
    const response = await api.delete(`/?action=api-comment-delete&id=${encodeURIComponent(comment.id)}`)
    showToast(response.data?.message || 'Xóa bình luận thành công.', 'success')
    const currentPage = commentsPagination.value?.current_page || 1
    const totalItems = Number(commentsPagination.value?.total_items ?? 0)
    const nextPage = comments?.value?.length === 1 && currentPage > 1 && totalItems > 1
      ? currentPage - 1
      : currentPage
    await fetchComments(nextPage)
  } catch (error) {
    showToast(error.response?.data?.message || 'Không thể xóa bình luận.', 'danger')
  } finally {
    commentDeletingId.value = null
  }
}

// ID các biến thể đang mở bảng thông số. Mặc định rỗng = mọi thẻ đều đóng.
// Gán Set MỚI mỗi lần đổi để Vue nhận ra thay đổi và vẽ lại.
const openDetailVariantIds = ref(new Set())

// Bảng thông số của biến thể này đang mở hay đóng.
function isVariantDetailOpen(variant) {
  return openDetailVariantIds.value.has(variant.id)
}

// Mở / đóng bảng thông số của một biến thể. Được gọi khi bấm nút "Chi tiết" trong thẻ biến thể.
function toggleVariantDetail(variant) {
  const next = new Set(openDetailVariantIds.value)
  if (next.has(variant.id)) next.delete(variant.id)
  else next.add(variant.id)
  openDetailVariantIds.value = next
}

// Biến thể này đang được tích chọn hay không (dùng cho checkbox).
function isVariantSelected(variant) {
  return selectedVariantIds.value.has(variant.id)
}

// Tích / bỏ tích một biến thể. Được gọi khi khách bấm checkbox cạnh bộ tăng giảm.
function toggleVariantSelected(variant, checked) {
  const next = new Set(selectedVariantIds.value)
  if (checked) next.add(variant.id)
  else next.delete(variant.id)
  selectedVariantIds.value = next
}

// computed: các biến thể đã tích VÀ còn thêm được (hết hàng hoặc đã đủ trong giỏ thì tự không tính),
// theo thứ tự hiển thị. Nút chung dùng danh sách này để biết gửi những món nào.
const selectedVariants = computed(() => {
  const variants = Array.isArray(product.value?.variants) ? product.value.variants : []
  return variants.filter((variant) => isVariantSelected(variant) && variantMaxQuantity(variant) > 0)
})

// computed: tổng số sản phẩm sẽ thêm vào giỏ = cộng số lượng đang chọn của mọi biến thể đã tích.
// Tự tính lại khi khách tích/bỏ tích hoặc bấm "+"/"−", nên dòng "Đã chọn ..." luôn khớp với số sẽ gửi đi.
const selectedTotalQuantity = computed(() =>
  selectedVariants.value.reduce((sum, variant) => sum + getVariantQuantity(variant), 0),
)

// Đọc giỏ hiện tại để biết mỗi biến thể đã có bao nhiêu trong giỏ. Lỗi thì coi như chưa có gì; BE vẫn chặn khi thêm.
async function fetchCartQuantities() {
  if (!loggedIn) return
  try {
    const res = await api.get('/?action=api-cart')
    const items = Array.isArray(res.data?.cart?.items) ? res.data.cart.items : []
    const map = {}
    for (const item of items) {
      map[item.variant_id] = (map[item.variant_id] || 0) + Number(item.quantity || 0)
    }
    cartQuantities.value = map
  } catch {
    cartQuantities.value = {}
  }
}

// Thêm mọi biến thể đã tích vào giỏ, mỗi biến thể với số lượng đang chọn ở bộ tăng giảm của nó.
// Được gọi khi bấm nút "Thêm vào giỏ" chung dưới danh sách biến thể.
// api-cart-add chỉ nhận 1 biến thể mỗi lần, nên gọi LẦN LƯỢT (await trong vòng for, không gửi song song)
// để BE xử lý tồn kho từng món theo thứ tự và thông báo lỗi khớp đúng món.
// Món nào lỗi (ví dụ 409 hết hàng) thì bỏ qua và thêm tiếp các món sau; món đã thêm được thì bỏ tích,
// món lỗi giữ nguyên dấu tích để khách chỉnh số lượng rồi bấm lại.
async function addSelectedToCart() {
  if (!loggedIn || addingSelected.value) return
  const variants = selectedVariants.value
  if (variants.length === 0) {
    showToast('Hãy tích chọn ít nhất một phiên bản còn hàng.', 'warning')
    return
  }

  addingSelected.value = true
  const added = []
  const failed = []
  let needRefreshStock = false
  try {
    for (const variant of variants) {
      const quantityToAdd = getVariantQuantity(variant)
      if (quantityToAdd < 1) {
        failed.push(`${variant.variant_name} (hết hàng)`)
        continue
      }
      try {
        await api.post('/?action=api-cart-add', {
          product_id: product.value.id,
          variant_id: variant.id,
          quantity: quantityToAdd,
        })
        added.push(variant)
      } catch (error) {
        failed.push(`${variant.variant_name} (${error.response?.data?.message || 'không thêm được'})`)
        // 409: vượt tồn kho hoặc vừa ngừng bán, cần tra lại kho để số "Còn N" trên trang không còn cũ.
        if (error.response?.status === 409) needRefreshStock = true
      }
    }

    // Bỏ tích các món đã thêm được; món lỗi giữ tích.
    const next = new Set(selectedVariantIds.value)
    added.forEach((variant) => next.delete(variant.id))
    selectedVariantIds.value = next

    // Báo kết quả gộp trong một toast: thành công hết / một phần / không món nào.
    if (failed.length === 0) {
      showToast('Đã thêm vào giỏ hàng.', 'success')
    } else if (added.length > 0) {
      showToast(`Đã thêm vào giỏ hàng. Chưa thêm được: ${failed.join('; ')}`, 'warning')
    } else {
      showToast(`Chưa thêm được: ${failed.join('; ')}`, 'danger')
    }

    if (added.length > 0) refreshCartCount()
    // Cập nhật số trong giỏ (và kho nếu có lỗi 409) để số "Còn N" và giới hạn nút "+" đổi ngay.
    // Lỗi khi tra lại thì bỏ qua: khách đã thấy thông báo ở trên, BE vẫn kiểm tra khi thêm lần sau.
    const refreshes = [fetchCartQuantities()]
    if (needRefreshStock) refreshes.push(refreshProductStock())
    await Promise.all(refreshes).catch(() => {})
  } finally {
    addingSelected.value = false
  }
}

// Nút này chỉ mở hoặc thu gọn khu vực; tải dữ liệu được thực hiện khi slug trang thay đổi.
function toggleComments() {
  commentsVisible.value = !commentsVisible.value
}

// watch + immediate: chạy ngay khi mở trang và chạy lại khi slug trên URL đổi.
// Cần watch vì Vue Router dùng lại component này khi chỉ đổi slug, onMounted sẽ không chạy lại.
watch(
  () => route.params.slug,
  (slug) => {
    // Sang sản phẩm khác thì xoá bình luận và ảnh của sản phẩm cũ.
    comments.value = null
    commentsPagination.value = null
    commentsError.value = ''
    commentsLoading.value = false
    commentsVisible.value = false
    newCommentContent.value = ''
    newCommentError.value = ''
    cancelEditComment()
    variantQuantities.value = {}
    // Sang sản phẩm khác thì bỏ hết dấu tích và đóng hết bảng thông số của sản phẩm cũ.
    selectedVariantIds.value = new Set()
    openDetailVariantIds.value = new Set()
    // Sang sản phẩm khác thì danh sách biến thể quay về chỉ hiện vài biến thể đầu.
    showAllVariants.value = false
    activeImageIndex.value = 0
    failedImages.value = new Set()

    if (typeof slug === 'string' && slug !== '') {
      fetchProduct(slug)
      fetchComments(1)
    }
  },
  { immediate: true },
)
</script>

<template>
  <div class="pd-page">
    <!-- StateBlock xử lý trạng thái tải sản phẩm theo thứ tự: đang tải -> lỗi -> không có dữ liệu.
         Lỗi (slug không tồn tại hoặc server lỗi) không truyền onRetry: phần lớn là 404, thử lại cũng vậy;
         thay vào đó có link quay lại danh sách ngay bên dưới. Có sản phẩm thì hiện nội dung trong slot. -->
    <StateBlock
      :loading="productLoading"
      :error="productError"
      :empty="!product"
      loading-text="Đang tải sản phẩm..."
      empty-text="Không tìm thấy sản phẩm."
    >
    <article class="pd-card">
      <!-- Breadcrumb cho desktop/tablet; mobile dùng link quay lại bên dưới. -->
      <nav class="pd-breadcrumb" aria-label="Breadcrumb">
        <RouterLink :to="{ name: 'catalog' }">Danh sách sản phẩm</RouterLink>
        <span class="pd-breadcrumb-separator" aria-hidden="true">/</span>
        <template v-if="product.category_name">
          <span>{{ product.category_name }}</span>
          <span class="pd-breadcrumb-separator" aria-hidden="true">/</span>
        </template>
        <span class="pd-breadcrumb-current" aria-current="page">{{ product.name }}</span>
      </nav>
      <RouterLink class="pd-back" :to="{ name: 'catalog' }">← Danh sách sản phẩm</RouterLink>

      <div class="pd-main">
        <!-- Album ảnh: ảnh lớn và các ảnh nhỏ để chọn khi có nhiều hơn một ảnh. -->
        <section class="pd-gallery" aria-label="Ảnh sản phẩm">
          <div class="pd-gallery-main">
            <img
              v-if="activeImage && !failedImages.has(activeImageIndex)"
              :src="resolveImageUrl(activeImage)"
              :alt="product.name"
              @error="markImageFailed(activeImageIndex)"
            >
            <span v-else class="pd-image-fallback" aria-hidden="true">SB</span>
          </div>

          <div v-if="galleryImages.length > 1" class="pd-thumbs">
            <button
              v-for="(image, index) in galleryImages"
              :key="`${index}-${image}`"
              type="button"
              class="pd-thumb"
              :class="{ 'is-active': index === activeImageIndex }"
              :aria-label="`Xem ảnh ${index + 1}`"
              :aria-pressed="index === activeImageIndex"
              @click="activeImageIndex = index"
            >
              <img v-if="!failedImages.has(index)" :src="resolveImageUrl(image)" alt="" @error="markImageFailed(index)">
              <span v-else class="pd-image-fallback pd-image-fallback--small" aria-hidden="true">{{ index + 1 }}</span>
            </button>
          </div>
        </section>

        <!-- Thông tin sản phẩm: danh mục, tên, mã, chỉ số, giá, tồn kho, mua hàng và mô tả. -->
        <section class="pd-info">
          <p v-if="product.category_name" class="pd-category">{{ product.category_name }}</p>
          <h1 class="pd-name">{{ product.name }}</h1>
          <p v-if="product.code" class="pd-meta">Mã: {{ product.code }}</p>
          <p class="pd-meta">{{ formatCount(product.view_count) }} lượt xem · {{ formatCount(product.sales_count) }} đã bán</p>

          <div class="pd-price">
            <template v-if="priceRange">
              <span v-if="priceRange.min !== priceRange.max" class="pd-price-from">Từ</span>
              <strong>{{ formatPrice(priceRange.min) }}</strong>
            </template>
            <strong v-else>Liên hệ</strong>
          </div>

          <p v-if="isOutOfStock" class="pd-stock pd-stock--out">Hết hàng</p>
          <p v-else class="pd-stock">Còn {{ product.stock }} sản phẩm</p>

          <!-- Danh sách đầy đủ biến thể; thành viên đăng nhập mới có số lượng + checkbox ở từng biến thể
               và một nút thêm giỏ chung dưới danh sách. -->
          <section class="pd-variants" aria-labelledby="pd-variants-title">
            <h2 id="pd-variants-title" class="pd-subheading">Chọn phiên bản</h2>
            <p v-if="!product.variants || product.variants.length === 0" class="pd-description">Sản phẩm chưa có phiên bản để bán.</p>
            <ul v-else class="pd-variant-list">
              <li
                v-for="variant in visibleVariants"
                :key="variant.id"
                class="pd-variant"
                :class="{ 'is-out': variantMaxQuantity(variant) <= 0 }"
              >
                <!-- Ô trái: ảnh vuông của biến thể (chưa có ảnh riêng thì ảnh sản phẩm).
                     Bấm ảnh thì album lớn phía trên chuyển sang đúng ảnh đó. -->
                <button
                  type="button"
                  class="pd-variant-photo"
                  :class="{ 'is-active': variantImageIndex(variant) >= 0 && variantImageIndex(variant) === activeImageIndex }"
                  :disabled="variantImageIndex(variant) < 0"
                  :aria-label="`Xem ảnh ${variant.variant_name}`"
                  @click="showVariantImage(variant)"
                >
                  <img
                    v-if="variantPhoto(variant) && !failedImages.has(variantImageIndex(variant))"
                    :src="resolveImageUrl(variantPhoto(variant))"
                    alt=""
                    loading="lazy"
                    @error="markImageFailed(variantImageIndex(variant))"
                  >
                  <span v-else class="pd-image-fallback pd-image-fallback--small" aria-hidden="true">SB</span>
                </button>

                <!-- Ô phải: tên, giá, số còn lại; ngay dưới là cụm chọn số lượng + checkbox tích chọn. -->
                <div class="pd-variant-info">
                  <strong class="pd-variant-name">{{ variant.variant_name }}</strong>
                  <div class="pd-variant-price">
                    <strong :class="{ 'is-sale': variantIsOnSale(variant) }">{{ formatPrice(variant.final_price) }}</strong>
                    <del v-if="variantIsOnSale(variant)">{{ formatPrice(variant.price) }}</del>
                  </div>
                  <span class="pd-variant-stock">
                    {{ variantMaxQuantity(variant) > 0 ? `Còn ${variantMaxQuantity(variant)} sản phẩm` : (variantInCart(variant) > 0 ? 'Đã đủ số lượng trong giỏ' : 'Hết hàng') }}
                    <template v-if="variantInCart(variant) > 0"> · Trong giỏ: {{ variantInCart(variant) }}</template>
                  </span>

                  <!-- Hàng điều khiển: cụm chọn mua (chỉ khi đã đăng nhập) bên trái, nút "Chi tiết" dạt sang phải. -->
                  <div class="pd-variant-controls">
                  <!-- Cụm chọn mua (chỉ khi đã đăng nhập): bộ tăng giảm số lượng và checkbox để đưa biến thể này
                       vào nút "Thêm vào giỏ" chung bên dưới danh sách. Hết hàng thì khóa cả cụm. -->
                  <div v-if="loggedIn" class="pd-variant-buy">
                    <div class="pd-stepper" role="group" :aria-label="`Số lượng ${variant.variant_name}`">
                      <button
                        type="button"
                        :disabled="addingSelected || checkingStockVariantId !== null || variantMaxQuantity(variant) <= 0 || getVariantQuantity(variant) <= 1"
                        aria-label="Giảm số lượng"
                        @click="stepVariantQuantity(variant, -1)"
                      >−</button>
                      <input
                        type="number"
                        class="pd-stepper-input"
                        min="1"
                        :max="variantMaxQuantity(variant)"
                        :value="getVariantQuantity(variant)"
                        :disabled="addingSelected || checkingStockVariantId !== null || variantMaxQuantity(variant) <= 0"
                        :aria-label="`Số lượng ${variant.variant_name}`"
                        @change="onVariantQuantityChange(variant, $event)"
                      />
                      <button
                        type="button"
                        :disabled="addingSelected || checkingStockVariantId !== null"
                        aria-label="Tăng số lượng"
                        @click="stepVariantQuantity(variant, 1)"
                      >+</button>
                    </div>
                    <label class="pd-variant-check">
                      <input
                        type="checkbox"
                        :checked="isVariantSelected(variant) && variantMaxQuantity(variant) > 0"
                        :disabled="addingSelected || variantMaxQuantity(variant) <= 0"
                        @change="toggleVariantSelected(variant, $event.target.checked)"
                      />
                      <span>Chọn</span>
                    </label>
                  </div>

                  <!-- Nút đóng/mở bảng thông số (chỉ có khi biến thể có thông số). Mặc định đóng cho thẻ gọn. -->
                  <button
                    v-if="variantDetails(variant).length > 0"
                    type="button"
                    class="pd-variant-detail-toggle"
                    :class="{ 'is-open': isVariantDetailOpen(variant) }"
                    :aria-expanded="isVariantDetailOpen(variant)"
                    :aria-controls="`pd-variant-details-${variant.id}`"
                    @click="toggleVariantDetail(variant)"
                  >
                    Chi tiết
                    <span class="pd-variants-more-chevron" :class="{ 'is-open': isVariantDetailOpen(variant) }" aria-hidden="true">▾</span>
                  </button>
                  </div>
                </div>

                <!-- Hàng dưới, trải hết bề ngang thẻ: bảng thông số của biến thể (nhãn tiếng Việt, đã bỏ giá trị trống).
                     Chỉ hiện khi khách bấm nút "Chi tiết". -->
                <dl
                  v-if="variantDetails(variant).length > 0 && isVariantDetailOpen(variant)"
                  :id="`pd-variant-details-${variant.id}`"
                  class="pd-variant-details"
                >
                  <div v-for="attribute in variantDetails(variant)" :key="attribute.key" class="pd-variant-detail">
                    <dt>{{ attribute.label }}</dt>
                    <dd>{{ attribute.value }}</dd>
                  </div>
                </dl>
              </li>
            </ul>
            <!-- Nút xổ xuống / thu gọn: chỉ có khi sản phẩm có nhiều hơn VARIANTS_PREVIEW_COUNT biến thể -->
            <button
              v-if="hiddenVariantCount > 0"
              type="button"
              class="pd-variants-more"
              :aria-expanded="showAllVariants"
              @click="showAllVariants = !showAllVariants"
            >
              {{ showAllVariants ? 'Thu gọn' : `Xem thêm ${hiddenVariantCount} phiên bản` }}
              <span class="pd-variants-more-chevron" :class="{ 'is-open': showAllVariants }" aria-hidden="true">▾</span>
            </button>

            <!-- Nút thêm giỏ chung (chỉ khi đã đăng nhập): thêm mọi biến thể đã tích với số lượng của từng biến thể.
                 Chưa tích món nào còn hàng thì khóa nút. -->
            <div v-if="loggedIn && product.variants && product.variants.length > 0" class="pd-variants-cart">
              <span class="pd-variants-cart-count">
                Đã chọn <strong>{{ selectedVariants.length }}</strong> phiên bản<template v-if="selectedVariants.length > 0">, tổng <strong>{{ selectedTotalQuantity }}</strong> sản phẩm</template>
              </span>
              <button
                type="button"
                class="pd-btn pd-btn--primary"
                :disabled="selectedVariants.length === 0 || addingSelected || checkingStockVariantId !== null"
                @click="addSelectedToCart"
              >
                {{ addingSelected ? 'Đang thêm...' : 'Thêm vào giỏ' }}
              </button>
            </div>
          </section>

          <!-- Khách chưa đăng nhập: không có nút mua hàng, chỉ có gợi ý đăng nhập. -->
          <p v-if="!loggedIn" class="pd-login-hint">
            <RouterLink :to="{ name: 'login' }" @click.capture="saveReturnUrl(route.fullPath)">
              Đăng nhập
            </RouterLink> để mua hàng và bình luận.
          </p>

        </section>

        <!-- Mô tả sản phẩm: máy tính nằm ở cột trái ngay dưới album ảnh, điện thoại nằm sau phần mua hàng
             (vị trí do CSS grid-template-areas của .pd-main quyết định). Mô tả dài quá khung thì thu gọn,
             có nút "Xem thêm" / "Ẩn bớt". -->
        <section class="pd-description-box" aria-labelledby="pd-description-title">
          <h2 id="pd-description-title" class="pd-subheading">Mô tả sản phẩm</h2>
          <p
            ref="descriptionEl"
            class="pd-description"
            :class="{ 'is-collapsed': !descriptionExpanded, 'has-fade': descriptionOverflows && !descriptionExpanded }"
          >{{ product.description || 'Chưa có mô tả cho sản phẩm này.' }}</p>
          <button
            v-if="descriptionOverflows"
            type="button"
            class="pd-description-toggle"
            :aria-expanded="descriptionExpanded"
            @click="descriptionExpanded = !descriptionExpanded"
          >
            {{ descriptionExpanded ? 'Ẩn bớt' : 'Xem thêm' }}
            <span class="pd-variants-more-chevron" :class="{ 'is-open': descriptionExpanded }" aria-hidden="true">▾</span>
          </button>
        </section>
      </div>

      <!-- Bình luận được tải khi mở trang; nút chỉ thu gọn hoặc mở khu vực này. -->
      <section class="pd-comments" aria-labelledby="pd-comments-title">
        <div class="pd-comments-header">
          <h2 id="pd-comments-title" class="pd-comments-title">
            Bình luận<template v-if="commentCount !== null"> ({{ commentCount }})</template>
          </h2>
          <button
            type="button"
            class="pd-btn pd-btn--outline pd-comments-toggle"
            :aria-expanded="commentsVisible"
            aria-controls="pd-comments-body"
            @click="toggleComments"
          >
            {{ commentsVisible ? 'Ẩn bình luận' : 'Xem bình luận' }}
          </button>
        </div>

        <!-- v-show chỉ ẩn bằng CSS nên dữ liệu đã tải vẫn được giữ khi thu gọn. -->
        <div v-show="commentsVisible" id="pd-comments-body" class="pd-comments-body">
          <!-- Thành viên có thể gửi bình luận; comment mới chờ BE duyệt. -->
          <form v-if="loggedIn" class="pd-comment-form" @submit.prevent="submitComment">
            <span class="pd-avatar" aria-hidden="true">SB</span>
            <div class="pd-comment-form-body">
              <label class="visually-hidden" for="pd-comment-input">Nội dung bình luận</label>
              <textarea
                id="pd-comment-input"
                v-model="newCommentContent"
                class="pd-textarea"
                rows="3"
                maxlength="2000"
                placeholder="Viết bình luận của bạn..."
                :aria-invalid="Boolean(newCommentError)"
                @input="newCommentError && validateComment(newCommentContent, newCommentError)"
              ></textarea>
              <p v-if="newCommentError" class="pd-field-error" role="alert">{{ newCommentError }}</p>
              <div class="pd-comment-form-footer">
                <span class="pd-demo-note">Tối đa 2.000 ký tự. Bình luận sẽ hiển thị sau khi được duyệt.</span>
                <button type="submit" class="pd-btn pd-btn--primary pd-btn--inline" :disabled="commentSubmitting">
                  <!-- Gửi bình luận phải chờ AI kiểm duyệt (thường dưới 1-2 giây), nên ghi rõ đang kiểm duyệt. -->
                  {{ commentSubmitting ? 'Đang kiểm duyệt...' : 'Gửi bình luận' }}
                </button>
              </div>
            </div>
          </form>
          <p v-else class="pd-login-hint">
            <RouterLink :to="{ name: 'login' }" @click.capture="saveReturnUrl(route.fullPath)">Đăng nhập</RouterLink>
            để gửi bình luận.
          </p>

          <!-- Các trạng thái tải bình luận: đang tải, lỗi (có thử lại), rỗng, có dữ liệu. -->
          <p v-if="commentsLoading" class="pd-comments-state" role="status">Đang tải bình luận...</p>

          <div v-else-if="commentsError" class="pd-comments-state pd-comments-state--error" role="alert">
            <span>{{ commentsError }}</span>
            <button
              type="button"
              class="pd-btn pd-btn--outline pd-btn--inline"
              @click="fetchComments(commentsPagination?.current_page || 1)"
            >
              Thử lại
            </button>
          </div>

          <p v-else-if="comments && comments.length === 0" class="pd-comments-state">
            Chưa có bình luận nào cho sản phẩm này.
          </p>

          <template v-else-if="comments">
            <ul class="pd-comment-list">
              <li v-for="comment in comments" :key="comment.id" class="pd-comment">
                <span class="pd-avatar" aria-hidden="true">{{ commentInitials(comment) }}</span>
                <div class="pd-comment-body">
                  <p class="pd-comment-meta">
                    <strong>{{ commentAuthor(comment) }}</strong>
                    <time :datetime="comment.created_at">{{ formatCommentDate(comment.created_at) }}</time>
                  </p>
                  <template v-if="editingCommentId === comment.id">
                    <textarea
                      v-model="editingCommentContent"
                      class="pd-textarea pd-edit-textarea"
                      rows="3"
                      maxlength="2000"
                      :aria-invalid="Boolean(editingCommentError)"
                      @input="editingCommentError && validateComment(editingCommentContent, editingCommentError)"
                    ></textarea>
                    <p v-if="editingCommentError" class="pd-field-error" role="alert">{{ editingCommentError }}</p>
                    <div class="pd-comment-actions">
                      <button type="button" class="pd-btn pd-btn--primary pd-btn--inline" :disabled="commentUpdatingId === comment.id" @click="updateComment(comment)">
                        {{ commentUpdatingId === comment.id ? 'Đang kiểm duyệt...' : 'Lưu' }}
                      </button>
                      <button type="button" class="pd-btn pd-btn--outline pd-btn--inline" :disabled="commentUpdatingId === comment.id" @click="cancelEditComment">Hủy</button>
                    </div>
                  </template>
                  <template v-else>
                    <p class="pd-comment-content">{{ comment.content }}</p>
                    <div v-if="canManageComment(comment)" class="pd-comment-actions">
                      <button type="button" class="pd-btn pd-btn--outline pd-btn--inline" :disabled="commentDeletingId === comment.id || commentUpdatingId !== null" @click="startEditComment(comment)">Sửa</button>
                      <button type="button" class="pd-btn pd-btn--outline pd-btn--inline" :disabled="commentDeletingId === comment.id || commentUpdatingId !== null" @click="deleteComment(comment)">
                        {{ commentDeletingId === comment.id ? 'Đang xóa...' : 'Xóa' }}
                      </button>
                    </div>
                  </template>
                </div>
              </li>
            </ul>

            <!-- Phân trang bình luận, chỉ hiện khi có từ 2 trang trở lên. -->
            <nav
              v-if="commentsPagination && commentsPagination.total_pages > 1"
              class="pd-pagination"
              aria-label="Phân trang bình luận"
            >
              <button
                type="button"
                class="pd-btn pd-btn--outline pd-btn--inline"
                :disabled="commentsPagination.current_page <= 1"
                @click="fetchComments(commentsPagination.current_page - 1)"
              >
                Trang trước
              </button>
              <span class="pd-pagination-indicator">
                {{ commentsPagination.current_page }} / {{ commentsPagination.total_pages }}
              </span>
              <button
                type="button"
                class="pd-btn pd-btn--outline pd-btn--inline"
                :disabled="commentsPagination.current_page >= commentsPagination.total_pages"
                @click="fetchComments(commentsPagination.current_page + 1)"
              >
                Trang sau
              </button>
            </nav>
          </template>
        </div>
      </section>
    </article>
    </StateBlock>

    <!-- Link quay lại khi tải sản phẩm lỗi (StateBlock chưa có chỗ đặt link trong khối lỗi). -->
    <div v-if="!productLoading && productError" class="pd-error-actions">
      <RouterLink class="pd-btn pd-btn--outline pd-btn--inline" :to="{ name: 'catalog' }">
        Quay lại danh sách sản phẩm
      </RouterLink>
    </div>
  </div>
</template>

<style scoped>
.pd-page {
  color: #131e2a;
}

.pd-card {
  padding: 32px 24px;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #ffffff;
  box-shadow: 0 4px 16px rgb(28 42 58 / 10%);
}

/* Khối chứa link "Quay lại danh sách sản phẩm" bên dưới StateBlock khi tải lỗi. */
.pd-error-actions {
  margin-top: 16px;
  text-align: center;
}

.pd-breadcrumb {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 6px;
  margin-bottom: 16px;
  color: #4a5565;
  font-size: 13px;
  line-height: 1.5;
}

.pd-breadcrumb a:hover {
  color: #8c6a32;
  text-decoration: underline;
}

.pd-breadcrumb-separator {
  color: #6b7280;
}

.pd-breadcrumb-current {
  color: #131e2a;
  font-weight: 500;
}

.pd-back {
  display: none;
  margin-bottom: 16px;
  color: #131e2a;
  font-size: 14px;
}

/* Bố cục chính đặt tên ô bằng grid-template-areas:
   - Máy tính: cột trái = album ảnh ("gallery") rồi mô tả ("desc") ngay bên dưới; cột phải = thông tin ("info")
     kéo dài cả 2 hàng. grid-template-rows "auto 1fr": hàng ảnh chỉ cao đúng bằng ảnh, phần chiều cao dư
     của cột thông tin dồn vào hàng mô tả, nên mô tả nằm sát ngay dưới ảnh, không bị đẩy xuống.
   - Điện thoại (xem @media bên dưới): xếp 1 cột ảnh → thông tin → mô tả, để khách thấy giá và nút mua trước. */
.pd-main {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  grid-template-rows: auto 1fr;
  grid-template-areas:
    "gallery info"
    "desc info";
  gap: 24px;
  padding-bottom: 24px;
}

.pd-gallery {
  grid-area: gallery;
}

.pd-info {
  grid-area: info;
}

/* Khối mô tả: thẻ trắng viền kem giống khung ảnh, nằm sát dưới album. */
.pd-description-box {
  grid-area: desc;
  align-self: start;
  padding: 16px 18px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
}

.pd-description-box .pd-subheading {
  margin: 0 0 10px;
}

/* Mô tả thu gọn: chỉ hiện khoảng 6 dòng (1.6em là chiều cao 1 dòng theo line-height). */
.pd-description.is-collapsed {
  max-height: calc(1.6em * 6);
  overflow: hidden;
}

/* Chỉ khi mô tả thật sự bị cắt mới làm mờ dần dòng cuối, báo cho khách biết còn chữ bên dưới.
   mask-image: phần trong suốt của dải màu làm chữ mờ dần, không cần thêm lớp phủ. */
.pd-description.has-fade {
  -webkit-mask-image: linear-gradient(to bottom, #000 65%, transparent);
  mask-image: linear-gradient(to bottom, #000 65%, transparent);
}

/* Nút "Xem thêm" / "Ẩn bớt": chữ màu đồng, không viền, mũi tên xoay khi mở. */
.pd-description-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  padding: 4px 0;
  border: 0;
  background: transparent;
  color: #9e7432;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}

.pd-description-toggle:hover {
  color: #b8893e;
}

.pd-description-toggle:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

.pd-gallery-main {
  display: grid;
  overflow: hidden;
  aspect-ratio: 1 / 1;
  place-items: center;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #efeae0;
}

.pd-gallery-main img,
.pd-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.pd-image-fallback {
  color: #4a5565;
  font-size: 32px;
  font-weight: 700;
}

.pd-image-fallback--small {
  font-size: 12px;
}

.pd-thumbs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.pd-thumb {
  display: grid;
  width: 60px;
  height: 60px;
  overflow: hidden;
  place-items: center;
  padding: 0;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #efeae0;
  cursor: pointer;
}

.pd-thumb.is-active {
  border: 2px solid #b8893e;
}

.pd-category {
  margin: 0 0 4px;
  color: #8c6a32;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.pd-name {
  margin: 0 0 6px;
  color: #131e2a;
  font-size: 28px;
  font-weight: 600;
  line-height: 1.25;
}

.pd-meta {
  margin: 0 0 2px;
  color: #4a5565;
  font-size: 13px;
}

.pd-price {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
  margin-top: 14px;
}

.pd-price strong {
  font-size: 24px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.pd-price strong.is-sale {
  color: #93000a;
}

.pd-price del {
  color: #6b7280;
  font-size: 15px;
}

.pd-sale-badge {
  padding: 2px 6px;
  border: 1px solid #ba1a1a;
  border-radius: 4px;
  background: #fdf1f0;
  color: #93000a;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}

.pd-stock {
  margin: 4px 0 12px;
  color: #4a5565;
  font-size: 13px;
  font-weight: 500;
}

.pd-stock--out {
  color: #93000a;
}

.pd-stepper {
  display: flex;
  overflow: hidden;
  align-items: stretch;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #ffffff;
}

.pd-stepper button {
  width: 44px;
  height: 44px;
  padding: 0;
  border: 0;
  background: transparent;
  color: #131e2a;
  font-size: 18px;
  cursor: pointer;
}

.pd-stepper button:disabled {
  background: #efeae0;
  color: #6b7280;
  cursor: not-allowed;
}

.pd-stepper-input {
  width: 56px;
  padding: 0;
  border: 0;
  background: transparent;
  border-right: 1px solid #d9d4ca;
  border-left: 1px solid #d9d4ca;
  border-radius: 0;
  color: #131e2a;
  font-size: 15px;
  font-weight: 600;
  text-align: center;
  -moz-appearance: textfield;
}

.pd-stepper-input::-webkit-outer-spin-button,
.pd-stepper-input::-webkit-inner-spin-button {
  margin: 0;
  -webkit-appearance: none;
}

.pd-stepper-input:focus {
  outline: none;
  background: #ffffff;
}

.pd-stepper-input:disabled {
  background: #efeae0;
  color: #6b7280;
}

.pd-variants {
  margin-top: 20px;
}

.pd-variant-list {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.pd-variant {
  display: grid;
  gap: 10px;
  padding: 12px;
  border: 1px solid #d9d4ca;
  border-radius: 6px;
}

/* Thẻ biến thể dạng lưới 2 hàng (grid-template-areas đặt tên từng ô cho dễ đọc):
   - Hàng trên: "photo" = ảnh vuông cố định 112px, "info" = tên, giá, số còn lại, cụm chọn mua.
   - Hàng dưới: "details" = bảng thông số trải hết bề ngang thẻ (chiếm cả 2 cột).
   minmax(0, 1fr): cột phải được co lại khi chữ dài, không đẩy thẻ tràn ngang. */
.pd-variant {
  grid-template-columns: 112px minmax(0, 1fr);
  grid-template-areas:
    "photo info"
    "details details";
  align-items: start;
  gap: 12px 14px;
  padding: 14px;
}

/* Ảnh vuông của biến thể; bấm để album lớn chuyển sang ảnh này. */
.pd-variant-photo {
  display: grid;
  grid-area: photo;
  width: 100%;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  place-items: center;
  padding: 0;
  border: 1px solid #e6e0d4;
  border-radius: 6px;
  background: #ffffff;
  cursor: pointer;
}

.pd-variant-photo:disabled {
  cursor: default;
}

.pd-variant-photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.pd-variant-photo.is-active,
.pd-variant-photo:hover:not(:disabled) {
  border-color: #b8893e;
}

.pd-variant-photo:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

/* Điện thoại: ảnh nhỏ lại để cột thông tin còn đủ chỗ cho cụm chọn mua. */
@media (max-width: 575.98px) {
  .pd-variant {
    grid-template-columns: 88px minmax(0, 1fr);
    gap: 10px 12px;
    padding: 12px;
  }
}

.pd-variant.is-out {
  opacity: 0.6;
}

/* Cột thông tin: các dòng xếp dọc, sát nhau. */
.pd-variant-info {
  display: grid;
  grid-area: info;
  align-content: start;
  gap: 4px;
  min-width: 0;
}

.pd-variant-name {
  color: #131e2a;
  font-size: 15px;
  line-height: 1.3;
}

.pd-variant-stock {
  color: #4a5565;
  font-size: 13px;
}

/* Hàng điều khiển ngay dưới dòng "Còn N sản phẩm": cụm chọn mua bên trái, nút "Chi tiết" bên phải.
   flex-wrap: màn hình quá hẹp thì phần tử tự xuống dòng thay vì tràn ra ngoài thẻ. */
.pd-variant-controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-top: 6px;
}

/* Cụm chọn mua: bộ tăng giảm + checkbox cùng một hàng. */
.pd-variant-buy {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

/* Bộ tăng giảm trong thẻ biến thể: các ô vuông 34px cho gọn. */
.pd-variant-buy .pd-stepper button {
  width: 34px;
  height: 34px;
  font-size: 16px;
}

.pd-variant-buy .pd-stepper-input {
  width: 44px;
  height: 34px;
  font-size: 14px;
}

/* Checkbox "Chọn": ô vuông cao bằng bộ tăng giảm, viền đổi màu đồng khi đã tích. */
.pd-variant-check {
  display: inline-flex;
  height: 34px;
  align-items: center;
  gap: 6px;
  margin: 0;
  padding: 0 10px;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #ffffff;
  color: #1c2a3a;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  user-select: none;
}

.pd-variant-check:has(input:checked) {
  border-color: #b8893e;
  background: #f7f0e3;
}

.pd-variant-check:has(input:disabled) {
  background: #efeae0;
  color: #6b7280;
  cursor: not-allowed;
}

.pd-variant-check input {
  width: 16px;
  height: 16px;
  margin: 0;
  accent-color: #b8893e;
  cursor: inherit;
}

/* Nút "Chi tiết ▾": ô viền nhạt cao 34px, cùng kiểu với bộ tăng giảm và ô "Chọn" để hàng điều khiển đồng bộ.
   margin-left: auto đẩy nút dạt về mép phải của hàng. Mũi tên xoay ngược khi mở. */
.pd-variant-detail-toggle {
  display: inline-flex;
  height: 34px;
  align-items: center;
  gap: 6px;
  margin-left: auto;
  padding: 0 12px;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #ffffff;
  color: #25364a;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  transition: border-color 140ms ease, background-color 140ms ease;
}

.pd-variant-detail-toggle:hover {
  border-color: #b8893e;
}

/* Đang mở: viền đồng, nền kem, giống ô "Chọn" khi đã tích. */
.pd-variant-detail-toggle.is-open {
  border-color: #b8893e;
  background: #f7f0e3;
  color: #9e7432;
}

@media (prefers-reduced-motion: reduce) {
  .pd-variant-detail-toggle {
    transition: none;
  }
}

.pd-variant-detail-toggle:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

/* Thanh nút "Thêm vào giỏ" chung dưới danh sách: số phiên bản đã chọn bên trái, nút bên phải. */
.pd-variants-cart {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: 12px;
  padding: 12px 14px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
}

.pd-variants-cart-count {
  color: #4a5565;
  font-size: 14px;
  font-weight: 500;
}

/* Hai con số (số phiên bản, tổng sản phẩm) đậm, to và navy để nổi lên giữa câu chữ xám. */
.pd-variants-cart-count strong {
  color: #131e2a;
  font-size: 17px;
  font-weight: 800;
}

/* Điện thoại: nút chung dàn hết bề ngang cho dễ bấm. */
@media (max-width: 575.98px) {
  .pd-variants-cart .pd-btn {
    width: 100%;
  }
}

/* Nút thả xuống: chữ nhỏ màu đồng, mũi tên xoay 180 độ khi mở. */
/* Nút "Xem thêm N phiên bản" / "Thu gọn" dưới danh sách biến thể. */
.pd-variants-more {
  display: flex;
  width: 100%;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-top: 10px;
  padding: 8px 12px;
  border: 1px solid #d9d4ca;
  border-radius: 6px;
  background: #ffffff;
  color: #9e7432;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
}

.pd-variants-more:hover {
  border-color: #b8893e;
}

.pd-variants-more:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

.pd-variants-more-chevron {
  display: inline-block;
  transition: transform 160ms ease;
}

.pd-variants-more-chevron.is-open {
  transform: rotate(180deg);
}

/* Bảng thông số của biến thể: hàng dưới của thẻ, trải hết bề ngang (ô "details" chiếm cả 2 cột).
   Kẻ dạng bảng 2 cột "nhãn | giá trị", mỗi thông số một hàng:
   - Khung ngoài viền nhạt bo góc; overflow: hidden để nền cột nhãn không tràn qua góc bo.
   - Các hàng ngăn bằng đường kẻ ngang mảnh; cột nhãn nền kem, ngăn với cột giá trị bằng đường kẻ dọc.
   Màu đồng để dành cho giá và nút chính; bảng chỉ dùng xám/navy để không lóa mắt. */
.pd-variant-details {
  grid-area: details;
  margin: 0;
  overflow: hidden;
  border: 1px solid #e6e0d4;
  border-radius: 6px;
  background: #ffffff;
}

/* Một hàng của bảng: cột nhãn rộng cố định 150px, cột giá trị chiếm phần còn lại.
   minmax(0, 1fr): giá trị dài thì xuống dòng trong ô, không đẩy bảng tràn ngang. */
.pd-variant-detail {
  display: grid;
  grid-template-columns: 150px minmax(0, 1fr);
}

/* Kẻ đường ngang giữa các hàng (hàng đầu không cần vì đã có viền khung). */
.pd-variant-detail + .pd-variant-detail {
  border-top: 1px solid #ece6da;
}

/* Ô nhãn: nền kem nhạt, chữ xám đậm vừa; đường kẻ dọc bên phải tách với ô giá trị. */
.pd-variant-detail dt {
  padding: 9px 12px;
  border-right: 1px solid #ece6da;
  background: #f7f4ee;
  color: #4a5565;
  font-size: 13px;
  font-weight: 600;
}

/* Ô giá trị: nền trắng, chữ navy, là phần khách cần đọc. */
.pd-variant-detail dd {
  margin: 0;
  padding: 9px 12px;
  color: #1c2a3a;
  font-size: 14px;
  line-height: 1.45;
}

/* Điện thoại: cột nhãn hẹp lại để cột giá trị còn đủ chỗ. */
@media (max-width: 575.98px) {
  .pd-variant-detail {
    grid-template-columns: 110px minmax(0, 1fr);
  }

  .pd-variant-detail dt,
  .pd-variant-detail dd {
    padding: 8px 10px;
  }
}

/* Màn rất hẹp: nhãn nằm trên, giá trị nằm dưới (bỏ đường kẻ dọc) để chữ không bị ép thành cột quá mảnh. */
@media (max-width: 359.98px) {
  .pd-variant-detail {
    grid-template-columns: minmax(0, 1fr);
  }

  .pd-variant-detail dt {
    padding-bottom: 4px;
    border-right: 0;
  }

  .pd-variant-detail dd {
    padding-top: 4px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .pd-variants-more-chevron {
    transition: none;
  }
}

.pd-variant-price {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px;
}

.pd-variant-price del {
  color: #6b7280;
  font-size: 14px;
}

.pd-price-from {
  margin-right: 6px;
  color: #4a5565;
  font-size: 14px;
}

.pd-btn {
  display: inline-flex;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  padding: 0 20px;
  border: 1px solid #b8893e;
  border-radius: 4px;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.2;
  cursor: pointer;
  transition: background-color 140ms ease, border-color 140ms ease;
}

.pd-btn--inline {
  font-size: 14px;
}

.pd-btn--primary {
  border-color: #b8893e;
  background: #b8893e;
  color: #ffffff;
}

.pd-btn--primary:hover:not(:disabled) {
  border-color: #b8893e;
  background: #9e7432;
}

.pd-btn--outline {
  background: transparent;
  color: #131e2a;
}

.pd-btn--outline:hover:not(:disabled) {
  border-color: #b8893e;
  background: #efeae0;
}

.pd-btn:disabled {
  border-color: #d9d4ca;
  background: #efeae0;
  color: #6b7280;
  cursor: not-allowed;
}

.pd-btn:focus-visible,
.pd-stepper button:focus-visible,
.pd-thumb:focus-visible,
.pd-textarea:focus-visible,
.pd-page a:focus-visible {
  outline: 2px solid #b8893e;
  outline-offset: 2px;
}

.pd-demo-note {
  margin: 0;
  color: #4a5565;
  font-size: 12px;
}

.pd-login-hint {
  margin: 20px 0 0;
  color: #4a5565;
  font-size: 14px;
}

.pd-login-hint a {
  color: #8c6a32;
  text-decoration: underline;
  text-underline-offset: 3px;
}

.pd-subheading {
  margin: 0 0 8px;
  font-size: 16px;
  font-weight: 700;
}

.pd-description {
  margin: 0;
  color: #4a5565;
  font-size: 14px;
  line-height: 1.6;
  white-space: pre-line;
}

.pd-comments {
  padding-top: 32px;
  border-top: 1px solid #d9d4ca;
}

.pd-comments-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.pd-comments-title {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
}

.pd-comments-toggle {
  font-size: 14px;
}

.pd-comments-body {
  display: grid;
  gap: 32px;
  margin-top: 24px;
}

.pd-avatar {
  display: grid;
  width: 40px;
  height: 40px;
  flex: 0 0 40px;
  place-items: center;
  border-radius: 50%;
  background: #efeae0;
  color: #4a5565;
  font-size: 12px;
  font-weight: 700;
}

.pd-comment-form {
  display: flex;
  gap: 16px;
}

.pd-comment-form-body {
  display: grid;
  min-width: 0;
  flex: 1;
  gap: 14px;
}

.pd-textarea {
  width: 100%;
  min-height: 84px;
  padding: 12px;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #ffffff;
  color: #131e2a;
  font-size: 14px;
  resize: vertical;
}

.pd-textarea::placeholder {
  color: #6b7280;
}

.pd-comment-form-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.pd-field-error {
  margin: 6px 0 0;
  color: #93000a;
  font-size: 13px;
}

.pd-edit-textarea {
  margin-top: 8px;
}

.pd-comment-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.pd-comments-state {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin: 0;
  color: #4a5565;
  font-size: 14px;
}

.pd-comments-state--error {
  color: #93000a;
}

.pd-comment-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.pd-comment {
  display: flex;
  gap: 16px;
  padding: 16px 0;
  border-top: 1px solid #d9d4ca;
}

.pd-comment:last-child {
  border-bottom: 1px solid #d9d4ca;
}

.pd-comment-body {
  min-width: 0;
  flex: 1;
}

.pd-comment-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px;
  margin: 0 0 4px;
}

.pd-comment-meta strong {
  font-size: 14px;
  font-weight: 600;
}

.pd-comment-meta time {
  color: #6b7280;
  font-size: 12px;
}

.pd-comment-content {
  margin: 0;
  color: #4a5565;
  font-size: 14px;
  line-height: 1.5;
  overflow-wrap: anywhere;
  white-space: pre-line;
}

.pd-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  margin-top: 24px;
}

.pd-pagination-indicator {
  min-width: 48px;
  color: #4a5565;
  font-size: 14px;
  font-weight: 600;
  text-align: center;
}

@media (max-width: 767.98px) {
  .pd-main {
    grid-template-columns: 1fr;
    grid-template-rows: none;
    grid-template-areas:
      "gallery"
      "info"
      "desc";
  }
}

@media (max-width: 575.98px) {
  .pd-card {
    padding: 16px;
  }

  .pd-breadcrumb {
    display: none;
  }

  .pd-back {
    display: inline-block;
  }

  .pd-name {
    font-size: 20px;
  }

  .pd-comments-header {
    flex-direction: column;
    align-items: stretch;
  }

  .pd-comment-form .pd-avatar {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .pd-btn {
    transition: none;
  }
}
/* ===== Bố cục theo Stitch "Chi tiết sản phẩm" (ghi đè các rule ở trên) ===== */
.pd-card {
  padding: 28px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(28, 42, 58, 0.08);
}

/* Breadcrumb in hoa nhỏ, phân cách bằng viền dưới. */
.pd-breadcrumb {
  margin-bottom: 24px;
  padding-bottom: 14px;
  border-bottom: 1px solid #e6e0d4;
  color: #6b6560;
  font-size: 12px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.pd-main {
  gap: 32px;
}

/* Ảnh lớn: khung trắng viền kem, có lề trong như thẻ ảnh trong Stitch. */
.pd-gallery-main {
  padding: 8px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 2px 8px rgba(28, 42, 58, 0.08);
}

.pd-gallery-main img {
  border-radius: 6px;
}

.pd-thumb {
  border-radius: 8px;
}

.pd-thumb.is-active {
  border: 2px solid #b8893e;
}

/* Dòng danh mục màu đồng, tên sản phẩm serif in hoa. */
.pd-category {
  color: #b8893e;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.pd-name {
  color: #1f1f1f;
  font-family: var(--font-display);
  font-weight: 600;
  line-height: 1.2;
  text-transform: uppercase;
}

/* Khối giá: thẻ trắng viền kem, giá to màu đồng. */
.pd-price {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin: 16px 0 8px;
  padding: 14px 16px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 2px 8px rgba(28, 42, 58, 0.06);
}

.pd-price strong {
  color: #b8893e;
}

.pd-stock {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border: 1px solid #cde5d2;
  border-radius: 4px;
  background: #ebf5ed;
  color: #246a38;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.pd-stock--out {
  border-color: #f8b4b4;
  background: #fde8e8;
  color: #9b1c1c;
}

/* Mỗi phiên bản là một thẻ nhỏ nền kem, viền đồng khi hover. */
.pd-variant {
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #fbf7ef;
  transition: border-color 140ms ease;
}

.pd-variant:hover {
  border-color: rgb(184 137 62 / 45%);
}

.pd-variant-price strong.is-sale,
.pd-variant-price strong {
  color: #b8893e;
}

.pd-subheading {
  color: #1f1f1f;
  font-family: var(--font-display);
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.pd-btn {
  border-radius: 6px;
  letter-spacing: 0.06em;
}

/* Khu bình luận: tách thành khối riêng có viền trên, tiêu đề serif. */
.pd-comments {
  margin-top: 8px;
  padding-top: 28px;
  border-top: 1px solid #e6e0d4;
}

.pd-comments-title {
  color: #1f1f1f;
  font-family: var(--font-display);
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.pd-comment {
  padding: 16px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
}

.pd-avatar {
  border: 1px solid #e6e0d4;
  background: #efeae0;
  color: #b8893e;
}

.pd-textarea {
  border-color: #e6e0d4;
  border-radius: 6px;
  background: #f7f4ee;
}

.pd-textarea:focus {
  border-color: #b8893e;
}

@media (max-width: 767.98px) {
  .pd-card {
    padding: 18px 14px;
  }

}
</style>
