<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Eye } from 'lucide-vue-next';

const page = usePage<SharedData>();

const stop = () => router.delete(route('platform.impersonation.destroy'));
</script>

<template>
    <div
        v-if="page.props.impersonation"
        role="alert"
        class="sticky top-0 z-20 flex flex-wrap items-center justify-between gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950"
    >
        <span class="flex items-center gap-2">
            <Eye class="h-4 w-4 shrink-0" />
            {{ t('impersonation.banner', { tenant: page.props.impersonation.tenant.name }) }}
        </span>
        <button type="button" class="rounded-md bg-amber-950 px-3 py-1 text-amber-50 hover:bg-amber-900" @click="stop">
            {{ t('impersonation.stop') }}
        </button>
    </div>
</template>
