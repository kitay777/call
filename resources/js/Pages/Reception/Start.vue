<script setup>
import { router } from "@inertiajs/vue3";
function startReception() {
    router.post(route("reception.start.post")); // Controller が in_progress へリダイレクトする
}
</script>

<template>
    <div class="min-h-screen grid place-items-center p-6">
        <button
            @click="startReception"
            class="text-3xl px-10 py-8 rounded-2xl shadow border bg-white"
        >
            オペレーターに接続して受付
        </button>
    </div>
</template>
