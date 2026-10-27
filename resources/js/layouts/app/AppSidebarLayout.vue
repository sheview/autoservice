<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import ModuleTabs from '@/components/ModuleTabs.vue';
import SampleCompanyBanner from '@/components/SampleCompanyBanner.vue';
import SubscriptionNotice from '@/components/SubscriptionNotice.vue';
import UploadNotice from '@/components/UploadNotice.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar">
            <ImpersonationBanner />
            <SampleCompanyBanner />
            <SubscriptionNotice />
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <ModuleTabs />
            <slot />
            <UploadNotice />
        </AppContent>
    </AppShell>
</template>
