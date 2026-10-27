<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { CodeTarget, LabelCodeReader } from '@/lib/labelCodes';
import { Check, ScanBarcode } from 'lucide-vue-next';

// The codes read from the form's photos (useLabelCodes): where each went, and a button per field to put it elsewhere.
defineProps<{ reader: LabelCodeReader; targets: CodeTarget[] }>();
</script>

<template>
    <div v-if="reader.reading.value" class="flex items-center gap-2 text-sm text-muted-foreground" aria-live="polite">
        <ScanBarcode class="size-4 animate-pulse" />{{ t('label_codes.reading') }}
    </div>
    <p v-else-if="reader.missed.value && !reader.found.value.length" class="text-xs text-muted-foreground" aria-live="polite">
        {{ t('label_codes.none') }}
    </p>

    <section v-if="reader.found.value.length" class="space-y-3 rounded-md border border-primary/30 bg-primary/5 p-3" aria-live="polite">
        <div class="flex items-start justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold">{{ t('label_codes.title', { count: reader.found.value.length }) }}</h3>
                <p class="text-xs text-muted-foreground">{{ t('label_codes.hint') }}</p>
            </div>
            <button type="button" class="min-h-11 shrink-0 px-2 text-sm text-muted-foreground underline sm:min-h-0" @click="reader.clear()">
                {{ t('label_codes.close') }}
            </button>
        </div>
        <ul class="space-y-3">
            <li v-for="code in reader.found.value" :key="code.value" class="space-y-1.5">
                <p class="break-all font-mono text-sm">
                    {{ code.value }}
                    <span v-if="code.kind === 'product'" class="ml-1 font-sans text-xs text-muted-foreground">({{ t('label_codes.product') }})</span>
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="target in targets"
                        :key="target.key"
                        type="button"
                        class="inline-flex min-h-11 items-center gap-1 rounded-md border px-3 text-sm sm:min-h-8"
                        :class="code.placed === target.key ? 'border-primary bg-primary text-primary-foreground' : 'bg-background hover:bg-accent'"
                        @click="reader.place(code, target.key)"
                    >
                        <Check v-if="code.placed === target.key" class="size-4" />{{ target.label }}
                    </button>
                </div>
            </li>
        </ul>
    </section>
</template>
