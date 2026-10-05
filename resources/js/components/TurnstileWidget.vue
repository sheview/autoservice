<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Cloudflare Turnstile, the light CAPTCHA of the public forms: shown only when the server gives a
 * site key; the token it hands back is sent with the form as "cf-turnstile-response".
 */
const props = defineProps<{ siteKey: string }>();
const token = defineModel<string>({ default: '' });

type Turnstile = { render: (el: HTMLElement, options: Record<string, unknown>) => string; remove: (id: string) => void };
const box = ref<HTMLElement | null>(null);
let widgetId: string | null = null;

const load = (): Promise<Turnstile> =>
    new Promise((resolve) => {
        const w = window as unknown as { turnstile?: Turnstile };
        if (w.turnstile) return resolve(w.turnstile);
        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        script.async = true;
        script.onload = () => resolve((window as unknown as { turnstile: Turnstile }).turnstile);
        document.head.appendChild(script);
    });

onMounted(async () => {
    const turnstile = await load();
    if (box.value) {
        widgetId = turnstile.render(box.value, {
            sitekey: props.siteKey,
            language: 'th',
            callback: (value: string) => (token.value = value),
            'expired-callback': () => (token.value = ''),
        });
    }
});
onBeforeUnmount(() => {
    const w = window as unknown as { turnstile?: Turnstile };
    if (widgetId && w.turnstile) w.turnstile.remove(widgetId);
});
</script>

<template>
    <div ref="box" class="flex min-h-16 justify-center" />
</template>
