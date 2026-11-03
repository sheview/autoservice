<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { mapsUrl, parseCoordinates } from '@/lib/coordinates';
import { t } from '@/lib/i18n';
import { ExternalLink, LocateFixed, MapPin, X } from 'lucide-vue-next';
import { defineAsyncComponent, ref, watch } from 'vue';

// Leaflet loads only when the map is opened.
const PinPicker = defineAsyncComponent(() => import('@/components/PinPicker.vue'));

/**
 * A place on the map as lat / lng: typed as "13.75, 100.50", pasted as a Google Maps link,
 * taken from the device's location, or picked on a map. Both empty = no place.
 */
const lat = defineModel<string>('lat', { required: true });
const lng = defineModel<string>('lng', { required: true });
defineProps<{ id?: string; error?: string }>();

const text = ref(lat.value && lng.value ? `${lat.value}, ${lng.value}` : '');
const invalid = ref(false);
const locating = ref(false);
const locateError = ref('');
const picking = ref(false);

// Saved from outside (form reset or another record): show what the form now holds.
watch([lat, lng], ([newLat, newLng]) => {
    const parsed = parseCoordinates(text.value);
    if (parsed?.lat !== newLat || parsed?.lng !== newLng) {
        text.value = newLat && newLng ? `${newLat}, ${newLng}` : '';
    }
});

const read = () => {
    const parsed = parseCoordinates(text.value);
    invalid.value = text.value.trim() !== '' && parsed === null;
    lat.value = parsed?.lat ?? '';
    lng.value = parsed?.lng ?? '';
    if (parsed) text.value = `${parsed.lat}, ${parsed.lng}`;
};

// The pasted text is in the box only after the paste event.
const readAfterPaste = () => setTimeout(read);

const pick = (pickedLat: string, pickedLng: string) => {
    text.value = `${pickedLat}, ${pickedLng}`;
    read();
};

const clear = () => {
    text.value = '';
    read();
};

const locate = () => {
    locateError.value = '';
    if (!navigator.geolocation) {
        locateError.value = t('coordinates.locate_unsupported');
        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            locating.value = false;
            text.value = `${position.coords.latitude.toFixed(7)}, ${position.coords.longitude.toFixed(7)}`;
            read();
        },
        () => {
            locating.value = false;
            locateError.value = t('coordinates.locate_failed');
        },
        { enableHighAccuracy: true, timeout: 15000 },
    );
};
</script>

<template>
    <div class="grid gap-2">
        <div class="flex flex-wrap gap-2">
            <Input
                :id="id"
                v-model="text"
                class="min-w-0 flex-1 font-mono"
                :placeholder="t('coordinates.placeholder')"
                autocomplete="off"
                @change="read"
                @paste="readAfterPaste"
            />
            <Button type="button" variant="outline" :disabled="locating" @click="locate">
                <LocateFixed class="h-4 w-4" />
                {{ locating ? t('coordinates.locating') : t('coordinates.locate') }}
            </Button>
            <Button type="button" variant="outline" @click="picking = !picking">
                <MapPin class="h-4 w-4" />
                {{ picking ? t('map.hide_map') : t('map.pick_on_map') }}
            </Button>
            <Button v-if="lat && lng" type="button" variant="outline" as-child>
                <a :href="mapsUrl(lat, lng)" target="_blank" rel="noopener">
                    <ExternalLink class="h-4 w-4" />
                    {{ t('coordinates.open_map') }}
                </a>
            </Button>
            <Button v-if="text" type="button" variant="ghost" :aria-label="t('coordinates.clear')" @click="clear">
                <X class="h-4 w-4" />
            </Button>
        </div>
        <PinPicker v-if="picking" :lat="lat" :lng="lng" @pick="pick" />
        <p class="text-xs text-muted-foreground">{{ t('coordinates.hint') }}</p>
        <InputError :message="invalid ? t('coordinates.invalid') : locateError || error" />
    </div>
</template>
