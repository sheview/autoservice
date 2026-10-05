<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';

// The company's symptom and fix chips: two lists the admin keeps in order.
const props = defineProps<{ presets: { symptom: string[]; solution: string[] }; kinds: ('symptom' | 'solution')[] }>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('presets.title'), href: route('service.presets.edit') }];
const form = useForm({ symptom: [...props.presets.symptom], solution: [...props.presets.solution] });

const move = (kind: 'symptom' | 'solution', i: number, by: number) => {
    const list = form[kind];
    const j = i + by;
    if (j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
};
const save = () => form.put(route('service.presets.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('presets.title')" />

        <form class="space-y-6 p-4" @submit.prevent="save">
            <Heading :title="t('presets.title')" :description="t('presets.description')" />
            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="grid gap-6 lg:grid-cols-2">
                <section v-for="kind in kinds" :key="kind" class="space-y-3 rounded-lg border p-4">
                    <h3 class="font-semibold">{{ t(`presets.kinds.${kind}`) }}</h3>
                    <ul class="space-y-2">
                        <li v-for="(_, i) in form[kind]" :key="i" class="flex items-center gap-1">
                            <Input v-model="form[kind][i]" maxlength="100" required />
                            <Button type="button" size="icon" variant="ghost" :aria-label="t('presets.up')" @click="move(kind, i, -1)"
                                ><ArrowUp class="h-4 w-4"
                            /></Button>
                            <Button type="button" size="icon" variant="ghost" :aria-label="t('presets.down')" @click="move(kind, i, 1)"
                                ><ArrowDown class="h-4 w-4"
                            /></Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                class="text-red-600"
                                :aria-label="t('presets.remove')"
                                @click="form[kind].splice(i, 1)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </li>
                    </ul>
                    <InputError :message="(form.errors as Record<string, string>)[`${kind}.0`]" />
                    <Button type="button" variant="outline" size="sm" @click="form[kind].push('')"
                        ><Plus class="h-4 w-4" />{{ t('presets.add') }}</Button
                    >
                </section>
            </div>

            <Button :disabled="form.processing">{{ t('presets.save') }}</Button>
        </form>
    </AppLayout>
</template>
