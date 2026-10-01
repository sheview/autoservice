<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { Head, router } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';

// Shown instead of every page once the company's subscription and its read-only period are over.
defineProps<{
    company: string;
    subscription: { ends_on: string | null; read_only_until: string | null };
}>();

const logout = () => router.post(route('logout'));
</script>

<template>
    <Head :title="t('subscription.locked_title')" />

    <div class="flex min-h-svh items-center justify-center bg-background p-6">
        <div class="w-full max-w-md space-y-4 rounded-xl border p-6 text-center">
            <Lock class="mx-auto h-10 w-10 text-muted-foreground" />
            <h1 class="text-xl font-semibold">{{ t('subscription.locked_title') }}</h1>
            <p class="text-sm text-muted-foreground">
                {{ t('subscription.locked', { company, date: subscription.ends_on ?? '', until: subscription.read_only_until ?? '' }) }}
            </p>
            <p class="text-sm">{{ t('subscription.contact') }}</p>
            <Button variant="outline" @click="logout">{{ t('subscription.logout') }}</Button>
        </div>
    </div>
</template>
