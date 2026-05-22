<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { authApi } from '@/api/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const toast = useToast()
const router = useRouter()

// Backend min/max — PasswordHasher::MIN_LENGTH=12, MAX_LENGTH=128.
const MIN_LEN = 12
const MAX_LEN = 128

const current = ref('')
const next = ref('')
const confirm = ref('')
const showCurrent = ref(false)
const showNext = ref(false)
const busy = ref(false)
const error = ref('')

const nextLenOk = computed(() => next.value.length >= MIN_LEN && next.value.length <= MAX_LEN)
const matchOk = computed(() => next.value !== '' && next.value === confirm.value)
const canSubmit = computed(() =>
  current.value !== '' && nextLenOk.value && matchOk.value && !busy.value
)

async function submit() {
  if (!canSubmit.value) return
  error.value = ''
  busy.value = true
  try {
    await authApi.changePassword(current.value, next.value)
    toast.success(t('auth.password_changed'))
    current.value = ''
    next.value = ''
    confirm.value = ''
    // Po změně backend invalidoval ostatní sessions (kromě této). Stránka zůstává.
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('auth.password_change_failed'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-lg mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('auth.change_password_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('auth.change_password_subtitle') }}</p>
    </div>

    <form @submit.prevent="submit" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <!-- Aktuální heslo -->
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.current_password') }} *</label>
        <div class="flex gap-2">
          <input
            v-model="current"
            :type="showCurrent ? 'text' : 'password'"
            autocomplete="current-password"
            required
            class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          <button type="button" @click="showCurrent = !showCurrent"
                  class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
            {{ showCurrent ? '🙈' : '👁' }}
          </button>
        </div>
      </div>

      <!-- Nové heslo -->
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password') }} *</label>
        <div class="flex gap-2">
          <input
            v-model="next"
            :type="showNext ? 'text' : 'password'"
            autocomplete="new-password"
            :minlength="MIN_LEN"
            :maxlength="MAX_LEN"
            required
            class="flex-1 h-10 px-3 border rounded-md text-sm"
            :class="next === '' ? 'border-neutral-300' : nextLenOk ? 'border-success-500/40' : 'border-warning-500/40'" />
          <button type="button" @click="showNext = !showNext"
                  class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
            {{ showNext ? '🙈' : '👁' }}
          </button>
        </div>
        <p class="text-xs mt-1"
           :class="next === '' ? 'text-neutral-500' : nextLenOk ? 'text-success-600' : 'text-warning-600'">
          {{ t('auth.password_min_hint', { n: MIN_LEN }) }}
          <span v-if="next !== ''">— {{ next.length }}/{{ MAX_LEN }}</span>
        </p>
      </div>

      <!-- Potvrzení -->
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password_confirm') }} *</label>
        <input
          v-model="confirm"
          :type="showNext ? 'text' : 'password'"
          autocomplete="new-password"
          required
          class="w-full h-10 px-3 border rounded-md text-sm"
          :class="confirm === '' ? 'border-neutral-300' : matchOk ? 'border-success-500/40' : 'border-danger-500/40'" />
        <p v-if="confirm !== '' && !matchOk" class="text-xs text-danger-500 mt-1">
          {{ t('auth.password_mismatch') }}
        </p>
      </div>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ error }}
      </div>

      <div class="flex items-center justify-between pt-2 border-t border-neutral-100">
        <button type="button" @click="router.back()"
                class="cursor-pointer h-10 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
          {{ t('common.back') }}
        </button>
        <button type="submit" :disabled="!canSubmit"
                class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ busy ? t('common.saving') : t('auth.change_password_submit') }}
        </button>
      </div>

      <p class="text-xs text-neutral-500 pt-2">
        ℹ {{ t('auth.password_change_note') }}
      </p>
    </form>
  </div>
</template>
