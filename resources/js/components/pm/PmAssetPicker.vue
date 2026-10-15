<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { computed, ref } from 'vue';

export interface PickableAsset {
    id: number;
    asset_code: string;
    name: string;
    category: string | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    location: string | null;
    floor: string | null;
    room: string | null;
}

/**
 * Picks the equipment a PM plan covers out of its contract's: filters by category, brand and place
 * (floor / room, else location) and a search, "pick all shown". None picked = every asset.
 */
const props = defineProps<{ assets: PickableAsset[] }>();
const model = defineModel<number[]>({ required: true });

const search = ref('');
const category = ref('');
const brand = ref('');
const place = ref('');
const placeOf = (a: PickableAsset) =>
    [a.floor ? t('pm_field.floor', { floor: a.floor }) : null, a.room].filter(Boolean).join(' · ') || a.location || '';
const options = (pick: (a: PickableAsset) => string | null) => [...new Set(props.assets.map(pick).filter((v): v is string => !!v))].sort();

const shown = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.assets.filter(
        (a) =>
            (!category.value || a.category === category.value) &&
            (!brand.value || a.brand === brand.value) &&
            (!place.value || placeOf(a) === place.value) &&
            (!term || [a.asset_code, a.name, a.serial_number, a.model].some((v) => v?.toLowerCase().includes(term))),
    );
});
const picked = computed(() => new Set(model.value));
const toggle = (id: number) => (model.value = picked.value.has(id) ? model.value.filter((v) => v !== id) : [...model.value, id]);
const pickShown = () => (model.value = [...new Set([...model.value, ...shown.value.map((a) => a.id)])]);
const clearShown = () => {
    const ids = new Set(shown.value.map((a) => a.id));
    model.value = model.value.filter((id) => !ids.has(id));
};

const selectClass = 'h-9 rounded-md border border-input bg-transparent px-2 text-sm shadow-xs';
</script>

<template>
    <div class="space-y-2">
        <p class="text-xs text-muted-foreground">
            {{
                model.length
                    ? t('pm_plans.assets_picked', { count: model.length, total: assets.length })
                    : t('pm_plans.assets_all', { total: assets.length })
            }}
        </p>
        <div class="flex flex-wrap gap-2">
            <Input v-model="search" type="search" class="h-9 max-w-52" :placeholder="t('pm_plans.assets_search')" />
            <select v-model="category" :class="selectClass" :aria-label="t('pm_plans.assets_category')">
                <option value="">{{ t('pm_plans.assets_category') }}</option>
                <option v-for="option in options((a) => a.category)" :key="option" :value="option">{{ option }}</option>
            </select>
            <select v-model="brand" :class="selectClass" :aria-label="t('pm_plans.assets_brand')">
                <option value="">{{ t('pm_plans.assets_brand') }}</option>
                <option v-for="option in options((a) => a.brand)" :key="option" :value="option">{{ option }}</option>
            </select>
            <select v-model="place" :class="selectClass" :aria-label="t('pm_plans.assets_place')">
                <option value="">{{ t('pm_plans.assets_place') }}</option>
                <option v-for="option in options(placeOf)" :key="option" :value="option">{{ option }}</option>
            </select>
            <Button type="button" variant="outline" size="sm" class="h-9" @click="pickShown">{{
                t('pm_plans.assets_pick_shown', { count: shown.length })
            }}</Button>
            <Button type="button" variant="ghost" size="sm" class="h-9" @click="clearShown">{{ t('pm_plans.assets_clear_shown') }}</Button>
        </div>
        <div class="max-h-80 overflow-auto rounded-md border">
            <table class="w-full text-sm">
                <tbody>
                    <tr
                        v-for="asset in shown"
                        :key="asset.id"
                        class="cursor-pointer border-t first:border-t-0 hover:bg-muted/50"
                        @click="toggle(asset.id)"
                    >
                        <td class="w-8 px-2 py-1.5">
                            <input
                                type="checkbox"
                                class="size-4 rounded border-input"
                                :checked="picked.has(asset.id)"
                                :aria-label="asset.asset_code"
                                @click.stop="toggle(asset.id)"
                            />
                        </td>
                        <td class="px-2 py-1.5 font-mono text-xs">{{ asset.asset_code }}</td>
                        <td class="px-2 py-1.5">{{ asset.name }}</td>
                        <td class="px-2 py-1.5 text-xs text-muted-foreground">
                            {{ [asset.category, asset.brand, asset.model].filter(Boolean).join(' · ') }}
                        </td>
                        <td class="px-2 py-1.5 text-xs text-muted-foreground">{{ placeOf(asset) }}</td>
                    </tr>
                    <tr v-if="shown.length === 0">
                        <td colspan="5" class="px-2 py-4 text-center text-muted-foreground">{{ t('common.no_results') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
