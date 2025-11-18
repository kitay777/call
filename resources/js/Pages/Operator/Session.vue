<!-- resources/js/Pages/Operator/Session.vue -->
<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import io from "socket.io-client";

/* ==============================
   ■ props
============================== */
const props = defineProps({
    reception: { type: Object, required: true },
    signalingUrl: { type: String, default: "" },
});

const CSRF =
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content") || "";

/* ==============================
   ■ WebRTC 関連
============================== */
const localVideo = ref(null);
const remoteVideo = ref(null);
const connecting = ref(false);
const connected = ref(false);
const errorMsg = ref("");

let pc = null;
let socket = null;
let localStream = null;
let remoteStream = null;
let roomId = "";

const SIGNALING_URL =
    (import.meta.env.VITE_SIGNALING_URL &&
        String(import.meta.env.VITE_SIGNALING_URL)) ||
    props.signalingUrl ||
    "";

/* ==============================
   ■ リアルタイム最新画像（顔 / 署名）
============================== */
const lastCapturedFace = ref(null);
const lastCapturedSignature = ref(null);

/* ==============================
   ■ モーダル系（顔一覧 / 署名一覧 / セッションまとめ）
============================== */
const showFaceModal = ref(false);
const faceList = ref([]);
async function loadFaceCaptures() {
    const res = await fetch("/operation/face-captures-json");
    faceList.value = await res.json();
    showFaceModal.value = true;
}

const showSignatureModal = ref(false);
const signatureList = ref([]);
async function loadSignatureList() {
    const res = await fetch("/operation/signature-list-json");
    signatureList.value = await res.json();
    showSignatureModal.value = true;
}

const showSessionSummaryModal = ref(false);
const sessionSummary = ref([]);
async function loadSessionSummary() {
    const res = await fetch("/operation/session-summary-json");
    sessionSummary.value = await res.json();
    showSessionSummaryModal.value = true;
}

/* ==============================
   ■ Heartbeat
============================== */
let hbTimer;
function heartbeat() {
    fetch(`/reception/heartbeat/${props.reception.token}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
    }).catch(() => {});
}

/* ==============================
   ■ Cleanup
============================== */
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

/* ==============================
   ■ Local media
============================== */
async function startLocalMedia() {
    localStream = await navigator.mediaDevices.getUserMedia({
        video: { width: 1280, height: 720 },
        audio: true,
    });
    if (localVideo.value) {
        localVideo.value.srcObject = localStream;
        localVideo.value.muted = true;
        await localVideo.value.play().catch(() => {});
    }
}

/* ==============================
   ■ joinCall
============================== */
async function joinCall() {
    try {
        if (!SIGNALING_URL) throw new Error("SIGNALING_URL が未設定です");
        connecting.value = true;

        // --- roomId 確定（API → fallback）
        try {
            const res = await fetch(
                `/api/video/accept/${encodeURIComponent(
                    props.reception.token
                )}`,
                {
                    method: "POST",
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                        "X-CSRF-TOKEN": CSRF,
                    },
                }
            );
            if (res.ok) roomId = (await res.json())?.roomId || "";
        } catch {}

        if (!roomId)
            roomId =
                props.reception?.meta?.room_id ||
                props.reception?.code ||
                props.reception?.token;
        if (!roomId) throw new Error("roomId not decided");

        // --- RTCPeerConnection ---
        pc = new RTCPeerConnection({
            iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
        });

        remoteStream = new MediaStream();
        if (remoteVideo.value) {
            remoteVideo.value.srcObject = remoteStream;
            remoteVideo.value.autoplay = true;
            remoteVideo.value.muted = false;
        }

        pc.ontrack = (e) => {
            const stream = e.streams?.[0];
            if (remoteVideo.value && stream) {
                remoteVideo.value.srcObject = stream;
            }
        };

        await startLocalMedia();
        localStream.getTracks().forEach((t) => pc.addTrack(t, localStream));

        pc.onicecandidate = (e) => {
            if (e.candidate && socket && roomId)
                socket.emit("ice-candidate", { roomId, candidate: e.candidate });
        };

        // --- Socket.IO ---
        socket = io(SIGNALING_URL, { transports: ["websocket"] });

        /* ==============================
           ★★★ Phase-change（完全統合版） ★★★
        =============================== */
        socket.on("phase-change", ({ phase, image }) => {
            console.log("[operator] phase-change:", phase, image);

            if (phase === "face_captured") {
                lastCapturedFace.value = image;
                return;
            }

            if (phase === "signature_done") {
                lastCapturedSignature.value = image;
                return;
            }

            if (phase.startsWith("important_check_")) {
                const num = phase.split("_")[2];
                alert(`ユーザーが ${num} 番目を確認しました`);
                return;
            }

            if (phase === "important_done") {
                alert("ユーザーが同意を完了しました");
                return;
            }
        });

        // --- Answer受信 ---
        socket.on("sdp-answer", async ({ sdp }) => {
            await pc.setRemoteDescription({ type: "answer", sdp });
            connected.value = true;
            connecting.value = false;
        });

        // --- Offer受信 ---
        socket.on("sdp-offer", async ({ sdp, roomId: rid }) => {
            if (rid && rid !== roomId) return;
            await pc.setRemoteDescription({ type: "offer", sdp });
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            socket.emit("sdp-answer", {
                roomId,
                role: "callee",
                sdp: answer.sdp,
            });
            connected.value = true;
            connecting.value = false;
        });

        socket.on("ice-candidate", async ({ candidate }) => {
            if (candidate) await pc.addIceCandidate(candidate).catch(() => {});
        });

        socket.once("connect", () => {
            socket.emit("join-room", { roomId, role: "callee" });
        });

        // caller が join したら先手 offer を送る
        socket.on("peer-joined", ({ role }) => {
            if (role === "caller") sendOwnOffer();
        });

        const sendOwnOffer = async () => {
            try {
                const offer = await pc.createOffer({
                    offerToReceiveAudio: true,
                    offerToReceiveVideo: true,
                });
                await pc.setLocalDescription(offer);
                socket.emit("sdp-offer", {
                    roomId,
                    role: "callee",
                    sdp: offer.sdp,
                });
            } catch (e) {
                console.error("offer error", e);
            }
        };
    } catch (e) {
        errorMsg.value = e?.message || String(e);
        leaveCall();
    }
}

/* ==============================
   ■ leaveCall
============================== */
function leaveCall() {
    try {
        socket && roomId && socket.emit("stop", { roomId });
    } catch {}
    cleanup();
    window.location.href = "/operation/operators";
}

/* ==============================
   ■ sendPhase
============================== */
function sendPhase(phase) {
    if (!socket || !socket.connected) {
        console.warn("[operator] socket not ready");
        return;
    }
    if (!roomId) {
        console.warn("[operator] no roomId");
        return;
    }

    if (!connected.value) {
        console.warn("[operator] not connected yet, retrying...");
        setTimeout(() => sendPhase(phase), 500);
        return;
    }

    socket.emit("phase-change", { roomId, phase });
    console.log("[operator] sent phase:", phase);
}

/* ==============================
   ■ Mounted / Unmounted
============================== */
onMounted(() => {
    joinCall();
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
        <!-- 左エリア（映像） -->
        <div class="space-y-3">
            <div
                class="aspect-video bg-black rounded-xl border overflow-hidden grid place-items-center"
            >
                <video
                    ref="remoteVideo"
                    autoplay
                    playsinline
                    muted
                    class="w-full h-full object-cover"
                />
            </div>

            <div class="hidden md:block">
                <div class="text-xs text-slate-500 mb-1">
                    プレビュー（オペレーター）
                </div>
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
                <span
                    v-if="connected"
                    class="text-green-600 text-sm self-center"
                    >接続中</span
                >
                <span
                    v-else-if="connecting"
                    class="text-slate-500 text-sm self-center"
                    >接続準備中…</span
                >
            </div>

            <p v-if="errorMsg" class="text-red-600 text-sm">
                Error: {{ errorMsg }}
            </p>
        </div>

        <!-- 右サイド（ステップ＋リアルタイム画像） -->
        <aside class="space-y-3">
            <div class="rounded-xl border p-4">
                <div class="text-sm font-semibold mb-3">ステップ操作</div>
                <div class="space-y-2">
                    <button
                        class="w-full h-12 rounded-xl border hover:bg-slate-50"
                        @click="sendPhase('verify')"
                    >
                        本人確認
                    </button>
                    <button
                        class="w-full h-12 rounded-xl border hover:bg-slate-50"
                        @click="sendPhase('important')"
                    >
                        重要事項説明
                    </button>
                    <button
                        class="w-full h-12 rounded-xl border hover:bg-slate-50"
                        @click="sendPhase('sign')"
                    >
                        署名
                    </button>
                </div>
            </div>

            <!-- ★ 最新の顔キャプチャ -->
            <div
                v-if="lastCapturedFace"
                class="rounded-xl border p-4 bg-white shadow"
            >
                <div class="text-sm font-semibold mb-2">
                    最新の本人確認画像
                </div>
                <img :src="lastCapturedFace" class="w-full rounded border" />
            </div>

            <!-- ★ 最新の署名 -->
            <div
                v-if="lastCapturedSignature"
                class="rounded-xl border p-4 bg-white shadow"
            >
                <div class="text-sm font-semibold mb-2">最新の署名</div>
                <img
                    :src="lastCapturedSignature"
                    class="w-full rounded border bg-white"
                />
            </div>
        </aside>
    </div>

    <!-- ▼ ボタン群 -->
    <div class="mt-6 text-center space-y-4">
        <button
            @click="loadSessionSummary"
            class="px-6 py-3 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700"
        >
            顔＋署名（セッション別）一覧
        </button>
        <button
            @click="loadFaceCaptures"
            class="px-6 py-3 bg-blue-600 text-white rounded-xl shadow hover:bg-blue-700"
        >
            顔キャプチャ一覧
        </button>
        <button
            @click="loadSignatureList"
            class="px-6 py-3 bg-purple-600 text-white rounded-xl shadow hover:bg-purple-700"
        >
            署名一覧
        </button>
    </div>

    <!-- モーダル：顔一覧 -->
    <div
        v-if="showFaceModal"
        class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center"
    >
        <div
            class="bg-white rounded-xl p-6 w-[90%] max-w-4xl shadow-xl relative"
        >
            <button
                class="absolute top-3 right-3 text-gray-600 hover:text-black"
                @click="showFaceModal = false"
            >
                ✖
            </button>
            <h2 class="text-xl font-bold mb-4">顔キャプチャ一覧</h2>
            <div
                class="grid grid-cols-2 md:grid-cols-4 gap-4 max-h-[70vh] overflow-y-auto"
            >
                <div
                    v-for="item in faceList"
                    :key="item.id"
                    class="border rounded p-2"
                >
                    <img :src="item.image_url" class="rounded w-full mb-2" />
                    <div class="text-xs text-gray-600">
                        Token: {{ item.token }}<br />
                        Room: {{ item.code }}<br />
                        {{ item.time }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- モーダル：署名一覧 -->
    <div
        v-if="showSignatureModal"
        class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center"
    >
        <div
            class="bg-white rounded-xl p-6 w-[90%] max-w-4xl shadow-xl relative"
        >
            <button
                class="absolute top-3 right-3 text-gray-600 hover:text-black"
                @click="showSignatureModal = false"
            >
                ✖
            </button>
            <h2 class="text-xl font-bold mb-4">署名一覧</h2>
            <div
                class="grid grid-cols-2 md:grid-cols-4 gap-4 max-h-[70vh] overflow-y-auto"
            >
                <div
                    v-for="item in signatureList"
                    :key="item.id"
                    class="border rounded p-2"
                >
                    <img :src="item.image_url" class="rounded w-full mb-2" />
                    <div class="text-xs text-gray-600">
                        Token: {{ item.token }}<br />
                        Room: {{ item.code }}<br />
                        {{ item.time }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- モーダル：セッション別まとめ -->
    <div
        v-if="showSessionSummaryModal"
        class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center"
    >
        <div
            class="bg-white rounded-xl p-6 w-[90%] max-w-5xl shadow-xl relative"
        >
            <button
                class="absolute top-3 right-3 text-gray-600 hover:text-black"
                @click="showSessionSummaryModal = false"
            >
                ✖
            </button>
            <h2 class="text-xl font-bold mb-4">セッション別 顔＋署名 一覧</h2>
            <div class="space-y-6 max-h-[70vh] overflow-y-auto">
                <div
                    v-for="item in sessionSummary"
                    :key="item.id"
                    class="border rounded-xl p-4 shadow"
                >
                    <div class="font-semibold mb-2">
                        Token: {{ item.token }} / Room: {{ item.code }}
                        <span class="text-gray-500 text-sm ml-2">
                            {{ item.time }}
                        </span>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="text-center">
                            <div class="text-sm mb-2">顔キャプチャ</div>
                            <img
                                v-if="item.face_image"
                                :src="item.face_image"
                                class="w-full rounded border"
                            />
                            <div v-else class="text-gray-400 text-sm">
                                顔なし
                            </div>
                        </div>
                        <div class="text-center">
                            <div class="text-sm mb-2">署名</div>
                            <img
                                v-if="item.signature_image"
                                :src="item.signature_image"
                                class="w-full rounded border bg-white"
                            />
                            <div v-else class="text-gray-400 text-sm">
                                署名なし
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.aspect-video {
    aspect-ratio: 16 / 9;
}
</style>
