<script lang="ts">
import type { PartUnitRow } from '@/types/inventory';

export type SerialHit = PartUnitRow & { part: { id: number; code: string; name: string } | null };
</script>

<script setup lang="ts">
import PartUnitTimeline from '@/components/PartUnitTimeline.vue';
import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Pieces whose serial number matches a list's search: which part, where it is now, and its whole
 * history (received when and from where, out on which document to whom) one tap away.
 */
defineProps<{ hits: SerialHit[] }>();

const open = ref<number | null>(null);
</script>

<template>
    <section v-if="hits.length" class="space-y-2 rounded-md border border-primary/30 bg-primary/5 p-3">
        <h3 class="text-sm font-semibold">{{ t('part_units.hits_title') }}</h3>
        <ul class="space-y-1">
            <li v-for="hit in hits" :key="hit.id" class="text-sm">
                <button type="button" class="flex w-full flex-wrap items-center gap-2 text-left" @click="open = open === hit.id ? null : hit.id">
                    <span class="font-mono font-semibold">{{ hit.serial_number }}</span>
                    <span class="rounded bg-muted px-1.5 text-xs">{{ t(`part_units.statuses.${hit.status}`) }}</span>
                    <Link v-if="hit.part" :href="route('inventory.parts.show', hit.part.id)" class="text-xs text-primary hover:underline" @click.stop>
                        {{ hit.part.code }} · {{ hit.part.name }}
                    </Link>
                    <span class="ml-auto text-xs text-primary">{{ open === hit.id ? t('part_units.hide_history') : t('part_units.history') }}</span>
                </button>
                <div v-if="open === hit.id" class="mt-2 pl-2"><PartUnitTimeline :unit-id="hit.id" /></div>
            </li>
        </ul>
    </section>
</template>
