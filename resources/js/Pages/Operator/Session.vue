<script setup>
import { ref } from "vue";

const props = defineProps({ reception: Object });
const flash = ref(false);

async function go(next) {
    if (next === "verify") {
        flash.value = true;
        setTimeout(() => (flash.value = false), 600); // シャーン
    }
    await fetch(`/reception/advance/${props.reception.token}`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')
                .content,
        },
        body: JSON.stringify({ next }),
    });
    // ← 画面遷移なし（オペ側は据え置き）
}
</script>

<template>
    <main class="min-h-screen bg-slate-50 p-4 md:p-8">
        <div class="max-w-6xl mx-auto grid md:grid-cols-[1fr_300px] gap-6">
            <!-- 左：お客様映像（仮） -->
            <section class="video-wrap" :class="{ flash }">
                <div class="placeholder">
                    📷 お客様の映像（後でremoteに差し替え）
                </div>
            </section>

            <!-- 右：操作パネル -->
            <aside class="panel">
                <div class="title">ステップ操作</div>
                <button class="btn" @click="go('verify')">本人確認</button>
                <button class="btn" @click="go('important')">
                    重要事項説明
                </button>
                <button class="btn" @click="go('sign')">署名</button>
            </aside>
        </div>
    </main>
</template>

<style scoped>
.panel {
    @apply bg-white rounded-2xl border shadow-sm p-4 space-y-3;
}
.title {
    @apply text-sm text-slate-600 mb-1;
}
.btn {
    @apply w-full px-4 py-3 rounded-xl border bg-white hover:bg-slate-50;
}
.video-wrap {
    position: relative;
    background: #000;
    border-radius: 1rem;
    aspect-ratio: 16/9;
    overflow: hidden;
}
.placeholder {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    color: #fff8;
}
.video-wrap.flash::after {
    content: "";
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.6);
    animation: flash 0.5s ease;
    pointer-events: none;
}
@keyframes flash {
    from {
        opacity: 0.9;
    }
    to {
        opacity: 0;
    }
}
</style>
