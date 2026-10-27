<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <main
        :class="
            cn(
                // The page's own text colour: the wrapper around it carries the (light) sidebar's.
                // min-w-0: a wide table scrolls inside the page instead of squeezing the sidebar's gap (tablets).
                'relative flex min-h-svh min-w-0 flex-1 flex-col bg-background text-foreground',
                'peer-data-[variant=inset]:min-h-[calc(100svh-theme(spacing.4))] md:peer-data-[variant=inset]:m-2 md:peer-data-[state=collapsed]:peer-data-[variant=inset]:ml-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow-lg md:peer-data-[variant=inset]:shadow-black/30',
                props.class,
            )
        "
    >
        <slot />
    </main>
</template>
