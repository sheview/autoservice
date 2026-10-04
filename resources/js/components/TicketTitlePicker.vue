<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const title = defineModel<string>({ required: true });

type Group = { label: string; items: string[] };

// The common problems from lang/th/ui.php (tickets.title_presets), grouped by kind of work.
const groups = computed(() => {
    const tickets = (usePage<SharedData>().props.translations as Record<string, Record<string, unknown>> | undefined)?.tickets;
    return Object.values((tickets?.title_presets ?? {}) as Record<string, Group>);
});

const OTHER = '__other__';
const isPreset = (value: string) => groups.value.some((g) => g.items.includes(value));

// A title that is not on the list (typed by hand, or saved before the list existed) opens as "other".
const choice = ref(title.value === '' ? '' : isPreset(title.value) ? title.value : OTHER);

watch(choice, (value, old) => {
    if (value === OTHER) {
        if (old !== OTHER && isPreset(title.value)) title.value = '';
    } else {
        title.value = value;
    }
});

const selectClass = 'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
</script>

<template>
    <div class="grid gap-2">
        <select id="title" v-model="choice" :class="selectClass" required>
            <option value="" disabled>{{ t('tickets.title_pick') }}</option>
            <optgroup v-for="group in groups" :key="group.label" :label="group.label">
                <option v-for="item in group.items" :key="item" :value="item">{{ item }}</option>
            </optgroup>
            <option :value="OTHER">{{ t('tickets.title_other') }}</option>
        </select>
        <Input v-if="choice === OTHER" v-model="title" :placeholder="t('tickets.title_other_placeholder')" required />
    </div>
</template>
