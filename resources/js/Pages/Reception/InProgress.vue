<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from "vue";
import io from "socket.io-client";

const props = defineProps({ reception: Object });
const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

// ======== 映像関連 ========
const remoteVideoEl = ref(null);
const localVideoEl = ref(null);
const hasRemote = ref(false);
const hasLocal = ref(false);

// ======== ステップ状態 ========
const showDocument = ref(false);
const currentIndex = ref(0);
const checks = [
  "1. 重要事項の内容を理解しました。",
  "2. 契約条件および補償内容を確認しました。",
  "3. 注意事項・免責事項に同意します。",
  "4. 著しい過失・重過失・法令違反による事故について理解しました。",
  "5. 駐車違反については自己責任で対応することを理解しました。",
  "6. 給油、オプション備品について理解しました。",
  "7. 延長について理解しました。",
  "8. 事故について理解しました。",
  "9. 故障について理解しました。",
  "10. レンタカー貸渡約款および利用規約について理解しました。",
  "11. クレジットカード決済による追加料金等について理解しました。",
];
const checked = ref(Array(checks.length).fill(false));

// ======== 署名関連 ========
const showSignPad = ref(false);
const signatureCanvas = ref(null);
let ctx = null;
let drawing = false;

// ======== WebRTC / Socket ========
let pc = null;
let socket = null;
let localStream = null;
let roomId = "";
let joined = false;
let pollTimer, hbTimer;
const SIGNALING_URL = import.meta.env.VITE_SIGNALING_URL || "";

// ====== カメラ起動 ======
async function startCamera() {
  try {
    localStream = await navigator.mediaDevices.getUserMedia({
      video: true,
      audio: true,
    });
    if (localVideoEl.value) {
      localVideoEl.value.srcObject = localStream;
      localVideoEl.value.muted = true;
      await localVideoEl.value.play().catch(() => {});
      hasLocal.value = true;
    }
  } catch {
    alert("カメラ・マイクの利用を許可してください。");
  }
}

// ====== 状態ポーリング ======
async function pollStatus() {
  const res = await fetch(`/reception/status/${props.reception.token}`);
  const json = await res.json();
  const rid =
    json.meta?.room_id || props.reception?.code || props.reception?.token;
  if (rid && !joined) {
    roomId = rid;
    await join();
  }
}

function heartbeat() {
  fetch(`/reception/heartbeat/${props.reception.token}`).catch(() => {});
}

// ====== WebRTC ======
async function join() {
  if (!SIGNALING_URL) return console.warn("SIGNALING_URL not set");
  joined = true;

  pc = new RTCPeerConnection({
    iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
  });

  pc.ontrack = (event) => {
    const stream = event.streams?.[0];
    if (stream && remoteVideoEl.value) {
      remoteVideoEl.value.srcObject = stream;
      remoteVideoEl.value
        .play()
        .then(() => (hasRemote.value = true))
        .catch(() => {});
    }
  };

  if (localStream)
    localStream.getTracks().forEach((t) => pc.addTrack(t, localStream));

  pc.onicecandidate = (e) => {
    if (e.candidate && socket && roomId)
      socket.emit("ice-candidate", { roomId, candidate: e.candidate });
  };

  socket = io(SIGNALING_URL, { transports: ["websocket"] });
  socket.on("connect_error", (e) => console.error("[caller socket]", e));

  // ===== フェーズ受信 =====
  socket.on("phase-change", async ({ phase }) => {
    console.log("[caller] phase-change:", phase);
    if (phase === "important") {
      showDocument.value = true;
      showSignPad.value = false;
      currentIndex.value = 0;
    } else if (phase === "sign") {
      showDocument.value = false;
      showSignPad.value = true;
      await nextTick();
      initSignaturePad();
    }
  });

  const emitOffer = async () => {
    await new Promise((resolve) =>
      socket.emit("join-room", { roomId, role: "caller" }, resolve)
    );
    const offer = await pc.createOffer({
      offerToReceiveAudio: true,
      offerToReceiveVideo: true,
    });
    await pc.setLocalDescription(offer);
    socket.emit("sdp-offer", { roomId, role: "caller", sdp: offer.sdp });
  };

  socket.once("connect", () => emitOffer());
  socket.on("sdp-answer", async ({ sdp }) =>
    await pc.setRemoteDescription({ type: "answer", sdp })
  );
  socket.on("ice-candidate", ({ candidate }) =>
    candidate && pc.addIceCandidate(candidate).catch(console.error)
  );
}

// ===== チェック処理 =====
function checkItem(i) {
  checked.value[i] = true;
  if (socket?.connected) {
    socket.emit("phase-change", { roomId, phase: `important_check_${i + 1}` });
  }

  if (i + 1 < checks.length) {
    currentIndex.value = i + 1;
  } else {
    submitAgreement();
  }
}

// ===== 完了処理 =====
async function submitAgreement() {
  await fetch(`/reception/ack-important/${props.reception.token}`, {
    method: "POST",
    headers: { "X-Requested-With": "XMLHttpRequest", "X-CSRF-TOKEN": CSRF },
  });
  socket.emit("phase-change", { roomId, phase: "important_done" });
  showDocument.value = false;
  alert("全ての項目が確認されました。");
}

// ===== 署名キャンバス =====
function initSignaturePad() {
  const canvas = signatureCanvas.value;
  if (!canvas) return;

  const rect = canvas.getBoundingClientRect();
  canvas.width = rect.width;
  canvas.height = rect.height;

  ctx = canvas.getContext("2d");
  ctx.lineWidth = 2;
  ctx.lineCap = "round";
  ctx.strokeStyle = "#000";
  drawing = false;

  const pos = (e) => {
    const p = e.touches ? e.touches[0] : e;
    const r = canvas.getBoundingClientRect();
    return { x: p.clientX - r.left, y: p.clientY - r.top };
  };
  const start = (e) => {
    drawing = true;
    const { x, y } = pos(e);
    ctx.beginPath();
    ctx.moveTo(x, y);
  };
  const move = (e) => {
    if (!drawing) return;
    const { x, y } = pos(e);
    ctx.lineTo(x, y);
    ctx.stroke();
  };
  const end = () => (drawing = false);

  ["mousedown", "touchstart"].forEach((ev) => canvas.addEventListener(ev, start));
  ["mousemove", "touchmove"].forEach((ev) => canvas.addEventListener(ev, move));
  ["mouseup", "mouseleave", "touchend"].forEach((ev) => canvas.addEventListener(ev, end));
}

function clearSignature() {
  if (!signatureCanvas.value || !ctx) return;
  ctx.clearRect(0, 0, signatureCanvas.value.width, signatureCanvas.value.height);
}

// ====== 署名送信 ======
async function submitSignature() {
  const canvas = signatureCanvas.value;
  if (!canvas) return;
  const img = canvas.toDataURL("image/png");

  try {
    const res = await fetch(`/reception/sign/${props.reception.token}`, {
      method: "POST",
      credentials: "include",
      headers: {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-TOKEN": CSRF,
      },
      body: JSON.stringify({ image: img }),
    });
    const data = await res.json();
    console.log("✅ signature saved:", data);

    // 🔔 署名完了通知（パス付き）
    socket.emit("phase-change", {
      roomId,
      phase: "signature-done",
      image: data.path,
    });

    showSignPad.value = false;
    alert("署名が完了しました。");
  } catch (e) {
    console.error("❌ signature upload failed", e);
    alert("署名送信に失敗しました。ページを再読み込みしてください。");
  }
}

// ===== クリーンアップ =====
function leaveAll() {
  pc?.close();
  socket?.disconnect();
  localStream?.getTracks().forEach((t) => t.stop());
}

onMounted(async () => {
  await startCamera();
  pollStatus();
  pollTimer = setInterval(pollStatus, 3000);
  heartbeat();
  hbTimer = setInterval(heartbeat, 5000);
});
onBeforeUnmount(() => {
  clearInterval(pollTimer);
  clearInterval(hbTimer);
  leaveAll();
});
</script>

<template>
  <main class="min-h-screen bg-slate-50 p-4 md:p-8">
    <!-- 🎥 ビデオ -->
    <section class="max-w-5xl mx-auto rounded-2xl overflow-hidden relative bg-black aspect-video">
      <video
        ref="remoteVideoEl"
        autoplay
        playsinline
        muted
        class="absolute inset-0 w-full h-full object-contain bg-black"
      ></video>

      <div
        v-show="hasLocal"
        class="absolute bottom-3 right-3 z-10"
        style="width: min(28vw, 240px); aspect-ratio: 16/9"
      >
        <video
          ref="localVideoEl"
          autoplay
          playsinline
          muted
          class="w-full h-full object-cover rounded-xl shadow-xl ring-1 ring-white/40"
        ></video>
      </div>
    </section>

    <!-- 📄 重要事項 -->
    <div v-if="showDocument" class="mt-8 max-w-5xl mx-auto">
      <h3 class="text-lg font-semibold text-blue-700 mb-4">重要事項説明書</h3>
      <div class="border rounded overflow-hidden mb-6 shadow">
        <embed src="/storage/jyuyo.pdf" type="application/pdf" class="w-full h-[60vh]" />
      </div>
      <div class="p-6 border rounded bg-white shadow text-center">
        <p class="text-lg font-semibold mb-4">{{ checks[currentIndex] }}</p>
        <button
          class="px-6 py-2 bg-emerald-600 text-white rounded hover:bg-emerald-700"
          @click="checkItem(currentIndex)"
        >
          確認しました
        </button>
        <p class="mt-4 text-sm text-gray-500">
          {{ currentIndex + 1 }} / {{ checks.length }} 項目
        </p>
      </div>
    </div>

    <!-- ✍️ 署名 -->
    <div
      v-if="showSignPad"
      class="mt-8 max-w-5xl mx-auto text-center bg-white p-6 rounded-xl shadow"
    >
      <h2 class="text-lg font-semibold mb-4 text-blue-700">署名をお願いします</h2>
      <canvas
        ref="signatureCanvas"
        width="600"
        height="200"
        class="border rounded bg-gray-50 shadow mx-auto"
      ></canvas>
      <div class="mt-4 flex justify-center gap-3">
        <button @click="clearSignature" class="px-4 py-2 bg-gray-300 rounded">
          やり直し
        </button>
        <button
          @click="submitSignature"
          class="px-4 py-2 bg-emerald-600 text-white rounded"
        >
          送信
        </button>
      </div>
    </div>
  </main>
</template>

<style scoped>
.aspect-video {
  aspect-ratio: 16 / 9;
}
canvas {
  touch-action: none;
  cursor: crosshair;
  width: 600px;
  height: 200px;
}
</style>
