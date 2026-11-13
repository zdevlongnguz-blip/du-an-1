import { ref } from 'vue'

const TOAST_DURATION_MS = 5000
const toast = ref(null)
const allowedTypes = new Set(['success', 'danger', 'warning', 'info'])
let hideTimer = null

export function dismissToast() {
  clearTimeout(hideTimer)
  hideTimer = null
  toast.value = null
}

// Toast tự ẩn sau 5 giây; thông báo mới sẽ reset bộ đếm.
export function showToast(message, type = 'info') {
  clearTimeout(hideTimer)
  toast.value = {
    message: String(message),
    type: allowedTypes.has(type) ? type : 'info',
  }
  hideTimer = setTimeout(dismissToast, TOAST_DURATION_MS)
}

export function useToast() {
  return { toast, showToast, dismissToast }
}