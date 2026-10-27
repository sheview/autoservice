<script setup lang="ts">
import { t } from '@/lib/i18n';
import { uploadNotice } from '@/lib/uploadNotice';
import { X } from 'lucide-vue-next';
</script>

<template>
    <!-- A request too large for the server (see lib/uploadNotice.ts) -->
    <div
        v-if="uploadNotice"
        role="alert"
        class="fixed inset-x-4 bottom-4 z-50 mx-auto flex max-w-xl items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 shadow-lg dark:border-red-900 dark:bg-red-950 dark:text-red-200"
    >
        <p class="flex-1">{{ uploadNotice }}</p>
        <button type="button" class="-m-2 flex size-11 shrink-0 items-center justify-center rounded-md" :aria-label="t('common.dismiss')" @click="uploadNotice = null">
            <X class="size-4" />
        </button>
    </div>
</template>
