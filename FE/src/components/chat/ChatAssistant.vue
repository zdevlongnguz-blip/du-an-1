<script setup>
// Khung chat trợ lý AI "Louis Dé Fule" dạng popup, đặt ở App.vue nên dùng được ở mọi trang.
// Gọi API api-ai-chat qua helpers/ai/chatAssistant.js. Chưa đăng nhập thì hiện lời mời đăng nhập.
// Khi BE trả lệnh (actions), component mở trang tương ứng: danh sách/chi tiết sản phẩm, sân khấu hát, hoặc trang lên đơn nháp.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useChatAssistant, MAX_MESSAGE_LENGTH } from '@/helpers/ai/chatAssistant'
import { clearCheckoutDraft } from '@/helpers/ai/checkoutDraft'
import { saveReturnUrl } from '@/helpers/auth/returnUrl'
import { getUser } from '@/helpers/auth/session'
import { getDisplayName } from '@/helpers/auth/profile'
import { resolveSystemImageUrl } from '@/helpers/media/imageUrl'
import { showToast } from '@/helpers/ui/toast'

const props = defineProps({
  // Trạng thái đăng nhập do App.vue truyền xuống (App cập nhật lại mỗi lần đổi trang).
  authenticated: { type: Boolean, default: false },
})

const router = useRouter()
const route = useRoute()
const {
  messages,
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
} = useChatAssistant()

// Nội dung đang gõ trong ô nhập. v-model: tự đồng bộ giữa ô nhập và biến này.
const draft = ref('')
// Thẻ DOM của vùng tin nhắn, dùng để cuộn xuống tin mới nhất.
const scrollBox = ref(null)
// Thẻ DOM của ô nhập, dùng để đặt con trỏ vào ô khi mở chat.
const inputEl = ref(null)
// Khoảng cách (px) từ đỉnh màn hình tới cạnh trên của panel. null = dùng giá trị trong CSS.
const panelTop = ref(null)

// Khoảng trống giữa thanh điều hướng và cạnh trên của panel.
const PANEL_GAP_PX = 12

// Ảnh đại diện của trợ lý, lấy từ BE/assets/system/fule.webp.
const avatarUrl = resolveSystemImageUrl('fule.webp')
// true khi ảnh tải lỗi (sai đường dẫn, server tắt...): mọi avatar chuyển sang hiện chữ "L".
const avatarFailed = ref(false)

// Tên dùng trong lời chào: họ tên trong hồ sơ, chưa có thì phần trước @ của email; rỗng = chào chung.
const greetingName = ref('')

// Đọc lại tên từ localStorage (auth_user). localStorage không "phản ứng" như ref,
// nên phải chủ động đọc lại khi có thể đã đổi: đổi trạng thái đăng nhập, đổi trang, mở khung chat.
function refreshGreetingName() {
  greetingName.value = props.authenticated ? getDisplayName(getUser()) : ''
}

// watch nhiều nguồn cùng lúc; immediate: true để chạy luôn 1 lần khi component vừa tạo.
watch([() => props.authenticated, () => route.fullPath, isOpen], refreshGreetingName, { immediate: true })

// true trong lúc gọi API xóa, để khóa nút tránh bấm lặp.
const isClearing = ref(false)

// Bấm nút xóa: hỏi xác nhận bằng hộp thoại của trình duyệt, khách đồng ý thì mới gọi API xóa.
async function handleClearHistory() {
  if (!window.confirm('Xóa toàn bộ lịch sử trò chuyện với Louis Dé Fule? Không thể khôi phục.')) return
  isClearing.value = true
  const ok = await clearAllHistory()
  isClearing.value = false
  if (ok) {
    clearCheckoutDraft()
    showToast('Đã xóa toàn bộ lịch sử trò chuyện.', 'success')
  } else {
    showToast('Không xóa được lịch sử, quý ông thử lại giúp Louis nhé.', 'danger')
  }
}

// Gợi ý mặc định khi chưa có câu trả lời nào, hoặc khi AI không sinh gợi ý.
// Mỗi chip ứng với một việc trợ lý làm được: hát (tool fule_sing), xem 1 sản phẩm (tool search_product),
// lên đơn nháp cho 1 phiên bản cụ thể (search_product rồi create_order_draft).
const DEFAULT_SUGGESTIONS = [
  'Hát 1 bài đi anh Fule',
  'Tôi muốn xem sản phẩm "Vinh Râu"',
  'Tôi muốn mua sáp Hanz de Fuko phiên bản Hũ 113 gam',
]

// computed: lấy gợi ý của câu trả lời mới nhất (AI sinh theo ngữ cảnh), không có thì dùng gợi ý mặc định.
const suggestions = computed(() => {
  for (let i = messages.value.length - 1; i >= 0; i--) {
    const message = messages.value[i]
    if (message.role === 'assistant') {
      return message.suggestions.length > 0 ? message.suggestions : DEFAULT_SUGGESTIONS
    }
  }
  return DEFAULT_SUGGESTIONS
})

// computed: tự tính lại khi draft hoặc isSending đổi. true khi được phép bấm gửi.
const canSend = computed(() => {
  const text = draft.value.trim()
  return !isSending.value && historyStatus.value !== 'loading' && text !== '' && text.length <= MAX_MESSAGE_LENGTH
})

// Cuộn vùng tin nhắn xuống cuối. nextTick: đợi Vue vẽ xong tin mới rồi mới cuộn.
async function scrollToBottom() {
  await nextTick()
  if (scrollBox.value) {
    scrollBox.value.scrollTop = scrollBox.value.scrollHeight
  }
}

// Mở trang mà BE yêu cầu (đã được lọc theo danh sách route cho phép trong helper).
// Trên điện thoại, panel che toàn màn hình nên thu nhỏ panel để khách thấy trang vừa mở.
async function navigateTo(location) {
  if (!location) return
  await router.push(location)
  if (window.matchMedia('(max-width: 575.98px)').matches) {
    closeChat()
  }
}

// Gửi một câu hỏi (từ ô nhập hoặc từ chip gợi ý), báo lỗi bằng toast nếu gửi thất bại.
async function submitText(text) {
  const location = await sendMessage(text)
  const lastMessage = messages.value[messages.value.length - 1]
  if (lastMessage?.role === 'user' && lastMessage.failed) {
    showToast(lastMessage.errorText, 'danger')
  }
  await navigateTo(location)
}

// Xử lý khi bấm nút gửi hoặc nhấn Enter trong ô nhập.
async function handleSubmit() {
  if (!canSend.value) return
  const text = draft.value
  draft.value = ''
  await submitText(text)
}

// Xử lý khi bấm một câu hỏi gợi ý.
async function handleSuggestion(text) {
  if (isSending.value) return
  await submitText(text)
}

// Gửi lại câu hỏi bị lỗi khi bấm "Thử lại".
async function handleRetry(messageId) {
  const location = await retryMessage(messageId)
  await navigateTo(location)
}

// Mở panel, cuộn xuống tin mới nhất và đặt con trỏ vào ô nhập.
async function handleOpen() {
  openChat()
  await scrollToBottom()
  inputEl.value?.focus()
}

// Lấy từ khóa mà tool search_product đã dùng, để hiện chip "Đã tra cứu" dưới câu trả lời.
function searchedKeywords(toolCalls) {
  return toolCalls
    .filter((call) => call?.name === 'search_product' && typeof call.args?.keyword === 'string')
    .map((call) => call.args.keyword)
}

// computed: style đặt cạnh trên của panel ngay dưới thanh điều hướng.
const panelStyle = computed(() => (panelTop.value === null ? {} : { top: `${panelTop.value}px` }))

// Tính lại vị trí cạnh trên của panel. Được gọi khi mở chat và khi đổi kích thước cửa sổ.
// .site-header là thanh điều hướng trong App.vue; lấy cạnh dưới của nó để panel không che menu.
function updatePanelTop() {
  // Điện thoại: panel chiếm toàn màn hình (đặt trong CSS), không cần tính.
  if (window.matchMedia('(max-width: 575.98px)').matches) {
    panelTop.value = null
    return
  }
  const header = document.querySelector('.site-header')
  const headerBottom = header ? header.getBoundingClientRect().bottom : 0
  panelTop.value = Math.max(headerBottom, 0) + PANEL_GAP_PX
}

// Gỡ sự kiện đổi kích thước cửa sổ, tránh chạy thừa khi chat đã đóng.
// Lưu ý: bấm ra ngoài KHÔNG đóng chat (đã tắt theo yêu cầu); chỉ đóng bằng nút thu nhỏ hoặc phím Esc.
function removeGlobalListeners() {
  window.removeEventListener('resize', updatePanelTop)
}

// watch: chỉ lắng nghe "đổi kích thước cửa sổ" khi panel đang mở, để tính lại vị trí panel.
// immediate: chạy ngay lần đầu, phòng khi panel đã mở sẵn lúc component được tạo.
watch(isOpen, (open) => {
  removeGlobalListeners()
  if (open) {
    updatePanelTop()
    window.addEventListener('resize', updatePanelTop)
  }
}, { immediate: true })

// onBeforeUnmount: chạy khi component bị gỡ khỏi trang, dọn sự kiện đã gắn.
onBeforeUnmount(removeGlobalListeners)

// watch: theo dõi số tin nhắn và trạng thái đang gửi; có thay đổi thì cuộn xuống cuối.
watch([() => messages.value.length, isSending], scrollToBottom)

// Khi đăng xuất (hoặc token hết hạn), xóa hội thoại và đơn nháp trên màn hình để người dùng sau không thấy.
// forgetChat (không phải resetChat) để lần đăng nhập sau nạp lại lịch sử của đúng người đó.
watch(() => props.authenticated, (loggedIn) => {
  if (!loggedIn) {
    forgetChat()
    clearCheckoutDraft()
  }
})

// watch: khi khung chat đang mở và đã đăng nhập thì nạp lại cuộc chat gần nhất (chỉ 1 lần, xem loadHistory).
// Theo dõi cả isOpen lẫn authenticated vì chat có thể được mở từ trang khác (openChat) hoặc đăng nhập lúc đang mở.
// immediate: chạy luôn 1 lần khi component vừa tạo, phòng trường hợp panel đã mở sẵn.
watch([isOpen, () => props.authenticated], async ([open, loggedIn]) => {
  if (open && loggedIn) {
    await loadHistory()
    scrollToBottom()
  }
}, { immediate: true })
</script>

<template>
  <div class="chat-assistant">
    <!-- Nút mở chat (launcher) ở góc phải dưới, ẩn khi panel đang mở -->
    <button
      v-if="!isOpen"
      type="button"
      class="chat-launcher"
      aria-label="Mở trợ lý tư vấn Louis"
      @click="handleOpen"
    >
      <span class="chat-launcher-pill" aria-hidden="true">Chat with Fule</span>
      <span class="chat-launcher-avatar">
        <img v-if="!avatarFailed" :src="avatarUrl" alt="" class="chat-avatar-img" @error="avatarFailed = true" /><span v-else class="chat-avatar-letter">L</span>
        <span class="chat-online-dot"></span>
      </span>
      <span v-if="unreadCount > 0" class="chat-launcher-badge">{{ unreadCount }}</span>
    </button>

    <!-- Panel chat: Transition tạo hiệu ứng mở/đóng từ góc phải dưới -->
    <Transition name="chat-panel">
      <section
        v-if="isOpen"
        class="chat-panel"
        :style="panelStyle"
        role="dialog"
        aria-label="Trợ lý tư vấn Louis Dé Fule"
        @keydown.esc="closeChat"
      >
        <!-- Header: avatar, tên trợ lý và các nút điều khiển -->
        <header class="chat-header">
          <span class="chat-header-avatar">
            <img v-if="!avatarFailed" :src="avatarUrl" alt="" class="chat-avatar-img" @error="avatarFailed = true" /><span v-else class="chat-avatar-letter">L</span>
            <span class="chat-online-dot"></span>
          </span>
          <div class="chat-header-copy">
            <h2 class="chat-header-name">Louis Dé Fule</h2>
            <p class="chat-header-status">Trợ lý tư vấn · Đang hoạt động</p>
          </div>
          <!-- Xóa toàn bộ lịch sử: hỏi xác nhận bằng hộp thoại của trình duyệt rồi mới gọi API. -->
          <button
            v-if="authenticated && (messages.length > 0 || historyStatus === 'loaded')"
            type="button"
            class="chat-icon-button"
            title="Xóa toàn bộ lịch sử chat"
            aria-label="Xóa toàn bộ lịch sử chat"
            :disabled="isSending || isClearing"
            @click="handleClearHistory"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></svg>
          </button>
          <button type="button" class="chat-icon-button" title="Thu nhỏ" aria-label="Thu nhỏ khung chat" @click="closeChat">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14" /></svg>
          </button>
        </header>

        <!-- Chưa đăng nhập: thông báo và link tới trang đăng nhập (lưu trang hiện tại để quay lại) -->
        <div v-if="!authenticated" class="chat-body chat-guest">
          <span class="chat-guest-avatar"><img v-if="!avatarFailed" :src="avatarUrl" alt="" class="chat-avatar-img" @error="avatarFailed = true" /><span v-else class="chat-avatar-letter">L</span></span>
          <h3 class="chat-guest-title">Đăng nhập để trò chuyện cùng Louis</h3>
          <p class="chat-guest-text">
            Trợ lý tư vấn chỉ dành cho thành viên. Đăng nhập để được gợi ý sản phẩm phù hợp và lưu lại cuộc trò chuyện.
          </p>
          <RouterLink
            :to="{ name: 'login' }"
            class="chat-button-primary"
            @click.capture="saveReturnUrl(route.fullPath)"
          >
            Đăng nhập
          </RouterLink>
          <RouterLink :to="{ name: 'register' }" class="chat-guest-link">Chưa có tài khoản? Đăng ký</RouterLink>
        </div>

        <template v-else>
          <!-- Danh sách tin nhắn. aria-live: trình đọc màn hình đọc tin mới khi xuất hiện -->
          <div ref="scrollBox" class="chat-body" aria-live="polite">
            <!-- Lời chào ở đầu hội thoại, gắn tên user nếu có (chỉ hiển thị, không gửi lên server).
                 Khoảng trắng trước tên đặt trong chuỗi JS vì Vue bỏ khoảng trắng đầu dòng trong template. -->
            <div class="chat-row chat-row--assistant">
              <span class="chat-message-avatar"><img v-if="!avatarFailed" :src="avatarUrl" alt="" class="chat-avatar-img" @error="avatarFailed = true" /><span v-else class="chat-avatar-letter">L</span></span>
              <div class="chat-message-stack">
                <span class="chat-sender">Louis Dé Fule</span>
                <div class="chat-bubble chat-bubble--assistant">
                  Bông dua quý ông{{ greetingName ? ' ' + greetingName : '' }}! Tôi là Louis Dé Fule, trợ lý tư vấn của FuLe BarberShop. Quý ông đang tìm sáp, pomade hay sản phẩm chăm sóc tóc nào để Fule tư vấn ạ?
                </div>
              </div>
            </div>

            <!-- Trạng thái nạp lại cuộc chat cũ: đang tải, hoặc lỗi kèm nút thử lại -->
            <p v-if="historyStatus === 'loading'" class="chat-history-note">Đang tải lại cuộc trò chuyện trước...</p>
            <p v-else-if="historyStatus === 'error'" class="chat-history-note chat-history-note--error">
              Không tải được cuộc trò chuyện trước.
              <button type="button" class="chat-history-retry" @click="loadHistory">Thử lại</button>
            </p>

            <!-- Các lượt hội thoại. v-for + :key: Vue dùng id để cập nhật đúng từng tin nhắn -->
            <div
              v-for="message in messages"
              :key="message.id"
              class="chat-row"
              :class="message.role === 'user' ? 'chat-row--user' : 'chat-row--assistant'"
            >
              <span v-if="message.role === 'assistant'" class="chat-message-avatar">
                <img v-if="!avatarFailed" :src="avatarUrl" alt="" class="chat-avatar-img" @error="avatarFailed = true" /><span v-else class="chat-avatar-letter">L</span>
              </span>
              <div class="chat-message-stack">
                <span v-if="message.role === 'assistant'" class="chat-sender">Louis Dé Fule</span>
                <div class="chat-bubble" :class="`chat-bubble--${message.role}`">
                  {{ message.text }}
                  <span class="chat-time">{{ message.time }}</span>
                </div>

                <!-- Chip cho biết trợ lý đã tra cứu dữ liệu thật với từ khóa nào -->
                <span
                  v-for="keyword in message.role === 'assistant' ? searchedKeywords(message.toolCalls) : []"
                  :key="keyword"
                  class="chat-tool-chip"
                >
                  Đã tra cứu sản phẩm: “{{ keyword }}”
                </span>

                <!-- Tin nhắn gửi lỗi: hiện lý do và nút thử lại -->
                <div v-if="message.failed" class="chat-error" role="alert">
                  <span>{{ message.errorText }}</span>
                  <button type="button" class="chat-retry" :disabled="isSending" @click="handleRetry(message.id)">
                    Thử lại
                  </button>
                </div>
              </div>
            </div>

            <!-- Đang chờ trả lời: hiệu ứng ba chấm -->
            <div v-if="isSending" class="chat-row chat-row--assistant">
              <span class="chat-message-avatar"><img v-if="!avatarFailed" :src="avatarUrl" alt="" class="chat-avatar-img" @error="avatarFailed = true" /><span v-else class="chat-avatar-letter">L</span></span>
              <div class="chat-message-stack">
                <span class="chat-sender">Louis Dé Fule</span>
                <div class="chat-bubble chat-bubble--assistant chat-typing" aria-label="Louis đang trả lời">
                  <span></span><span></span><span></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Câu hỏi gợi ý nhanh (AI sinh theo ngữ cảnh, hoặc gợi ý mặc định) -->
          <div class="chat-suggestions">
            <span class="chat-suggestions-label">Gợi ý cho bạn</span>
            <button
              v-for="suggestion in suggestions"
              :key="suggestion"
              type="button"
              class="chat-suggestion"
              :disabled="isSending"
              @click="handleSuggestion(suggestion)"
            >
              {{ suggestion }}
            </button>
          </div>

          <!-- Ô nhập câu hỏi. submit.prevent: chặn trình duyệt tải lại trang khi gửi form -->
          <form class="chat-composer" @submit.prevent="handleSubmit">
            <input
              ref="inputEl"
              v-model="draft"
              type="text"
              class="chat-input"
              :maxlength="MAX_MESSAGE_LENGTH"
              :disabled="isSending"
              :placeholder="isSending ? 'Louis đang trả lời...' : 'Nhắn cho Louis...'"
              aria-label="Nhập câu hỏi cho trợ lý"
            />
            <button type="submit" class="chat-send" :disabled="!canSend" aria-label="Gửi câu hỏi">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-8-6 16-3-6-7-2z" /></svg>
            </button>
          </form>
          <p class="chat-note" :class="{ 'chat-note--busy': isSending }">
            {{ isSending
              ? 'Louis đang tra cứu dữ liệu cửa hàng...'
              : 'Louis dùng AI nên có thể nhầm. Giá và tồn kho chính xác xem tại trang sản phẩm.' }}
          </p>
        </template>
      </section>
    </Transition>
  </div>
</template>

<style scoped>
/* Vị trí cố định ở góc phải dưới, nằm trên nội dung trang nhưng dưới toast hệ thống. */
.chat-assistant {
  position: fixed;
  right: 24px;
  bottom: 24px;
  z-index: 1070;
}

/* ---------- Launcher ---------- */
.chat-launcher {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
}

.chat-launcher-avatar {
  position: relative;
  display: grid;
  place-items: center;
  width: 60px;
  height: 60px;
  border: 3px solid var(--color-brass);
  border-radius: 50%;
  background: var(--color-navy);
  box-shadow: 0 10px 24px rgba(19, 30, 42, 0.25);
  transition: transform 200ms ease-out, border-color 200ms ease-out;
}

/* Vòng sáng nhấp nháy báo trợ lý đang trực tuyến (chu kỳ 2 giây). */
.chat-launcher-avatar::before {
  content: '';
  position: absolute;
  inset: -3px;
  border: 2px solid var(--color-brass);
  border-radius: 50%;
  animation: chat-pulse 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}

.chat-launcher:hover .chat-launcher-avatar,
.chat-launcher:focus-visible .chat-launcher-avatar {
  transform: scale(1.08) translateY(-2px);
  border-color: var(--color-brass-light);
}

.chat-launcher:hover .chat-launcher-avatar::before,
.chat-launcher:focus-visible .chat-launcher-avatar::before {
  animation: none;
  opacity: 0;
}

.chat-launcher:active .chat-launcher-avatar {
  transform: scale(0.95);
}

/* Nhãn "Chat với Louis" trượt ra khi rê chuột. */
.chat-launcher-pill {
  padding: 10px 16px;
  border-radius: 8px;
  background: var(--color-navy);
  color: #fff;
  font-weight: 600;
  white-space: nowrap;
  opacity: 0;
  transform: translateX(12px);
  pointer-events: none;
  transition: opacity 200ms ease-out, transform 200ms ease-out;
}

.chat-launcher:hover .chat-launcher-pill,
.chat-launcher:focus-visible .chat-launcher-pill {
  opacity: 1;
  transform: translateX(0);
}

.chat-launcher-badge {
  position: absolute;
  top: -8px;
  right: -8px;
  display: grid;
  place-items: center;
  min-width: 22px;
  height: 22px;
  padding: 0 6px;
  border: 2px solid #fff;
  border-radius: 999px;
  background: #b3261e;
  color: #fff;
  font-size: 0.75rem;
  font-weight: 700;
}

@keyframes chat-pulse {
  0% { transform: scale(1); opacity: 0.45; }
  100% { transform: scale(1.3); opacity: 0; }
}

/* ---------- Avatar dùng chung ---------- */
/* Ảnh lấp đầy khung tròn; object-fit: cover cắt ảnh cho vừa khung mà không bị méo. */
.chat-avatar-img {
  display: block;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
}

.chat-avatar-letter {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--color-brass-light);
  line-height: 1;
}

.chat-launcher-avatar .chat-avatar-letter {
  font-size: 1.75rem;
}

/* Avatar hình tròn nên chấm xanh đặt sát mép phải dưới, không lệch ra ngoài. */
.chat-online-dot {
  position: absolute;
  right: 0;
  bottom: 0;
  width: 14px;
  height: 14px;
  border: 2px solid #fff;
  border-radius: 50%;
  background: #2f7d4f;
}

/* ---------- Panel ---------- */
/* Panel cố định theo màn hình: cạnh trên ngay dưới thanh điều hướng (top tính bằng JS, 96px là
   giá trị dự phòng), cạnh dưới cách đáy 24px. Chiều cao tự giãn theo khoảng trống giữa hai cạnh. */
.chat-panel {
  position: fixed;
  top: 96px;
  right: 24px;
  bottom: 24px;
  display: flex;
  flex-direction: column;
  width: 340px;
  overflow: hidden;
  border: 1px solid var(--color-brass);
  border-radius: 8px;
  background: var(--color-paper);
  box-shadow: 0 24px 48px rgba(19, 30, 42, 0.28);
  transform-origin: bottom right;
}

.chat-panel-enter-active,
.chat-panel-leave-active {
  transition: opacity 200ms ease-out, transform 200ms ease-out;
}

.chat-panel-enter-from,
.chat-panel-leave-to {
  opacity: 0;
  transform: scale(0.9);
}

/* flex-shrink: 0 cho header, chip, ô nhập, chú thích: chỉ vùng tin nhắn được co giãn,
   các phần còn lại giữ nguyên chiều cao (trước đây hàng chip bị bóp méo vì bị co). */
.chat-header,
.chat-suggestions,
.chat-composer,
.chat-note {
  flex-shrink: 0;
}

.chat-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-bottom: 2px solid var(--color-brass);
  background: var(--color-navy);
  color: #fff;
}

.chat-header-avatar {
  position: relative;
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 36px;
  height: 36px;
  border: 2px solid var(--color-brass);
  border-radius: 50%;
  background: var(--color-navy-2);
}

.chat-header-avatar .chat-avatar-letter {
  font-size: 1.1rem;
}

.chat-header-avatar .chat-online-dot {
  right: -2px;
  bottom: -2px;
  width: 10px;
  height: 10px;
  border-color: var(--color-navy);
}

.chat-header-copy {
  flex: 1 1 auto;
  min-width: 0;
}

.chat-header-name {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.05rem;
  font-weight: 700;
  color: #fff;
}

.chat-header-status {
  margin: 0;
  font-size: 0.72rem;
  color: rgba(255, 255, 255, 0.75);
}

.chat-icon-button {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: rgba(255, 255, 255, 0.85);
}

.chat-icon-button:hover:not(:disabled),
.chat-icon-button:focus-visible {
  background: var(--color-navy-3);
  color: #fff;
}

.chat-icon-button:disabled {
  opacity: 0.4;
}

.chat-icon-button svg,
.chat-send svg {
  width: 18px;
  height: 18px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}

/* ---------- Vùng tin nhắn ---------- */
.chat-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 12px;
}

.chat-row {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.chat-row--user {
  justify-content: flex-end;
}

.chat-message-avatar {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 28px;
  height: 28px;
  border: 1px solid var(--color-brass);
  border-radius: 50%;
  background: var(--color-navy);
}

.chat-message-avatar .chat-avatar-letter {
  font-size: 0.9rem;
}

.chat-message-stack {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  max-width: 82%;
}

.chat-row--user .chat-message-stack {
  align-items: flex-end;
}

.chat-sender {
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--color-brass-dark);
}

/* pre-line: giữ xuống dòng trong câu trả lời của AI nhưng không hiển thị HTML (an toàn hơn v-html). */
.chat-bubble {
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 0.85rem;
  line-height: 1.5;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

.chat-bubble--assistant {
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  color: var(--color-ink);
}

.chat-bubble--user {
  background: var(--color-navy);
  color: #fff;
}

.chat-time {
  display: block;
  margin-top: 4px;
  font-size: 0.66rem;
  text-align: right;
  opacity: 0.6;
}

.chat-tool-chip {
  padding: 3px 10px;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: var(--color-soft);
  color: var(--color-muted);
  font-size: 0.7rem;
  line-height: 1.4;
}

.chat-error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  color: #ba1a1a;
  font-size: 0.8rem;
  text-align: right;
}

/* Dòng báo đang tải / lỗi tải lịch sử, nằm giữa vùng tin nhắn. */
.chat-history-note {
  margin: 0.25rem 0 0.75rem;
  color: #6b6560;
  font-size: 0.78rem;
  text-align: center;
}

.chat-history-note--error {
  color: #93000a;
}

.chat-history-retry {
  padding: 0;
  border: 0;
  background: none;
  color: #9e7432;
  font-weight: 600;
  text-decoration: underline;
}

.chat-retry {
  padding: 2px 10px;
  border: 1px solid #ba1a1a;
  border-radius: 6px;
  background: #fff;
  color: #ba1a1a;
  font-weight: 600;
}

.chat-retry:hover:not(:disabled) {
  background: #ba1a1a;
  color: #fff;
}

/* Ba chấm nhảy lần lượt báo trợ lý đang soạn câu trả lời. */
.chat-typing {
  display: flex;
  gap: 5px;
  padding: 14px 16px;
}

.chat-typing span {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-brass);
  animation: chat-typing 1.2s infinite ease-in-out;
}

.chat-typing span:nth-child(2) { animation-delay: 0.15s; }
.chat-typing span:nth-child(3) { animation-delay: 0.3s; }

@keyframes chat-typing {
  0%, 60%, 100% { transform: translateY(0); opacity: 0.5; }
  30% { transform: translateY(-4px); opacity: 1; }
}

/* ---------- Gợi ý, ô nhập, chú thích ---------- */
/* Các chip xuống dòng (flex-wrap) thay vì cuộn ngang, nên không chip nào bị cắt chữ. */
.chat-suggestions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 8px 12px 10px;
  border-top: 1px solid var(--color-border);
  background: var(--color-paper);
}

.chat-suggestions-label {
  flex-basis: 100%;
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-muted);
}

/* Chip nền kem nhạt, không viền; rê chuột thì hiện viền đồng. max-width: câu dài sẽ xuống dòng trong chip. */
.chat-suggestion {
  max-width: 100%;
  padding: 4px 10px;
  border: 1px solid transparent;
  border-radius: 999px;
  background: var(--color-soft);
  color: var(--color-navy);
  font-size: 0.74rem;
  font-weight: 500;
  line-height: 1.35;
  text-align: left;
  transition: background-color 150ms ease, border-color 150ms ease, color 150ms ease;
}

.chat-suggestion:hover:not(:disabled),
.chat-suggestion:focus-visible {
  border-color: var(--color-brass);
  background: var(--color-surface);
  color: var(--color-brass-dark);
}

.chat-suggestion:disabled {
  opacity: 0.5;
}

.chat-composer {
  display: flex;
  gap: 8px;
  padding: 8px 12px 4px;
  background: var(--color-surface);
}

.chat-input {
  flex: 1 1 auto;
  min-width: 0;
  padding: 8px 12px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-paper);
  color: var(--color-ink);
  font-size: 0.85rem;
}

.chat-input:focus {
  border-color: var(--color-brass);
  outline: 2px solid rgba(184, 137, 62, 0.25);
}

.chat-input:disabled {
  background: var(--color-soft);
}

.chat-send {
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  width: 38px;
  border: 0;
  border-radius: 8px;
  background: var(--color-brass);
  color: #fff;
}

.chat-send:hover:not(:disabled) {
  background: #9e7432;
}

.chat-send:disabled {
  background: var(--color-placeholder);
  color: var(--color-muted);
}

.chat-note {
  margin: 0;
  padding: 4px 12px 8px;
  background: var(--color-surface);
  color: var(--color-muted);
  font-size: 0.66rem;
  text-align: center;
}

.chat-note--busy {
  color: var(--color-brass-dark);
  font-style: italic;
}

/* ---------- Trạng thái chưa đăng nhập ---------- */
.chat-guest {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 32px 28px;
  text-align: center;
}

.chat-guest-avatar {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border: 3px solid var(--color-brass);
  border-radius: 50%;
  background: var(--color-navy);
}

.chat-guest-avatar .chat-avatar-letter {
  font-size: 2.2rem;
}

.chat-guest-title {
  margin: 4px 0 0;
  font-family: var(--font-display);
  font-size: 1.3rem;
  font-weight: 700;
  color: var(--color-ink);
}

.chat-guest-text {
  margin: 0;
  color: var(--color-muted);
  font-size: 0.9rem;
}

.chat-button-primary {
  display: inline-block;
  min-width: 160px;
  padding: 10px 20px;
  border-radius: 8px;
  background: var(--color-brass);
  color: #fff;
  font-weight: 600;
  text-decoration: none;
}

.chat-button-primary:hover,
.chat-button-primary:focus-visible {
  background: #9e7432;
  color: #fff;
}

.chat-guest-link {
  color: var(--color-navy);
  font-size: 0.85rem;
  text-decoration: none;
  transition: color 140ms ease;
}

/* Rê chuột hoặc chọn bằng bàn phím (Tab): chữ đổi sang màu đồng đậm và gạch chân, để biết đây là link bấm được. */
.chat-guest-link:hover,
.chat-guest-link:focus-visible {
  color: #9e7432;
  text-decoration: underline;
  text-underline-offset: 3px;
}

/* ---------- Điện thoại: panel chiếm toàn màn hình ---------- */
@media (max-width: 575.98px) {
  .chat-assistant {
    right: 16px;
    bottom: 16px;
  }

  .chat-panel {
    position: fixed;
    inset: 0;
    width: auto;
    height: auto;
    border: 0;
    border-radius: 0;
    padding-bottom: env(safe-area-inset-bottom, 0px);
  }

  .chat-header {
    padding-top: calc(14px + env(safe-area-inset-top, 0px));
  }

  /* Nhãn trượt ra không hợp với màn hình cảm ứng (không có rê chuột). */
  .chat-launcher-pill {
    display: none;
  }
}

/* Người dùng bật "giảm chuyển động" trong hệ điều hành thì tắt các hiệu ứng lặp. */
@media (prefers-reduced-motion: reduce) {
  .chat-launcher-avatar::before,
  .chat-typing span {
    animation: none;
  }

  .chat-panel-enter-active,
  .chat-panel-leave-active,
  .chat-launcher-avatar,
  .chat-launcher-pill {
    transition: none;
  }
}
</style>
