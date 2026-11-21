// Composable dùng chung: chạy một hàm mỗi khi khách QUAY LẠI tab của trang (sau khi xem tab khác).
// Dùng để tải lại dữ liệu dễ bị cũ (tồn kho, giá) đúng lúc khách nhìn lại trang, thay vì hẹn giờ gọi API liên tục.
// Đang dùng ở: trang Giỏ hàng, trang chi tiết sản phẩm, trang đặt hàng qua trợ lý AI.
//
// Composable: hàm bắt đầu bằng "use", gọi trong <script setup> của component để gắn thêm hành vi
// (ở đây là tự gắn và tự gỡ bộ lắng nghe sự kiện theo vòng đời của component).
import { onBeforeUnmount, onMounted } from 'vue'

/**
 * Gọi callback mỗi khi tab của trang được hiện lại.
 * Mỗi trang tự quyết trong callback: có cần tải lại không (điều kiện bỏ qua) và tải lại cái gì.
 *
 * Phải gọi ở cấp trên cùng của <script setup> (không gọi trong hàm khác hay sau await),
 * vì onMounted / onBeforeUnmount chỉ gắn được vào component khi gọi lúc component đang được tạo.
 *
 * @param {() => void} callback Hàm chạy khi khách quay lại tab.
 */
export function useTabVisible(callback) {
  // visibilitychange: trình duyệt báo khi tab bị ẩn (sang tab khác, thu nhỏ cửa sổ) hoặc hiện lại.
  // Chỉ chạy callback khi tab HIỆN LẠI (visibilityState === 'visible'), bỏ qua lúc tab bị ẩn.
  function onVisibilityChange() {
    if (document.visibilityState === 'visible') callback()
  }

  // Gắn bộ lắng nghe khi component vừa hiện ra.
  onMounted(() => {
    document.addEventListener('visibilitychange', onVisibilityChange)
  })
  // Rời trang thì gỡ bộ lắng nghe, tránh gọi API khi trang không còn hiển thị.
  onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibilityChange)
  })
}
