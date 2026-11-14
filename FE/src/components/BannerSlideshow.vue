<script setup>
import Carousel from 'bootstrap/js/dist/carousel'
import { onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { resolveImageUrl } from '@/helpers/media/imageUrl'

// Carousel trang chủ: Bootstrap xử lý tự chuyển, swipe và nút điều khiển.
const props = defineProps({
  banners: {
    type: Array,
    required: true,
  },
})

// Phần tử gốc để Bootstrap gắn sự kiện và quản lý trạng thái carousel.
const carouselElement = ref(null)
// Chỉ số banner đang hiển thị, được đồng bộ từ sự kiện slid.bs.carousel.
const activeIndex = ref(0)
let carouselInstance = null

// Đồng bộ chỉ số đang hoạt động sau khi Bootstrap hoàn thành chuyển động.
function updateActiveSlide(event) {
  activeIndex.value = event.to
}

// Khởi tạo Bootstrap Carousel khi có nhiều banner; interval là 5 giây.
onMounted(() => {
  if (!carouselElement.value || props.banners.length < 2) return

  carouselInstance = new Carousel(carouselElement.value, {
    interval: 5000,
    keyboard: true,
    pause: 'hover',
    ride: 'carousel',
    touch: true,
    wrap: true,
  })
  carouselElement.value.addEventListener('slid.bs.carousel', updateActiveSlide)
})

// Gỡ sự kiện và giải phóng timer/listener Bootstrap khi rời trang chủ.
onUnmounted(() => {
  carouselElement.value?.removeEventListener('slid.bs.carousel', updateActiveSlide)
  carouselInstance?.dispose()
  carouselInstance = null
})
</script>

<template>
  <!-- Bootstrap quản lý autoplay, hover, swipe và chuyển slide. -->
  <section
    ref="carouselElement"
    class="carousel slide banner-carousel"
    aria-label="Banner nổi bật"
    aria-roledescription="carousel"
  >
    <!-- Mỗi banner là một carousel item; banner đầu tiên hiển thị khi tải xong. -->
    <div class="carousel-inner">
      <article
        v-for="(banner, index) in banners"
        :key="banner.id"
        class="carousel-item banner-slide"
        :class="{ active: index === activeIndex }"
        role="group"
        aria-roledescription="slide"
        :aria-label="`Banner ${index + 1} trên ${banners.length}`"
      >
        <img v-if="banner.image" class="banner-image" :src="resolveImageUrl(banner.image)" alt="" />
        <div class="banner-overlay" aria-hidden="true"></div>

        <div class="banner-content">
          <h2 class="banner-title">{{ banner.title }}</h2>
          <p class="banner-description">{{ banner.content }}</p>
          <RouterLink v-if="banner.CTA_link" class="banner-cta" :to="banner.CTA_link">
            Khám phá ngay
          </RouterLink>
        </div>
      </article>
    </div>

    <!-- Mỗi indicator là nút chọn trực tiếp một banner. -->
    <div v-if="banners.length > 1" class="banner-indicators" role="group" aria-label="Chọn banner">
      <button
        v-for="(banner, index) in banners"
        :key="banner.id"
        type="button"
        class="banner-indicator"
        :class="{ 'is-active': index === activeIndex }"
        :aria-label="`Tới banner ${index + 1}`"
        :aria-current="index === activeIndex ? 'true' : undefined"
        @click="carouselInstance?.to(index)"
      >
        <span aria-hidden="true"></span>
      </button>
    </div>

    <!-- Mũi tên được ẩn trên mobile như bản thiết kế Stitch. -->
    <div v-if="banners.length > 1" class="banner-navigation">
      <button type="button" class="banner-navigation-button" aria-label="Banner trước" @click="carouselInstance?.prev()">
        <span class="banner-arrow-icon banner-arrow-icon--previous" aria-hidden="true"></span>
      </button>
      <button type="button" class="banner-navigation-button" aria-label="Banner tiếp theo" @click="carouselInstance?.next()">
        <span class="banner-arrow-icon banner-arrow-icon--next" aria-hidden="true"></span>
      </button>
    </div>
  </section>
</template>

<style scoped>
.banner-carousel {
  position: relative;
  display: block;
  height: 240px;
  overflow: hidden;
  border-radius: 4px;
  background: #212529;
  color: #ffffff;
  isolation: isolate;
}

.carousel-inner {
  height: 100%;
}

.banner-slide {
  position: relative;
  height: 100%;
  align-items: center;
}

.banner-slide.active,
.banner-slide.carousel-item-next,
.banner-slide.carousel-item-prev {
  display: flex;
}

.banner-image,
.banner-overlay {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.banner-image {
  z-index: 0;
  object-fit: cover;
}

.banner-overlay {
  z-index: 1;
  background: rgba(0, 0, 0, 0.5);
}

.banner-content {
  position: relative;
  z-index: 2;
  max-width: 480px;
  padding: 0 16px;
}

.banner-title {
  margin: 0 0 12px;
  color: #ffffff;
  font-size: 20px;
  font-weight: 700;
  line-height: 1.25;
}

.banner-description {
  display: none;
  margin: 0 0 20px;
  color: #ffffff;
  font-size: 14px;
  line-height: 1.4;
}

.banner-cta {
  display: inline-flex;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  padding: 0 20px;
  border-radius: 4px;
  background: #e9c176;
  color: #4e3700;
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  transition: background-color 0.2s ease;
}

.banner-cta:hover {
  background: #f5ce72;
  color: #4e3700;
}

.banner-cta:focus-visible,
.banner-indicator:focus-visible,
.banner-navigation-button:focus-visible {
  outline: 3px solid #f2c14e;
  outline-offset: 3px;
}

.banner-indicators {
  position: absolute;
  z-index: 1;
  bottom: 12px;
  left: 16px;
  display: flex;
  gap: 8px;
}

.banner-indicator {
  display: grid;
  width: 32px;
  height: 24px;
  place-items: center;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
}

.banner-indicator span {
  width: 24px;
  height: 2px;
  background: rgba(255, 255, 255, 0.55);
}

.banner-indicator.is-active span {
  background: #e9c176;
}

.banner-navigation {
  position: absolute;
  z-index: 1;
  right: 16px;
  bottom: 12px;
  display: none;
  gap: 8px;
}

.banner-navigation-button {
  display: grid;
  width: 44px;
  height: 44px;
  place-items: center;
  padding: 0;
  border: 1px solid rgba(255, 255, 255, 0.55);
  border-radius: 3px;
  background: rgba(20, 22, 24, 0.56);
  color: #ffffff;
  cursor: pointer;
  transition: background-color 160ms ease, border-color 160ms ease, color 160ms ease, transform 160ms ease;
}

.banner-navigation-button:hover {
  transform: translateY(-1px);
  border-color: #e9c176;
  background: #e9c176;
  color: #4e3700;
}

.banner-arrow-icon {
  width: 9px;
  height: 9px;
  border-right: 1.5px solid currentColor;
  border-bottom: 1.5px solid currentColor;
}

.banner-arrow-icon--previous {
  transform: rotate(135deg);
}

.banner-arrow-icon--next {
  transform: rotate(-45deg);
}

@media (min-width: 768px) {
  .banner-carousel {
    height: 320px;
  }

  .banner-navigation {
    display: flex;
  }

  .banner-content {
    max-width: 420px;
    padding: 0 24px;
  }

  .banner-title {
    font-size: 26px;
  }

  .banner-description {
    display: block;
    font-size: 13px;
  }

  .banner-indicators {
    left: 24px;
    bottom: 16px;
  }

  .banner-navigation {
    right: 24px;
    bottom: 16px;
  }
}

@media (min-width: 1200px) {
  .banner-carousel {
    height: 400px;
  }

  .banner-content {
    max-width: 480px;
    padding: 0 32px;
  }

  .banner-title {
    font-size: 32px;
  }

  .banner-description {
    font-size: 14px;
  }

  .banner-indicators {
    left: 32px;
  }
}

</style>