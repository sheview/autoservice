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
import { ImageUp } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    company: { name: string; service_phone: string | null; service_email: string | null; logo_url: string | null };
    logoMaxKb: number;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('company.title'), href: route('tenancy.company.edit') }];

const form = useForm({
    service_phone: props.company.service_phone ?? '',
    service_email: props.company.service_email ?? '',
    logo: null as File | null,
    remove_logo: false as boolean,
});

// The logo shown: the one picked (not saved yet), none (to be removed), or the saved one.
const pickedUrl = ref<string | null>(null);
const logoUrl = computed(() => pickedUrl.value ?? (form.remove_logo ? null : props.company.logo_url));
const input = ref<HTMLInputElement | null>(null);

const pickLogo = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
    form.remove_logo = false;
    if (pickedUrl.value) URL.revokeObjectURL(pickedUrl.value);
    pickedUrl.value = file ? URL.createObjectURL(file) : null;
};

const removeLogo = () => {
    form.logo = null;
    form.remove_logo = true;
    if (pickedUrl.value) URL.revokeObjectURL(pickedUrl.value);
    pickedUrl.value = null;
    if (input.value) input.value.value = '';
};
onBeforeUnmount(() => pickedUrl.value && URL.revokeObjectURL(pickedUrl.value));

// A logo file goes as multipart, which PHP only reads on POST: send a POST that says PUT.
const submit = () =>
    form
        .transform((data) => ({ ...data, _method: 'put' }))
        .post(route('tenancy.company.update'), {
            preserveScroll: true,
            onSuccess: () => {
                form.logo = null;
                form.remove_logo = false;
                pickedUrl.value = null;
            },
        });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('company.title')" />

        <div class="space-y-6 p-4">
            <Heading :title="t('company.title')" :description="t('company.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <div class="grid gap-6 lg:grid-cols-2">
                <form class="space-y-6" @submit.prevent="submit">
                    <div class="grid content-start gap-2">
                        <Label>{{ t('company.name') }}</Label>
                        <p class="text-sm font-medium">{{ company.name }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('company.name_hint') }}</p>
                    </div>

                    <div class="grid content-start gap-2">
                        <Label>{{ t('company.logo') }}</Label>
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="flex h-20 w-40 items-center justify-center rounded-md border bg-white p-1">
                                <img v-if="logoUrl" :src="logoUrl" :alt="t('company.logo')" class="max-h-full max-w-full object-contain" />
                                <span v-else class="text-xs text-muted-foreground">{{ t('company.no_logo') }}</span>
                            </div>
                            <Button type="button" variant="outline" size="sm" @click="input?.click()">
                                <ImageUp class="h-4 w-4" />
                                {{ t('company.choose_logo') }}
                            </Button>
                            <Button v-if="logoUrl" type="button" variant="ghost" size="sm" @click="removeLogo">{{ t('company.remove_logo') }}</Button>
                            <input
                                ref="input"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp"
                                class="hidden"
                                :aria-label="t('company.choose_logo')"
                                @change="pickLogo"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">{{ t('company.logo_hint', { mb: logoMaxKb / 1024 }) }}</p>
                        <InputError :message="form.errors.logo" />
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid content-start gap-2">
                            <Label for="service_phone">{{ t('company.service_phone') }}</Label>
                            <Input id="service_phone" v-model="form.service_phone" type="tel" :placeholder="t('company.service_phone_placeholder')" />
                            <InputError :message="form.errors.service_phone" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="service_email">{{ t('company.service_email') }}</Label>
                            <Input id="service_email" v-model="form.service_email" type="email" />
                            <InputError :message="form.errors.service_email" />
                        </div>
                    </div>

                    <Button :disabled="form.processing">{{ t('common.save') }}</Button>
                </form>

                <!-- How the top of a QR label will look -->
                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ t('company.preview') }}</h3>
                    <div class="flex items-center gap-4 rounded-md border bg-white p-4 text-black">
                        <img v-if="logoUrl" :src="logoUrl" alt="" class="h-14 max-w-32 object-contain" />
                        <div class="min-w-0 leading-snug">
                            <p class="truncate font-bold">{{ company.name }}</p>
                            <p v-if="form.service_phone" class="truncate text-sm">{{ t('labels.service_phone', { phone: form.service_phone }) }}</p>
                            <p v-if="form.service_email" class="truncate text-sm">{{ t('labels.service_email', { email: form.service_email }) }}</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
