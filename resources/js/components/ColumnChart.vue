<script setup lang="ts">
import { t } from '@/lib/i18n';
import { computed, ref } from 'vue';

export interface ChartSeries {
    key: string;
    label: string;
    values: number[];
}

/**
 * Column chart of counts, plain HTML: one column group per category (month or year), series side
 * by side, or stacked when they are parts of one total. Series take the categorical colors in
 * order (--series-1, --series-2); legend above, tooltip on hover/focus, and a table view.
 */
const props = defineProps<{
    title: string;
    categories: string[];
    series: ChartSeries[];
    stacked?: boolean;
}>();

const hovered = ref<number | null>(null);

const columnTotal = (index: number) => props.series.reduce((sum, s) => sum + (s.values[index] ?? 0), 0);
const seriesTotal = (s: ChartSeries) => s.values.reduce((sum, value) => sum + value, 0);

// Whole-number ticks: 0 and 4 or 5 clean steps that reach the largest column.
const scale = computed(() => {
    const largest = Math.max(
        0,
        ...props.categories.map((_, i) => (props.stacked ? columnTotal(i) : Math.max(0, ...props.series.map((s) => s.values[i] ?? 0)))),
    );
    const rough = Math.max(1, largest / 4);
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = Math.max(1, [1, 2, 5, 10].map((m) => m * magnitude).find((candidate) => candidate >= rough) ?? 10 * magnitude);
    const steps = Math.max(1, Math.ceil(largest / step));
    return { max: step * steps, ticks: Array.from({ length: steps + 1 }, (_, i) => step * i).reverse() };
});

const height = (value: number) => `${(value / scale.value.max) * 100}%`;
const color = (index: number) => `var(--series-${index + 1})`;
const isEmpty = computed(() => props.series.every((s) => s.values.every((value) => value === 0)));
</script>

<template>
    <figure class="viz space-y-3 rounded-md border p-4">
        <figcaption class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <span class="text-sm font-semibold">{{ title }}</span>
            <span class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <span v-for="(s, index) in series" :key="s.key" class="inline-flex items-center gap-1.5">
                    <span class="size-2.5 rounded-sm" :style="{ background: color(index) }" aria-hidden="true" />
                    {{ s.label }}
                    <span class="font-medium text-foreground">{{ seriesTotal(s).toLocaleString() }}</span>
                </span>
            </span>
        </figcaption>

        <div class="flex gap-2" aria-hidden="true">
            <!-- y axis -->
            <div class="relative h-48 min-w-6 text-right text-[11px] tabular-nums leading-none text-muted-foreground">
                <span v-for="tick in scale.ticks" :key="tick" class="absolute right-0 translate-y-1/2" :style="{ bottom: height(tick) }">{{
                    tick.toLocaleString()
                }}</span>
            </div>

            <div class="min-w-0 flex-1">
                <div class="relative h-48">
                    <!-- gridlines: hairlines at the ticks, the baseline one step stronger -->
                    <div
                        v-for="tick in scale.ticks"
                        :key="tick"
                        class="absolute inset-x-0 h-px"
                        :class="tick === 0 ? 'bg-muted-foreground/40' : 'bg-border'"
                        :style="{ bottom: height(tick) }"
                    />
                    <p v-if="isEmpty" class="absolute inset-0 flex items-center justify-center text-xs text-muted-foreground">
                        {{ t('dashboard.charts.empty') }}
                    </p>

                    <!-- columns -->
                    <div class="absolute inset-0 flex">
                        <div
                            v-for="(category, i) in categories"
                            :key="category"
                            class="relative flex flex-1 items-end justify-center gap-0.5 rounded-sm px-0.5"
                            :class="{ 'bg-muted/60': hovered === i }"
                            @mouseenter="hovered = i"
                            @mouseleave="hovered = null"
                        >
                            <template v-if="stacked">
                                <div class="flex h-full w-full max-w-6 flex-col-reverse gap-0.5">
                                    <template v-for="(s, index) in series" :key="s.key">
                                        <div
                                            v-if="s.values[i]"
                                            class="w-full last:rounded-t"
                                            :style="{ height: `calc(${height(s.values[i])} - 2px)`, background: color(index) }"
                                        />
                                    </template>
                                </div>
                            </template>
                            <template v-else>
                                <div
                                    v-for="(s, index) in series"
                                    :key="s.key"
                                    class="w-full max-w-6 rounded-t"
                                    :style="{ height: s.values[i] ? height(s.values[i]) : '0', background: color(index) }"
                                />
                            </template>

                            <!-- tooltip -->
                            <div
                                v-if="hovered === i"
                                class="pointer-events-none absolute top-1 z-20 min-w-32 whitespace-nowrap rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
                                :class="i < categories.length / 2 ? 'left-full ml-1' : 'right-full mr-1'"
                            >
                                <p class="mb-1 font-semibold">{{ category }}</p>
                                <p v-for="(s, index) in series" :key="s.key" class="flex items-center gap-1.5">
                                    <span class="size-2 rounded-sm" :style="{ background: color(index) }" />
                                    <span class="text-muted-foreground">{{ s.label }}</span>
                                    <span class="ml-auto pl-3 font-medium tabular-nums">{{ (s.values[i] ?? 0).toLocaleString() }}</span>
                                </p>
                                <p v-if="stacked" class="mt-1 flex border-t pt-1">
                                    <span class="text-muted-foreground">{{ t('dashboard.charts.total') }}</span>
                                    <span class="ml-auto pl-3 font-medium tabular-nums">{{ columnTotal(i).toLocaleString() }}</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- x axis -->
                <div class="mt-1 flex text-[11px] text-muted-foreground">
                    <span v-for="category in categories" :key="category" class="flex-1 truncate text-center">{{ category }}</span>
                </div>
            </div>
        </div>

        <details class="text-xs">
            <summary class="cursor-pointer text-muted-foreground">{{ t('dashboard.charts.table') }}</summary>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full tabular-nums">
                    <caption class="sr-only">
                        {{
                            title
                        }}
                    </caption>
                    <thead>
                        <tr class="text-left text-muted-foreground">
                            <th class="py-1 pr-3 font-medium" scope="col" />
                            <th v-for="s in series" :key="s.key" class="py-1 pr-3 text-right font-medium" scope="col">{{ s.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(category, i) in categories" :key="category" class="border-t">
                            <th class="py-1 pr-3 text-left font-normal" scope="row">{{ category }}</th>
                            <td v-for="s in series" :key="s.key" class="py-1 pr-3 text-right">{{ (s.values[i] ?? 0).toLocaleString() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </figure>
</template>

<style scoped>
/* Categorical slots 1-2 of the chart palette, stepped per theme (validated against both surfaces). */
.viz {
    --series-1: #2a78d6;
    --series-2: #eb6834;
}
:global(.dark) .viz {
    --series-1: #3987e5;
    --series-2: #d95926;
}
</style>
