<script setup>
import { computed } from 'vue'
import { usePage, router } from '@inertiajs/vue3'

const page = usePage()

// ✅ .value を外す（props はそのままリアクティブ）
const user    = computed(() => page.props.auth?.user ?? page.props.me ?? null)
const profile = computed(() => page.props.profile ?? null)

function update(state) {
  router.post('/operator/state', { state }, { preserveScroll: true })
}
</script>

<template>
  <!-- 一時デバッグ: props の中身を確認（動作確認できたら消してOK） -->
  <!-- <pre class="text-xs">{{ JSON.stringify($page.props, null, 2) }}</pre> -->

  <main class="p-6 space-y-4">
    <header class="flex items-center justify-between">
      <div>
        <div class="text-xl font-semibold">{{ user?.name ?? '—' }}</div>
        <div class="text-sm text-slate-500">{{ user?.email ?? '' }}</div>
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
