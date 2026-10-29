<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { Paginated } from '@/types';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ paginator: Paginated<unknown> }>();

// The page numbers between "previous" and "next" (Laravel's window round the current page, "..." between).
const pages = computed(() => (props.paginator.last_page > 1 ? (props.paginator.links ?? []).slice(1, -1) : []));
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
        <span v-if="paginator.total > 0">
            {{ t('common.showing', { from: paginator.from ?? 0, to: paginator.to ?? 0, total: paginator.total }) }}
        </span>
        <span v-else />
        <nav class="flex flex-wrap items-center gap-1" :aria-label="t('common.pages')">
            <Button variant="outline" size="sm" :disabled="!paginator.prev_page_url" as-child>
                <Link v-if="paginator.prev_page_url" :href="paginator.prev_page_url" preserve-scroll>{{ t('common.previous') }}</Link>
                <span v-else>{{ t('common.previous') }}</span>
            </Button>
            <template v-for="(page, index) in pages" :key="index">
                <span v-if="!page.url" class="px-1">…</span>
                <Button v-else :variant="page.active ? 'default' : 'outline'" size="sm" class="min-w-9 tabular-nums" as-child>
                    <Link :href="page.url" preserve-scroll :aria-current="page.active ? 'page' : undefined">{{ page.label }}</Link>
                </Button>
            </template>
            <Button variant="outline" size="sm" :disabled="!paginator.next_page_url" as-child>
                <Link v-if="paginator.next_page_url" :href="paginator.next_page_url" preserve-scroll>{{ t('common.next') }}</Link>
                <span v-else>{{ t('common.next') }}</span>
            </Button>
        </nav>
    </div>
</template>
