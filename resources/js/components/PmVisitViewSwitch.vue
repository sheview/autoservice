<script setup lang="ts">
import { useCalendarLink } from '@/composables/useCalendarLink';
import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import { CalendarDays, List } from 'lucide-vue-next';

defineProps<{ current: 'list' | 'calendar' }>();

const base = 'flex items-center gap-1.5 px-3 py-1.5 text-sm';
// The team's PM on the one calendar (งานของฉัน), where there is one.
const calendarHref = useCalendarLink('pm', route('maintenance.calendar'));
</script>

<template>
    <div class="inline-flex overflow-hidden rounded-md border">
        <Link :href="route('maintenance.visits.index')" :class="[base, current === 'list' ? 'bg-muted font-medium' : 'hover:bg-muted/50']">
            <List class="h-4 w-4" />
            {{ t('pm_visits.list_view') }}
        </Link>
        <Link :href="calendarHref" :class="[base, 'border-l', current === 'calendar' ? 'bg-muted font-medium' : 'hover:bg-muted/50']">
            <CalendarDays class="h-4 w-4" />
            {{ t('pm_visits.calendar_view') }}
        </Link>
    </div>
</template>
