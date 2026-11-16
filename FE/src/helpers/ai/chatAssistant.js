// Trạng thái và thao tác dùng chung của khung chat trợ lý AI (popup đặt ở App.vue).
// State nằm ở cấp module (ngoài component) nên khi đổi trang, hội thoại vẫn còn nguyên.
// Gọi API api-ai-chat (POST, cần đăng nhập). Lịch sử hội thoại do BE lưu, FE chỉ giữ conversation_id.
import { ref } from 'vue'
import api from '@/helpers/api/client'
import { getCheckoutDraftForChat, setCheckoutDraft } from '@/helpers/ai/checkoutDraft'

// Độ dài tối đa của một câu hỏi, khớp giới hạn của BE (2000 ký tự).
export const MAX_MESSAGE_LENGTH = 2000

// Danh sách route mà BE được phép yêu cầu mở, kèm các tham số được nhận.
// Route hoặc tham số khác bị bỏ qua: FE không điều hướng theo URL tự do do AI/BE gửi về.
const ALLOWED_ROUTES = {
  catalog: { query: ['search'], params: [] },
  'product-detail': { query: [], params: ['slug'] },
  // Trang sân khấu: Louis hát tặng một bài (song = tên bài, video = tên file video).
  'fule-sing': { query: ['song', 'video'], params: [] },
}

// Danh sách tin nhắn đang hiển thị. Mỗi phần tử:
// { id, role: 'user' | 'assistant', text, time, toolCalls, suggestions, failed, errorText }
// suggestions: các câu gợi ý AI sinh kèm câu trả lời, hiện thành chip bấm nhanh.
const messages = ref([])
// ID cuộc hội thoại trên server. null = chưa có, BE sẽ tạo mới ở lần gửi đầu tiên.
const conversationId = ref(null)
// true khi panel chat đang mở.
const isOpen = ref(false)
// true khi đang chờ BE/Gemini trả lời, dùng để khóa ô nhập và hiện hiệu ứng "đang gõ".
const isSending = ref(false)
// Số câu trả lời đến khi panel đang đóng, hiện thành huy hiệu đỏ trên nút mở chat.
const unreadCount = ref(0)
// Trạng thái nạp lại cuộc chat cũ từ server: 'idle' (chưa nạp), 'loading', 'loaded', 'error'.
// Chỉ nạp 1 lần sau mỗi lần đăng nhập/tải lại trang; bấm "cuộc mới" không nạp lại cuộc cũ.
const historyStatus = ref('idle')

let nextMessageId = 1
// Mã lượt nạp lịch sử. Tăng lên khi đăng xuất để bỏ qua kết quả về muộn của lượt cũ
// (tránh hiện lịch sử của người vừa đăng xuất cho người đăng nhập sau).
let historyRequestId = 0

// Tạo một tin nhắn mới với giờ gửi hiện tại (dạng 14:32).
function createMessage(role, text, extra = {}) {
  return {
    id: nextMessageId++,
    role,
    text,
    time: new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }),
    toolCalls: [],
    suggestions: [],
    failed: false,
    errorText: '',
    ...extra,
  }
}

// Lấy các tham số dạng chuỗi không rỗng nằm trong danh sách cho phép.
function pickStrings(source, allowedKeys) {
  const result = {}
  for (const key of allowedKeys) {
    const value = source?.[key]
    if (typeof value === 'string' && value.trim() !== '') {
      result[key] = value
    }
  }
  return result
}

// Đổi một lệnh của BE thành đích điều hướng cho Vue Router.
// Trả về null nếu lệnh không phải điều hướng, route không được phép hoặc thiếu tham số bắt buộc (ví dụ slug).
export function toRouteLocation(action) {
  if (!action || action.type !== 'navigate') return null

  const rule = ALLOWED_ROUTES[action.route]
  if (!rule) return null

  const params = pickStrings(action.params, rule.params)
  if (rule.params.some((key) => !params[key])) return null

  return {
    name: action.route,
    query: pickStrings(action.query, rule.query),
    params,
  }
}

// Mở panel chat và xóa số tin chưa đọc.
export function openChat() {
  isOpen.value = true
  unreadCount.value = 0
}

// Đóng (thu nhỏ) panel chat. Hội thoại vẫn được giữ lại.
export function closeChat() {
  isOpen.value = false
}

// Bắt đầu cuộc trò chuyện mới: xóa tin nhắn trên màn hình và quên conversation_id.
// Cuộc cũ vẫn nằm trong DB, chỉ là FE không gửi tiếp vào đó nữa.
export function resetChat() {
  messages.value = []
  conversationId.value = null
  unreadCount.value = 0
}

// Xóa toàn bộ lịch sử chat trên server (API api-ai-history-clear, DELETE, cần đăng nhập).
// Thành công thì xóa màn hình và quên conversation_id. Trả true nếu thành công; false nếu lỗi (giữ nguyên hội thoại).
// Không xóa khi đang gửi câu hỏi, vì câu trả lời đến sau sẽ ghi lại cuộc chat vừa xóa.
export async function clearAllHistory() {
  if (isSending.value || historyStatus.value === 'loading') return false
  try {
    // API api-ai-history-clear (DELETE): { status, message, deleted_conversations }.
    const response = await api.delete('/?action=api-ai-history-clear')
    if (response.data?.status !== 'success') throw new Error('Phản hồi không hợp lệ')
    resetChat()
    // Lịch sử đã được nạp (rỗng) rồi, không cần nạp lại khi mở chat.
    historyStatus.value = 'loaded'
    return true
  } catch (err) {
    console.error('Không xóa được lịch sử chat:', err)
    return false
  }
}

// Dùng khi đăng xuất: xóa hội thoại trên màn hình và quên trạng thái đã nạp,
// để lần đăng nhập sau nạp đúng lịch sử của người mới.
export function forgetChat() {
  resetChat()
  historyRequestId++
  historyStatus.value = 'idle'
}

// Đổi thời điểm server trả về (dạng "2026-11-16 22:24:56") thành giờ hiển thị 22:24.
// Trả rỗng nếu chuỗi không đọc được, để không hiện "Invalid Date".
function formatServerTime(value) {
  const date = new Date(String(value || '').replace(' ', 'T'))
  return Number.isNaN(date.getTime())
    ? ''
    : date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })
}

// Nạp lại cuộc chat gần nhất từ server. Gọi khi mở khung chat lúc đã đăng nhập.
// Bỏ qua nếu đang nạp, đã nạp, hoặc khách đã bắt đầu chat (để không ghi đè tin nhắn mới).
export async function loadHistory() {
  if (historyStatus.value === 'loading' || historyStatus.value === 'loaded') return
  if (messages.value.length > 0 || conversationId.value !== null) {
    historyStatus.value = 'loaded'
    return
  }

  const requestId = ++historyRequestId
  historyStatus.value = 'loading'

  try {
    // API api-ai-history (GET, cần đăng nhập): { status, conversation_id, messages }.
    // messages: [{ role: 'user'|'assistant', text, created_at, tool_calls, suggestions }], cũ trước mới sau.
    // conversation_id = null nghĩa là user chưa chat lần nào.
    const response = await api.get('/?action=api-ai-history')
    const data = response.data
    if (data?.status !== 'success' || !Array.isArray(data.messages)) {
      throw new Error('Phản hồi không hợp lệ')
    }
    // Đã đăng xuất (hoặc có lượt nạp mới hơn) trong lúc chờ: bỏ kết quả này.
    if (requestId !== historyRequestId) return

    conversationId.value = data.conversation_id ?? null
    messages.value = data.messages
      .filter((item) => (item?.role === 'user' || item?.role === 'assistant') && typeof item.text === 'string')
      .map((item) => createMessage(item.role, item.text, {
        time: formatServerTime(item.created_at),
        toolCalls: Array.isArray(item.tool_calls) ? item.tool_calls : [],
        suggestions: Array.isArray(item.suggestions)
          ? item.suggestions.filter((text) => typeof text === 'string' && text.trim() !== '').slice(0, 3)
          : [],
      }))
    historyStatus.value = 'loaded'
  } catch {
    // Lỗi thật (mạng, 500...): báo 'error' để khung chat hiện nút "Thử lại", không coi như chưa từng chat.
    // 401 do interceptor tự xử lý (chuyển về trang đăng nhập).
    if (requestId === historyRequestId) {
      historyStatus.value = 'error'
    }
  }
}

// Gửi câu hỏi của một tin nhắn user lên BE và thêm câu trả lời vào danh sách.
// Trả về đích điều hướng nếu BE yêu cầu mở trang, ngược lại trả về null.
async function requestReply(messageId) {
  // Lấy phần tử qua messages.value để thay đổi được Vue theo dõi (cập nhật giao diện).
  const userMessage = messages.value.find((item) => item.id === messageId)
  if (!userMessage) return null

  isSending.value = true
  userMessage.failed = false
  userMessage.errorText = ''

  try {
    const body = { message: userMessage.text }
    if (conversationId.value) {
      body.conversation_id = conversationId.value
    }
    // Đang có đơn nháp: gửi kèm trạng thái mới nhất (đã gồm số lượng, người nhận khách sửa trên trang)
    // để Louis lên đơn tiếp từ đúng cái khách đang thấy, không dùng danh sách cũ trong lịch sử chat.
    const draftForChat = getCheckoutDraftForChat()
    if (draftForChat) {
      body.checkout_draft = draftForChat
    }

    // API api-ai-chat: trả { status, conversation_id, reply, tool_calls, actions, suggestions }.
    // Lỗi: 401 (interceptor tự chuyển về login), 404 cuộc hội thoại không thuộc user, 422, 500, 502.
    const response = await api.post('/?action=api-ai-chat', body)
    const data = response.data
    if (data?.status !== 'success') {
      throw new Error('Phản hồi không hợp lệ')
    }

    conversationId.value = data.conversation_id ?? conversationId.value
    messages.value.push(createMessage('assistant', data.reply || 'Xin lỗi, tôi chưa có câu trả lời phù hợp.', {
      toolCalls: Array.isArray(data.tool_calls) ? data.tool_calls : [],
      // Chỉ giữ chuỗi không rỗng, tối đa 3 câu (BE đã lọc, FE kiểm tra lại cho chắc).
      suggestions: Array.isArray(data.suggestions)
        ? data.suggestions.filter((item) => typeof item === 'string' && item.trim() !== '').slice(0, 3)
        : [],
    }))
    if (!isOpen.value) {
      unreadCount.value += 1
    }

    // Lấy lệnh hợp lệ đầu tiên (BE hiện gửi tối đa 1 lệnh).
    const actions = Array.isArray(data.actions) ? data.actions : []
    for (const action of actions) {
      // Lệnh đơn nháp (tool create_order_draft): lưu đơn vào bộ nhớ rồi mở trang lên đơn.
      // Không đưa đơn lên URL để tên, SĐT, địa chỉ không lộ trên thanh địa chỉ và lịch sử trình duyệt.
      if (action?.type === 'checkout-draft') {
        if (setCheckoutDraft(action.draft)) return { name: 'ai-checkout' }
        continue
      }
      const location = toRouteLocation(action)
      if (location) return location
    }
    return null
  } catch (err) {
    // 404: cuộc hội thoại cũ không còn hoặc không thuộc user. Bỏ conversation_id để lần gửi lại tạo cuộc mới.
    if (err.response?.status === 404) {
      conversationId.value = null
    }
    // Đánh dấu tin nhắn lỗi để hiện nút "Thử lại", không nuốt lỗi thành câu trả lời rỗng.
    userMessage.failed = true
    userMessage.errorText = err.response?.data?.message || 'Không gửi được câu hỏi, vui lòng thử lại.'
    return null
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên mở khóa ô nhập ở đây.
    isSending.value = false
  }
}

// Gửi câu hỏi mới. Trả về đích điều hướng (nếu có) để component gọi router.push.
export async function sendMessage(text) {
  const message = String(text ?? '').trim()
  // Không gửi khi đang nạp lịch sử: lịch sử về sau sẽ ghi đè tin vừa gửi và lệch conversation_id.
  if (message === '' || message.length > MAX_MESSAGE_LENGTH || isSending.value || historyStatus.value === 'loading') {
    return null
  }

  const userMessage = createMessage('user', message)
  messages.value.push(userMessage)
  return requestReply(userMessage.id)
}

// Gửi lại một câu hỏi bị lỗi. Trả về đích điều hướng (nếu có).
export async function retryMessage(messageId) {
  const target = messages.value.find((item) => item.id === messageId)
  if (!target || !target.failed || isSending.value) {
    return null
  }
  return requestReply(messageId)
}

// Dùng trong component: lấy state và các hàm thao tác của khung chat.
export function useChatAssistant() {
  return {
    messages,
    conversationId,
    isOpen,
    isSending,
    unreadCount,
    historyStatus,
    openChat,
    closeChat,
    resetChat,
    forgetChat,
    clearAllHistory,
    loadHistory,
    sendMessage,
    retryMessage,
  }
}
