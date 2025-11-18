<script setup>
const props = defineProps({
    captures: Array
});
</script>

<template>
    <div class="p-6">
        <h1 class="text-2xl font-bold mb-6">顔キャプチャ一覧</h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <div
                v-for="item in props.captures"
                :key="item.id"
                class="border rounded-lg p-3 shadow bg-white"
            >
                <img
                    :src="item.image_url"
                    class="w-full aspect-video object-cover rounded mb-2"
                />

                <div class="text-sm text-gray-700">
                    <div><strong>ID:</strong> {{ item.id }}</div>
                    <div><strong>Token:</strong> {{ item.token }}</div>
                    <div><strong>Room:</strong> {{ item.code }}</div>
                    <div class="text-gray-500 text-xs mt-1">
                        {{ item.time }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
