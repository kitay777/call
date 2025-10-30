<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import io from "socket.io-client";

const props = defineProps({
  reception: { type: Object, required: true },
  signalingUrl: { type: String, default: "" },
});

const CSRF =
  document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
  "";

const localVideo = ref(null);
const remoteVideo = ref(null);
const connecting = ref(false);
const connected = ref(false);
const errorMsg = ref("");
const signatureImage = ref(null); // ✅ ユーザー署名の表示

let pc = null;
let socket = null;
let localStream = null;
let remoteStream = null;
let roomId = "";
let hasJoined = false;

const SIGNALING_URL =
  import.meta.env.VITE_SIGNALING_URL || props.signalingUrl || "";

let hbTimer;

// ===== 心拍維持 =====
function heartbeat() {
  fetch(`/reception/heartbeat/${props.reception.token}`, {
    headers: { "X-Requested-With": "XMLHttpRequest" },
  }).catch(() => {});
}

// ===== クリーンアップ =====
function cleanup() {
  connected.value = false;
  connecting.value = false;
  try {
    pc?.getSenders()?.forEach((s) => s.track && s.track.stop());
  } catch {}
  try {
    pc?.close();
  } catch {}
  pc = null;
  try {
    localStream?.getTracks()?.forEach((t) => t.stop());
  } catch {}
  localStream = null;
  try {
    socket?.disconnect();
  } catch {}
  socket = null;
  try {
    if (remoteVideo.value) remoteVideo.value.srcObject = null;
  } catch {}
}

// ===== カメラ起動 =====
async function startLocalMedia() {
  try {
    localStream = await navigator.mediaDevices.getUserMedia({
      video: { width: 1280, height: 720 },
      audio: true,
    });
    console.log("[callee] local tracks", localStream.getTracks().map((t) => t.kind));

    if (localVideo.value) {
      localVideo.value.srcObject = localStream;
      localVideo.value.muted = true;
      localVideo.value.playsInline = true;
      await localVideo.value.play().catch(() => {});
    }
  } catch (e) {
    errorMsg.value = "カメラ/マイクの許可が必要です。";
    console.error(e);
    throw e;
  }
}

// ===== Join Call =====
async function joinCall() {
  if (hasJoined) return;
  hasJoined = true;

  try {
    connecting.value = true;
    errorMsg.value = "";

    roomId =
      props.reception?.meta?.room_id ||
      props.reception?.code ||
      props.reception?.token ||
      "";
    if (!roomId) throw new Error("roomId not decided");

    console.log("[callee] roomId", roomId);

    pc = new RTCPeerConnection({
      iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
    });

    // === Remote映像 ===
    remoteStream = new MediaStream();
    remoteVideo.value.srcObject = remoteStream;
    remoteVideo.value.muted = false;

    pc.ontrack = (e) => {
      const stream = e.streams?.[0];
      if (!stream) return;
      remoteVideo.value.srcObject = stream;
      remoteVideo.value
        .play()
        .then(() => console.log("[callee] remote video playing"))
        .catch((err) => console.warn("[callee] play() failed", err));
    };

    await startLocalMedia();
    localStream.getTracks().forEach((t) => pc.addTrack(t, localStream));

    pc.onicecandidate = (e) => {
      if (e.candidate && socket && roomId)
        socket.emit("ice-candidate", { roomId, candidate: e.candidate });
    };

    // ===== Socket接続 =====
    socket = io(SIGNALING_URL, { transports: ["websocket"] });
    socket.on("connect_error", (e) => console.error("[callee socket]", e));

    // === SDP Offer送信 ===
    async function sendOwnOffer() {
      const offer = await pc.createOffer({
        offerToReceiveAudio: true,
        offerToReceiveVideo: true,
      });
      await pc.setLocalDescription(offer);
      socket.emit("sdp-offer", { roomId, role: "callee", sdp: offer.sdp });
      console.log("[callee] sent own offer");
    }

    // === Answer受信 ===
    socket.on("sdp-answer", async ({ sdp }) => {
      await pc.setRemoteDescription({ type: "answer", sdp });
      connected.value = true;
      connecting.value = false;
      console.log("[callee] connection established ✅");
    });

    socket.on("ice-candidate", async ({ candidate }) => {
      if (candidate) await pc.addIceCandidate(candidate);
    });

    // === 署名・重要事項イベント ===
    socket.on("phase-change", ({ phase, image }) => {
      if (phase.startsWith("important_check_")) {
        const num = phase.split("_")[2];
        alert(`☑ ユーザーが ${num} 番目の項目を確認しました。`);
      } else if (phase === "important_done") {
        alert("✅ ユーザーがすべての項目を確認し、同意を完了しました。");
      } else if (phase === "signature-done" && image) {
        console.log("🖊️ 署名完了:", image);
        signatureImage.value = image;
      }
    });

    // === Join Room ===
    socket.once("connect", () => {
      socket.emit("join-room", { roomId, role: "callee" }, () => {
        console.log("[callee] joined room, waiting for caller...");
      });
    });

    // === Callerが入室したらOffer送信 ===
    socket.on("peer-joined", ({ role }) => {
      if (role === "caller") {
        console.log("[callee] caller joined, sending offer...");
        sendOwnOffer();
      }
    });
  } catch (e) {
    errorMsg.value = e.message || String(e);
    cleanup();
  }
}

// ===== 切断 =====
function leaveCall() {
  try {
    socket && roomId && socket.emit("stop", { roomId });
  } catch {}
  cleanup();
}

// ===== フェーズ送信 =====
function sendPhase(phase) {
  if (!socket?.connected) {
    console.warn("[callee] socket not ready");
    return;
  }
  socket.emit("phase-change", { roomId, phase });
  console.log("[callee] sent phase:", phase);
}

onMounted(() => {
  heartbeat();
  hbTimer = setInterval(heartbeat, 5000);
});
onBeforeUnmount(() => {
  if (hbTimer) clearInterval(hbTimer);
  cleanup();
});
</script>

<template>
  <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6 p-6">
    <div class="space-y-3">
      <div class="aspect-video bg-black rounded-xl border overflow-hidden grid place-items-center">
        <video ref="remoteVideo" autoplay playsinline class="w-full h-full object-cover" />
      </div>

      <div class="hidden md:block">
        <div class="text-xs text-slate-500 mb-1">プレビュー（オペレーター）</div>
        <video
          ref="localVideo"
          autoplay
          playsinline
          muted
          class="w-48 h-36 bg-black rounded border object-cover"
        />
      </div>

      <div class="flex gap-2">
        <button
          class="px-4 py-2 rounded bg-emerald-600 text-white disabled:opacity-50"
          :disabled="connecting || connected"
          @click="joinCall"
        >
          接続
        </button>
        <button
          class="px-4 py-2 rounded bg-gray-600 text-white disabled:opacity-50"
          :disabled="!connecting && !connected"
          @click="leaveCall"
        >
          切断
        </button>
        <span v-if="connected" class="text-green-600 text-sm self-center">接続中</span>
        <span v-else-if="connecting" class="text-slate-500 text-sm self-center">接続準備中…</span>
      </div>

      <p v-if="errorMsg" class="text-red-600 text-sm">Error: {{ errorMsg }}</p>

      <!-- 🖊️ 署名プレビュー -->
      <div v-if="signatureImage" class="mt-4 p-4 bg-white border rounded shadow text-center">
        <p class="text-sm text-gray-500 mb-2">署名データ：</p>
        <img :src="'/storage/' + signatureImage" class="mx-auto max-h-48 border rounded" />
      </div>
    </div>

    <aside class="space-y-3">
      <div class="rounded-xl border p-4">
        <div class="text-sm font-semibold mb-3">ステップ操作</div>
        <div class="space-y-2">
          <button class="w-full h-12 rounded-xl border hover:bg-slate-50" @click="sendPhase('verify')">
            本人確認
          </button>
          <button class="w-full h-12 rounded-xl border hover:bg-slate-50" @click="sendPhase('important')">
            重要事項説明
          </button>
          <button class="w-full h-12 rounded-xl border hover:bg-slate-50" @click="sendPhase('sign')">
            署名
          </button>
        </div>
      </div>
    </aside>
  </div>
</template>

<style scoped>
.aspect-video {
  aspect-ratio: 16 / 9;
}
</style>
