<script setup lang="ts">
import { t } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { GraduationCap } from 'lucide-vue-next';

// The training company: a strip on every page, so nobody takes it for a real company's data.
const page = usePage<SharedData>();
</script>

<template>
    <div
        v-if="page.props.tenant?.is_sample"
        role="status"
        class="flex items-center gap-2 bg-sky-100 px-4 py-2 text-sm font-medium text-sky-900 dark:bg-sky-950 dark:text-sky-100"
    >
        <GraduationCap class="h-4 w-4 shrink-0" />
        {{ t('sample_company.banner') }}
    </div>
</template>
