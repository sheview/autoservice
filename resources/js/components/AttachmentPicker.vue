<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { formatBytes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { Paperclip, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Picks files to attach when the form is saved. Files that are too big or of another type are
 * refused here already (the server checks again), so the user knows before saving.
 */
const props = withDefaults(defineProps<{ images?: boolean; errors?: Record<string, string>; field?: string }>(), {
    images: false,
    errors: () => ({}),
    field: 'attachments',
});

const files = defineModel<File[]>({ required: true });

// Same limits as App\Modules\Document\Support\Attachments.
const MAX_MB = 2;
const MAX_FILES = 10;

const extensions = computed(() => ['pdf', 'doc', 'docx', 'xls', 'xlsx', ...(props.images ? ['jpg', 'jpeg', 'png'] : [])]);
const accept = computed(() => extensions.value.map((ext) => `.${ext}`).join(','));
const refused = ref<string[]>([]);
const input = ref<HTMLInputElement | null>(null);

const pick = (event: Event) => {
    const target = event.target as HTMLInputElement;
    refused.value = [];
    const picked = [...(target.files ?? [])].filter((file) => {
        const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
        if (!extensions.value.includes(ext)) {
            refused.value.push(t('attachments.wrong_type', { name: file.name }));
            return false;
        }
        if (file.size > MAX_MB * 1024 * 1024) {
            refused.value.push(t('attachments.too_big', { name: file.name, mb: MAX_MB }));
            return false;
        }
        return true;
    });
    const all = [...files.value, ...picked];
    if (all.length > MAX_FILES) {
        refused.value.push(t('attachments.too_many', { max: MAX_FILES }));
    }
    files.value = all.slice(0, MAX_FILES);
    target.value = ''; // the same file can be picked again after removing it
};

const remove = (index: number) => (files.value = files.value.filter((_, i) => i !== index));

// Server errors: "attachments" or "attachments.N".
const serverErrors = computed(() =>
    Object.entries(props.errors)
        .filter(([key]) => key === props.field || key.startsWith(`${props.field}.`))
        .map(([, message]) => message),
);
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap items-center gap-3">
            <Button type="button" variant="outline" size="sm" :disabled="files.length >= MAX_FILES" @click="input?.click()">
                <Paperclip class="h-4 w-4" />
                {{ t('attachments.choose') }}
            </Button>
            <span class="text-xs text-muted-foreground">
                {{ t(images ? 'attachments.hint_images' : 'attachments.hint', { mb: MAX_MB, max: MAX_FILES }) }}
            </span>
            <input ref="input" type="file" multiple :accept="accept" class="hidden" :aria-label="t('attachments.choose')" @change="pick" />
        </div>
        <ul v-if="files.length" class="divide-y rounded-md border text-sm">
            <li v-for="(file, index) in files" :key="`${file.name}-${index}`" class="flex items-center gap-2 px-3 py-1.5">
                <Paperclip class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate">{{ file.name }}</span>
                <span class="text-xs text-muted-foreground">{{ formatBytes(file.size) }} · {{ t('attachments.pending') }}</span>
                <Button type="button" variant="ghost" size="icon" class="h-7 w-7" :aria-label="t('attachments.remove')" @click="remove(index)">
                    <X class="h-4 w-4" />
                </Button>
            </li>
        </ul>
        <InputError v-for="message in [...refused, ...serverErrors]" :key="message" :message="message" />
    </div>
</template>
