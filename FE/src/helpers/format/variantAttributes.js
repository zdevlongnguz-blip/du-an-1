// Đọc thuộc tính (attributes) của biến thể sản phẩm để hiển thị, dùng chung cho
// card catalog (ProductCardCatalog.vue), trang chi tiết (ProductDetail.vue), giỏ hàng (CartView.vue)
// và câu trả lời của Louis ở catalog (catalog.vue).
//
// attributes do BE trả về là MẢNG các phần tử { key, label, value } (xem BE/extensions/VariantAttributes.php):
//   [{ key: 'dung_tich', label: 'Dung tích', value: '113g' }, { key: 'do_bong', label: 'Độ bóng', value: null }, ...]
// - 5 khóa mặc định luôn đứng đầu theo thứ tự cố định, khóa tự do đứng sau.
// - label là nhãn tiếng Việt do BE gửi, FE hiển thị thẳng, không giữ bảng nhãn riêng.
// - value null nghĩa là chưa rõ, không hiển thị.

// Giá trị có nội dung để hiện không (bỏ null, undefined, chuỗi rỗng).
function hasValue(value) {
  return value !== null && value !== undefined && String(value).trim() !== ''
}

// Danh sách thuộc tính để render bằng v-for: giữ thứ tự BE gửi, bỏ phần tử không có giá trị.
// Trả về [{ key, label, value }]; thiếu label thì dùng key (đổi "_" thành dấu cách) cho khỏi trống.
export function listVariantAttributes(attributes) {
  if (!Array.isArray(attributes)) return []

  return attributes
    .filter((item) => item && typeof item.key === 'string' && hasValue(item.value))
    .map((item) => ({
      key: item.key,
      label: typeof item.label === 'string' && item.label.trim() !== '' ? item.label : item.key.replace(/_/g, ' '),
      value: String(item.value).trim(),
    }))
}

// Lấy giá trị của một khóa, ví dụ getVariantAttribute(attributes, 'dung_tich') -> '113g'.
// Không có khóa hoặc chưa rõ giá trị thì trả chuỗi rỗng.
export function getVariantAttribute(attributes, key) {
  if (!Array.isArray(attributes)) return ''
  const item = attributes.find((attribute) => attribute && attribute.key === key)
  return item && hasValue(item.value) ? String(item.value).trim() : ''
}
