<script lang="ts">
// Every page mounts its own layout, so the sidebar is built anew on each visit and would jump back
// to the top. Its scroll position is kept here (module scope outlives the component) and put back.
let savedScroll = 0;
</script>

<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { t } from '@/lib/i18n';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    BarChart3,
    Bell,
    BookOpen,
    Briefcase,
    Building,
    Building2,
    CalendarCheck,
    CalendarDays,
    Circle,
    ClipboardList,
    DoorOpen,
    FileText,
    FolderKanban,
    Gauge,
    HandHelping,
    HardDrive,
    LayoutGrid,
    ListChecks,
    MapPin,
    Network,
    Package,
    QrCode,
    ScanSearch,
    ScrollText,
    Server,
    Settings,
    Share2,
    ShieldCheck,
    ShoppingCart,
    Star,
    Tags,
    UserCheck,
    UserSearch,
    Users,
    Warehouse,
    Wrench,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const content = ref<{ $el: HTMLElement } | null>(null);
const rememberScroll = (event: Event) => (savedScroll = (event.target as HTMLElement).scrollTop);
onMounted(() => nextTick(() => content.value?.$el.scrollTo({ top: savedScroll })));

// Icon names used in config/modules.php "navigation".
const icons: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    'door-open': DoorOpen,
    server: Server,
    wrench: Wrench,
    'calendar-days': CalendarDays,
    'calendar-check': CalendarCheck,
    'clipboard-list': ClipboardList,
    'list-checks': ListChecks,
    'map-pin': MapPin,
    'qr-code': QrCode,
    package: Package,
    network: Network,
    'arrow-left-right': ArrowLeftRight,
    star: Star,
    'bar-chart-3': BarChart3,
    'hard-drive': HardDrive,
    'hand-helping': HandHelping,
    tags: Tags,
    'file-text': FileText,
    briefcase: Briefcase,
    users: Users,
    'shield-check': ShieldCheck,
    building: Building,
    'building-2': Building2,
    settings: Settings,
    'shopping-cart': ShoppingCart,
    'scan-search': ScanSearch,
    'share-2': Share2,
    'user-check': UserCheck,
    'user-search': UserSearch,
    'folder-kanban': FolderKanban,
    gauge: Gauge,
    bell: Bell,
    'book-open': BookOpen,
    'scroll-text': ScrollText,
    warehouse: Warehouse,
};

// Built on the server from config/modules.php (permission + enabled modules).
// Items of a tab set ("pm.overview", "pm.plans", ...) are one entry here, the first the user may open,
// plus the pages pinned in config (used every day); the others are tabs on top of the pages
// (ModuleTabs). Each page of a set marks one entry current: its pinned page, else a pinned page of
// its tab, else the set's entry. A set named in nav_sets (settings) shows that name and a gear.
const mainNavItems = computed<NavItem[]>(() => {
    const navigation = page.props.navigation;
    const setOf = (item: { tab: string | null }) => item.tab?.split('.')[0] ?? null;
    const sets = new Set<string>();
    const shown = navigation.filter((item) => {
        const set = setOf(item);
        if (!set || item.pinned) return true;
        if (sets.has(set)) return false;
        sets.add(set);
        return true;
    });
    // The entry each page of a set belongs to.
    const owner = (item: (typeof navigation)[number]) =>
        item.pinned
            ? item
            : (shown.find((entry) => entry.pinned && entry.tab === item.tab) ?? shown.find((entry) => !entry.pinned && setOf(entry) === setOf(item)));

    return shown.map((item) => {
        const set = setOf(item);
        const also = set ? navigation.filter((other) => setOf(other) === set && owner(other) === item).map((other) => other.href) : [];
        const named = set && !item.pinned ? t(`nav_sets.${set}`) : '';
        return named && named !== `nav_sets.${set}`
            ? { title: named, href: item.href, icon: Settings, group: item.group, also }
            : { title: item.title, href: item.href, icon: icons[item.icon] ?? Circle, group: item.group, also };
    });
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" class="h-14" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent ref="content" @scroll.passive="rememberScroll">
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
