<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import { ImageOff } from 'lucide-vue-next';
import { ref } from 'vue';

// One suggestion for the item name (PurchaseItemSuggestions): a part in stock or an item bought before.
export interface PurchaseItemSuggestion {
    source: 'part' | 'purchase';
    code: string | null;
    name: string;
    detail: string | null;
    unit: string | null;
    unit_price: string | null;
    item_kind: 'asset' | 'part' | null;
    asset_category_id: number | null;
    description: string | null;
    links: string[];
    on_hand: number | null;
    photo: string | null;
}

/** The item name of a purchase request, with suggestions under it while it is typed. */
const model = defineModel<string>({ required: true });
defineProps<{ id: string; placeholder?: string; required?: boolean }>();
const emit = defineEmits<{ pick: [suggestion: PurchaseItemSuggestion] }>();

const suggestions = ref<PurchaseItemSuggestion[]>([]);
const open = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const lookup = () => {
    clearTimeout(timer);
    const q = model.value.trim();
    if (q.length < 2) {
        suggestions.value = [];
        open.value = false;
        return;
    }
    timer = setTimeout(async () => {
        try {
            const response = await fetch(route('inventory.purchase-requests.suggestions', { q }), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error(String(response.status));
            suggestions.value = (await response.json()) as PurchaseItemSuggestion[];
            open.value = suggestions.value.length > 0;
        } catch {
            suggestions.value = [];
            open.value = false;
        }
    }, 250);
};

const pick = (suggestion: PurchaseItemSuggestion) => {
    model.value = suggestion.name;
    open.value = false;
    emit('pick', suggestion);
};
</script>

<template>
    <div class="relative">
        <Input
            :id="id"
            v-model="model"
            autocomplete="off"
            :required="required"
            :placeholder="placeholder"
            @input="lookup"
            @focus="open = suggestions.length > 0"
            @blur="open = false"
            @keydown.esc="open = false"
        />
        <ul v-if="open" class="absolute z-20 mt-1 max-h-80 w-full overflow-y-auto rounded-md border bg-background shadow-md">
            <li class="bg-muted/50 px-3 py-1 text-xs font-medium">{{ t('purchase_requests.suggest.title') }}</li>
            <li v-for="(suggestion, index) in suggestions" :key="`${suggestion.source}-${suggestion.code}-${index}`">
                <!-- mousedown: picked before the box loses focus and closes the list -->
                <button
                    type="button"
                    class="flex w-full items-center gap-3 border-t px-3 py-2 text-left text-sm hover:bg-muted"
                    @mousedown.prevent="pick(suggestion)"
                >
                    <span
                        v-if="suggestion.source === 'part'"
                        class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted text-muted-foreground/50"
                    >
                        <img v-if="suggestion.photo" :src="suggestion.photo" :alt="suggestion.name" class="size-full object-cover" loading="lazy" />
                        <ImageOff v-else class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ suggestion.name }}</span>
                        <span class="block truncate text-xs text-muted-foreground">
                            {{ t(`purchase_requests.suggest.${suggestion.source}`) }}
                            <span class="font-mono">{{ suggestion.code }}</span>
                            <template v-if="suggestion.detail"> · {{ suggestion.detail }}</template>
                        </span>
                    </span>
                    <span class="whitespace-nowrap text-right text-xs text-muted-foreground">
                        <template v-if="suggestion.on_hand !== null">
                            {{ t('purchase_requests.suggest.on_hand', { qty: suggestion.on_hand, unit: suggestion.unit ?? '' }) }}<br />
                        </template>
                        <template v-if="suggestion.unit_price !== null">
                            {{ t('purchase_requests.suggest.price', { price: Number(suggestion.unit_price).toLocaleString('th-TH') }) }}
                        </template>
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
