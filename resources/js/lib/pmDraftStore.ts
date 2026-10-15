/**
 * Where an unsaved PM item (what the technician typed so far) is kept. The pages only talk to a
 * DraftStore, so the server store below can later be swapped for one that keeps drafts on the
 * device (IndexedDB) and sends them when back online — the PWA step — without touching the pages.
 */
import type { Answer } from '@/lib/pmTemplate';

export type PmItemDraft = {
    result: string | null;
    answers: Record<string, Answer>;
    remarks: Record<string, string>;
    note: string;
    not_inspected_reason: string | null;
    instrument_id: number | null;
};

export type DraftTarget = { visitUlid: string; itemId: number };

export interface DraftStore {
    /** Keeps the draft; resolves with when it was kept (ISO 8601). */
    save(target: DraftTarget, draft: PmItemDraft): Promise<string>;
}

const xsrfToken = () => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

/** Sends the draft to the server (PmFieldController@draft). */
export const serverDraftStore: DraftStore = {
    async save(target, draft) {
        const response = await fetch(route('maintenance.field.draft', [target.visitUlid, target.itemId]), {
            method: 'PUT',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(draft),
        });
        if (!response.ok) throw new Error(`draft ${response.status}`);
        return ((await response.json()) as { saved_at: string }).saved_at;
    },
};
