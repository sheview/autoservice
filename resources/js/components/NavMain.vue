<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();
const { state } = useSidebar();

const isActive = (href: string) => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);

// Items in their sections, in the order the server sent them (a section with nothing the user may open is not there).
const sections = computed(() =>
    props.items.reduce<{ label: string | null; items: NavItem[] }[]>((list, item) => {
        const label = item.group ?? null;
        const last = list[list.length - 1];
        if (last && last.label === label) {
            last.items.push(item);
        } else {
            list.push({ label, items: [item] });
        }
        return list;
    }, []),
);

// Sections the user folded, remembered in this browser. The section of the page open now always
// shows, and so does every section while the sidebar is shrunk to icons (no labels to unfold with).
const STORAGE_KEY = 'nav.collapsed';
const readCollapsed = (): string[] => {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    } catch {
        return [];
    }
};
const collapsed = ref<string[]>(readCollapsed());
const toggle = (label: string) => {
    collapsed.value = collapsed.value.includes(label) ? collapsed.value.filter((l) => l !== label) : [...collapsed.value, label];
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(collapsed.value));
    } catch {
        // storage blocked: folding still works until the next page
    }
};
const isOpen = (section: { label: string | null; items: NavItem[] }) =>
    section.label === null || state.value === 'collapsed' || !collapsed.value.includes(section.label) || section.items.some((i) => isActive(i.href));
</script>

<template>
    <SidebarGroup v-for="(section, index) in sections" :key="section.label ?? `top-${index}`" class="px-2 py-0" :class="{ 'pt-1': section.label }">
        <SidebarGroupLabel v-if="section.label" as-child>
            <button
                type="button"
                class="w-full justify-between hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                :aria-expanded="isOpen(section)"
                @click="toggle(section.label)"
            >
                {{ section.label }}
                <ChevronDown class="transition-transform" :class="{ '-rotate-90': !isOpen(section) }" />
            </button>
        </SidebarGroupLabel>
        <SidebarMenu v-show="isOpen(section)">
            <SidebarMenuItem v-for="item in section.items" :key="item.href">
                <SidebarMenuButton as-child :is-active="isActive(item.href)" :tooltip="item.title">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
