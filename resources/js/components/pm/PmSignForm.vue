<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SignaturePad from '@/components/SignaturePad.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { useForm } from '@inertiajs/vue3';

/**
 * The customer signs a PM work order: who they are, their drawing, and how satisfied they are
 * (four levels, kept as the work order's survey). The same form on the technician's device and on
 * the customer's own phone (a sign link); posted to url.
 */
const props = defineProps<{ url: string; levels: string[]; surveyOn?: boolean }>();
const emit = defineEmits<{ signed: [] }>();

const form = useForm({
    image: null as string | null,
    name: '',
    position: '',
    phone: '',
    email: '',
    satisfaction: null as string | null,
    comment: '',
});
const submit = () => form.post(props.url, { preserveScroll: true, onSuccess: () => emit('signed') });
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="grid gap-1">
                <Label for="signer_name">{{ t('pm_sign.name') }}</Label>
                <Input id="signer_name" v-model="form.name" required autocomplete="name" class="h-11" />
                <InputError :message="form.errors.name" />
            </div>
            <div class="grid gap-1">
                <Label for="signer_position">{{ t('pm_sign.position') }}</Label>
                <Input id="signer_position" v-model="form.position" class="h-11" />
            </div>
            <div class="grid gap-1">
                <Label for="signer_phone">{{ t('pm_sign.phone') }}</Label>
                <Input id="signer_phone" v-model="form.phone" type="tel" autocomplete="tel" class="h-11" />
                <InputError :message="form.errors.phone" />
            </div>
            <div class="grid gap-1">
                <Label for="signer_email">{{ t('pm_sign.email') }}</Label>
                <Input id="signer_email" v-model="form.email" type="email" autocomplete="email" class="h-11" />
                <InputError :message="form.errors.email" />
            </div>
        </div>

        <div class="grid gap-1">
            <Label>{{ t('pm_sign.signature') }}</Label>
            <SignaturePad v-model="form.image" />
            <InputError :message="form.errors.image ?? (form.errors as Record<string, string>).signature" />
        </div>

        <fieldset v-if="surveyOn !== false" class="space-y-2">
            <legend class="text-sm font-medium">{{ t('pm_sign.satisfaction') }}</legend>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <label
                    v-for="level in levels"
                    :key="level"
                    class="flex min-h-11 cursor-pointer items-center justify-center rounded-md border px-3 text-sm"
                    :class="form.satisfaction === level ? 'border-primary bg-primary/10 font-semibold' : 'hover:bg-muted'"
                >
                    <input v-model="form.satisfaction" type="radio" :value="level" class="sr-only" />
                    {{ t(`pm_sign.levels.${level}`) }}
                </label>
            </div>
            <textarea
                v-model="form.comment"
                rows="2"
                :placeholder="t('pm_sign.comment')"
                :aria-label="t('pm_sign.comment')"
                class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
            />
        </fieldset>

        <Button class="h-12 w-full text-base" :disabled="form.processing || !form.image">{{ t('pm_sign.submit_customer') }}</Button>
    </form>
</template>
