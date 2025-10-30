<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import io from "socket.io-client";

const props = defineProps({
    reception: { type: Object, required: true },
    signalingUrl: { type: String, default: "" },
});

const CSRF =
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content") || "";

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

let hbTimer;
function heartbeat() {
    fetch(`/reception/heartbeat/${props.reception.token}`, {
        headers: { "X-Requested-With": "XMLHttpRequest" },
    }).catch(() => {});
}

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

async function startLocalMedia() {
    try {
        localStream = await navigator.mediaDevices.getUserMedia({
            video: { width: 1280, height: 720 },
            audio: true,
        });
        console.log(
            "[callee] local tracks",
            localStream.getTracks().map((t) => t.kind)
        );

        if (localVideo.value) {
            localVideo.value.srcObject = localStream;
            localVideo.value.muted = true;
            localVideo.value.playsInline = true;
            await localVideo.value.play().catch(() => {});
        }
    } catch (e) {
        const name = e?.name || "";
        errorMsg.value =
            name === "NotAllowedError" || name === "SecurityError"
                ? "カメラ/マイクの許可が必要です。ブラウザの設定を確認してください。"
                : e?.message || String(e);
        console.error(e);
        throw e;
    }
}

async function joinCall() {
    try {
        if (!SIGNALING_URL) throw new Error("SIGNALING_URL が未設定です");
        errorMsg.value = "";
        connecting.value = true;

        // === roomId 確定 ===
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
            if (res.ok) {
                const data = await res.json();
                roomId = data?.roomId || "";
            }
        } catch {}
        if (!roomId)
            roomId =
                props.reception?.meta?.room_id ||
                props.reception?.code ||
                props.reception?.token ||
                "";
        if (!roomId) throw new Error("roomId not decided");

        console.log("[callee] roomId", roomId);

        // === RTCPeerConnection ===
        pc = new RTCPeerConnection({
            iceServers: [{ urls: "stun:stun.l.google.com:19302" }],
        });

        // === Remote 映像 ===
        remoteStream = new MediaStream();
        if (remoteVideo.value) {
            remoteVideo.value.srcObject = remoteStream;
            remoteVideo.value.autoplay = true;
            remoteVideo.value.playsInline = true;
            // ⚠️ muted削除：受信側は音声を再生可能に
            remoteVideo.value.muted = false;
        }

        pc.ontrack = (e) => {
            console.log(
                "[callee] ontrack event",
                e.track?.kind,
                e.streams?.length
            );
            const stream = e.streams?.[0];
            if (!stream) return;
            if (remoteVideo.value) {
                remoteVideo.value.srcObject = stream;
                remoteVideo.value.muted = false;
                remoteVideo.value.playsInline = true;
                remoteVideo.value.autoplay = true;
                remoteVideo.value
                    .play()
                    .then(() => console.log("[callee] remote video playing"))
                    .catch((err) =>
                        console.warn("[callee] play() failed", err)
                    );
            }
        };

        // === Localメディア ===
        await startLocalMedia();
        localStream.getTracks().forEach((t) => {
            console.log("[callee] addTrack", t.kind);
            pc.addTrack(t, localStream);
        });

        // === ICE ===
        pc.onicecandidate = (e) => {
            if (e.candidate && socket && roomId)
                socket.emit("ice-candidate", {
                    roomId,
                    candidate: e.candidate,
                });
        };

        // === Socket.IO ===
        socket = io(SIGNALING_URL, { transports: ["websocket"] });
        socket.on("connect_error", (e) => console.error("[callee socket]", e));

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
                console.log("[callee] sent own offer");
            } catch (e) {
                console.error("[callee] offer failed", e);
            }
        };
        // === 自分の offer に対する answer を受信 ===
        socket.on("sdp-answer", async ({ sdp }) => {
            try {
                await pc.setRemoteDescription({ type: "answer", sdp });
                connected.value = true; // ✅ KurentoからAnswerを受け取ったら接続完了
                connecting.value = false;
                console.log("[callee] got answer → connection established ✅");
            } catch (e) {
                console.error("[callee sdp-answer error]", e);
            }
        });

        socket.on("phase-change", ({ phase }) => {
            if (phase.startsWith("important_check_")) {
                const num = phase.split("_")[2];
                alert(`☑ ユーザーが ${num} 番目の項目を確認しました。`);
            } else if (phase === "important_done") {
                alert(
                    "✅ ユーザーがすべての項目を確認し、同意を完了しました。"
                );
            }
        });
        // === join-room の後 ===
        socket.once("peer-joined", async ({ roomId }) => {
            console.log("[callee] peer joined, waiting offer...");
        });

        // === 受信専用 offer 処理 ===
        socket.on("sdp-offer", async ({ sdp, roomId: rid }) => {
            try {
                if (!sdp || (rid && rid !== roomId)) return;
                console.log("[callee] got caller offer");
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
            } catch (e) {
                console.error("[callee sdp-offer error]", e);
            }
        });

        socket.on("ice-candidate", async ({ candidate }) => {
            try {
                if (candidate) await pc.addIceCandidate(candidate);
            } catch {}
        });

        // === join-room 後にOffer送信 ===
        socket.once("connect", () => {
            console.log("[callee] socket connected, joining room...");
            socket.emit("join-room", { roomId, role: "callee" }, () => {
                console.log("[callee] joined room, waiting for caller...");
            });
        });

        // 🔹 caller が部屋に入ってから offer 送信
        socket.on("peer-joined", ({ role }) => {
            if (role === "caller") {
                console.log("[callee] caller joined, sending offer...");
                sendOwnOffer();
            }
        });
    } catch (e) {
        errorMsg.value = e?.message || String(e);
        leaveCall();
    }
    console.log(
        "[callee] senders:",
        pc.getSenders().map((s) => ({
            kind: s.track?.kind,
            readyState: s.track?.readyState,
            enabled: s.track?.enabled,
        }))
    );
}

function leaveCall() {
    try {
        socket && roomId && socket.emit("stop", { roomId });
    } catch {}
    cleanup();
}

onMounted(() => {
    heartbeat();
    hbTimer = setInterval(heartbeat, 5000);
});
onBeforeUnmount(() => {
    if (hbTimer) clearInterval(hbTimer);
    cleanup();
});
function sendPhase(phase) {
    if (!socket || !socket.connected) {
        console.warn("[callee] socket not ready");
        return;
    }
    if (!roomId) {
        console.warn("[callee] no roomId");
        return;
    }

    // ✅ connected が false のときは再試行する
    if (!connected.value) {
        console.warn("[callee] not connected yet, retrying...");
        setTimeout(() => sendPhase(phase), 1000);
        return;
    }

    socket.emit("phase-change", { roomId, phase });
    console.log("[callee] sent phase:", phase);
}
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-6 p-6">
        <div class="space-y-3">
            <div
                class="aspect-video bg-black rounded-xl border overflow-hidden grid place-items-center"
            >
                <!-- ⚠️ muted 削除 -->
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
                >
                    接続中
                </span>
                <span
                    v-else-if="connecting"
                    class="text-slate-500 text-sm self-center"
                >
                    接続準備中…
                </span>
            </div>

            <p v-if="errorMsg" class="text-red-600 text-sm">
                Error: {{ errorMsg }}
            </p>
        </div>
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
        </aside>
    </div>
</template>

<style scoped>
.aspect-video {
    aspect-ratio: 16 / 9;
}
</style>
