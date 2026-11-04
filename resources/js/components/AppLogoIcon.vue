<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- The app's mark: the same image as the favicon (public/icon-192.png, sharp up to ~96px on retina screens). -->
    <img src="/icon-192.png?v=2" alt="" :class="['object-contain', className]" v-bind="$attrs" aria-hidden="true" />
</template>
