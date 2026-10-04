<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';

defineProps<{ active: 'items' | 'kpi' }>();
</script>

<template>
    <nav class="flex gap-1 border-b text-sm">
        <Link
            v-for="tab in [
                { key: 'items', href: route('reporting.people.index') },
                { key: 'kpi', href: route('reporting.people.kpi') },
            ]"
            :key="tab.key"
            :href="tab.href"
            class="-mb-px border-b-2 px-4 py-2"
            :class="active === tab.key ? 'border-primary font-semibold' : 'border-transparent text-muted-foreground hover:text-foreground'"
        >
            {{ t(`summary.tab_${tab.key}`) }}
        </Link>
    </nav>
</template>
