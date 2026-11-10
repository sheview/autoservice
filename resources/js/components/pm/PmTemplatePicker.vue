<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { Check } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

export interface PmTemplateOption {
    code: string;
    name: string;
    // how many questions, and the first of them (PmTemplateResolver::options)
    questions?: number;
    preview?: string[];
}

/**
 * Picks another PM form for one piece of equipment: the forms that fit it best first ("แนะนำ"),
 * then every form, found by any part of its name or code; the one tapped shows its first questions
 * before it is confirmed.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        options: PmTemplateOption[];
        // codes that fit the equipment best, most specific first
        suggested?: string[];
        current?: string | null;
        processing?: boolean;
        error?: string;
    }>(),
    { suggested: () => [], current: null, processing: false, error: '' },
);
const emit = defineEmits<{ 'update:open': [value: boolean]; pick: [code: string, remember: boolean] }>();

const search = ref('');
const picked = ref<string | null>(null);
const remember = ref(true);
watch(
    () => props.open,
    (open) => {
        if (open) {
            search.value = '';
            picked.value = props.current;
            remember.value = true;
        }
    },
);

const byCode = computed(() => new Map(props.options.map((option) => [option.code, option])));
const matches = (option: PmTemplateOption) => {
    const term = search.value.trim().toLowerCase();
    return !term || option.name.toLowerCase().includes(term) || option.code.toLowerCase().includes(term);
};
const suggestedOptions = computed(() =>
    props.suggested.map((code) => byCode.value.get(code)).filter((option): option is PmTemplateOption => !!option && matches(option)),
);
const otherOptions = computed(() => props.options.filter((option) => !props.suggested.includes(option.code) && matches(option)));
const groups = computed(() =>
    [
        { key: 'suggested', title: t('pm_visits.template_suggested'), options: suggestedOptions.value },
        {
            key: 'all',
            title: suggestedOptions.value.length ? t('pm_visits.template_others') : t('pm_visits.template_all'),
            options: otherOptions.value,
        },
    ].filter((group) => group.options.length),
);

const confirm = () => picked.value && picked.value !== props.current && emit('pick', picked.value, remember.value);
</script>

<template>
    <Dialog :open="open" @update:open="(value: boolean) => emit('update:open', value)">
        <DialogContent class="flex max-h-[90dvh] flex-col gap-3 sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('pm_visits.pick_template') }}</DialogTitle>
                <DialogDescription>{{ t('pm_visits.change_template_hint') }}</DialogDescription>
            </DialogHeader>

            <Input v-model="search" type="search" class="h-11" :placeholder="t('pm_visits.template_search')" />

            <div class="-mx-1 min-h-0 flex-1 space-y-4 overflow-y-auto px-1">
                <p v-if="!groups.length" class="py-6 text-center text-sm text-muted-foreground">{{ t('pm_visits.template_none_found') }}</p>
                <section v-for="group in groups" :key="group.key" class="space-y-1.5">
                    <h3 class="text-xs font-semibold text-muted-foreground">{{ group.title }}</h3>
                    <button
                        v-for="option in group.options"
                        :key="option.code"
                        type="button"
                        class="block w-full rounded-md border p-3 text-left transition"
                        :class="picked === option.code ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-muted/50'"
                        :aria-pressed="picked === option.code"
                        @click="picked = option.code"
                    >
                        <span class="flex items-center gap-2">
                            <Check v-if="picked === option.code" class="h-4 w-4 shrink-0 text-primary" />
                            <span class="min-w-0 flex-1 font-medium">{{ option.name }}</span>
                            <span v-if="option.code === current" class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{
                                t('pm_visits.template_current')
                            }}</span>
                            <span v-if="option.questions !== undefined" class="shrink-0 text-xs text-muted-foreground">
                                {{ t('pm_visits.template_questions', { count: option.questions }) }}
                            </span>
                        </span>
                        <ul
                            v-if="picked === option.code && option.preview?.length"
                            class="mt-2 list-inside list-disc space-y-0.5 text-xs text-muted-foreground"
                        >
                            <li v-for="(label, index) in option.preview" :key="index">{{ label }}</li>
                            <li v-if="(option.questions ?? 0) > option.preview.length" class="list-none">
                                {{ t('pm_visits.template_more', { count: (option.questions ?? 0) - option.preview.length }) }}
                            </li>
                        </ul>
                    </button>
                </section>
            </div>

            <label class="inline-flex items-center gap-2 text-sm">
                <input v-model="remember" type="checkbox" class="size-4 rounded border-input" />
                {{ t('pm_visits.remember_template') }}
            </label>
            <InputError :message="error" />

            <DialogFooter class="gap-2">
                <Button type="button" variant="ghost" @click="emit('update:open', false)">{{ t('common.cancel') }}</Button>
                <Button type="button" :disabled="processing || !picked || picked === current" @click="confirm">{{
                    t('pm_visits.use_template')
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
