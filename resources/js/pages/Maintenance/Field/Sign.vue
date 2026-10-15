<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PmSignForm from '@/components/pm/PmSignForm.vue';
import PmSignSummary, { type PmSignWork } from '@/components/pm/PmSignSummary.vue';
import SignaturePad from '@/components/SignaturePad.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { FileText, Printer, QrCode } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Hand in and sign, on the technician's device: the summary of the round, the technician's
 * signature (hand-in), then the customer — on this device, or through a link / QR to sign on their
 * own phone. The customer's signature locks the results.
 */
const props = defineProps<{
    visit: { ulid: string; visit_no: string; status: string; locked_at: string | null };
    work: PmSignWork;
    can: { submit: boolean; link: boolean };
    signing: { technician: boolean; customer: boolean };
    levels: string[];
    surveyOn: boolean;
}>();

const page = usePage<SharedData & { flash: { sign_link?: { url: string; expires_at: string; qr: string } | null } }>();
const user = computed(() => page.props.auth.user as { name: string });
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('my_work.title'), href: route('service.my-work') },
    { title: props.visit.visit_no, href: route('maintenance.field.visit', props.visit.ulid) },
    { title: t('pm_sign.title'), href: route('maintenance.field.sign', props.visit.ulid) },
];

// Hand in: the summary and the technician's signature.
const handIn = useForm({ summary: props.work.summary ?? '', technician_signature: null as string | null });
const submit = () => handIn.post(route('maintenance.visits.submit', props.visit.ulid), { preserveScroll: true });

// Then the customer: here, or a link.
const mode = ref<'here' | 'link' | null>(null);
const link = computed(() => page.props.flash.sign_link ?? null);
const makeLink = () => {
    mode.value = 'link';
    router.post(route('maintenance.visits.sign-link', props.visit.ulid), {}, { preserveScroll: true });
};
const copied = ref(false);
const copy = async () => {
    if (!link.value) return;
    await navigator.clipboard?.writeText(link.value.url);
    copied.value = true;
};
const errors = computed(() => page.props.errors as Record<string, string>);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('pm_sign.title')" />

        <div class="mx-auto max-w-2xl space-y-5 p-4 pb-24">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-lg font-semibold">{{ t('pm_sign.title') }}</h1>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <a :href="route('maintenance.visits.sheet', visit.ulid)" target="_blank" rel="noopener"
                            ><FileText class="mr-1 h-4 w-4" />{{ t('pm_sign.sheet') }}</a
                        >
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <a :href="route('maintenance.visits.sheet', { visit: visit.ulid, print: 1 })" target="_blank" rel="noopener"
                            ><Printer class="mr-1 h-4 w-4" />{{ t('pm_visits.print') }}</a
                        >
                    </Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <InputError :message="errors.visit" />

            <section class="rounded-lg border p-4">
                <PmSignSummary :work="work" />
                <p v-if="work.totals.pending" class="mt-3 text-sm text-amber-700 dark:text-amber-300">
                    {{ t('pm_sign.pending_items', { count: work.totals.pending }) }}
                    <Link :href="route('maintenance.field.visit', visit.ulid)" class="underline">{{ t('pm_field.back') }}</Link>
                </p>
            </section>

            <!-- 1. The technician hands in and signs -->
            <section v-if="can.submit" class="space-y-3 rounded-lg border p-4">
                <h2 class="font-semibold">{{ t('pm_sign.technician') }}</h2>
                <textarea
                    v-model="handIn.summary"
                    rows="2"
                    :placeholder="t('pm_visits.summary')"
                    :aria-label="t('pm_visits.summary')"
                    class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                />
                <p class="text-sm text-muted-foreground">{{ t('pm_sign.technician_hint', { name: user.name }) }}</p>
                <SignaturePad v-model="handIn.technician_signature" />
                <InputError :message="handIn.errors.technician_signature" />
                <Button class="h-12 w-full text-base" :disabled="handIn.processing || work.totals.pending > 0" @click="submit">{{
                    t('pm_sign.submit_work')
                }}</Button>
            </section>

            <!-- 2. The customer: on this device, or on their own phone -->
            <section v-if="can.link" class="space-y-4 rounded-lg border p-4">
                <div>
                    <h2 class="font-semibold">{{ t('pm_sign.customer') }}</h2>
                    <p class="text-sm text-muted-foreground">{{ t('pm_sign.customer_hint') }}</p>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <Button :variant="mode === 'here' ? 'default' : 'outline'" class="h-12" @click="mode = 'here'">{{
                        t('pm_sign.sign_here')
                    }}</Button>
                    <Button :variant="mode === 'link' ? 'default' : 'outline'" class="h-12" @click="makeLink"
                        ><QrCode class="mr-1 h-4 w-4" />{{ t('pm_sign.send_link') }}</Button
                    >
                </div>

                <PmSignForm
                    v-if="mode === 'here'"
                    :url="route('maintenance.visits.customer-sign', visit.ulid)"
                    :levels="levels"
                    :survey-on="surveyOn"
                />

                <div v-else-if="mode === 'link' && link" class="space-y-3 text-center">
                    <div class="mx-auto w-56 rounded-lg bg-white p-3" v-html="link.qr" />
                    <p class="text-sm text-muted-foreground">{{ t('pm_sign.link_ready', { at: dateTime(link.expires_at) }) }}</p>
                    <div class="flex gap-2">
                        <input
                            :value="link.url"
                            readonly
                            class="h-10 min-w-0 flex-1 rounded-md border bg-muted px-3 font-mono text-xs"
                            :aria-label="t('pm_sign.copy')"
                        />
                        <Button variant="outline" @click="copy">{{ copied ? t('pm_sign.copied') : t('pm_sign.copy') }}</Button>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
