<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Mail, MessageCircle, Plus, Send, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Channel = 'line' | 'telegram' | 'mail';

const props = defineProps<{
    settings: {
        events: string[];
        line: { enabled: boolean; to: string; token_set: boolean };
        telegram: { enabled: boolean; chat_id: string; token_set: boolean };
        mail: { enabled: boolean; recipients: string[] };
    };
    eventGroups: Record<string, string[]>;
    maxRecipients: number;
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [{ title: t('alerts.title'), href: route('platform.alerts.edit') }];

// Tokens are never sent back: the boxes start empty and an empty box keeps the saved token.
const form = useForm({
    events: [...props.settings.events],
    line: { enabled: props.settings.line.enabled, to: props.settings.line.to, token: '', remove_token: false },
    telegram: { enabled: props.settings.telegram.enabled, chat_id: props.settings.telegram.chat_id, token: '', remove_token: false },
    mail: { enabled: props.settings.mail.enabled, recipients: props.settings.mail.recipients.length ? [...props.settings.mail.recipients] : [''] },
});

const submit = () =>
    form
        .transform((data) => ({ ...data, mail: { ...data.mail, recipients: data.mail.recipients.map((r) => r.trim()).filter(Boolean) } }))
        .put(route('platform.alerts.update'), {
            preserveScroll: true,
            onSuccess: () => {
                form.line.token = '';
                form.telegram.token = '';
                form.line.remove_token = false;
                form.telegram.remove_token = false;
            },
        });

const testing = ref<Channel | null>(null);
const test = (channel: Channel) =>
    router.post(
        route('platform.alerts.test'),
        { channel },
        { preserveScroll: true, onStart: () => (testing.value = channel), onFinish: () => (testing.value = null) },
    );

const errors = computed(() => form.errors as Record<string, string>);
const addRecipient = () => form.mail.recipients.length < props.maxRecipients && form.mail.recipients.push('');
const removeRecipient = (index: number) => {
    form.mail.recipients.splice(index, 1);
    if (form.mail.recipients.length === 0) form.mail.recipients.push('');
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('alerts.title')" />

        <div class="max-w-4xl space-y-6 p-4">
            <Heading :title="t('alerts.title')" :description="t('alerts.description')" />

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>
            <p v-if="page.props.flash.error" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                {{ page.props.flash.error }}
            </p>

            <form class="space-y-6" @submit.prevent="submit">
                <!-- What to hear about -->
                <section class="space-y-3 rounded-md border p-4">
                    <h3 class="text-sm font-semibold">{{ t('alerts.events') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <fieldset v-for="(events, group) in eventGroups" :key="group" class="space-y-2">
                            <legend class="text-xs font-medium text-muted-foreground">{{ t(`alerts.groups.${group}`) }}</legend>
                            <label v-for="event in events" :key="event" class="flex items-center gap-2 text-sm">
                                <input v-model="form.events" type="checkbox" :value="event" class="size-4 rounded border-input" />
                                {{ t(`alerts.event_labels.${event}`) }}
                            </label>
                        </fieldset>
                    </div>
                </section>

                <!-- LINE -->
                <section class="space-y-3 rounded-md border p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <MessageCircle class="h-4 w-4 text-green-600" />{{ t('alerts.line.title') }}
                        </h3>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.line.enabled" type="checkbox" class="size-4 rounded border-input" />{{ t('alerts.enabled') }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('alerts.line.hint') }}</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid content-start gap-2">
                            <Label for="line_token">{{ t('alerts.line.token') }}</Label>
                            <Input id="line_token" v-model="form.line.token" type="password" autocomplete="off" />
                            <p class="text-xs text-muted-foreground">
                                {{ settings.line.token_set ? t('alerts.token_set') : t('alerts.token_missing') }}
                            </p>
                            <label v-if="settings.line.token_set" class="flex items-center gap-2 text-xs">
                                <input v-model="form.line.remove_token" type="checkbox" class="size-3.5" />{{ t('alerts.remove_token') }}
                            </label>
                            <InputError :message="errors['line.token']" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="line_to">{{ t('alerts.line.to') }}</Label>
                            <Input id="line_to" v-model="form.line.to" :placeholder="t('alerts.line.to_placeholder')" />
                            <InputError :message="errors['line.to']" />
                        </div>
                    </div>
                    <Button type="button" variant="outline" size="sm" :disabled="testing !== null" @click="test('line')">
                        <Send class="h-4 w-4" />{{ t('alerts.test') }}
                    </Button>
                </section>

                <!-- Telegram -->
                <section class="space-y-3 rounded-md border p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Send class="h-4 w-4 text-sky-600" />{{ t('alerts.telegram.title') }}
                        </h3>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.telegram.enabled" type="checkbox" class="size-4 rounded border-input" />{{ t('alerts.enabled') }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('alerts.telegram.hint') }}</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid content-start gap-2">
                            <Label for="telegram_token">{{ t('alerts.telegram.token') }}</Label>
                            <Input id="telegram_token" v-model="form.telegram.token" type="password" autocomplete="off" />
                            <p class="text-xs text-muted-foreground">
                                {{ settings.telegram.token_set ? t('alerts.token_set') : t('alerts.token_missing') }}
                            </p>
                            <label v-if="settings.telegram.token_set" class="flex items-center gap-2 text-xs">
                                <input v-model="form.telegram.remove_token" type="checkbox" class="size-3.5" />{{ t('alerts.remove_token') }}
                            </label>
                            <InputError :message="errors['telegram.token']" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="telegram_chat">{{ t('alerts.telegram.chat_id') }}</Label>
                            <Input id="telegram_chat" v-model="form.telegram.chat_id" :placeholder="t('alerts.telegram.chat_id_placeholder')" />
                            <InputError :message="errors['telegram.chat_id']" />
                        </div>
                    </div>
                    <Button type="button" variant="outline" size="sm" :disabled="testing !== null" @click="test('telegram')">
                        <Send class="h-4 w-4" />{{ t('alerts.test') }}
                    </Button>
                </section>

                <!-- E-mail -->
                <section class="space-y-3 rounded-md border p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="flex items-center gap-2 text-sm font-semibold"><Mail class="h-4 w-4" />{{ t('alerts.mail.title') }}</h3>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.mail.enabled" type="checkbox" class="size-4 rounded border-input" />{{ t('alerts.enabled') }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('alerts.mail.hint', { max: maxRecipients }) }}</p>
                    <div v-for="(recipient, index) in form.mail.recipients" :key="index" class="space-y-1">
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="form.mail.recipients[index]"
                                type="email"
                                class="max-w-md"
                                :aria-label="`${t('alerts.mail.recipient')} ${index + 1}`"
                            />
                            <Button type="button" variant="ghost" size="icon" :aria-label="t('common.delete')" @click="removeRecipient(index)">
                                <X class="h-4 w-4" />
                            </Button>
                        </div>
                        <InputError :message="errors[`mail.recipients.${index}`]" />
                    </div>
                    <InputError :message="errors['mail.recipients']" />
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="form.mail.recipients.length >= maxRecipients"
                            @click="addRecipient"
                        >
                            <Plus class="h-4 w-4" />{{ t('alerts.add_recipient') }}
                        </Button>
                        <Button type="button" variant="outline" size="sm" :disabled="testing !== null" @click="test('mail')">
                            <Send class="h-4 w-4" />{{ t('alerts.test') }}
                        </Button>
                    </div>
                </section>

                <div class="flex items-center gap-3">
                    <Button :disabled="form.processing">{{ t('alerts.save') }}</Button>
                    <span class="text-xs text-muted-foreground">{{ t('alerts.test_hint') }}</span>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
