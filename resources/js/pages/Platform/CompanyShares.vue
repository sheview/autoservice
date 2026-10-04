<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface ShareRow {
    id: number;
    company: string | null;
    abilities: string[];
    branches: string[];
    people_count: number;
    status: 'pending' | 'active' | 'revoked';
    reason: string | null;
    expires_on: string | null;
    granted_by_name: string | null;
    accepted_by_name: string | null;
    revoked_by_name: string | null;
}

defineProps<{ incoming: ShareRow[]; outgoing: ShareRow[] }>();

const page = usePage<SharedData>();
const errors = computed(() => page.props.errors as Record<string, string>);
const breadcrumbs: BreadcrumbItem[] = [{ title: t('shares.company_title'), href: route('platform.company-shares.index') }];

const decide = (share: ShareRow, decision: 'accept' | 'revoke') =>
    (decision === 'accept' || confirm(t('shares.confirm_revoke'))) &&
    router.post(route('platform.company-shares.decide', share.id), { decision }, { preserveScroll: true });

const statusClass: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    active: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    revoked: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('shares.company_title')" />

        <div class="space-y-8 p-4">
            <Heading :title="t('shares.company_title')" :description="t('shares.company_description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.share" />

            <section v-for="side in ['incoming', 'outgoing'] as const" :key="side" class="space-y-3">
                <h3 class="font-semibold">{{ t(`shares.${side}`) }}</h3>
                <ul v-if="(side === 'incoming' ? incoming : outgoing).length" class="divide-y rounded-lg border">
                    <li
                        v-for="share in side === 'incoming' ? incoming : outgoing"
                        :key="share.id"
                        class="flex flex-wrap items-start justify-between gap-3 p-4"
                    >
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold">{{ share.company }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs" :class="statusClass[share.status]">{{
                                    t(`shares.statuses.${share.status}`)
                                }}</span>
                            </div>
                            <div class="text-sm">
                                {{ share.abilities.map((a) => t(`shares.abilities.${a}`)).join(', ') }}
                                <span v-if="share.people_count"> · {{ t('shares.people_count', { count: share.people_count }) }}</span>
                            </div>
                            <div v-if="side === 'incoming'" class="text-sm text-muted-foreground">
                                {{
                                    share.branches.length
                                        ? t('shares.branches_count', { names: share.branches.join(', ') })
                                        : t('shares.all_branches')
                                }}
                            </div>
                            <div class="text-xs text-muted-foreground">
                                <span v-if="share.reason">{{ share.reason }} · </span>
                                <span v-if="share.granted_by_name">{{ t('shares.granted_by', { name: share.granted_by_name }) }}</span>
                                <span v-if="share.accepted_by_name"> · {{ t('shares.accepted_by', { name: share.accepted_by_name }) }}</span>
                                <span v-if="share.revoked_by_name"> · {{ t('shares.revoked_by', { name: share.revoked_by_name }) }}</span>
                                <span v-if="share.expires_on"> · {{ t('shares.expires', { date: share.expires_on }) }}</span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <Button v-if="side === 'incoming' && share.status === 'pending'" size="sm" @click="decide(share, 'accept')">{{
                                t('shares.accept')
                            }}</Button>
                            <Button v-if="share.status !== 'revoked'" size="sm" variant="ghost" class="text-red-600" @click="decide(share, 'revoke')">
                                {{ t('shares.revoke') }}
                            </Button>
                        </div>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">{{ t(`shares.none_${side}`) }}</p>
            </section>
        </div>
    </AppLayout>
</template>
