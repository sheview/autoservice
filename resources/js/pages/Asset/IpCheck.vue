<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

interface IpRow {
    ip: string;
    // No assets = the address is free. A device of another branch is counted but not named.
    assets: { ulid: string | null; asset_code: string | null; name: string | null; customer: string | null; visible: boolean }[];
}

const props = defineProps<{
    filters: { range: string; customer: string };
    error: 'invalid' | 'too_large' | null;
    rows: IpRow[];
    summary: { total: number; used: number; duplicates: number };
    customers: { id: number; code: string; name: string }[];
    max: number;
    // ip-check.run: without it the form is shown disabled.
    can: { run: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: t('ip_check.title'), href: route('asset.ip-check') }];

const form = reactive({ ...props.filters });
const check = () => props.can.run && router.get(route('asset.ip-check'), { ...form }, { preserveState: true, preserveScroll: true });

// Shown rows only: the range is at most a few hundred addresses, so this is filtered here.
const show = ref<'all' | 'free' | 'used'>('all');
const shown = computed(() =>
    props.rows.filter((row) => (show.value === 'free' ? row.assets.length === 0 : show.value === 'used' ? row.assets.length > 0 : true)),
);

const copied = ref(false);
const copyFree = async () => {
    await navigator.clipboard.writeText(
        props.rows
            .filter((row) => row.assets.length === 0)
            .map((row) => row.ip)
            .join('\n'),
    );
    copied.value = true;
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('ip_check.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('ip_check.title')" :description="t('ip_check.description')" />

            <form class="flex flex-wrap items-end gap-3" @submit.prevent="check">
                <fieldset :disabled="!can.run" class="contents">
                    <div class="grid gap-1">
                        <Label for="range">{{ t('ip_check.range') }}</Label>
                        <Input id="range" v-model="form.range" required class="w-72 font-mono" :placeholder="t('ip_check.range_placeholder')" />
                    </div>
                    <div v-if="customers.length" class="grid gap-1">
                        <Label for="customer">{{ t('ip_check.customer') }}</Label>
                        <select id="customer" v-model="form.customer" :class="selectClass">
                            <option value="">{{ t('ip_check.all') }}</option>
                            <option value="own">{{ t('ip_check.own') }}</option>
                            <option v-for="customer in customers" :key="customer.id" :value="String(customer.id)">{{ customer.name }}</option>
                        </select>
                    </div>
                    <Button>{{ t('ip_check.check') }}</Button>
                </fieldset>
            </form>
            <p class="-mt-3 text-xs text-muted-foreground">
                {{ can.run ? t('ip_check.range_hint', { max }) : t('ip_check.cannot_run') }}
            </p>

            <p v-if="error" role="alert" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ t(`ip_check.${error}`, { max }) }}
            </p>

            <template v-if="rows.length">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-sm">
                        <p class="font-medium">
                            {{ t('ip_check.summary', { total: summary.total, used: summary.used, free: summary.total - summary.used }) }}
                        </p>
                        <p v-if="summary.duplicates" class="text-red-700 dark:text-red-400">
                            {{ t('ip_check.duplicates', { count: summary.duplicates }) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label for="show" class="text-sm text-muted-foreground">{{ t('ip_check.show') }}</label>
                        <select id="show" v-model="show" :class="selectClass">
                            <option value="all">{{ t('ip_check.show_all') }}</option>
                            <option value="free">{{ t('ip_check.show_free') }}</option>
                            <option value="used">{{ t('ip_check.show_used') }}</option>
                        </select>
                        <Button variant="outline" size="sm" type="button" :disabled="summary.total === summary.used" @click="copyFree">
                            {{ copied ? t('ip_check.copied') : t('ip_check.copy_free') }}
                        </Button>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-md border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="w-40 px-4 py-2 font-medium">{{ t('ip_check.ip') }}</th>
                                <th class="w-28 px-4 py-2 font-medium">{{ t('ip_check.status') }}</th>
                                <th class="px-4 py-2 font-medium">{{ t('ip_check.device') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in shown" :key="row.ip" class="border-t align-top">
                                <td class="px-4 py-1.5 font-mono">{{ row.ip }}</td>
                                <td class="px-4 py-1.5">
                                    <span
                                        v-if="row.assets.length === 0"
                                        class="rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800 dark:bg-green-950 dark:text-green-200"
                                    >
                                        {{ t('ip_check.free') }}
                                    </span>
                                    <span
                                        v-else-if="row.assets.length > 1"
                                        class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800 dark:bg-red-950 dark:text-red-200"
                                    >
                                        {{ t('ip_check.duplicate') }}
                                    </span>
                                    <span v-else class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ t('ip_check.used') }}</span>
                                </td>
                                <td class="px-4 py-1.5">
                                    <div v-for="(asset, i) in row.assets" :key="i">
                                        <template v-if="asset.visible && asset.ulid">
                                            <Link
                                                :href="route('asset.assets.show', asset.ulid)"
                                                class="font-mono text-xs text-primary underline-offset-4 hover:underline"
                                            >
                                                {{ asset.asset_code }}
                                            </Link>
                                            <span class="ml-2">{{ asset.name }}</span>
                                            <span v-if="asset.customer" class="ml-2 text-xs text-muted-foreground">{{ asset.customer }}</span>
                                        </template>
                                        <span v-else class="text-muted-foreground">{{ t('ip_check.hidden_device') }}</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">{{ t('ip_check.note') }}</p>
            </template>

            <p v-else-if="!error" class="rounded-md border px-4 py-8 text-center text-sm text-muted-foreground">{{ t('ip_check.empty') }}</p>
        </div>
    </AppLayout>
</template>
