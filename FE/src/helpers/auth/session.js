const TOKEN_KEY = 'auth_token'
const USER_KEY = 'auth_user'

export function setAuthSession(token, user = null) {
  if (!token) {
    clearAuthSession()
    return
  }

  localStorage.setItem(TOKEN_KEY, token)
  localStorage.setItem(USER_KEY, JSON.stringify(user ?? {}))
}

export function getToken() {
  return localStorage.getItem(TOKEN_KEY) || ''
}

export function getUser() {
  try {
    const raw = localStorage.getItem(USER_KEY)
    return raw ? JSON.parse(raw) : null
  } catch (error) {
    console.error('Parse user error:', error)
    return null
  }
}

export function clearAuthSession() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
}

export function isAuthenticated() {
  return Boolean(getToken())
}

export default {
  setAuthSession,
  getToken,
  getUser,
  clearAuthSession,
  isAuthenticated,
}
