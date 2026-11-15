<script setup>
// Trang sân khấu giải trí: Louis Dé Fule hát tặng một bài. Trang được mở từ khung chat (tool fule_sing ở BE)
// với query ?song=<tên bài>&video=<tên file>. Không gọi API; video lấy từ BE/assets/fule/songs/.
// Trình duyệt chặn tự phát video có tiếng, nên người dùng phải bấm "Nghe Louis hát" thì video mới phát.
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { resolveSongVideoUrl } from '@/helpers/media/videoUrl'
import { resolveSystemImageUrl } from '@/helpers/media/imageUrl'
import { openChat } from '@/helpers/ai/chatAssistant'

const route = useRoute()

// computed: tên bài lấy từ URL, tự cập nhật khi Louis hát bài khác lúc đang ở trang này.
const songTitle = computed(() => (typeof route.query.song === 'string' ? route.query.song.trim() : ''))
// computed: URL video. Rỗng nếu thiếu hoặc sai tên file (helper đã kiểm tra định dạng).
const videoUrl = computed(() => resolveSongVideoUrl(route.query.video))
// true khi có đủ tên bài và video hợp lệ để hiện sân khấu.
const hasSong = computed(() => songTitle.value !== '' && videoUrl.value !== '')

// Ảnh đại diện của Louis, hiện trên màn chờ trước khi phát.
const avatarUrl = resolveSystemImageUrl('fule.webp')

// Thẻ <video> trên trang, dùng để gọi play() khi người dùng bấm nút.
const videoEl = ref(null)
// true sau lần bấm phát đầu tiên: ẩn màn chờ, hiện thanh điều khiển của video.
const started = ref(false)
// true khi video đang phát, dùng để chạy hiệu ứng sóng nhạc.
const isPlaying = ref(false)
// true khi không tải được video (thường do file chưa được tải lên server).
const loadFailed = ref(false)
// Thông báo khi trình duyệt từ chối phát (hiếm gặp), rỗng = không có lỗi.
const playError = ref('')

// Bấm "Nghe Louis hát": phát video. play() trả về Promise, có thể bị trình duyệt từ chối.
async function handlePlay() {
  if (!videoEl.value) return
  playError.value = ''
  try {
    await videoEl.value.play()
    started.value = true
  } catch {
    playError.value = 'Trình duyệt chưa cho phát video. Quý ông bấm lại nút phát giúp Louis nhé.'
  }
}

// watch: khi đổi sang bài khác (query đổi), đưa sân khấu về trạng thái chờ ban đầu.
watch(() => route.query.video, () => {
  started.value = false
  isPlaying.value = false
  loadFailed.value = false
  playError.value = ''
})
</script>

<template>
  <section class="fule-stage">
    <div class="fule-stage-inner">
      <!-- Tiêu đề sân khấu -->
      <p class="fule-eyebrow">Sân khấu Fule · Live</p>
      <h1 class="fule-title">Louis Dé Fule hát tặng quý ông</h1>

      <!-- Chưa chọn bài: mời mở khung chat để nhờ Louis hát -->
      <div v-if="!hasSong" class="fule-empty">
        <img :src="avatarUrl" alt="" class="fule-empty-avatar" />
        <p class="fule-empty-text">Louis đang chờ quý ông chọn bài. Mở khung chat và nói “Louis hát một bài đi” nhé!</p>
        <button type="button" class="fule-button" @click="openChat">Mở khung chat</button>
      </div>

      <template v-else>
        <p class="fule-song">
          <span class="fule-song-label">Bài hát</span>
          <span class="fule-song-name">“{{ songTitle }}”</span>
        </p>

        <!-- Khu phát nhạc: desktop đĩa bên trái, video bên phải; mobile video ở trên, đĩa ở dưới -->
        <div class="fule-player">
          <!-- Đĩa nhạc có nhãn là ảnh anh Fule, xoay đều liên tục -->
          <div class="fule-disc-wrap" aria-hidden="true">
            <div class="fule-disc">
              <img :src="avatarUrl" alt="" class="fule-disc-label" />
              <span class="fule-disc-hole"></span>
            </div>
            <!-- Vệt bóng sáng đứng yên phủ lên đĩa, nhờ vậy nhìn rõ đĩa đang quay -->
            <span class="fule-disc-shine"></span>
          </div>

          <!-- Video chưa có trên server: báo thân thiện thay vì để khung đen -->
          <div v-if="loadFailed" class="fule-empty fule-empty--slot" role="alert">
            <p class="fule-empty-text">Louis vẫn đang tập dượt bài này, video chưa được tải lên. Quý ông quay lại sau nhé!</p>
            <RouterLink :to="{ name: 'catalog' }" class="fule-button">Dạo cửa hàng trong lúc chờ</RouterLink>
          </div>

          <!-- Khung video + màn chờ có nút phát -->
          <div v-else class="fule-frame" :class="{ 'fule-frame--playing': isPlaying }">
            <!-- preload="metadata": chỉ tải thông tin video, chưa tải cả file cho tới khi bấm phát -->
            <video
              ref="videoEl"
              class="fule-video"
              :src="videoUrl"
              :controls="started"
              preload="metadata"
              playsinline
              @play="isPlaying = true"
              @pause="isPlaying = false"
              @ended="isPlaying = false"
              @error="loadFailed = true"
            ></video>

            <div v-if="!started" class="fule-cover">
              <button type="button" class="fule-play" @click="handlePlay">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
                Nghe Louis hát
              </button>
              <p v-if="playError" class="fule-play-error" role="alert">{{ playError }}</p>
            </div>
          </div>
        </div>

        <!-- Sóng nhạc trang trí, chỉ nhảy khi video đang phát -->
        <div v-if="!loadFailed" class="fule-equalizer" :class="{ 'fule-equalizer--on': isPlaying }" aria-hidden="true">
          <span v-for="bar in 12" :key="bar" :style="{ animationDelay: `${(bar % 5) * 0.12}s` }"></span>
        </div>
      </template>

      <RouterLink :to="{ name: 'catalog' }" class="fule-back">← Quay lại cửa hàng</RouterLink>
    </div>
  </section>
</template>

<style scoped>
/* Nền navy kèm vệt sáng đồng ở trên, giống đèn rọi sân khấu. */
.fule-stage {
  min-height: calc(100vh - 160px);
  padding: 48px 16px 64px;
  background:
    radial-gradient(ellipse at 50% -10%, rgba(216, 176, 106, 0.35), transparent 55%),
    linear-gradient(180deg, var(--color-navy) 0%, var(--color-navy-2) 100%);
  color: #fff;
}

.fule-stage-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  max-width: 820px;
  margin: 0 auto;
  text-align: center;
}

.fule-eyebrow {
  margin: 0 0 8px;
  color: var(--color-brass-light);
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.fule-title {
  margin: 0 0 12px;
  font-family: var(--font-display);
  font-size: clamp(1.8rem, 4vw, 2.8rem);
  font-weight: 700;
  color: #fff;
}

.fule-song {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0 0 24px;
}

.fule-song-label {
  color: rgba(255, 255, 255, 0.6);
  font-size: 0.75rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.fule-song-name {
  font-family: var(--font-display);
  font-size: 1.5rem;
  color: var(--color-brass-light);
}

/* ---------- Bố cục đĩa + video ---------- */
/* Desktop: 2 cột, đĩa bên trái (nhỏ hơn), video bên phải. */
.fule-player {
  display: grid;
  grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
  align-items: center;
  gap: 40px;
  width: 100%;
  max-width: 820px;
}

/* ---------- Đĩa nhạc ---------- */
.fule-disc-wrap {
  position: relative;
  width: min(100%, 300px);
  aspect-ratio: 1;
  justify-self: center;
}

/* Đĩa than: các vòng rãnh tạo bằng repeating-radial-gradient (lặp lại vòng tròn sáng/tối xen kẽ). */
.fule-disc {
  position: relative;
  width: 100%;
  height: 100%;
  border: 3px solid var(--color-brass);
  border-radius: 50%;
  background:
    repeating-radial-gradient(circle at center, #10161e 0 2px, #1d2733 2px 4px);
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.5), inset 0 0 0 6px #0b121a;
  /* linear + infinite: xoay đều tốc độ, lặp mãi không dừng. */
  animation: fule-spin 8s linear infinite;
}

/* Nhãn giữa đĩa là ảnh anh Fule. */
.fule-disc-label {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 46%;
  height: 46%;
  border: 3px solid var(--color-brass-light);
  border-radius: 50%;
  object-fit: cover;
  transform: translate(-50%, -50%);
}

/* Lỗ tâm đĩa. */
.fule-disc-hole {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 6%;
  height: 6%;
  border: 2px solid var(--color-brass);
  border-radius: 50%;
  background: var(--color-navy);
  transform: translate(-50%, -50%);
}

/* Vệt bóng nằm ngoài .fule-disc nên không xoay theo, tạo cảm giác ánh đèn chiếu lên đĩa đang quay. */
.fule-disc-shine {
  position: absolute;
  inset: 3px;
  border-radius: 50%;
  background: conic-gradient(
    from 30deg,
    transparent 0deg,
    rgba(255, 255, 255, 0.1) 30deg,
    transparent 70deg,
    transparent 210deg,
    rgba(255, 255, 255, 0.07) 240deg,
    transparent 280deg
  );
  pointer-events: none;
}

@keyframes fule-spin {
  to { transform: rotate(360deg); }
}

/* ---------- Khung video ---------- */
.fule-frame {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  overflow: hidden;
  border: 2px solid var(--color-brass);
  border-radius: 8px;
  background: #0b121a;
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
  transition: box-shadow 300ms ease;
}

.fule-frame--playing {
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45), 0 0 40px rgba(216, 176, 106, 0.35);
}

.fule-video {
  display: block;
  width: 100%;
  height: 100%;
  background: #0b121a;
}

/* Màn chờ phủ lên video cho tới khi người dùng bấm phát. */
.fule-cover {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
  padding: 16px;
  background: radial-gradient(circle at center, rgba(28, 42, 58, 0.85), rgba(11, 18, 26, 0.95));
}

.fule-play {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 12px 26px;
  border: 0;
  border-radius: 999px;
  background: var(--color-brass);
  color: #fff;
  font-size: 1rem;
  font-weight: 600;
  box-shadow: 0 8px 20px rgba(184, 137, 62, 0.4);
  transition: transform 150ms ease, background-color 150ms ease;
}

.fule-play:hover,
.fule-play:focus-visible {
  background: #9e7432;
  transform: translateY(-2px);
}

.fule-play svg {
  width: 20px;
  height: 20px;
  fill: currentColor;
}

.fule-play-error {
  margin: 0;
  color: #ffb4ab;
  font-size: 0.85rem;
}

/* ---------- Sóng nhạc ---------- */
.fule-equalizer {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 5px;
  height: 36px;
  margin-top: 20px;
}

.fule-equalizer span {
  width: 6px;
  height: 6px;
  border-radius: 3px;
  background: var(--color-brass);
  opacity: 0.5;
}

.fule-equalizer--on span {
  opacity: 1;
  animation: fule-bar 0.9s ease-in-out infinite;
}

@keyframes fule-bar {
  0%, 100% { height: 6px; }
  50% { height: 34px; }
}

/* ---------- Trạng thái chờ / lỗi ---------- */
.fule-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  max-width: 460px;
  margin: 12px 0 8px;
  padding: 28px 24px;
  border: 1px solid rgba(216, 176, 106, 0.4);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.04);
}

/* Thẻ báo lỗi nằm đúng chỗ của khung video (cột phải trên desktop). */
.fule-empty--slot {
  max-width: none;
  margin: 0;
}

.fule-empty-avatar {
  width: 88px;
  height: 88px;
  border: 3px solid var(--color-brass);
  border-radius: 50%;
  object-fit: cover;
}

.fule-empty-text {
  margin: 0;
  color: rgba(255, 255, 255, 0.85);
}

.fule-button {
  display: inline-block;
  padding: 10px 22px;
  border: 0;
  border-radius: 8px;
  background: var(--color-brass);
  color: #fff;
  font-weight: 600;
  text-decoration: none;
}

.fule-button:hover,
.fule-button:focus-visible {
  background: #9e7432;
  color: #fff;
}

.fule-back {
  margin-top: 32px;
  color: rgba(255, 255, 255, 0.7);
  font-size: 0.9rem;
  text-decoration: none;
}

.fule-back:hover {
  color: var(--color-brass-light);
}

/* Mobile/tablet hẹp: xếp dọc, video ở trên, đĩa ở dưới.
   order: đổi thứ tự hiển thị mà không phải đổi thứ tự trong HTML. */
@media (max-width: 767.98px) {
  .fule-player {
    grid-template-columns: minmax(0, 1fr);
    gap: 28px;
  }

  .fule-frame,
  .fule-empty--slot {
    order: 1;
  }

  .fule-disc-wrap {
    order: 2;
    width: min(70%, 240px);
  }
}

/* Người dùng bật "giảm chuyển động" thì tắt các hiệu ứng lặp. */
@media (prefers-reduced-motion: reduce) {
  .fule-disc,
  .fule-equalizer--on span {
    animation: none;
  }
}
</style>
