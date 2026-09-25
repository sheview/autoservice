<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useCan } from '@/composables/useCan';
import { t } from '@/lib/i18n';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Building2, LayoutGrid, ShieldCheck, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const can = useCan();

// The menu becomes config-driven (permissions + enabled modules) in step 04.
const mainNavItems = computed<NavItem[]>(() =>
    [
        { title: t('nav.dashboard'), href: '/dashboard', icon: LayoutGrid },
        { title: t('nav.users'), href: '/users', icon: Users, permission: 'user.view' },
        { title: t('nav.roles'), href: '/roles', icon: ShieldCheck, permission: 'role.view' },
        { title: t('nav.tenants'), href: '/platform/impersonation', icon: Building2, permission: 'platform.impersonate' },
    ].filter((item: NavItem) => !item.permission || can(item.permission)),
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
