<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import IpStatusBadge from '@/components/IpStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface IpLabel {
    id: number;
    ulid: string;
    ip: string;
    status: string;
    cidr: string | null;
}

/**
 * The IP address a ticket is about: shown with a link to its page, picked by typing an address
 * or hostname, or taken from the ticket's asset in one click.
 */
const props = defineProps<{
    ticketUlid: string;
    ip: { current: IpLabel | null; suggested: { key: string; ip: string; cidr: string | null } | null; can_change: boolean };
    choices: { key: string; ip: string; cidr: string; status: string; hostname: string | null }[] | undefined;
}>();

const page = usePage<SharedData>();
const error = computed(() => (page.props.errors as Record<string, string>).ip);

const search = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.reload({ only: ['ipChoices'], data: { ip_search: value } }), 300);
});

const link = (key: string | null) => {
    router.post(route('service.tickets.ip', props.ticketUlid), { ip: key }, { preserveScroll: true, onSuccess: () => (search.value = '') });
};
</script>

<template>
    <section class="space-y-2">
        <h3 class="text-sm font-semibold">{{ t('ip_check.ticket_ip') }}</h3>
        <div class="space-y-3 rounded-md border p-4 text-sm">
            <div v-if="ip.current" class="flex flex-wrap items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                    <Link :href="route('asset.ip-check.ips.by-ulid', ip.current.ulid)" class="font-mono text-primary hover:underline">{{
                        ip.current.ip
                    }}</Link>
                    <span class="text-xs text-muted-foreground">{{ ip.current.cidr }}</span>
                    <IpStatusBadge :status="ip.current.status" />
                </span>
                <button v-if="ip.can_change" type="button" class="text-xs text-muted-foreground hover:underline" @click="link(null)">
                    {{ t('ip_check.ticket_ip_remove') }}
                </button>
            </div>
            <p v-else class="text-muted-foreground">{{ t('ip_check.ticket_ip_none') }}</p>

            <template v-if="ip.can_change">
                <div v-if="!ip.current && ip.suggested" class="flex flex-wrap items-center gap-2">
                    <span>{{ t('ip_check.ticket_ip_suggested', { ip: ip.suggested.ip }) }}</span>
                    <Button size="sm" variant="outline" @click="link(ip.suggested.key)">{{ t('ip_check.ticket_ip_use') }}</Button>
                </div>
                <Input v-model="search" type="search" autocomplete="off" class="font-mono" :placeholder="t('ip_check.ticket_ip_search')" />
                <ul v-if="search && choices?.length" class="max-h-56 divide-y overflow-y-auto rounded-md border">
                    <li v-for="choice in choices" :key="choice.key">
                        <button type="button" class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-muted" @click="link(choice.key)">
                            <span class="font-mono">{{ choice.ip }}</span>
                            <span class="text-xs text-muted-foreground">{{ choice.cidr }}</span>
                            <span v-if="choice.hostname" class="text-xs">{{ choice.hostname }}</span>
                            <IpStatusBadge :status="choice.status" class="ml-auto" />
                        </button>
                    </li>
                </ul>
                <p v-else-if="search && choices" class="text-xs text-muted-foreground">{{ t('common.no_results') }}</p>
                <InputError :message="error" />
            </template>
        </div>
    </section>
</template>
