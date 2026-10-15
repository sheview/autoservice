<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The tab bar on top of a module's pages, from the sidebar items marked "tab" in config/modules.php
 * ("{set}.{tab}", already filtered by permission and enabled modules). The page belongs to the item
 * whose address is the longest start of its own; its set gives the tabs, its tab the sub-tabs.
 * Nothing shows on other pages, or when there is only one page to go to.
 */
const page = usePage<SharedData>();

const tabbed = computed(() => page.props.navigation.filter((item) => item.tab));
const path = computed(() => page.url.split('?')[0]);

const current = computed(() => {
    const matches = tabbed.value.filter((item) => path.value === item.href || path.value.startsWith(`${item.href}/`));
    return matches.sort((a, b) => b.href.length - a.href.length)[0] ?? null;
});

const set = computed(() => current.value?.tab?.split('.')[0] ?? null);
const tabs = computed(() => {
    if (!set.value) return [];
    const byTab = new Map<string, { key: string; href: string }>();
    for (const item of tabbed.value) {
        const [itemSet, key] = item.tab!.split('.');
        if (itemSet === set.value && !byTab.has(key)) byTab.set(key, { key, href: item.href });
    }
    return [...byTab.values()];
});
const subTabs = computed(() => tabbed.value.filter((item) => item.tab === current.value?.tab));
const show = computed(() => tabs.value.length > 1 || subTabs.value.length > 1);
</script>

<template>
    <nav v-if="current && show" class="border-b px-4" :aria-label="t(`nav_groups.${set}`)">
        <div v-if="tabs.length > 1" class="-mb-px flex gap-1 overflow-x-auto">
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href"
                class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors"
                :class="
                    current.tab === `${set}.${tab.key}`
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground'
                "
                :aria-current="current.tab === `${set}.${tab.key}` ? 'page' : undefined"
            >
                {{ t(`nav_tabs.${set}.${tab.key}`) }}
            </Link>
        </div>
        <div v-if="subTabs.length > 1" class="flex gap-1 overflow-x-auto py-2">
            <Link
                v-for="item in subTabs"
                :key="item.href"
                :href="item.href"
                class="whitespace-nowrap rounded-full px-3 py-1 text-xs transition-colors"
                :class="
                    item.href === current.href
                        ? 'bg-primary/10 font-semibold text-primary'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                :aria-current="item.href === current.href ? 'page' : undefined"
            >
                {{ item.title }}
            </Link>
        </div>
    </nav>
</template>
