<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

interface Props {
    class?: string;
}

defineProps<Props>();

const page = usePage<SharedData>();
</script>

<template>
    <!-- ! beats the sidebar button's size for its icons (16px); shrunk to an icon in a collapsed sidebar. -->
    <AppLogoIcon class="aspect-square !size-11 shrink-0 drop-shadow-sm group-data-[collapsible=icon]:!size-8" />
    <div class="ml-1 grid flex-1 text-left">
        <span class="mb-1 truncate text-base font-bold leading-none">{{ page.props.name }}</span>
        <span v-if="page.props.tenant" class="truncate text-xs text-muted-foreground">{{ page.props.tenant.name }}</span>
    </div>
</template>
