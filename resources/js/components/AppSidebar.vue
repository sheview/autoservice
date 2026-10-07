<script lang="ts">
// Every page mounts its own layout, so the sidebar is built anew on each visit and would jump back
// to the top. Its scroll position is kept here (module scope outlives the component) and put back.
let savedScroll = 0;
</script>

<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
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
    bell: Bell,
    'book-open': BookOpen,
    'scroll-text': ScrollText,
};

// Built on the server from config/modules.php (permission + enabled modules).
const mainNavItems = computed<NavItem[]>(() =>
    page.props.navigation.map((item) => ({ title: item.title, href: item.href, icon: icons[item.icon] ?? Circle, group: item.group })),
);
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
