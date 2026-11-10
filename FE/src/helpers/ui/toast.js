import { ref } from 'vue'

const toast = ref(null)
const allowedTypes = new Set(['success', 'danger', 'warning', 'info'])

export function dismissToast() {
  toast.value = null
}

export function showToast(message, type = 'info') {
  toast.value = {
    message: String(message),
    type: allowedTypes.has(type) ? type : 'info',
  }
}

export function useToast() {
  return { toast, showToast, dismissToast }
}