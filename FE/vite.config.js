import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [vue()],
    // Đường dẫn gốc của FE, lấy từ VITE_BASE_PATH trong file .env.<mode>.
    base: env.VITE_BASE_PATH || '/',
    // Cho phép dùng @/ thay cho đường dẫn tương đối đến thư mục src.
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url))
      }
    },
    // Cấu hình máy chủ phát triển của Vite.
    server: {
      host: 'localhost',
      port: 5173,
      // Chuyển request /api từ FE sang backend để trình duyệt gọi cùng origin.
      proxy: {
        '/api': {
          target: env.VITE_API_PROXY_TARGET || 'http://localhost/du-an-1/BE',
          changeOrigin: true,
          secure: false,
          // Bỏ /api ở đầu đường dẫn trước khi gửi request tới BE.
          rewrite: (path) => path.replace(/^\/api/, '')
        }
      }
    },
    build: {
      // Đặt bản build vào thư mục public của BE để BE phục vụ giao diện.
      outDir: '../BE/public/dist',
      // Xóa bản build cũ trong outDir trước khi tạo bản mới.
      emptyOutDir: true,
      sourcemap: false,
      // Tách file build theo loại để dễ phân phối và lưu cache.
      rollupOptions: {
        output: {
          entryFileNames: 'js/[name]-[hash].js',
          chunkFileNames: 'js/[name]-[hash].js',
          assetFileNames: (assetInfo) => {
            const name = assetInfo.name || ''
            const ext = name.split('.').pop()

            if (/\.(png|jpe?g|gif|svg|webp|avif)$/.test(name)) {
              return 'assets/images/[name]-[hash].[ext]'
            }

            if (ext === 'css') {
              return 'assets/css/[name]-[hash].[ext]'
            }

            return 'assets/[name]-[hash].[ext]'
          }
        }
      }
    }
  }
})

