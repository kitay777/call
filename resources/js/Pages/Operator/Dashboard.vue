<script setup>
import { computed } from 'vue'
import { usePage, router, Link } from '@inertiajs/vue3' // ← Link を追加

const page = usePage()

const user    = computed(() => page.props.auth?.user ?? page.props.me ?? null)
const profile = computed(() => page.props.profile ?? null)

function update(state) {
  router.post('/operator/state', { state }, { preserveScroll: true })
}
</script>

<template>
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

    <div class="flex gap-2 mt-4">
      <!-- 🔗 追加したリンクボタン -->
      <Link
        href="/operation/operators"
        class="px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700"
      >
        オペレーター一覧へ
      </Link>

      <button
        class="px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700"
        @click="router.post('/logout')"
      >
        ログアウト
      </button>
    </div>
  </main>
</template>
