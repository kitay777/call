<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import io from 'socket.io-client'

/**
 * Props:
 *  - roomId: /talk/ops/{roomId} で遷移してきた時にセットされる（省略可）
 *  - signalingUrl: props で渡したい場合（省略可）
 */
const props = defineProps({
  roomId: { type: String, default: '' },
  signalingUrl: { type: String, default: '' },
})

/* ---------- UI state ---------- */
const localVideo = ref(null)
const remoteVideo = ref(null)

const connected = ref(false)
const connecting = ref(false)
const errorMsg = ref('')

const idInput = ref('')                          // トークン or 6桁コードをここに入れる
const currentRoomId = ref(props.roomId ?? '')    // 直接参加用 roomId（UUID）

/* ---------- runtime ---------- */
let pc = null
let socket = null
let localStream = null

// .env の VITE_SIGNALING_URL > props.signalingUrl の順で使う
const SIGNALING_URL =
  (import.meta.env.VITE_SIGNALING_URL && String(import.meta.env.VITE_SIGNALING_URL)) ||
  (props.signalingUrl || '')

/* ---------- computed ---------- */
const canAccept = computed(() => !!idInput.value && !connecting.value && !connected.value)
const canJoinByRoom = computed(() => !!currentRoomId.value && !connecting.value && !connected.value)

/* ---------- utils ---------- */
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
    try { currentRoomId.value && socket.emit('stop', { roomId: currentRoomId.value }) } catch {}
    try { socket.disconnect() } catch {}
    socket = null
  }
}

async function startLocalMedia() {
  try {
    localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true })
    if (localVideo.value) localVideo.value.srcObject = localStream
  } catch (e) {
    const name = e?.name || ''
    if (name === 'NotAllowedError' || name === 'SecurityError') {
      errorMsg.value = 'このサイトのカメラ/マイクが拒否されています。アドレスバーの「ぁあ」→ このWebサイトの設定で「許可」にしてください。'
    } else {
      errorMsg.value = e?.message || String(e)
    }
    throw e
  }
}

/* ---------- Kurento callee ---------- */
async function connectAsCallee() {
  if (!SIGNALING_URL) throw new Error('SIGNALING_URL が未設定です')

  // 1) RTCPeerConnection
  pc = new RTCPeerConnection({
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      // TURN を使うなら追記（例）
      // { urls: 'turn:turn.picton.jp:3478?transport=udp', username: 'kitayama', credential: 'celica77' },
    ],
  })

  pc.onicecandidate = (e) => {
    if (e.candidate && socket && currentRoomId.value) {
      socket.emit('ice-candidate', { roomId: currentRoomId.value, candidate: e.candidate })
    }
  }
  pc.ontrack = (e) => {
    if (remoteVideo.value) remoteVideo.value.srcObject = e.streams[0]
  }

  // ローカルメディアを登録
  localStream.getTracks().forEach(t => pc.addTrack(t, localStream))

  // 2) Socket.IO
  socket = io(SIGNALING_URL, { transports: ['websocket'] })
  socket.on('connect_error', (e) => console.error('[socket] error', e))
  socket.on('connect',       ()  => console.log('[socket] connected', socket.id))

  // 3) “自分で” offer を作り、role: 'callee' で送る
  const emitOffer = async () => {
    try {
      console.log('[callee] emit offer for room', currentRoomId.value)
      const offer = await pc.createOffer({ offerToReceiveAudio: true, offerToReceiveVideo: true })
      await pc.setLocalDescription(offer)
      socket.emit('sdp-offer', { roomId: currentRoomId.value, role: 'callee', sdp: offer.sdp })
    } catch (e) {
      errorMsg.value = e?.message || String(e)
      hangup()
    }
  }

  if (socket.connected) {
    await emitOffer()
  } else {
    socket.once('connect', emitOffer)
    // 予備：connect イベント取り逃し/遅延対策
    setTimeout(() => { if (socket && !pc.currentRemoteDescription) emitOffer().catch(()=>{}) }, 2000)
  }

  // 4) sdp-answer を受け取り RemoteDescription へ
  socket.on('sdp-answer', async ({ sdp }) => {
    try {
      await pc.setRemoteDescription({ type: 'answer', sdp })
      connected.value  = true
      connecting.value = false
      console.log('[callee] answer set')
    } catch (e) {
      errorMsg.value = e?.message || String(e)
      hangup()
    }
  })

  // 5) 逆向き ICE
  socket.on('ice-candidate', ({ candidate }) => {
    try { if (candidate) pc.addIceCandidate(candidate) } catch {}
  })

  socket.on('stop', () => hangup())
}

/* ---------- UI actions ---------- */
/** トークン or 6桁コードを自動判定して受ける */
async function acceptByTokenOrCode() {
  try {
    errorMsg.value = ''
    connecting.value = true

    await startLocalMedia()

    const v = idInput.value.trim()
    const isCode = /^\d{6}$/.test(v)
    const url = isCode
      ? `/api/video/accept-code/${v}`
      : `/api/video/accept/${encodeURIComponent(v)}`

    const res = await fetch(url, { method: 'POST' })
    if (!res.ok) throw new Error('accept API failed')
    const data = await res.json()
    currentRoomId.value = data?.roomId
    if (!currentRoomId.value) throw new Error('roomId not issued')

    await connectAsCallee()
  } catch (e) {
    errorMsg.value = e?.message || String(e)
    hangup()
  }
}

/** roomId（UUID）が分かっている時に直接参加 */
async function joinByRoomId() {
  try {
    if (!currentRoomId.value) return
    errorMsg.value = ''
    connecting.value = true

    await startLocalMedia()
    await connectAsCallee()
  } catch (e) {
    errorMsg.value = e?.message || String(e)
    hangup()
  }
}

function hangup() { cleanupPeer() }

onBeforeUnmount(() => cleanupPeer())
</script>

<template>
  <div class="p-6 space-y-4">
    <h1 class="text-xl font-bold">ビデオ通話（運営側）</h1>

    <div class="grid md:grid-cols-2 gap-4">
      <div class="space-y-2">
        <label class="block text-sm font-medium">トークン / 部屋番号（6桁）で受ける</label>
        <div class="flex gap-2">
          <input class="border rounded px-3 py-2 w-full" placeholder="例）141d66dd-... または 123456" v-model="idInput" />
          <button class="px-4 py-2 rounded bg-emerald-600 text-white disabled:opacity-50"
                  :disabled="!canAccept" @click="acceptByTokenOrCode">
            受ける
          </button>
        </div>
        <p class="text-xs text-gray-500">※ 数字6桁なら部屋番号として、その他はトークンとして扱います。</p>
      </div>

      <div class="space-y-2">
        <label class="block text-sm font-medium">roomId（UUID）を指定して参加</label>
        <div class="flex gap-2">
          <input class="border rounded px-3 py-2 w-full" placeholder="roomId（UUID）" v-model="currentRoomId" />
          <button class="px-4 py-2 rounded bg-blue-600 text-white disabled:opacity-50"
                  :disabled="!canJoinByRoom" @click="joinByRoomId">
            参加
          </button>
        </div>
        <p class="text-xs text-gray-500">※ /talk/ops/{roomId} で遷移してきた場合は自動で反映されます。</p>
      </div>
    </div>

    <div class="flex flex-wrap gap-4 pt-2">
      <video ref="localVideo" autoplay playsinline muted class="w-64 h-48 bg-black rounded-lg border" />
      <video ref="remoteVideo" autoplay playsinline class="w-64 h-48 bg-black rounded-lg border" />
    </div>

    <div class="flex gap-2">
      <button class="px-4 py-2 rounded bg-gray-600 text-white disabled:opacity-50"
              @click="hangup" :disabled="!connecting && !connected">
        切断
      </button>
    </div>

    <div class="text-sm space-y-1">
      <p v-if="connecting && !connected">接続準備中…（自端のOffer送信 → Answer待ち）</p>
      <p v-if="connected" class="text-green-600">接続中</p>
      <p v-if="currentRoomId">roomId: {{ currentRoomId }}</p>
      <p v-if="errorMsg" class="text-red-600">Error: {{ errorMsg }}</p>
    </div>
  </div>
</template>

<style scoped>
video { object-fit: cover; }
</style>
