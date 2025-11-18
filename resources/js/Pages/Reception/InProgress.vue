<!-- resources/js/Pages/Reception/InProgress.vue -->
<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from "vue";
import io from "socket.io-client";

const props = defineProps({ reception: Object });

const CSRF =
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content") || "";

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
    "4. 著しい過失・重過失・法令違反について理解しました。",
    "5. 駐車違反については自己責任で対応することを理解しました。",
    "6. 給油、オプション備品について理解しました。",
    "7. 延長について理解しました。",
    "8. 事故について理解しました。",
    "9. 故障について理解しました。",
    "10. 約款・利用規約について理解しました。",
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
let pollTimer = null;
let hbTimer = null;

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

// ====== 顔キャプチャ ======
async function captureFace() {
    const video = remoteVideoEl.value;
    if (!video) {
        console.warn("no remote video");
        return;
    }

    const canvas = document.createElement("canvas");
    canvas.width = video.videoWidth || 1280;
    canvas.height = video.videoHeight || 720;

    const ctxLocal = canvas.getContext("2d");
    ctxLocal.drawImage(video, 0, 0, canvas.width, canvas.height);

    const base64 = canvas.toDataURL("image/png");

    try {
        const res = await fetch(`/reception/${props.reception.token}/face-upload`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": CSRF,
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify({ image: base64 }),
        });

        const j = await res.json();
        console.log("Face uploaded:", j);
const payload = {
    roomId,
    phase: "face_captured",
    image: j.url,
};
console.log("[caller] emit phase-change payload:", payload);
        // 🔥 オペレーターへリアルタイム通知
        socket?.emit("phase-change", {
            roomId,
            phase: "face_captured",
            image: j.url, // ここは asset('storage/...') のフルURL
        });

        alert("本人確認のための写真を保存しました");
    } catch (e) {
        console.error("Upload failed:", e);
        alert("顔画像の送信に失敗しました。再度お試しください。");
    }
}

// ====== WebRTC ======
async function join() {
    if (!SIGNALING_URL) {
        console.warn("SIGNALING_URL not set");
        return;
    }
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

    if (localStream) {
        localStream.getTracks().forEach((t) => pc.addTrack(t, localStream));
    }

    pc.onicecandidate = (e) => {
        if (e.candidate && socket && roomId) {
            socket.emit("ice-candidate", { roomId, candidate: e.candidate });
        }
    };

    socket = io(SIGNALING_URL, { transports: ["websocket"] });
    socket.on("connect_error", (e) =>
        console.error("[caller socket connect_error]", e)
    );

    // stop 受信（オペレーター側終了）
    socket.on("stop", () => {
        console.log("[caller] stop received from operator");
        leaveAll();
        alert("通話が終了しました。ありがとうございました。");
        window.location.href = "/reception/start";
    });

    // ===== フェーズ受信 =====
    socket.on("phase-change", async ({ phase, image }) => {
        console.log("[caller] phase-change:", phase, image);

        if (phase === "important") {
            showDocument.value = true;
            showSignPad.value = false;
            currentIndex.value = 0;
            return;
        }

        if (phase === "sign") {
            showDocument.value = false;
            showSignPad.value = true;
            await nextTick();
            setTimeout(() => initSignaturePad(), 150);
            return;
        }

        if (phase === "verify") {
            console.log("[caller] verify received → capture face");
            await captureFace();
            return;
        }

        // important_check_x / important_done は既存通り
        if (phase.startsWith("important_check_")) {
            // ここはオペレーター向け通知なので caller 側では特に何もしない
            return;
        }
        if (phase === "important_done") {
            // ここも同様（必要ならUI更新）
            return;
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

    socket.once("connect", () => {
        console.log("[caller] socket connected, send offer");
        emitOffer();
    });

    socket.on("sdp-answer", async ({ sdp }) => {
        await pc.setRemoteDescription({ type: "answer", sdp });
    });

    socket.on("ice-candidate", ({ candidate }) => {
        if (candidate) {
            pc.addIceCandidate(candidate).catch((e) =>
                console.error("addIceCandidate error", e)
            );
        }
    });
}

// ===== チェック処理 =====
function checkItem(i) {
    checked.value[i] = true;
    if (socket?.connected) {
        socket.emit("phase-change", {
            roomId,
            phase: `important_check_${i + 1}`,
        });
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
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": CSRF,
        },
    });
    socket?.emit("phase-change", { roomId, phase: "important_done" });
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

    const getPos = (e) => {
        const p = e.touches ? e.touches[0] : e;
        const r = canvas.getBoundingClientRect();
        return { x: p.clientX - r.left, y: p.clientY - r.top };
    };

    const start = (e) => {
        drawing = true;
        const { x, y } = getPos(e);
        ctx.beginPath();
        ctx.moveTo(x, y);
    };
    const move = (e) => {
        if (!drawing) return;
        const { x, y } = getPos(e);
        ctx.lineTo(x, y);
        ctx.stroke();
    };
    const end = () => {
        drawing = false;
    };

    ["mousedown", "touchstart"].forEach((ev) =>
        canvas.addEventListener(ev, start)
    );
    ["mousemove", "touchmove"].forEach((ev) =>
        canvas.addEventListener(ev, move)
    );
    ["mouseup", "mouseleave", "touchend"].forEach((ev) =>
        canvas.addEventListener(ev, end)
    );
}

function clearSignature() {
    const canvas = signatureCanvas.value;
    if (!canvas || !ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
}

// ===== 署名送信 =====
async function submitSignature() {
    const canvas = signatureCanvas.value;
    if (!canvas) return;
    const img = canvas.toDataURL("image/png");

    try {
        const res = await fetch(`/reception/sign/${props.reception.token}`, {
            method: "POST",
            credentials: "include",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-TOKEN": CSRF,
            },
            body: JSON.stringify({ image: img }),
        });

        const data = await res.json();
        console.log("signature saved:", data);

        socket?.emit("phase-change", {
            roomId,
            phase: "signature_done",
            image: data.url,
        });

        showSignPad.value = false;
        alert("署名が完了しました。");
    } catch (e) {
        console.error("signature upload failed", e);
        alert("署名送信に失敗しました。ページを再読み込みしてください。");
    }
}

// ===== クリーンアップ =====
function leaveAll() {
    try {
        pc?.close();
    } catch {}
    try {
        socket?.disconnect();
    } catch {}
    try {
        localStream?.getTracks()?.forEach((t) => t.stop());
    } catch {}
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
    leaveAll();
});
</script>

<template>
    <main class="min-h-screen bg-slate-50 p-4 md:p-8">
        <!-- 🎥 ビデオ -->
        <section
            class="max-w-5xl mx-auto rounded-2xl overflow-hidden relative bg-black aspect-video"
        >
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
            <h3 class="text-lg font-semibold text-blue-700 mb-4">
                重要事項説明書
            </h3>
            <div class="border rounded overflow-hidden mb-6 shadow">
                <embed
                    src="/storage/jyuyo.pdf"
                    type="application/pdf"
                    class="w-full h-[60vh]"
                />
            </div>
            <div class="p-6 border rounded bg-white shadow text-center">
                <p class="text-lg font-semibold mb-4">
                    {{ checks[currentIndex] }}
                </p>
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
            <h2 class="text-lg font-semibold mb-4 text-blue-700">
                署名をお願いします
            </h2>
            <canvas
                ref="signatureCanvas"
                width="600"
                height="200"
                class="border rounded bg-gray-50 shadow mx-auto"
            ></canvas>
            <div class="mt-4 flex justify-center gap-3">
                <button
                    @click="clearSignature"
                    class="px-4 py-2 bg-gray-300 rounded"
                >
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
