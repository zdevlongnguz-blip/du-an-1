// Kiểm tra file ảnh trước khi tải lên ở các trang quản trị (ảnh đại diện, ảnh slide...).
// Giới hạn khớp với validate_upload trong BE/configs/helper.php: tối đa 2MB, chỉ jpg/png/gif/webp.
// BE vẫn kiểm tra lại; kiểm tra ở FE chỉ để báo lỗi ngay, không phải chờ tải lên xong.

// Dung lượng tối đa (byte): 2MB.
export const MAX_IMAGE_BYTES = 2 * 1024 * 1024

// Loại file ảnh được nhận (MIME type). IMAGE_ACCEPT dùng cho thuộc tính accept của <input type="file">.
export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
export const IMAGE_ACCEPT = IMAGE_TYPES.join(',')

// Kiểm tra một file ảnh. Trả chuỗi lỗi để hiện cho admin, hoặc chuỗi rỗng nếu file hợp lệ.
export function validateImageFile(file) {
  if (!file) return 'Chưa chọn ảnh.'
  if (!IMAGE_TYPES.includes(file.type)) return 'Chỉ nhận ảnh jpg, png, gif hoặc webp.'
  if (file.size > MAX_IMAGE_BYTES) return 'Ảnh tối đa 2MB.'
  return ''
}
