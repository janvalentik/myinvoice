import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi, type User, type SetupStatus } from '@/api/auth'
import { useSupplierStore } from './supplier'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const csrfToken = ref<string>('')
  const setupStatus = ref<SetupStatus | null>(null)
  const loading = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const needsSetup = computed(() => setupStatus.value?.needs_setup === true)
  const mustSetupTotp = computed(() => user.value?.must_setup_totp === true)

  async function fetchSetupStatus() {
    setupStatus.value = await authApi.setupStatus()
    return setupStatus.value
  }

  async function refresh() {
    try {
      const data = await authApi.me()
      user.value = data.user
      csrfToken.value = data.csrf_token
      useSupplierStore().setAvailable(data.suppliers || [], data.current_supplier_id || 0)
      return true
    } catch {
      user.value = null
      csrfToken.value = ''
      return false
    }
  }

  async function login(
    email: string,
    password: string,
    captchaToken?: string,
    totp?: string,
    opts?: { emailOtp?: string; rememberDevice?: boolean; resendOtp?: boolean },
  ) {
    loading.value = true
    try {
      const data = await authApi.login({
        email,
        password,
        totp: totp || undefined,
        email_otp: opts?.emailOtp || undefined,
        remember_device: opts?.rememberDevice || undefined,
        resend_otp: opts?.resendOtp || undefined,
        cf_turnstile_response: captchaToken,
      })
      user.value = data.user
      csrfToken.value = data.csrf_token
      // Po loginu načti suppliery (login response je nemá, /me je vrátí)
      await refresh()
      return data.user
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await authApi.logout()
    } finally {
      user.value = null
      csrfToken.value = ''
    }
  }

  return {
    user,
    csrfToken,
    setupStatus,
    loading,
    isAuthenticated,
    needsSetup,
    mustSetupTotp,
    fetchSetupStatus,
    refresh,
    login,
    logout,
  }
})
