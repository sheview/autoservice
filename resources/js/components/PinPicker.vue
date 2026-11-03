<script setup lang="ts">
import { t } from '@/lib/i18n';
import { createMap, dotIcon } from '@/lib/map';
import L from 'leaflet';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * A small map to place a pin: a click puts it there (lat / lng to 7 decimals, as stored).
 * Opens on the pin when there is one, else on Thailand.
 */
const props = defineProps<{ lat: string; lng: string }>();
const emit = defineEmits<{ pick: [lat: string, lng: string] }>();

const element = ref<HTMLElement | null>(null);
let map: L.Map | null = null;
let marker: L.Marker | null = null;

const place = (lat: string, lng: string, pan: boolean) => {
    if (!map) return;
    if (!lat || !lng) {
        marker?.remove();
        marker = null;
        return;
    }
    const point: L.LatLngTuple = [Number(lat), Number(lng)];
    marker ??= L.marker(point, { icon: dotIcon('#7c3aed', 20) }).addTo(map);
    marker.setLatLng(point);
    if (pan) map.setView(point, Math.max(map.getZoom(), 16));
};

onMounted(() => {
    if (!element.value) return;
    map = createMap(element.value);
    place(props.lat, props.lng, true);
    map.on('click', (event: L.LeafletMouseEvent) => emit('pick', event.latlng.lat.toFixed(7), event.latlng.lng.toFixed(7)));
});

onBeforeUnmount(() => map?.remove());

// Typed, pasted or located from outside: move the pin there.
watch(
    () => [props.lat, props.lng],
    ([lat, lng]) => place(lat, lng, true),
);
</script>

<template>
    <div class="grid gap-1">
        <div ref="element" class="isolate h-72 w-full cursor-crosshair rounded-md border" />
        <p class="text-xs text-muted-foreground">{{ t('map.pick_hint') }}</p>
    </div>
</template>
