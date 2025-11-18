<script setup>
import { computed, ref, onMounted, onUnmounted } from "vue";
import { usePage, router } from "@inertiajs/vue3";

const page = usePage();
const ops = computed(() => page.props.operators ?? []);
const counts = computed(() => page.props.counts ?? {});

// === セッション詳細モーダル ===
const showSessionDetail = ref(false);
const sessionDetail = ref(null);

async function loadSessionDetail(token) {
    try {
        const res = await fetch(`/operation/session-detail-json/${token}`);
        sessionDetail.value = await res.json();
        showSessionDetail.value = true;
    } catch (e) {
        console.error("session detail error:", e);
    }
}

// 既存ボード
const buckets = computed(() => ({
    available: ops.value.filter((o) => o.state === "available"),
    busy: ops.value.filter((o) => o.state === "busy"),
    break: ops.value.filter((o) => o.state === "break"),
    off_today: ops.value.filter((o) => o.state === "off_today"),
}));

// 待機中受付リスト
const waiting = ref([]);
let timer;

async function pollWaiting() {
    try {
        const res = await fetch("/operation/waiting-list", {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });
        if (res.ok) waiting.value = await res.json();
    } catch (e) {
        console.error("[pollWaiting error]", e);
    }
}

function openSession(token) {
    router.visit(`/operator/session/${token}`);
}

function goBack() {
    router.visit("/dashboard");
}

onMounted(() => {
    pollWaiting();
    timer = setInterval(pollWaiting, 5000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <main class="p-6 space-y-6">
        <!-- 上部に戻るボタン -->
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-lg font-semibold text-slate-800">
                オペレーター管理
            </h1>
            <button
                @click="goBack"
                class="px-4 py-2 text-sm rounded-lg border bg-white hover:bg-slate-50"
            >
                ← 戻る
            </button>
        </div>

        <!-- 追加：待機中の受付（丸インジケータ） -->
        <section>
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-sm font-semibold text-slate-700">
                    待機中の受付
                </h2>
                <div class="text-xs text-slate-500">5秒ごと更新</div>
            </div>

<div
    v-for="r in waiting"
    :key="r.id"
    class="p-4 rounded-xl border bg-white hover:bg-slate-50 flex flex-col gap-3"
>

    <!-- ① セッションへ入る（既存） -->
    <button
        @click="openSession(r.token)"
        class="flex items-center justify-between w-full"
    >
        <div class="text-left">
            <div class="font-medium">受付: {{ r.token.slice(0, 8) }}</div>
            <div class="text-xs text-slate-500">
                status: {{ r.status }}
            </div>
        </div>
        <div
            :class="[
                'w-3 h-3 rounded-full',
                r.alive
                    ? 'bg-green-500 shadow-[0_0_0_3px_rgba(34,197,94,0.25)] animate-pulse'
                    : (r.has_video
                        ? 'bg-red-500 shadow-[0_0_0_3px_rgba(239,68,68,0.25)] animate-pulse'
                        : 'bg-slate-300')
            ]"
        />
    </button>

    <!-- ② 顔＋署名を見る（新規追加） -->
    <button
        @click="loadSessionDetail(r.token)"
        class="px-3 py-1 text-xs rounded bg-indigo-600 text-white hover:bg-indigo-700"
    >
        顔＋署名を見る
    </button>
    

</div>
        </section>

        <!-- ↓ 以下は既存の状態ボード -->
        <!-- ... -->
    </main>
    <!-- セッション詳細モーダル -->
    <div
        v-if="showSessionDetail"
        class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center"
    >
        <div class="bg-white p-6 rounded-xl w-[90%] max-w-3xl shadow relative">
            <button
                class="absolute top-3 right-3 text-gray-600 hover:text-black"
                @click="showSessionDetail = false"
            >
                ✖
            </button>

            <h2 class="text-lg font-semibold mb-4">
                セッション詳細（顔＋署名）
            </h2>

            <div v-if="sessionDetail" class="space-y-4">
                <div class="text-sm text-gray-700">
                    Token: {{ sessionDetail.token }} / Room:
                    {{ sessionDetail.code }} /
                    {{ sessionDetail.time }}
                </div>
                <br>
                <a href="{{ sessionDetail.face_image }}" target="_blank" rel="noopener noreferrer">
                {{ sessionDetail.face_image }}</a>
                </br>
                <a href="{{ sessionDetail.signature_image }}" target="_blank" rel="noopener noreferrer">
                {{ sessionDetail.signature_image }}</a> 
                </br>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h3 class="text-sm font-medium mb-2">顔キャプチャ</h3>
                        
                        <img
                            v-if="sessionDetail.face_image"
                            :src="sessionDetail.face_image"
                            class="w-full rounded border"
                        />
                        <p v-else class="text-gray-400 text-xs">なし</p>
                    </div>

                    <div>
                        <h3 class="text-sm font-medium mb-2">署名</h3>
                        <img
                            v-if="sessionDetail.signature_image"
                            :src="sessionDetail.signature_image"
                            class="w-full rounded border bg-white"
                        />
                        <p v-else class="text-gray-400 text-xs">なし</p>
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
