// Định dạng thuộc tính (attributes) của biến thể sản phẩm để hiển thị, dùng chung cho
// card catalog (ProductCardCatalog.vue) và trang chi tiết (ProductDetail.vue).
//
// attributes là JSON "dẻo" từ BE, ví dụ {"dung_tich": "113g", "do_bong": "bóng nhẹ", "phu_hop": null}.
// Khóa viết không dấu, giá trị tiếng Việt có dấu; khóa chưa rõ thì giá trị là null.

// Nhãn tiếng Việt cho các khóa. 5 khóa đầu khớp với 5 khóa mặc định định nghĩa ở
// BE/embedServices/ProductEmbedText.php (thêm khóa mặc định ở BE thì thêm nhãn ở đây), sau đó là vài khóa tự do hay gặp.
export const VARIANT_ATTRIBUTE_LABELS = {
  dung_tich: 'Dung tích',
  do_giu_nep: 'Độ giữ nếp',
  do_bong: 'Độ bóng',
  phu_hop: 'Phù hợp',
  kieu_toc: 'Kiểu tóc',
  mui_huong: 'Mùi hương',
  xuat_xu: 'Xuất xứ',
}

// Đổi attributes thành danh sách { key, label, value } để render bằng v-for.
// Bỏ giá trị null/rỗng; khóa chưa có nhãn thì hiện tên khóa với "_" thay bằng dấu cách.
export function listVariantAttributes(attributes) {
  if (!attributes || typeof attributes !== 'object') return []

  return Object.entries(attributes)
    .filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '')
    .map(([key, value]) => ({
      key,
      label: VARIANT_ATTRIBUTE_LABELS[key] || key.replace(/_/g, ' '),
      value: String(value).trim(),
    }))
}
