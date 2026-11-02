<script setup lang="ts">
import { t } from '@/lib/i18n';
import { computed } from 'vue';

/** How far a running import is. Rounded down, so 100% only shows once it is really done. */
const props = defineProps<{ total: number; done: number }>();

const percent = computed(() => (props.total > 0 ? Math.floor((props.done / props.total) * 100) : 0));
</script>

<template>
    <div v-if="total > 0" class="min-w-48 space-y-1">
        <div class="h-2 overflow-hidden rounded-full bg-muted">
            <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${percent}%` }" />
        </div>
        <p class="text-xs text-muted-foreground">{{ t('asset_imports.progress', { percent, done, total }) }}</p>
    </div>
    <p v-else class="text-xs text-muted-foreground">{{ t('asset_imports.reading') }}</p>
</template>
