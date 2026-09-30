<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StarRating from '@/components/StarRating.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/vue3';

// The page behind the public survey link: no sign-in, so no app layout or navigation.
const props = defineProps<{
    company: string;
    survey: { ticket_no: string; ticket_title: string; score: number | null; answered: boolean };
    action: string;
    maxScore: number;
}>();

const form = useForm({ score: null as number | null, comment: '', name: '' });

const submit = () => form.post(props.action, { preserveScroll: true });
</script>

<template>
    <Head :title="t('ticket_survey.public_title')" />

    <div class="flex min-h-svh flex-col items-center justify-center bg-background p-6">
        <div class="w-full max-w-md space-y-6 rounded-xl border p-6">
            <div class="space-y-1 text-center">
                <h1 class="text-xl font-semibold">{{ t('ticket_survey.public_title') }}</h1>
                <p class="text-sm text-muted-foreground">{{ t('ticket_survey.public_intro', { company }) }}</p>
            </div>

            <div class="rounded-md bg-muted/50 px-4 py-3 text-sm">
                <p class="font-mono text-xs text-muted-foreground">{{ t('ticket_survey.public_ticket', { no: survey.ticket_no }) }}</p>
                <p>{{ survey.ticket_title }}</p>
            </div>

            <div v-if="survey.answered" class="space-y-3 text-center">
                <StarRating :model-value="survey.score" :max="maxScore" size="lg" />
                <p class="text-sm text-green-700 dark:text-green-400">{{ t('ticket_survey.public_done') }}</p>
            </div>

            <form v-else class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2 text-center">
                    <p class="text-sm font-medium">{{ t('ticket_survey.ask') }}</p>
                    <StarRating v-model="form.score" :max="maxScore" size="lg" editable />
                    <InputError :message="form.errors.score" />
                </div>

                <div class="space-y-1">
                    <label for="comment" class="sr-only">{{ t('surveys.comment') }}</label>
                    <textarea
                        id="comment"
                        v-model="form.comment"
                        rows="3"
                        maxlength="2000"
                        :placeholder="t('ticket_survey.comment_placeholder')"
                        class="shadow-xs w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.comment" />
                </div>

                <div class="space-y-1">
                    <label for="name" class="sr-only">{{ t('ticket_survey.name_placeholder') }}</label>
                    <Input id="name" v-model="form.name" maxlength="100" autocomplete="name" :placeholder="t('ticket_survey.name_placeholder')" />
                    <InputError :message="form.errors.name" />
                </div>

                <Button class="w-full" size="lg" :disabled="form.processing || form.score === null">{{ t('ticket_survey.submit') }}</Button>
            </form>
        </div>
    </div>
</template>
