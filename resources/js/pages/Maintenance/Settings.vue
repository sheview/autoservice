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
    settings: {
        review_step: boolean;
        approval_step: boolean;
        require_technician_signature: boolean;
        require_customer_signature: boolean;
        require_approver_signature: boolean;
        share_days: number;
        sign_link_days: number;
        generate_days_ahead: number;
        report_note: string | null;
    };
    maxShareDays: number;
    noteMax: number;
    maxSignLinkDays: number;
    maxDaysAhead: number;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('pm_settings.title'), href: route('maintenance.settings.edit') }];

const form = useForm({ ...props.settings, report_note: props.settings.report_note ?? '' });
const submit = () => form.put(route('maintenance.settings.update'), { preserveScroll: true });

const toggles = ['review_step', 'approval_step', 'require_technician_signature', 'require_customer_signature', 'require_approver_signature'] as const;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_settings.title')" />

        <div class="max-w-3xl space-y-6 p-4">
            <Heading :title="t('pm_settings.title')" :description="t('pm_settings.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <form class="space-y-6" @submit.prevent="submit">
                <section class="space-y-3 rounded-lg border p-4">
                    <h3 class="font-semibold">{{ t('pm_settings.workflow') }}</h3>
                    <label v-for="key in toggles" :key="key" class="flex items-start gap-3 text-sm">
                        <input v-model="form[key]" type="checkbox" class="mt-0.5 size-4 rounded border-input" />
                        <span>
                            <span class="block font-medium">{{ t(`pm_settings.${key}`) }}</span>
                            <span class="block text-muted-foreground">{{ t(`pm_settings.${key}_hint`) }}</span>
                        </span>
                    </label>
                </section>

                <section class="space-y-2 rounded-lg border p-4">
                    <h3 class="font-semibold">{{ t('pm_settings.plans') }}</h3>
                    <div class="grid gap-2 sm:max-w-xs">
                        <Label for="generate_days_ahead">{{ t('pm_settings.generate_days_ahead') }}</Label>
                        <Input id="generate_days_ahead" v-model="form.generate_days_ahead" type="number" min="1" :max="maxDaysAhead" />
                        <p class="text-xs text-muted-foreground">{{ t('pm_settings.generate_days_ahead_hint', { max: maxDaysAhead }) }}</p>
                        <InputError :message="form.errors.generate_days_ahead" />
                    </div>
                </section>

                <section class="space-y-4 rounded-lg border p-4">
                    <h3 class="font-semibold">{{ t('pm_settings.reports') }}</h3>
                    <div class="grid gap-2 sm:max-w-xs">
                        <Label for="share_days">{{ t('pm_settings.share_days') }}</Label>
                        <Input id="share_days" v-model="form.share_days" type="number" min="1" :max="maxShareDays" />
                        <p class="text-xs text-muted-foreground">{{ t('pm_settings.share_days_hint', { max: maxShareDays }) }}</p>
                        <InputError :message="form.errors.share_days" />
                    </div>
                    <div class="grid gap-2 sm:max-w-xs">
                        <Label for="sign_link_days">{{ t('pm_settings.sign_link_days') }}</Label>
                        <Input id="sign_link_days" v-model="form.sign_link_days" type="number" min="1" :max="maxSignLinkDays" />
                        <p class="text-xs text-muted-foreground">{{ t('pm_settings.sign_link_days_hint', { max: maxSignLinkDays }) }}</p>
                        <InputError :message="form.errors.sign_link_days" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="report_note">{{ t('pm_settings.report_note') }}</Label>
                        <textarea
                            id="report_note"
                            v-model="form.report_note"
                            rows="3"
                            :maxlength="noteMax"
                            class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <p class="text-xs text-muted-foreground">{{ t('pm_settings.report_note_hint') }}</p>
                        <InputError :message="form.errors.report_note" />
                    </div>
                </section>

                <Button :disabled="form.processing">{{ t('common.save') }}</Button>
            </form>
        </div>
    </AppLayout>
</template>
