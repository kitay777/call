<script setup>
import { ref, onBeforeUnmount } from 'vue'
import io from 'socket.io-client'

/**
 * Props:
 *  - token: 受付用トークン（URLの {token}）
 *  - signalingUrl: 省略可。VITE_SIGNALING_URL が無ければこちらを使う
 */
const props = defineProps({
  token: { type: String, required: true },
  signalingUrl: { type: String, default: '' },
})

const localVideo = ref(null)
const remoteVideo = ref(null)
const calling = ref(false)
const connecting = ref(false)
const connected = ref(false)
const errorMsg = ref('')
const roomId = ref('')

let pollTimer = null
let pc = null
let socket = null
let localStream = null

const SIGNALING_URL =
  (import.meta.env.VITE_SIGNALING_URL && String(import.meta.env.VITE_SIGNALING_URL)) ||
  (props.signalingUrl || '')

/* ========== util ========== */
function cleanupPeer() {
  connected.value = false
  connecting.value = false
  if (pc) {
    try { pc.getSenders().forEach(s => s.track && s.track.stop()) } catch {}
    try { pc.close() } catch {}
    pc = null
  }
  if (localStream) {
    try { localStream.getTracks().forEach(t => t.stop()) } catch {}
    localStream = null
  }
  if (socket) {
    try { socket.emit('stop', { roomId: roomId.value }) } catch {}
    try { socket.disconnect() } catch {}
    socket = null
  }
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
  // roomId は残して良い（UI表示に使う）
}

async function startLocalMedia() {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user' },
      audio: true,
    })
    localStream = stream
    if (localVideo.value) localVideo.value.srcObject = stream
  } catch (e) {
    const name = e?.name || ''
    if (name === 'NotAllowedError' || name === 'SecurityError') {
      errorMsg.value = 'このサイトのカメラ/マイクが拒否されています。アドレスバーの「ぁあ」> このWebサイトの設定 から「許可」に変更し、ページを再読み込みしてください。'
    } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
      errorMsg.value = '利用できるカメラ/マイクが見つかりません。'
    } else if (name === 'NotReadableError') {
      errorMsg.value = '他アプリがカメラ/マイクを使用中の可能性があります。'
    } else {
      errorMsg.value = `getUserMedia失敗: ${e?.message || e}`
    }
    throw e
  }
}

/** Reception@status をポーリングして meta.room_id を拾う */
async function pollRoomIdUntilReady() {
  return new Promise((resolve) => {
    const tryFetch = async () => {
      try {
        const res = await fetch(`/reception/status/${props.token}`, { credentials: 'same-origin' })
        if (!res.ok) return
        const data = await res.json()
        const rid = data?.meta?.room_id
        if (rid) {
          clearInterval(pollTimer)
          pollTimer = null
          resolve(rid)
        }
      } catch {}
    }
    tryFetch()
    pollTimer = setInterval(tryFetch, 1200)
  })
}

/* ========== signaling + Kurento (caller) ========== */
async function connectAsCaller() {
  if (!SIGNALING_URL) throw new Error('SIGNALING_URL が未設定です')

  pc = new RTCPeerConnection({
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      // TURN を入れるならここに追加
    ],
  })

  pc.onicecandidate = (e) => {
    if (e.candidate && socket && roomId.value) {
      socket.emit('ice-candidate', { roomId: roomId.value, candidate: e.candidate })
    }
  }
  pc.ontrack = (e) => {
    if (remoteVideo.value) remoteVideo.value.srcObject = e.streams[0]
  }

  // ローカルメディアを登録（startLocalMedia 済み前提）
  localStream.getTracks().forEach(t => pc.addTrack(t, localStream))

  socket = io(SIGNALING_URL, { transports: ['websocket'] })
  socket.on('connect_error', e => console.error('[socket] error', e))
  socket.on('connect', () => console.log('[socket] connected', socket.id))

  // Offer emit を分離（取り逃し防止）
  const emitOffer = async () => {
    try {
      console.log('[caller] emit offer for room', roomId.value)
      const offer = await pc.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true })
      await pc.setLocalDescription(offer)
      socket.emit('sdp-offer', { roomId: roomId.value, role: 'caller', sdp: offer.sdp })
    } catch (e) {
      errorMsg.value = e?.message || String(e)
      stopCall()
    }
  }

  if (socket.connected) {
    await emitOffer()
  } else {
    socket.once('connect', emitOffer)
    // 予備（遅延・イベント取り漏れ対策）
    setTimeout(() => { if (socket && !pc.currentRemoteDescription) emitOffer().catch(()=>{}) }, 2000)
  }

  socket.on('sdp-answer', async ({ sdp }) => {
    try {
      await pc.setRemoteDescription({ type: 'answer', sdp })
      connected.value = true
      connecting.value = false
      console.log('[caller] answer set')
    } catch (e) {
      errorMsg.value = e?.message || String(e)
      stopCall()
    }
  })

  socket.on('ice-candidate', ({ candidate }) => {
    try { if (candidate) pc.addIceCandidate(candidate) } catch {}
  })

  socket.on('stop', () => stopCall())
}

/* ========== UI actions ========== */
async function startCall() {
  errorMsg.value = ''
  try {
    calling.value = true
    connecting.value = true

    // 1) ユーザー操作直後でローカルメディア開始
    await startLocalMedia()

    // 2) 発信をサーバへ（任意。ここでは token 検証/通知）
    await fetch(`/api/video/request/${props.token}`, { method: 'POST' }).catch(()=>{})

    // 3) roomId が付くまで待つ
    roomId.value = await pollRoomIdUntilReady()
    console.log('[client] roomId =', roomId.value)

    // 4) シグナリング接続 → Offer emit
    await connectAsCaller()
  } catch (e) {
    errorMsg.value = e?.message || String(e)
    stopCall()
  }
}

function stopCall() {
  cleanupPeer()
  calling.value = false
}

onBeforeUnmount(() => cleanupPeer())
</script>

<template>
  <div class="p-6 space-y-4">
    <h1 class="text-xl font-bold">ビデオ通話（発信側）</h1>

    <div class="flex flex-wrap gap-4">
      <video ref="localVideo" autoplay playsinline muted class="w-64 h-48 bg-black rounded-lg border" />
      <video ref="remoteVideo" autoplay playsinline class="w-64 h-48 bg-black rounded-lg border" />
    </div>

    <div class="flex gap-2">
      <button class="px-4 py-2 rounded bg-blue-600 text-white disabled:opacity-50"
              @click="startCall" :disabled="calling || connecting || connected">
        通話開始
      </button>
      <button class="px-4 py-2 rounded bg-gray-600 text-white disabled:opacity-50"
              @click="stopCall" :disabled="!calling">
        切断
      </button>
    </div>

    <div class="text-sm space-y-1">
      <p v-if="connecting && !connected">接続中…（オペレーターが応答すると自動接続します）</p>
      <p v-if="connected" class="text-green-600">接続中</p>
      <p class="text-xs text-gray-500">token: {{ props.token }}</p>
      <p v-if="roomId">roomId: {{ roomId }}</p>
      <p v-if="errorMsg" class="text-red-600">Error: {{ errorMsg }}</p>
    </div>
  </div>
</template>

<style scoped>
video { object-fit: cover; }
</style>
