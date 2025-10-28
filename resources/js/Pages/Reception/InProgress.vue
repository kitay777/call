<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import { router } from "@inertiajs/vue3";
import io from "socket.io-client";

const props = defineProps({ reception: Object });
const CSRF =
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content") || "";

const phase = ref(props.reception?.status || "in_progress");
const remoteVideoEl = ref(null); // オペ映像（大）
const localVideoEl = ref(null); // 自分PIP
const hasRemote = ref(false);
const hasLocal = ref(false);
const currentPhase = ref("");

let pc = null;
let socket = null;
let localStream = null;
let roomId = "";
let joined = false;
let pollTimer;
let hbTimer;

const SIGNALING_URL =
    (import.meta.env.VITE_SIGNALING_URL &&
        String(import.meta.env.VITE_SIGNALING_URL)) ||
    "";

// ===== Local Camera =====
async function startCamera() {
    try {
        localStream = await navigator.mediaDevices.getUserMedia({
            video: true,
            audio: true,
        });
        console.log(
            "[caller] local tracks",
            localStream.getTracks().map((t) => t.kind)
        );

        // Vue の ref を使ってプレビュー表示
        if (localVideoEl.value) {
            localVideoEl.value.srcObject = localStream;
            localVideoEl.value.muted = true; // 自分の音をループさせない
            localVideoEl.value.playsInline = true;
            await localVideoEl.value
                .play()
                .catch((err) => console.warn("[caller] play() error", err));
        }

        hasLocal.value = true; // PIP 表示を有効化
    } catch (err) {
        console.error("[caller] getUserMedia failed", err.name, err.message);
        alert("カメラ・マイクの使用を許可してください。");
    }
}

// ===== 状態ポーリング =====
async function pollStatus() {
    try {
        const res = await fetch(`/reception/status/${props.reception.token}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });
        const json = await res.json();
        phase.value = json.status;
        const rid =
            json.meta?.room_id ||
            props.reception?.code ||
            props.reception?.token;
        if (rid && !joined) {
            roomId = rid;
            console.log("[caller] roomId", roomId);
            await join();
        }
    } catch {}
}

function heartbeat() {
    fetch(`/reception/heartbeat/${props.reception.token}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
    }).catch(() => {});
}

// ======== WebRTC Join ========
async function join() {
    if (!SIGNALING_URL) return console.warn("SIGNALING_URL not set");
    joined = true;

    pc = new RTCPeerConnection({
        iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
    });
    console.log("[caller] RTCPeerConnection created");

    // === Remote映像の受信 ===
    pc.ontrack = (event) => {
        const stream = event.streams?.[0];
        if (!stream || !remoteVideoEl.value) return;

        // すでに stream が設定済みなら再設定しない
        if (remoteVideoEl.value.srcObject !== stream) {
            console.log("[caller] ontrack new stream");
            remoteVideoEl.value.srcObject = stream;
        } else {
            console.log("[caller] ontrack duplicate, ignored");
        }

        // 少し遅延を入れて再生
        setTimeout(() => {
            remoteVideoEl.value
                .play()
                .then(() => {
                    hasRemote.value = true;
                    console.log("[caller] video playing ✅");
                })
                .catch((err) => console.warn("[caller] play() blocked", err));
        }, 300);
    };

    // === Local映像の送信 ===
    if (localStream) {
        localStream.getTracks().forEach((t) => pc.addTrack(t, localStream));
    } else {
        console.warn("[caller] no localStream");
    }

    // === ICE送信 ===
    pc.onicecandidate = (e) => {
        if (e.candidate && socket && roomId)
            socket.emit("ice-candidate", { roomId, candidate: e.candidate });
    };

    // === Socket.IO ===
    socket = io(SIGNALING_URL, { transports: ["websocket"] });
    socket.on("connect_error", (e) => console.error("[caller socket]", e));

    socket.on("phase-change", ({ phase }) => {
        console.log("[caller] phase-change:", phase);
        currentPhase.value = phase;
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
        console.log("[caller] sending offer");
    };

    socket.once("connect", () => {
        console.log("[caller] socket connected, emitting offer");
        emitOffer();
    });

    socket.on("sdp-answer", async ({ sdp }) => {
        try {
            await pc.setRemoteDescription({ type: "answer", sdp });
            connected.value = true; // ✅ ここを追加
            connecting.value = false;
            console.log("[callee] got answer and marked connected");
        } catch (e) {
            console.error("[callee sdp-answer]", e);
        }
    });

    socket.on("ice-candidate", ({ candidate }) => {
        if (candidate) pc.addIceCandidate(candidate).catch(console.error);
    });
}

// ====== Clean Up ======
function leavePCOnly() {
    try {
        pc?.getSenders()?.forEach((s) => s.track && s.track.stop());
    } catch {}
    try {
        pc?.close();
    } catch {}
    pc = null;
    try {
        socket && roomId && socket.emit("stop", { roomId });
    } catch {}
    try {
        socket?.disconnect();
    } catch {}
    socket = null;
}

function hangup() {
    fetch(`/reception/advance/${props.reception.token}`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": CSRF,
        },
        body: JSON.stringify({ next: "done" }),
    }).finally(() => {
        leavePCOnly();
        if (localStream) localStream.getTracks().forEach((t) => t.stop());
        router.visit(`/reception/start`);
    });
}

onMounted(async () => {
    await startCamera();
    pollStatus();
    pollTimer = setInterval(pollStatus, 3000);
    heartbeat();
    hbTimer = setInterval(heartbeat, 5000);
});

onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer);
    if (hbTimer) clearInterval(hbTimer);
    leavePCOnly();
    if (localStream) localStream.getTracks().forEach((t) => t.stop());
});
</script>

<template>
    <main class="min-h-screen bg-slate-50 p-4 md:p-8">
        <div class="max-w-5xl mx-auto flex items-center justify-between mb-4">
            <div v-if="currentPhase" class="text-center my-4">
                <p
                    v-if="currentPhase === 'verify'"
                    class="text-blue-600 text-lg font-semibold"
                >
                    本人確認を開始します。身分証をカメラにお見せください。
                </p>
                <p
                    v-else-if="currentPhase === 'important'"
                    class="text-blue-600 text-lg font-semibold"
                >
                    重要事項の説明を行います。画面をご確認ください。
                </p>
                <p
                    v-else-if="currentPhase === 'sign'"
                    class="text-blue-600 text-lg font-semibold"
                >
                    署名の準備をお願いします。
                </p>
            </div>
            <button
                class="text-sm px-4 py-2 rounded-xl border bg-white shadow hover:bg-slate-50"
                @click="hangup"
            >
                終了
            </button>
        </div>

        <section
            class="max-w-5xl mx-auto rounded-2xl overflow-hidden relative"
            style="background: #000; aspect-ratio: 16/9"
        >
            <video
                ref="remoteVideoEl"
                autoplay
                playsinline
                muted
                class="absolute inset-0 w-full h-full"
                style="object-fit: contain; background: none"
            ></video>

            <!-- 右下PIP：自分 -->
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
                    class="w-full h-full object-cover rounded-xl shadow-xl ring-1 ring-white/40 [-scale-x-100]"
                ></video>
            </div>

            <div
                v-if="!hasRemote"
                class="absolute inset-0 grid place-items-center z-0"
            >
                <div class="text-white/85 text-sm px-3 py-1 rounded">
                    オペレーターの接続を待機中...
                </div>
            </div>

            <div
                class="absolute top-3 right-3 text-xs px-3 py-1 rounded-full bg-white/90 z-20"
            >
                画面：オペレーター（あなたの映像は送信中）
            </div>
        </section>
    </main>
    <transition name="fade">
        <div
            v-if="currentPhase"
            class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
        >
            <div class="bg-white rounded-2xl p-6 shadow-xl text-center">
                <p class="text-xl font-semibold text-blue-700 mb-2">
                    {{
                        currentPhase === "verify"
                            ? "本人確認を開始します"
                            : currentPhase === "important"
                            ? "重要事項の説明を行います"
                            : currentPhase === "sign"
                            ? "署名の準備をお願いします"
                            : ""
                    }}
                </p>
                <button
                    class="mt-3 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                    @click="currentPhase = ''"
                >
                    OK
                </button>
            </div>
        </div>
    </transition>

    <style scoped>
        .fade-enter-active,
        .fade-leave-active {
            transition: opacity 0.3s;
        }
        .fade-enter-from,
        .fade-leave-to {
            opacity: 0;
        }
    </style>
</template>
