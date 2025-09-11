<script setup lang="ts">
import { router } from '@inertiajs/vue3'
const props = defineProps<{ reception: any }>()
const go = (next: string) => fetch(route('reception.advance', props.reception.token), {
method: 'POST', headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as any)?.content, 'Content-Type': 'application/json' },
body: JSON.stringify({ next })
}).then(() => router.visit(route(`reception.${next}`, props.reception.token)))
</script>
<template>
<div class="p-6 space-y-6">
<div class="text-2xl">受付/応対を開始しました</div>
<div class="flex gap-3 flex-wrap">
<button class="btn" @click="go('verify')">本人確認へ</button>
<button class="btn" @click="go('apply')">入会手続きへ</button>
<button class="btn" @click="go('important')">重要事項へ</button>
<button class="btn" @click="go('sign')">サインへ</button>
<button class="btn" @click="go('done')">完了</button>
</div>
</div>
</template>