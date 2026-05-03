<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { projectsApi, type Project } from '@/api/projects'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const route = useRoute()
const router = useRouter()

const project = ref<Project | null>(null)
const loading = ref(true)

const canDelete = computed(() => (project.value?.invoices_count ?? 0) === 0)

async function load() {
  loading.value = true
  project.value = await projectsApi.get(Number(route.params.id))
  loading.value = false
}

onMounted(load)

async function archive() {
  if (!project.value) return
  if (!confirm(t('project.archive_confirm'))) return
  await projectsApi.archive(project.value.id)
  router.push(`/clients/${project.value.client_id}`)
}

async function deleteProject() {
  if (!project.value) return
  if (!confirm(t('project.delete_warning', { name: project.value.name }))) return
  try {
    await projectsApi.delete(project.value.id)
    router.push(`/clients/${project.value.client_id}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('project.delete_failed'))
  }
}
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else-if="project" class="space-y-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <RouterLink :to="`/clients/${project.client_id}`" class="text-sm text-neutral-600 hover:text-neutral-900">
          ← {{ project.client_company_name }}
        </RouterLink>
        <h1 class="text-2xl font-semibold mt-1">{{ project.name }}</h1>
        <div class="text-sm text-neutral-500 mt-1 flex items-center gap-2 flex-wrap">
          <span class="text-xs px-2 py-0.5 rounded"
            :class="{
              'bg-emerald-50 text-emerald-700': project.status === 'active',
              'bg-amber-50 text-amber-700': project.status === 'paused',
              'bg-neutral-100 text-neutral-600': project.status === 'closed',
            }">{{ project.status }}</span>
          <span v-if="project.project_number" class="font-mono text-xs">{{ project.project_number }}</span>
          <span v-if="project.requires_work_report_approval"
            class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700"
            :title="t('project.requires_approval_hint')">
            ✓ {{ t('project.requires_approval_badge') }}
          </span>
        </div>
      </div>
      <div class="flex flex-wrap gap-2 justify-end">
        <RouterLink v-if="project.status === 'active'"
          :to="`/invoices/new?client_id=${project.client_id}&project_id=${project.id}`"
          class="cursor-pointer px-3 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('project.new_invoice') }}
        </RouterLink>
        <RouterLink :to="`/projects/${project.id}/edit`"
          class="cursor-pointer px-3 h-9 text-sm border border-primary-500/40 rounded-md text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          {{ t('project.edit_project') }}
        </RouterLink>
        <RouterLink :to="`/clients/${project.client_id}/edit`"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z"/></svg>
          {{ t('project.edit_client') }}
        </RouterLink>
        <button @click="archive"
          class="cursor-pointer px-3 h-9 text-sm border border-warning-500/50 rounded-md text-warning-600 hover:bg-warning-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 1 1 0-4h14a2 2 0 1 1 0 4M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8m-9 4h4"/></svg>
          {{ t('common.archive') }}
        </button>
        <button v-if="canDelete" @click="deleteProject"
          class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 rounded-md text-danger-500 hover:bg-danger-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
          {{ t('common.delete') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.rates_due_section') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.hourly_rate') }}</dt><dd class="font-mono">{{ project.hourly_rate.toLocaleString('cs') }} {{ project.currency }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.due_short') }}</dt><dd>{{ project.payment_due_days }} {{ t('common.days') }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.currency') }}</dt><dd class="font-mono">{{ project.currency }}</dd></div>
        </dl>
      </div>

      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('common.budgets') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.budget_total_short') }}</dt><dd class="font-mono">{{ project.budget_total?.toLocaleString('cs') ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.yearly') }}</dt><dd class="font-mono">{{ project.budget_yearly?.toLocaleString('cs') ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.monthly') }}</dt><dd class="font-mono">{{ project.budget_monthly?.toLocaleString('cs') ?? '—' }}</dd></div>
        </dl>
      </div>

      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.reference_section') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.project_number') }}</dt><dd class="font-mono">{{ project.project_number || '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.contract_number') }}</dt><dd class="font-mono">{{ project.contract_number || '—' }}</dd></div>
        </dl>
      </div>
    </div>

    <div v-if="project.billing_emails.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.billing_emails') }}</h3>
      <ul class="space-y-1.5 text-sm">
        <li v-for="b in project.billing_emails" :key="b.position" class="flex items-center justify-between border-b border-neutral-100 pb-1.5 last:border-b-0">
          <span class="text-neutral-900">{{ b.email }}</span>
          <span class="text-xs text-neutral-500">{{ b.label || '—' }}</span>
        </li>
      </ul>
      <p class="text-xs text-neutral-400 mt-2">{{ t('project.client_main_email_note', { email: project.client_main_email }) }}</p>
    </div>

    <div v-if="project.note" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('project.note') }}</h3>
      <p class="text-sm text-neutral-700 whitespace-pre-wrap">{{ project.note }}</p>
    </div>
  </div>
</template>
