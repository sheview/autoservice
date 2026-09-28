<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Briefcase, Building2, Circle, FileText, HardDrive, LayoutGrid, ShieldCheck, Tags, Users, type LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

// Icon names used in config/modules.php "navigation".
const icons: Record<string, LucideIcon> = {
    'layout-grid': LayoutGrid,
    'hard-drive': HardDrive,
    tags: Tags,
    'file-text': FileText,
    briefcase: Briefcase,
    users: Users,
    'shield-check': ShieldCheck,
    'building-2': Building2,
};

// Built on the server from config/modules.php (permission + enabled modules).
const mainNavItems = computed<NavItem[]>(() =>
    page.props.navigation.map((item) => ({ title: item.title, href: item.href, icon: icons[item.icon] ?? Circle })),
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
