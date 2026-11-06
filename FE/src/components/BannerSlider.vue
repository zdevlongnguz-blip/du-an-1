<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'

// Mỗi banner đứng ít nhất 7 giây để người dùng kịp đọc nội dung.
const AUTOPLAY_DELAY = 7000

const banners = ref([])
const loading = ref(true)
const error = ref('')
const currentIndex = ref(0)
const failedImages = ref(new Set())

// Người dùng có thể tắt/bật tự chuyển; hover hoặc focus chỉ tạm dừng.
const autoplayEnabled = ref(true)
const isHovered = ref(false)
const hasFocus = ref(false)
const rootEl = ref(null)

let timerId = null
let motionQuery = null

const hasMultiple = computed(() => banners.value.length > 1)
const currentBanner = computed(() => banners.value[currentIndex.value])
const isAutoplaying = computed(
  () => autoplayEnabled.value && hasMultiple.value && !isHovered.value && !hasFocus.value
)

const fetchBanners = async () => {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('?action=api-banners')
    banners.value = Array.isArray(data?.banners) ? data.banners : []
    currentIndex.value = 0
    failedImages.value = new Set()
  } catch (err) {
    error.value = 'Không thể tải banner.'
  } finally {
    loading.value = false
  }
}

const showSlide = (index) => {
  const total = banners.value.length
  if (total === 0) return
  currentIndex.value = (index + total) % total
}

const stopTimer = () => {
  clearInterval(timerId)
  timerId = null
}

// Luôn chạy lại từ đầu chu kỳ để slide mới không bị chuyển ngay sau tương tác.
const startTimer = () => {
  stopTimer()
  if (isAutoplaying.value) {
    timerId = setInterval(() => showSlide(currentIndex.value + 1), AUTOPLAY_DELAY)
  }
}

const goTo = (index) => {
  showSlide(index)
  startTimer()
}

const prev = () => goTo(currentIndex.value - 1)
const next = () => goTo(currentIndex.value + 1)

const toggleAutoplay = () => {
  autoplayEnabled.value = !autoplayEnabled.value
}

// focusout cũng xảy ra khi focus chuyển giữa các nút bên trong slideshow.
const onFocusOut = (event) => {
  if (!rootEl.value?.contains(event.relatedTarget)) {
    hasFocus.value = false
  }
}

watch(isAutoplaying, startTimer)

// Chỉ link nội bộ dạng "/..." mới đi qua Vue Router; "//host" là link ra ngoài.
const isInternalLink = (link) =>
  typeof link === 'string' && link.startsWith('/') && !link.startsWith('//')

const isExternalLink = (link) => typeof link === 'string' && /^https?:\/\//i.test(link)

const imageUrl = (image) => {
  if (/^(https?:)?\/\//i.test(image) || image.startsWith('/')) {
    return image
  }
  return `${api.defaults.baseURL}/assets/uploads/${image}`
}

const hasImage = (index) => Boolean(banners.value[index]?.image) && !failedImages.value.has(index)

const markImageFailed = (index) => {
  failedImages.value.add(index)
}

const applyMotionPreference = (event) => {
  autoplayEnabled.value = !event.matches
}

onMounted(() => {
  motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
  applyMotionPreference(motionQuery)
  motionQuery.addEventListener('change', applyMotionPreference)
  fetchBanners()
})

onBeforeUnmount(() => {
  stopTimer()
  motionQuery?.removeEventListener('change', applyMotionPreference)
})
</script>

<template>
  <div v-if="loading" class="banner-slider banner-placeholder placeholder-glow mb-4" role="status">
    <span class="placeholder"></span>
    <span class="visually-hidden">Đang tải banner...</span>
  </div>

  <div
    v-else-if="error"
    class="alert alert-warning d-flex align-items-center justify-content-between gap-3 mb-4"
    role="alert"
  >
    <span>{{ error }}</span>
    <button type="button" class="btn btn-sm btn-outline-dark" @click="fetchBanners">Thử lại</button>
  </div>

  <section
    v-else-if="currentBanner"
    ref="rootEl"
    class="banner-slider mb-4"
    aria-roledescription="carousel"
    aria-label="Banner nổi bật"
    @mouseenter="isHovered = true"
    @mouseleave="isHovered = false"
    @focusin="hasFocus = true"
    @focusout="onFocusOut"
  >
    <!-- Chỉ thông báo khi người dùng tự chuyển, tránh đọc liên tục lúc tự chạy. -->
    <p class="visually-hidden" :aria-live="isAutoplaying ? 'off' : 'polite'" aria-atomic="true">
      Banner {{ currentIndex + 1 }} / {{ banners.length }}: {{ currentBanner.title }}
    </p>

    <Transition name="banner-fade" mode="out-in">
      <article
        :key="currentIndex"
        class="banner-slide"
        role="group"
        aria-roledescription="slide"
        :aria-label="`${currentIndex + 1} / ${banners.length}`"
      >
        <img
          v-if="hasImage(currentIndex)"
          :src="imageUrl(currentBanner.image)"
          alt=""
          class="banner-image"
          @error="markImageFailed(currentIndex)"
        />

        <div class="banner-content" :class="{ 'has-controls': hasMultiple }">
          <p class="banner-title h3 fw-bold mb-2">{{ currentBanner.title }}</p>
          <p v-if="currentBanner.content" class="banner-text mb-3">{{ currentBanner.content }}</p>

          <RouterLink
            v-if="isInternalLink(currentBanner.CTA_link)"
            :to="currentBanner.CTA_link"
            class="btn btn-warning fw-semibold"
          >
            Khám phá ngay<span class="visually-hidden">: {{ currentBanner.title }}</span>
          </RouterLink>
          <a
            v-else-if="isExternalLink(currentBanner.CTA_link)"
            :href="currentBanner.CTA_link"
            class="btn btn-warning fw-semibold"
          >
            Khám phá ngay<span class="visually-hidden">: {{ currentBanner.title }}</span>
          </a>
        </div>
      </article>
    </Transition>

    <template v-if="hasMultiple">
      <button
        type="button"
        class="banner-control banner-control-prev"
        aria-label="Banner trước"
        @click="prev"
      >
        <span aria-hidden="true">&lsaquo;</span>
      </button>
      <button
        type="button"
        class="banner-control banner-control-next"
        aria-label="Banner sau"
        @click="next"
      >
        <span aria-hidden="true">&rsaquo;</span>
      </button>

      <div class="banner-footer">
        <button
          type="button"
          class="btn btn-sm btn-dark banner-toggle"
          :aria-pressed="!autoplayEnabled"
          @click="toggleAutoplay"
        >
          {{ autoplayEnabled ? 'Tạm dừng' : 'Tự chuyển' }}
        </button>

        <div class="banner-indicators">
          <button
            v-for="(banner, index) in banners"
            :key="banner.id ?? index"
            type="button"
            class="banner-dot"
            :class="{ active: index === currentIndex }"
            :aria-label="`Chuyển tới banner ${index + 1}: ${banner.title}`"
            :aria-current="index === currentIndex ? 'true' : undefined"
            @click="goTo(index)"
          ></button>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.banner-slider {
  position: relative;
  min-height: 240px;
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 1rem;
  background: linear-gradient(135deg, #1f2937 0%, #111827 55%, #3d3210 100%);
}

.banner-placeholder .placeholder {
  display: block;
  width: 100%;
  min-height: inherit;
  background-color: rgba(255, 255, 255, 0.08);
}

.banner-slide {
  position: relative;
  display: flex;
  align-items: flex-end;
  min-height: inherit;
}

.banner-slide::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.75), rgba(0, 0, 0, 0.1));
}

.banner-image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.banner-content {
  position: relative;
  z-index: 1;
  max-width: 40rem;
  padding: 1.5rem 1.5rem 2rem;
}

.banner-content.has-controls {
  padding: 1.5rem 3.75rem 4rem;
}

.banner-text {
  color: rgba(255, 255, 255, 0.85);
}

.banner-control {
  position: absolute;
  top: 50%;
  z-index: 2;
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  border: 0;
  border-radius: 50%;
  color: #fff;
  font-size: 1.75rem;
  line-height: 1;
  background: rgba(0, 0, 0, 0.5);
  transform: translateY(-50%);
}

.banner-control:hover {
  background: rgba(0, 0, 0, 0.75);
}

.banner-control-prev {
  left: 0.5rem;
}

.banner-control-next {
  right: 0.5rem;
}

.banner-footer {
  position: absolute;
  bottom: 0.75rem;
  left: 50%;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  transform: translateX(-50%);
}

.banner-indicators {
  display: flex;
  gap: 0.25rem;
}

.banner-dot {
  display: grid;
  place-items: center;
  width: 1.5rem;
  height: 1.5rem;
  padding: 0;
  border: 0;
  background: transparent;
}

.banner-dot::before {
  content: '';
  width: 0.625rem;
  height: 0.625rem;
  border: 1px solid #fff;
  border-radius: 50%;
}

.banner-dot.active::before {
  background: #ffc107;
  border-color: #ffc107;
}

.banner-slider button:focus-visible,
.banner-slider a:focus-visible {
  outline: 2px solid #ffc107;
  outline-offset: 2px;
}

.banner-fade-enter-active,
.banner-fade-leave-active {
  transition: opacity 0.4s ease;
}

.banner-fade-enter-from,
.banner-fade-leave-to {
  opacity: 0;
}

@media (min-width: 768px) {
  .banner-slider {
    min-height: 320px;
  }

  .banner-content {
    padding: 2.5rem 2.5rem 2.5rem;
  }

  .banner-content.has-controls {
    padding: 2.5rem 4.5rem 4rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .banner-fade-enter-active,
  .banner-fade-leave-active {
    transition: none;
  }

  .banner-placeholder .placeholder {
    animation: none;
  }
}
</style>
