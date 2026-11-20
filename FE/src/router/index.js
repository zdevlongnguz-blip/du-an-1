import CatalogView from "../views/products/catalog.vue"; // Import trang danh sách sản phẩm
import ProductDetailView from "../views/products/ProductDetail.vue";
import { createRouter, createWebHistory } from "vue-router";
import HomeView from "../views/HomeView.vue";
import TestView from "../views/TestView.vue";
import LoginView from "../views/auth/Login.vue";
import { getToken, getUser, isAuthenticated } from "@/helpers/auth/session";
import { showToast } from "@/helpers/ui/toast";
import api from "@/helpers/api/client";
import RegisterView from '../views/auth/Register.vue'
import ProfileView from '../views/auth/Profile.vue'
import ChangePasswordView from '../views/auth/ChangePassword.vue'
import ForgotPasswordView from '../views/auth/ForgotPassword.vue'
import ResetPasswordView from '../views/auth/ResetPassword.vue'
import OrderListView from '../views/orders/OrderList.vue'
import OrderDetailView from '../views/orders/OrderDetail.vue'
import PaymentResultView from '../views/payment/PaymentResult.vue'
import CheckoutView from '../views/payment/CheckoutView.vue'
import CartView from '../views/cart/CartView.vue'
import FuleSingView from '../views/ai/FuleSing.vue'
import AiCheckoutView from '../views/ai/AiCheckout.vue'

const routes = [
  {
    path: "/",
    name: "home",
    component: HomeView,
  },
  {
    path: "/test",
    name: "test",
    component: TestView,
  },
  {
    path: "/login",
    name: "login",
    component: LoginView,
  },

  // Route trang danh sách sản phẩm
  {
    path: "/catalog", // Đường dẫn URL để truy cập trang Catalog
    name: "catalog", // Tên định danh của route
    component: CatalogView, // Component giao diện được hiển thị khi truy cập
  },
  // Trang chi tiết sản phẩm hiển thị response sản phẩm và comments, không yêu cầu đăng nhập.
  {
    path: "/product-detail/:slug",
    name: "product-detail",
    component: ProductDetailView,
  },

  {
    path: "/profile",
    name: "profile",
    component: ProfileView,
    // Đánh dấu route này chỉ dành cho người dùng đã đăng nhập.
    meta: { requiresAuth: true }
  }, 

  {
    path: "/change-password",
    name: "change-password",
    component: ChangePasswordView,
    meta: { requiresAuth: true }
  },

  {
    path: "/register",
    name: "register",
    component: RegisterView,
  },

  {
    path: "/forgot-password",
    name: "forgot-password",
    component: ForgotPasswordView,
  },

  {
    path: "/reset-password",
    name: "reset-password",
    component: ResetPasswordView,
  },

  // Danh sách đơn hàng của user đã đăng nhập (xem, thanh toán lại, hủy đơn).
  {
    path: "/orders",
    name: "orders",
    component: OrderListView,
    meta: { requiresAuth: true },
  },
  // Chi tiết đơn / hóa đơn của một đơn; BE chỉ trả đơn thuộc user đang đăng nhập.
  {
    path: "/orders/:id(\\d+)",
    name: "order-detail",
    component: OrderDetailView,
    meta: { requiresAuth: true },
  },
  // Đường dẫn cũ /payment (trang thanh toán trước đây) chuyển về danh sách đơn để link cũ vẫn dùng được.
  // Lưu ý: /payment/success|error|cancel bên dưới là URL SePay quay về, giữ nguyên.
  {
    path: "/payment",
    redirect: { name: "orders" },
  },
  // Trang giỏ hàng để user xem, đổi số lượng và xóa từng phiên bản sản phẩm.
  {
    path: "/cart",
    name: "cart",
    component: CartView,
    meta: { requiresAuth: true },
  },
  // Checkout đọc giỏ hàng của user và cho chọn COD hoặc thanh toán online qua SePay.
  {
    path: "/checkout",
    name: "checkout",
    component: CheckoutView,
    meta: { requiresAuth: true },
  },
  // Các trang dưới đây chỉ hiển thị kết quả điều hướng, không xác nhận giao dịch.
  {
    path: "/payment/success",
    name: "payment-success",
    component: PaymentResultView,
    meta: { paymentResult: "success" },
  },
  {
    path: "/payment/error",
    name: "payment-error",
    component: PaymentResultView,
    meta: { paymentResult: "error" },
  },
  {
    path: "/payment/cancel",
    name: "payment-cancel",
    component: PaymentResultView,
    meta: { paymentResult: "cancel" },
  },

  // Sân khấu giải trí: Louis Dé Fule hát tặng một bài (mở từ khung chat, query song + video).
  // Không yêu cầu đăng nhập vì chỉ phát video công khai.
  {
    path: "/fule-sing",
    name: "fule-sing",
    component: FuleSingView,
  },
  // Trang "Lên đơn nhanh cho quý ông": đơn nháp do Louis Dé Fule (AI) lên, khách chỉnh số lượng/người nhận rồi xác nhận.
  // Cần đăng nhập vì hiển thị thông tin giao hàng và gọi API tạo đơn. Đơn nháp nằm trong bộ nhớ (helpers/ai/checkoutDraft.js).
  {
    path: "/ai-checkout",
    name: "ai-checkout",
    component: AiCheckoutView,
    meta: { requiresAuth: true },
  },

  // Khu quản trị: cần đăng nhập VÀ role admin (requiresAdmin, kiểm tra trong beforeEach bên dưới).
  // meta.layout = 'admin': App.vue ẩn header/footer/khung chat của shop, AdminLayout vẽ menu quản trị.
  // Các trang admin là route CON (children): hiện bên trong <RouterView /> của AdminLayout và thừa hưởng meta của route cha.
  // Thêm trang admin mới: thêm một phần tử vào children + một mục menu trong components/admin/AdminSidebar.vue.
  // import động (() => import(...)): code admin tách thành file riêng, khách xem shop không phải tải về.
  {
    path: "/admin",
    component: () => import("../components/admin/AdminLayout.vue"),
    meta: { requiresAuth: true, requiresAdmin: true, layout: "admin" },
    children: [
      // Trang Tổng quan (/admin).
      {
        path: "",
        name: "admin-dashboard",
        component: () => import("../views/admin/AdminDashboard.vue"),
      },
    ],
  },

  {
    path: "/:pathMatch(.*)*",
    name: "not-found",
    component: () => import("../views/NotFound.vue"),
  }

];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
});

// Token đã được BE xác nhận là admin trong lần tải trang này (rỗng = chưa xác nhận).
// Lưu theo token: đăng xuất / đăng nhập tài khoản khác thì token đổi, lần vào /admin sau sẽ hỏi lại BE.
// Chỉ hỏi 1 lần mỗi lần tải trang vì mọi API admin vẫn tự kiểm tra quyền ở BE trong từng request.
let verifiedAdminToken = "";

// Hỏi BE người đang đăng nhập có phải admin không: GET api-admin-me (BE dùng AdminMiddleware,
// cùng điều kiện với mọi API admin: token hợp lệ, khớp DB, role = admin).
// Trả true nếu là admin (HTTP 200). Lỗi thì ném ra để beforeEach quyết định:
// 403 = không phải admin; 401 = client.js đã tự xóa phiên và chuyển về /login.
async function confirmAdminWithServer() {
  const token = getToken();
  if (token && token === verifiedAdminToken) return true;
  const response = await api.get("/?action=api-admin-me");
  const ok = response.data?.status === "success" && response.data?.user?.role === "admin";
  verifiedAdminToken = ok ? token : "";
  return ok;
}

// Chuyển người chưa đăng nhập về trang Login khi vào route cần xác thực.
// Route cần quyền admin (khu /admin): hỏi BE qua api-admin-me trước khi cho vào.
// async: beforeEach chờ BE trả lời rồi mới chuyển trang, nên không có lúc nào hiện khung admin cho người không có quyền.
// Đây là chặn ở giao diện cho trải nghiệm tốt; quyền thật vẫn do BE kiểm tra ở từng API admin (401/403).
router.beforeEach(async (to) => {
  if (to.meta.requiresAuth && !isAuthenticated()) {
    return { name: "login" };
  }
  if (to.meta.requiresAdmin) {
    // Đọc nhanh role trong auth_user: chắc chắn không phải admin thì khỏi gọi BE.
    if (getUser()?.role !== "admin") {
      showToast("Tài khoản của bạn không có quyền vào trang quản trị.", "warning");
      return { name: "home" };
    }
    try {
      if (!(await confirmAdminWithServer())) {
        showToast("Tài khoản của bạn không có quyền vào trang quản trị.", "warning");
        return { name: "home" };
      }
    } catch (error) {
      const status = error.response?.status;
      // 401: client.js đã xóa phiên và chuyển về /login, ở đây chỉ dừng điều hướng.
      if (status === 401) return { name: "login" };
      showToast(
        status === 403
          ? "Tài khoản của bạn không có quyền vào trang quản trị."
          : "Chưa kiểm tra được quyền quản trị, vui lòng thử lại.",
        "warning"
      );
      return { name: "home" };
    }
  }
});

export default router;
