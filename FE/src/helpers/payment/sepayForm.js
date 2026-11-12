// Gửi các trường checkout đã ký từ backend bằng form POST trực tiếp sang SePay.
export function submitSePayCheckout(checkout = {}) {
  if (typeof checkout.action !== 'string'
    || !checkout.fields
    || typeof checkout.fields !== 'object'
    || Object.keys(checkout.fields).length === 0) {
    throw new Error('Dữ liệu thanh toán SePay không hợp lệ.')
  }

  const actionUrl = new URL(checkout.action)
  if (actionUrl.origin !== 'https://pay-sandbox.sepay.vn') {
    throw new Error('Địa chỉ cổng thanh toán SePay không hợp lệ.')
  }

  // Form POST giúp trình duyệt chuyển sang cổng thanh toán mà không lộ secret key.
  const form = document.createElement('form')
  form.method = 'POST'
  form.action = actionUrl.toString()

  Object.entries(checkout.fields).forEach(([name, value]) => {
    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = String(value)
    form.appendChild(input)
  })

  document.body.appendChild(form)
  form.submit()
}
