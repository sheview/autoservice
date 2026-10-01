<script setup lang="ts">
import AttachmentList, { type Attachment } from '@/components/AttachmentList.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';

const props = defineProps<{
    manual: {
        id: number;
        title: string;
        category: string | null;
        description: string | null;
        links: { label: string | null; url: string }[];
        created_by_name: string | null;
        created_at: string | null;
        updated_at: string | null;
    };
    attachments: Attachment[];
    maxMb: number;
    can: { manage: boolean };
}>();

const page = usePage<SharedData>();
const breadcrumbs: BreadcrumbItem[] = [
    { title: t('manuals.title'), href: route('document.manuals.index') },
    { title: props.manual.title, href: route('document.manuals.show', props.manual.id) },
];

// Links are shown with their site, so a reader sees where they lead.
const host = (url: string) => {
    try {
        return new URL(url).hostname;
    } catch {
        return url;
    }
};

const destroy = () => {
    if (confirm(t('manuals.confirm_delete', { title: props.manual.title }))) {
        router.delete(route('document.manuals.destroy', props.manual.id));
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="manual.title" />

        <div class="max-w-4xl space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p v-if="manual.category" class="text-sm text-muted-foreground">{{ manual.category }}</p>
                    <Heading
                        :title="manual.title"
                        :description="`${manual.created_by_name ? t('manuals.added_by', { name: manual.created_by_name }) + ' · ' : ''}${t('manuals.updated_at')} ${dateTime(manual.updated_at)}`"
                    />
                </div>
                <div v-if="can.manage" class="flex gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('document.manuals.edit', manual.id)">{{ t('common.edit') }}</Link>
                    </Button>
                    <Button variant="ghost" @click="destroy">{{ t('manuals.delete') }}</Button>
                </div>
            </div>

            <p v-if="page.props.flash.success" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                {{ page.props.flash.success }}
            </p>

            <p v-if="manual.description" class="whitespace-pre-line text-sm">{{ manual.description }}</p>

            <section v-if="manual.links.length" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('manuals.links') }}</h3>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="link in manual.links" :key="link.url" class="flex items-center gap-2 px-4 py-2">
                        <ExternalLink class="h-4 w-4 shrink-0 text-muted-foreground" />
                        <a
                            :href="link.url"
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                            class="min-w-0 flex-1 truncate text-primary underline-offset-4 hover:underline"
                        >
                            {{ link.label ?? link.url }}
                        </a>
                        <span class="shrink-0 rounded bg-muted px-1.5 text-xs text-muted-foreground">{{ host(link.url) }}</span>
                    </li>
                </ul>
            </section>

            <section class="space-y-2">
                <h3 class="text-sm font-semibold">{{ t('manuals.files') }}</h3>
                <AttachmentList
                    :attachments="attachments"
                    :title="false"
                    images
                    :max-mb="maxMb"
                    :can-delete="can.manage"
                    :upload-url="can.manage ? route('document.manuals.attachments.store', manual.id) : null"
                />
            </section>

            <p v-if="!manual.links.length && !attachments.length && !can.manage" class="text-sm text-muted-foreground">{{ t('manuals.none') }}</p>

            <Button variant="outline" as-child>
                <Link :href="route('document.manuals.index')">{{ t('common.back') }}</Link>
            </Button>
        </div>
    </AppLayout>
</template>
