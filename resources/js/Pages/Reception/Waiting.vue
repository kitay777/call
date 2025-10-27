<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { router } from "@inertiajs/vue3";

const props = defineProps<{
    reception: { token: string; status: string };
}>();

const waitingCount = ref<number>(0);
const etaMinutes = ref<number>(0);
let timer: number | undefined;
let hbTimer: number | undefined;

/** ステータス/人数/待ち時間を取得 */
const poll = async () => {
    try {
        const res = await fetch(
            route("reception.status", props.reception.token),
            { headers: { "X-Requested-With": "XMLHttpRequest" } }
        );
        const json = await res.json();
        waitingCount.value = json.waitingCount ?? 0;
        etaMinutes.value = json.etaMinutes ?? 0;

        // 割り当てられたら（waiting以外になったら）次の画面へ
        if (json.status !== "waiting") {
            router.visit(route("reception.in_progress", props.reception.token));
        }
    } catch (e) {
        console.debug("poll error", e);
    }
};

/** 心拍（オペ側に「生きている」ことを知らせる） */
const heartbeat = async () => {
    try {
        await fetch(`/reception/heartbeat/${props.reception.token}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });
    } catch (e) {
        console.debug("heartbeat error", e);
    }
};

onMounted(() => {
    poll();
    timer = window.setInterval(poll, 5000);
    heartbeat();
    hbTimer = window.setInterval(heartbeat, 5000);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
    if (hbTimer) clearInterval(hbTimer);
});
</script>

<template>
    <main class="min-h-screen grid place-items-center bg-slate-50 p-6">
        <section class="w-full max-w-2xl bg-white rounded-2xl shadow-xl border p-8">
            <!-- 上部メッセージ -->
            <div class="text-center space-y-3">
                <p class="text-2xl md:text-3xl font-semibold">
                    オペレーターに手続き受付中です。
                </p>
                <p class="text-lg md:text-xl text-slate-500">
                    しばらくお待ちください…
                </p>
            </div>

            <!-- メトリクス -->
            <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-xl border px-6 py-5 text-center">
                    <div class="text-sm text-slate-500">待ち時間</div>
                    <div class="mt-1 text-4xl font-bold tabular-nums">
                        {{ etaMinutes }}<span class="text-xl ml-1">分</span>
                    </div>
                </div>
                <div class="rounded-xl border px-6 py-5 text-center">
                    <div class="text-sm text-slate-500">待機人数</div>
                    <div class="mt-1 text-4xl font-bold tabular-nums">
                        {{ waitingCount }}<span class="text-xl ml-1">人</span>
                    </div>
                </div>
            </div>

            <!-- 補足 -->
            <p class="mt-6 text-center text-sm text-slate-500">
                表示は5秒ごとに自動更新されます。
            </p>
        </section>
    </main>
</template>
