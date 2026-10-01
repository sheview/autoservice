<script setup lang="ts">
import AttachmentPicker from '@/components/AttachmentPicker.vue';
import { Button } from '@/components/ui/button';
import { dateTime, formatBytes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { router, useForm } from '@inertiajs/vue3';
import { FileText, Trash2 } from 'lucide-vue-next';

export interface Attachment {
    id: number;
    name: string;
    size: number;
    uploaded_at: string | null;
    // Opens (PDF) or downloads the file; DELETE on it removes the file.
    url: string;
}

/**
 * The files attached to a record. With uploadUrl, more files can be attached right here.
 */
const props = withDefaults(
    defineProps<{ attachments: Attachment[]; canDelete?: boolean; uploadUrl?: string | null; images?: boolean; title?: boolean }>(),
    { canDelete: false, uploadUrl: null, images: false, title: true },
);

const upload = useForm({ attachments: [] as File[] });
const send = () =>
    upload.post(props.uploadUrl!, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => upload.reset(),
    });

const destroy = (file: Attachment) => {
    if (confirm(t('attachments.confirm_delete', { name: file.name }))) {
        router.delete(file.url, { preserveScroll: true });
    }
};
</script>

<template>
    <section class="space-y-2">
        <h3 v-if="title" class="text-sm font-semibold">{{ t('attachments.title') }}</h3>
        <ul v-if="attachments.length" class="divide-y rounded-md border text-sm">
            <li v-for="file in attachments" :key="file.id" class="flex flex-wrap items-center gap-2 px-4 py-2">
                <FileText class="h-4 w-4 shrink-0 text-muted-foreground" />
                <a :href="file.url" target="_blank" rel="noopener" class="min-w-0 flex-1 truncate text-primary underline-offset-4 hover:underline">
                    {{ file.name }}
                </a>
                <span class="text-xs text-muted-foreground">
                    {{ formatBytes(file.size) }}<template v-if="file.uploaded_at"> · {{ dateTime(file.uploaded_at) }}</template>
                </span>
                <Button
                    v-if="canDelete"
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="h-7 w-7"
                    :aria-label="t('common.delete')"
                    @click="destroy(file)"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">{{ t('attachments.none') }}</p>

        <form v-if="uploadUrl" class="space-y-2" @submit.prevent="send">
            <AttachmentPicker v-model="upload.attachments" :images="images" :errors="upload.errors" />
            <Button v-if="upload.attachments.length" size="sm" :disabled="upload.processing">{{ t('attachments.upload') }}</Button>
        </form>
    </section>
</template>
