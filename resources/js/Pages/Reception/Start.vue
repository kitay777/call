<script setup lang="ts">
import { router } from "@inertiajs/vue3";
const start = () => router.post(route("reception.start.post"));
</script>
<template>
    <div class="min-h-screen grid place-items-center p-6">
        <button
            @click="start"
            class="text-3xl px-10 py-8 rounded-2xl shadow border"
        >
            オペレーターに接続して受付
        </button>
    </div>
</template>
