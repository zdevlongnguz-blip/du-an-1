import CatalogView from "../views/products/catalog.vue"; // Import trang danh sách sản phẩm
import ProductDetailView from "../views/products/ProductDetail.vue";
import { createRouter, createWebHistory } from "vue-router";
import HomeView from "../views/HomeView.vue";
import TestView from "../views/TestView.vue";
import LoginView from "../views/auth/Login.vue";
import { isAuthenticated } from "@/helpers/auth/session";
import RegisterView from '../views/auth/Register.vue'
import ProfileView from '../views/auth/Profile.vue'
import ChangePasswordView from '../views/auth/ChangePassword.vue'
import ForgotPasswordView from '../views/auth/ForgotPassword.vue'
import ResetPasswordView from '../views/auth/ResetPassword.vue'

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

// Chuyển người chưa đăng nhập về trang Login khi vào route cần xác thực.
router.beforeEach((to) => {
  if (to.meta.requiresAuth && !isAuthenticated()) {
    return { name: "login" };
  }
});

export default router;
