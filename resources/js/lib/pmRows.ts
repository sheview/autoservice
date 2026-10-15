/**
 * The table of a PM work order (PC / Network templates) saves row by row without reloading the
 * page: a row with a result goes to PmFieldController@saveRow, "ผ่านทั้งหมด" to @passAll.
 */
import { xsrfToken } from '@/lib/pmDraftStore';
import type { Answer } from '@/lib/pmTemplate';

/** Fields shown in a cell; the others (tables, photos, multiple choice) are filled in on the item's own page. */
export const INLINE_TYPES = ['pass_fail', 'check', 'text', 'textarea', 'number', 'measure', 'select', 'readonly_from_asset'];

export type RowItem = {
    id: number;
    result: string;
    answers: Record<string, Answer>;
    note: string | null;
    not_inspected_reason: string | null;
    has_draft: boolean;
};

export type RowPayload = {
    result: string;
    answers: Record<string, Answer>;
    note: string | null;
    not_inspected_reason: string | null;
};

/** The server refused the row: messages by field (Laravel's 422). */
export class RowRejected extends Error {
    constructor(public errors: Record<string, string[]>) {
        super(Object.values(errors).flat()[0] ?? 'rejected');
    }
}

const send = async <T>(url: string, method: string, body: unknown): Promise<T> => {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    if (response.status === 422) throw new RowRejected(((await response.json()) as { errors: Record<string, string[]> }).errors);
    if (!response.ok) throw new Error(`row ${response.status}`);
    return (await response.json()) as T;
};

export const saveRow = async (visitUlid: string, itemId: number, payload: RowPayload): Promise<RowItem> =>
    (await send<{ item: RowItem }>(route('maintenance.field.row', [visitUlid, itemId]), 'PUT', payload)).item;

export const passRows = (visitUlid: string, itemIds: number[]) =>
    send<{ items: RowItem[]; skipped: number[]; message: string }>(route('maintenance.field.pass-all', visitUlid), 'POST', { item_ids: itemIds });
