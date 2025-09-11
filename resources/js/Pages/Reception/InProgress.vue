<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({ reception: Object })

const phase = ref(props.reception?.status || 'in_progress')
const videoEl = ref(null)
const hasStream = ref(false)        // ← これで判定
let localStream, pollTimer

async function startCamera() {
  try {
    localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true })
    if (videoEl.value) {
      videoEl.value.srcObject = localStream
      videoEl.value.muted = true
      await videoEl.value.play()
      hasStream.value = true        // ← ここで true
    }
    await fetch(`/reception/notify-video/${props.reception.token}`, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      }
    })
  } catch (e) { console.error(e) }
}

async function pollStatus() {
  try {
    const res  = await fetch(`/reception/status/${props.reception.token}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    const json = await res.json()
    phase.value = json.status
  } catch {}
}

function hangup() {
  fetch(`/reception/advance/${props.reception.token}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({ next: 'done' })
  }).finally(() => {
    if (localStream) localStream.getTracks().forEach(t => t.stop())
    router.visit(`/reception/done/${props.reception.token}`)
  })
}

onMounted(() => { startCamera(); pollStatus(); pollTimer = setInterval(pollStatus, 3000) })
onBeforeUnmount(() => { if (pollTimer) clearInterval(pollTimer); if (localStream) localStream.getTracks().forEach(t => t.stop()) })
</script>

<template>
  <main class="min-h-screen bg-slate-50 p-4 md:p-8">
    <div class="max-w-5xl mx-auto flex items-center justify-between mb-4">
      <div class="text-sm px-3 py-1 rounded-full border bg-white shadow">
        <template v-if="phase==='verify'">本人確認中</template>
        <template v-else-if="phase==='important'">重要事項説明中</template>
        <template v-else-if="phase==='sign'">署名手続き中</template>
        <template v-else>接続中</template>
      </div>
      <button class="text-sm px-4 py-2 rounded-xl border bg-white shadow hover:bg-slate-50" @click="hangup">終了</button>
    </div>

    <section class="max-w-5xl mx-auto rounded-2xl overflow-hidden relative" style="background:#000; aspect-ratio:16/9">
      <!-- video を最前面に -->
      <video ref="videoEl" playsinline class="absolute inset-0 w-full h-full object-cover z-10"></video>

      <!-- プレースホルダ（ストリーム無い時だけ）。背景は透明、文字だけ -->
      <div v-if="!hasStream" class="absolute inset-0 grid place-items-center z-0">
        <div class="text-white/85 text-sm px-3 py-1 rounded">カメラの許可を確認してください</div>
      </div>

      <!-- 右上ラベル -->
      <div class="absolute top-3 right-3 text-xs px-3 py-1 rounded-full bg-white/90 z-20">
        画面：お客様の映像
      </div>
    </section>

    <p class="max-w-5xl mx-auto mt-4 text-sm text-slate-600">
      ご案内：通話中に画面がスリープする可能性があります。その際は「スタート画面」に戻り再接続してください。
    </p>
  </main>
</template>
