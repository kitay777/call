<script setup>
import { computed, ref, onMounted, onUnmounted } from "vue";
import { usePage, router } from "@inertiajs/vue3";

const page = usePage();
const ops = computed(() => page.props.operators ?? []);
const counts = computed(() => page.props.counts ?? {});

// 既存の state ボード用（そのまま）
const buckets = computed(() => ({
    available: ops.value.filter((o) => o.state === "available"),
    busy: ops.value.filter((o) => o.state === "busy"),
    break: ops.value.filter((o) => o.state === "break"),
    off_today: ops.value.filter((o) => o.state === "off_today"),
}));

// ★ 追加：待機中受付（●表示用）
const waiting = ref([]);
let timer;
async function pollWaiting() {
    const res = await fetch("/operation/waiting-list", {
        headers: { "X-Requested-With": "XMLHttpRequest" },
    });
    waiting.value = await res.json();
}
function openSession(token) {
    router.visit(`/operator/session/${token}`);
}


onMounted(() => {
    pollWaiting();
    timer = setInterval(pollWaiting, 5000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <main class="p-6 space-y-6">
        <!-- 追加：待機中の受付（丸インジケータ） -->
        <section>
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-sm font-semibold text-slate-700">
                    待機中の受付
                </h2>
                <div class="text-xs text-slate-500">5秒ごと更新</div>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <button
                    v-for="r in waiting"
                    :key="r.id"
                    @click="openSession(r.token)"
                    class="flex items-center justify-between p-4 rounded-xl border bg-white hover:bg-slate-50"
                >
                    <div class="text-left">
                        <div class="font-medium">
                            受付: {{ r.token.slice(0, 8) }}
                        </div>
                        <div class="text-xs text-slate-500">
                            status: {{ r.status }}
                        </div>
                    </div>
                    <!-- ● -->
                    <div
                        :class="[
                            'w-3 h-3 rounded-full',
                            r.has_video
                                ? 'bg-red-500 shadow-[0_0_0_3px_rgba(239,68,68,0.25)] animate-pulse'
                                : 'bg-slate-300',
                        ]"
                    ></div>
                </button>

                <div
                    v-if="!waiting.length"
                    class="text-slate-400 text-sm p-4 border rounded-xl text-center"
                >
                    受付はありません
                </div>
            </div>
        </section>

        <!-- ↓ここから下は、前に作った4つの状態ボード（そのまま） -->
        <!-- 待機中 / 接客中 / 休憩中 / 本日休業 の各セクション ... -->
    </main>
</template>
