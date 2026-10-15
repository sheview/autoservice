<script setup lang="ts">
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';

export interface PmSignWork {
    number: string;
    title: string | null;
    customer: string | null;
    completed_at: string | null;
    summary: string | null;
    totals: Record<string, number>;
    failures: { asset: string | null; failures: string[]; note: string | null }[];
    signatures: { role: string; name: string; signed_at: string }[];
    locked_at: string | null;
    expires_at?: string | null;
}

/** What a signer signs for: the work order, the results counted, what failed, who signed already. */
defineProps<{ work: PmSignWork }>();

const tone: Record<string, string> = {
    passed: 'text-green-700 dark:text-green-300',
    failed: 'text-red-700 dark:text-red-300',
    not_inspected: 'text-muted-foreground',
    pending: 'text-amber-700 dark:text-amber-300',
};
</script>

<template>
    <div class="space-y-3">
        <div>
            <p class="font-mono text-sm text-muted-foreground">{{ work.number }}</p>
            <h2 class="text-lg font-semibold">{{ work.customer ?? work.title }}</h2>
            <p v-if="work.title && work.customer" class="text-sm text-muted-foreground">{{ work.title }}</p>
        </div>

        <div class="grid grid-cols-4 gap-2 text-center">
            <div class="rounded-md border p-2">
                <div class="text-xl font-semibold">{{ work.totals.total }}</div>
                <div class="text-xs text-muted-foreground">{{ t('pm_sign.total') }}</div>
            </div>
            <div v-for="result in ['passed', 'failed', 'not_inspected']" :key="result" class="rounded-md border p-2" :class="tone[result]">
                <div class="text-xl font-semibold">{{ work.totals[result] ?? 0 }}</div>
                <div class="text-xs">{{ t(`pm_visits.results.${result}`) }}</div>
            </div>
        </div>

        <div v-if="work.failures.length" class="space-y-1 rounded-md bg-red-50 p-3 text-sm text-red-900 dark:bg-red-950/40 dark:text-red-200">
            <p class="font-medium">{{ t('pm_sign.failures') }}</p>
            <ul class="list-disc space-y-0.5 pl-5">
                <li v-for="(failure, i) in work.failures" :key="i">
                    {{ failure.asset }}<template v-if="failure.failures.length">: {{ failure.failures.join(', ') }}</template>
                    <span v-if="failure.note" class="block text-xs opacity-80">{{ failure.note }}</span>
                </li>
            </ul>
        </div>
        <p v-if="work.summary" class="whitespace-pre-line text-sm">{{ work.summary }}</p>

        <ul v-if="work.signatures.length" class="space-y-0.5 text-xs text-muted-foreground">
            <li v-for="(signature, i) in work.signatures" :key="i">
                {{ t('pm_sign.signed_by', { role: t(`pm_sign.roles.${signature.role}`), name: signature.name, at: dateTime(signature.signed_at) }) }}
            </li>
        </ul>
        <p v-if="work.locked_at" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
            {{ t('pm_sign.locked', { at: dateTime(work.locked_at) }) }}
        </p>
    </div>
</template>
