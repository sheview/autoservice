<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    BarChart3,
    Briefcase,
    Building,
    Building2,
    CalendarCheck,
    CalendarDays,
    Circle,
    ClipboardList,
    FileText,
    HandHelping,
    HardDrive,
    LayoutGrid,
    ListChecks,
    Package,
    QrCode,
    Settings,
    ShieldCheck,
    ShoppingCart,
    Star,
    Tags,
    Users,
    Wrench,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// Icon names used in config/modules.php "navigation".
const icons: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    wrench: Wrench,
    'calendar-days': CalendarDays,
    'calendar-check': CalendarCheck,
    'clipboard-list': ClipboardList,
    'list-checks': ListChecks,
    'qr-code': QrCode,
    package: Package,
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
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
