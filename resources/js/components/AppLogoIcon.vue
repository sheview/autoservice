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
    <!-- The app's mark: a building on a tile in the brand's violet, the same as public/favicon.svg. -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" :class="className" v-bind="$attrs" aria-hidden="true">
        <defs>
            <linearGradient id="app-logo-bg" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#7c3aed" />
                <stop offset="1" stop-color="#6366f1" />
            </linearGradient>
        </defs>
        <rect width="64" height="64" rx="14" fill="url(#app-logo-bg)" />
        <rect x="16" y="9" width="32" height="46" rx="4.5" fill="none" stroke="#fff" stroke-width="4.5" stroke-linejoin="round" />
        <g fill="#fff">
            <rect x="23" y="16.5" width="6.5" height="6.5" rx="1.5" />
            <rect x="34.5" y="16.5" width="6.5" height="6.5" rx="1.5" />
            <rect x="23" y="27" width="6.5" height="6.5" rx="1.5" />
            <rect x="34.5" y="27" width="6.5" height="6.5" rx="1.5" />
            <rect x="27.5" y="39" width="9" height="16" rx="1.5" />
        </g>
    </svg>
</template>
