<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';

/**
 * Records the form would repeat (useDuplicates), each with why and what to do instead: open it,
 * receive stock into it, copy it... "blocking" ones stop the save; the others only warn.
 */
export interface DuplicateMatch {
    key: string;
    title: string;
    detail?: string | null;
    reasons: string[];
    blocking: boolean;
    actions: { label: string; href: string }[];
}

defineProps<{ matches: DuplicateMatch[] }>();
</script>

<template>
    <div
        v-if="matches.length"
        role="status"
        class="space-y-3 rounded-md border p-3 text-sm"
        :class="
            matches.some((m) => m.blocking)
                ? 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100'
                : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100'
        "
    >
        <p class="font-medium">{{ matches.some((m) => m.blocking) ? t('duplicates.blocking_title') : t('duplicates.warning_title') }}</p>
        <ul class="space-y-3">
            <li v-for="match in matches" :key="match.key" class="space-y-1">
                <p class="font-medium">{{ match.title }}</p>
                <p v-if="match.detail" class="text-xs opacity-80">{{ match.detail }}</p>
                <p class="flex flex-wrap gap-1">
                    <span v-for="reason in match.reasons" :key="reason" class="rounded bg-background/70 px-1.5 py-0.5 text-xs">
                        {{ t(`duplicates.reasons.${reason}`) }}
                    </span>
                </p>
                <div v-if="match.actions.length" class="flex flex-wrap gap-2 pt-1">
                    <Button v-for="action in match.actions" :key="action.href" variant="outline" size="sm" class="min-h-11 bg-background" as-child>
                        <Link :href="action.href">{{ action.label }}</Link>
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
