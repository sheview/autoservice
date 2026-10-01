<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Company {
    ulid: string;
    name: string;
    subdomain: string;
    status: string;
    created_at: string | null;
    subscription: { state: string; ends_on: string | null; days_left: number | null };
}

/**
 * The platform's home page: the customer companies at a glance, the ones to renew, the newest.
 */
const props = defineProps<{
    platform: {
        counts: Record<'total' | 'active' | 'expiring' | 'grace' | 'locked' | 'not_started' | 'suspended', number>;
        renewals: Company[];
        newest: Company[];
        watch_days: number;
        can: { manage: boolean };
    };
}>();

const tiles = computed(() => {
    const c = props.platform.counts;
    return [
        { key: 'total', label: t('dashboard.platform.total'), value: c.total, alert: false },
        { key: 'active', label: t('dashboard.platform.active'), value: c.active, alert: false },
        { key: 'expiring', label: t('dashboard.platform.expiring', { days: 30 }), value: c.expiring, alert: c.expiring > 0 },
        { key: 'grace', label: t('dashboard.platform.grace'), value: c.grace, alert: c.grace > 0 },
        { key: 'locked', label: t('dashboard.platform.locked'), value: c.locked, alert: c.locked > 0 },
        { key: 'suspended', label: t('dashboard.platform.suspended'), value: c.suspended, alert: false },
    ];
});

const stateClass: Record<string, string> = {
    active: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    unlimited: 'bg-muted text-muted-foreground',
    not_started: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    expiring: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    grace: 'bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-200',
    locked: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};

const daysText = (days: number | null) =>
    days === null
        ? '-'
        : days > 0
          ? t('dashboard.platform.days_left_value', { days })
          : days === 0
            ? t('dashboard.platform.today')
            : t('dashboard.platform.days_ago', { days: -days });

const enter = (company: Company) => router.post(route('platform.impersonation.store', company.ulid));
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <Link
                v-for="tile in tiles"
                :key="tile.key"
                :href="route('platform.impersonation.index')"
                class="rounded-md border p-4 transition-colors hover:bg-muted/40"
                :class="{ 'border-red-300 dark:border-red-900': tile.alert }"
            >
                <p class="text-xs text-muted-foreground">{{ tile.label }}</p>
                <p class="text-2xl font-semibold" :class="{ 'text-red-700 dark:text-red-400': tile.alert }">{{ tile.value }}</p>
            </Link>
        </div>

        <!-- To renew -->
        <section class="space-y-2">
            <div>
                <h2 class="text-sm font-semibold">{{ t('dashboard.platform.renewals') }}</h2>
                <p class="text-xs text-muted-foreground">{{ t('dashboard.platform.renewals_hint', { days: platform.watch_days }) }}</p>
            </div>
            <div v-if="platform.renewals.length" class="overflow-x-auto rounded-md border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('dashboard.platform.company') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('dashboard.platform.state') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('dashboard.platform.ends_on') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('dashboard.platform.days_left') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="company in platform.renewals" :key="company.ulid" class="border-t">
                            <td class="px-4 py-2">
                                <div class="font-medium">{{ company.name }}</div>
                                <div class="font-mono text-xs text-muted-foreground">{{ company.subdomain }}</div>
                            </td>
                            <td class="px-4 py-2">
                                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="stateClass[company.subscription.state]">
                                    {{ t(`subscription.states.${company.subscription.state}`) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2">{{ company.subscription.ends_on }}</td>
                            <td
                                class="whitespace-nowrap px-4 py-2 font-medium tabular-nums"
                                :class="{ 'text-red-700 dark:text-red-400': (company.subscription.days_left ?? 1) <= 7 }"
                            >
                                {{ daysText(company.subscription.days_left) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">
                                <div class="flex justify-end gap-2">
                                    <Button v-if="platform.can.manage" size="sm" as-child>
                                        <Link :href="route('platform.tenants.edit', company.ulid)">{{ t('dashboard.platform.renew') }}</Link>
                                    </Button>
                                    <Button size="sm" variant="outline" @click="enter(company)">{{ t('dashboard.platform.enter') }}</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="rounded-md border px-4 py-6 text-center text-sm text-muted-foreground">{{ t('dashboard.platform.no_renewals') }}</p>
        </section>

        <!-- Newest companies -->
        <section class="space-y-2">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">{{ t('dashboard.platform.newest') }}</h2>
                <Link :href="route('platform.impersonation.index')" class="text-sm text-primary underline-offset-4 hover:underline">
                    {{ t('dashboard.platform.all_companies') }}
                </Link>
            </div>
            <ul class="divide-y rounded-md border text-sm">
                <li v-for="company in platform.newest" :key="company.ulid" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2">
                    <span class="font-medium">{{ company.name }}</span>
                    <span class="font-mono text-xs text-muted-foreground">{{ company.subdomain }}</span>
                    <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs" :class="stateClass[company.subscription.state]">
                        {{ t(`subscription.states.${company.subscription.state}`) }}
                    </span>
                    <span v-if="company.status === 'suspended'" class="rounded-full bg-muted px-2 py-0.5 text-xs">{{
                        t('impersonation.status.suspended')
                    }}</span>
                    <span class="ml-auto text-xs text-muted-foreground">{{ t('dashboard.platform.joined') }} {{ dateTime(company.created_at) }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
