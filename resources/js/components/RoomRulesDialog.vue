<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { ref, watch } from 'vue';

/**
 * The accept popup shown when a request is sent: the rules of the chosen room only (the
 * customer's lines, then the company's own terms), with a link to the full document. "Accept and
 * send" works once the text is read to the end or the box is ticked; "Decline" closes it and the
 * form keeps everything typed. A room without rules either blocks or shows the company's terms
 * with a warning, as the room says; a version accepted before (once per version) needs no new tick.
 */
interface Rules {
    room: string;
    customer: string;
    version_id: number | null;
    version: number | null;
    effective_on: string | null;
    summary: string[];
    company_terms: string[];
    file_url: string | null;
    missing: boolean;
    blocked: boolean;
    accepted_before: string | null;
    team_note: boolean;
}

// requestUlid: the draft being sent again, if any (rules it accepted already need no new tick).
const props = defineProps<{ roomUlid: string | null; requestUlid?: string | null; open: boolean; processing?: boolean }>();
const emit = defineEmits<{ accept: [versionId: number | null]; close: [] }>();

const rules = ref<Rules | null>(null);
const loading = ref(false);
const readToEnd = ref(false);
const ticked = ref(false);
const body = ref<HTMLElement | null>(null);

const load = async () => {
    if (!props.roomUlid) return;
    loading.value = true;
    rules.value = null;
    readToEnd.value = false;
    ticked.value = false;
    try {
        const url = route('room-access.requests.rules', props.requestUlid ? { room: props.roomUlid, request: props.requestUlid } : props.roomUlid);
        const response = await fetch(url, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        rules.value = response.ok ? await response.json() : null;
    } finally {
        loading.value = false;
        // Short text that needs no scrolling counts as read.
        requestAnimationFrame(onScroll);
    }
};
watch(
    () => [props.open, props.roomUlid],
    () => props.open && load(),
    { immediate: true },
);

const onScroll = () => {
    const el = body.value;
    if (el && el.scrollTop + el.clientHeight >= el.scrollHeight - 8) readToEnd.value = true;
};
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col rounded-t-2xl bg-background shadow-xl sm:rounded-2xl">
                <header class="border-b px-5 py-4">
                    <h2 class="text-lg font-semibold">{{ t('room_requests.rules_title') }}</h2>
                    <p v-if="rules" class="text-sm text-muted-foreground">
                        {{ t('room_requests.rules_of', { customer: rules.customer, room: rules.room }) }}
                        <template v-if="rules.version">
                            · {{ t('room_requests.rules_version', { version: rules.version, date: rules.effective_on ?? '' }) }}</template
                        >
                    </p>
                </header>

                <div ref="body" class="flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm" @scroll="onScroll">
                    <p v-if="loading" class="text-muted-foreground">{{ t('room_requests.rules_loading') }}</p>
                    <template v-else-if="rules">
                        <p v-if="rules.blocked" class="rounded-md bg-red-50 px-3 py-2 text-red-800 dark:bg-red-950 dark:text-red-200">
                            {{ t('room_requests.rules_blocked') }}
                        </p>
                        <template v-else>
                            <p v-if="rules.missing" class="rounded-md bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                {{ t('room_requests.rules_missing') }}
                            </p>
                            <section v-if="rules.summary.length" class="space-y-2">
                                <h3 class="font-semibold">{{ t('room_requests.rules_customer') }}</h3>
                                <ol class="list-decimal space-y-1.5 pl-5">
                                    <li v-for="(line, i) in rules.summary" :key="i">{{ line }}</li>
                                </ol>
                                <a
                                    v-if="rules.file_url"
                                    :href="rules.file_url"
                                    target="_blank"
                                    class="inline-block text-primary underline-offset-4 hover:underline"
                                    >{{ t('room_requests.rules_open_file') }}</a
                                >
                            </section>
                            <section v-if="rules.company_terms.length" class="space-y-2">
                                <h3 class="font-semibold">{{ t('room_requests.rules_company') }}</h3>
                                <ol class="list-decimal space-y-1.5 pl-5">
                                    <li v-for="(line, i) in rules.company_terms" :key="i">{{ line }}</li>
                                </ol>
                            </section>
                            <p v-if="rules.team_note" class="rounded-md bg-muted px-3 py-2">{{ t('room_requests.rules_team') }}</p>
                        </template>
                    </template>
                </div>

                <footer v-if="rules && !rules.blocked" class="space-y-3 border-t px-5 py-4">
                    <p v-if="rules.accepted_before" class="text-sm text-green-700">
                        {{ t('room_requests.rules_accepted_before', { at: dateTime(rules.accepted_before) }) }}
                    </p>
                    <template v-else>
                        <p v-if="!readToEnd" class="text-xs text-muted-foreground">{{ t('room_requests.rules_scroll') }}</p>
                        <label class="flex items-start gap-2 text-sm font-medium">
                            <input v-model="ticked" type="checkbox" class="mt-0.5 size-5 rounded border-input" />
                            {{ t('room_requests.rules_accept_check') }}
                        </label>
                    </template>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="outline" @click="emit('close')">{{ t('room_requests.rules_decline') }}</Button>
                        <Button
                            type="button"
                            :disabled="processing || (!rules.accepted_before && !ticked && !readToEnd)"
                            @click="emit('accept', rules.version_id)"
                        >
                            {{ rules.accepted_before ? t('room_requests.rules_send') : t('room_requests.rules_accept') }}
                        </Button>
                    </div>
                </footer>
                <footer v-else class="flex justify-end border-t px-5 py-4">
                    <Button type="button" variant="outline" @click="emit('close')">{{ t('common.cancel') }}</Button>
                </footer>
            </div>
        </div>
    </Teleport>
</template>
