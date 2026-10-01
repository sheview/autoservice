<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps<{
    sessionTimeout: number;
    min: number;
    max: number;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('platform_settings.title'), href: route('platform.settings.edit') }];

const PRESETS = [15, 30, 60, 120, 480];

const form = useForm({ session_timeout_minutes: props.sessionTimeout });
const submit = () => form.put(route('platform.settings.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('platform_settings.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('platform_settings.title')" :description="t('platform_settings.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <form class="space-y-4 rounded-md border p-4" @submit.prevent="submit">
                <h3 class="text-sm font-semibold">{{ t('platform_settings.session') }}</h3>

                <div class="grid content-start gap-2">
                    <Label for="session_timeout_minutes">{{ t('platform_settings.session_timeout') }}</Label>
                    <Input
                        id="session_timeout_minutes"
                        v-model.number="form.session_timeout_minutes"
                        type="number"
                        :min="min"
                        :max="max"
                        step="1"
                        required
                        class="w-40"
                    />
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="minutes in PRESETS"
                            :key="minutes"
                            type="button"
                            size="sm"
                            :variant="form.session_timeout_minutes === minutes ? 'default' : 'outline'"
                            @click="form.session_timeout_minutes = minutes"
                        >
                            {{ t(`platform_settings.presets.${minutes}`) }}
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('platform_settings.session_timeout_hint', { min, max }) }}</p>
                    <InputError :message="form.errors.session_timeout_minutes" />
                </div>

                <p class="text-xs text-muted-foreground">{{ t('platform_settings.session_timeout_note') }}</p>
                <Button :disabled="form.processing">{{ t('common.save') }}</Button>
            </form>
        </div>
    </AppLayout>
</template>
