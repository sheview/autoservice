<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';
import { Link } from '@inertiajs/vue3';

defineProps<{ paginator: Paginated<unknown> }>();
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
        <span v-if="paginator.total > 0">
            {{ t('common.showing', { from: paginator.from ?? 0, to: paginator.to ?? 0, total: paginator.total }) }}
        </span>
        <span v-else />
        <div class="flex gap-2">
            <Button variant="outline" size="sm" :disabled="!paginator.prev_page_url" as-child>
                <Link v-if="paginator.prev_page_url" :href="paginator.prev_page_url" preserve-scroll>{{ t('common.previous') }}</Link>
                <span v-else>{{ t('common.previous') }}</span>
            </Button>
            <Button variant="outline" size="sm" :disabled="!paginator.next_page_url" as-child>
                <Link v-if="paginator.next_page_url" :href="paginator.next_page_url" preserve-scroll>{{ t('common.next') }}</Link>
                <span v-else>{{ t('common.next') }}</span>
            </Button>
        </div>
    </div>
</template>
