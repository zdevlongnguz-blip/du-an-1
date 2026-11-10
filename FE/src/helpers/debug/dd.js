// Biến dữ liệu thành JSON dễ đọc để hiển thị thô trong trang debug.
export function dd(value) {
  return JSON.stringify(value, null, 2) ?? String(value)
}