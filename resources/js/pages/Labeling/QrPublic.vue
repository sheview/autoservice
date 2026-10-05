<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head } from '@inertiajs/vue3';
import { LogIn, QrCode } from 'lucide-vue-next';

// The public side of an asset's QR code: no sign-in, so no app layout. Only the code and a broad
// name of the device; an unknown code or a wrong key reads the same.
defineProps<{
    company: string | null;
    asset: { asset_code: string; name: string } | null;
    signIn: string | null;
}>();
</script>

<template>
    <Head :title="t('qr.public_title')" />

    <div class="min-h-svh bg-gradient-to-b from-violet-50 to-white px-4 py-8 dark:from-slate-950 dark:to-slate-900">
        <div class="mx-auto w-full max-w-md space-y-6">
            <div class="space-y-2 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 text-white">
                    <QrCode class="size-7" />
                </div>
                <h1 class="text-2xl font-semibold">{{ t('qr.public_title') }}</h1>
                <p v-if="company" class="text-sm font-semibold text-violet-700 dark:text-violet-300">{{ company }}</p>
            </div>

            <div v-if="asset" class="rounded-2xl border bg-white p-5 text-center dark:bg-slate-950">
                <div class="text-lg font-semibold">{{ asset.name }}</div>
                <div class="font-mono text-sm text-muted-foreground">{{ asset.asset_code }}</div>
            </div>
            <p v-else class="rounded-2xl border border-dashed bg-white p-6 text-center text-sm text-muted-foreground dark:bg-slate-950">
                {{ t('qr.public_unknown') }}
            </p>

            <a
                v-if="signIn"
                :href="signIn"
                class="flex min-h-12 items-center justify-center gap-2 text-sm text-muted-foreground hover:text-violet-700"
            >
                <LogIn class="size-4" />{{ t('qr.staff_sign_in') }}
            </a>
        </div>
    </div>
</template>
