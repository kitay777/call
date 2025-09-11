<script setup lang="ts">
import { computed } from 'vue'
import { usePage, router } from '@inertiajs/vue3'

const page = usePage()

// null セーフに取得
const me      = computed(() => (page.props.value as any)?.auth?.user ?? null)
const profile = computed(() => (page.props.value as any)?.profile ?? null)

const update = (state:'available'|'busy'|'break'|'off_today') => {
  router.post(route('operator.state'), { state }, {
    preserveScroll: true,
    onSuccess: () => router.reload({ only: ['profile'] }),
  })
}
</script>

<template>
  <main class="p-6 space-y-4">
    <header class="flex items-center justify-between">
      <div>
        <div class="text-xl font-semibold">{{ me?.name ?? '---' }}</div>
        <div class="text-sm text-slate-500">{{ me?.email ?? '' }}</div>
      </div>
      <div class="px-3 py-1 rounded-full border text-sm">
        現在: {{ profile?.label ?? '—' }}
      </div>
    </header>

    <div class="flex gap-2">
      <button class="btn" @click="update('available')">待機中</button>
      <button class="btn" @click="update('busy')">接客中</button>
      <button class="btn" @click="update('break')">休憩中</button>
      <button class="btn" @click="update('off_today')">本日休業</button>
    </div>
  </main>
</template>
