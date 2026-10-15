/**
 * The PM template schema on the browser side: the same fields and range rules as
 * app/Modules/Maintenance/Support/ChecklistItems.php, for marking a reading red while it is typed.
 * The server judges every answer again when it is saved.
 */

export type PassFail = 'pass' | 'fail' | 'na';

export type TablePart = { key: string; label: string; unit?: string; min?: number | null; max?: number | null };

export type RepeatColumn = TablePart & { type: 'text' | 'number' | 'pass_fail' };

// A type alias (not an interface), so useForm accepts it as form data.
export type TemplateField = {
    key: string;
    label: string;
    type: string;
    section?: string;
    required?: boolean;
    unit?: string;
    min?: number | null;
    max?: number | null;
    options?: string[];
    rows?: TablePart[] | { count?: number; from?: string; max?: number };
    columns?: (TablePart | RepeatColumn)[];
    photos?: number;
    source?: string;
};

export type MeasureTableAnswer = Record<string, Record<string, number | string | null>>;
export type RepeatRow = Record<string, number | string | null>;
export type Answer = boolean | string | number | string[] | MeasureTableAnswer | RepeatRow[] | null;

export const PASS_FAIL: PassFail[] = ['pass', 'fail', 'na'];

const isSet = (value: unknown) => value !== null && value !== undefined && value !== '';
const num = (value: unknown) => (value === null || value === undefined ? null : Number(value));

export const isNumber = (value: unknown): boolean => isSet(value) && !isNaN(Number(value));

export const outOfRange = (value: unknown, min?: number | null, max?: number | null): boolean => {
    if (!isNumber(value)) return false;
    const n = Number(value);
    return (isSet(min) && n < Number(min)) || (isSet(max) && n > Number(max));
};

/** A measure table cell takes its column's range, else its row's. */
export const cellRange = (row: TablePart, column: TablePart): [number | null, number | null] =>
    isSet(column.min) || isSet(column.max) ? [num(column.min), num(column.max)] : [num(row.min), num(row.max)];

/** "210 – 240 V", "≥ 10.5", "≤ 32 °C" or '' : the recommended range as shown. */
export const rangeText = (min?: number | null, max?: number | null, unit?: string): string => {
    const text = isSet(min) && isSet(max) ? `${min} – ${max}` : isSet(min) ? `≥ ${min}` : isSet(max) ? `≤ ${max}` : '';
    return text && unit ? `${text} ${unit}` : text;
};

export const tableRows = (field: TemplateField): TablePart[] => (Array.isArray(field.rows) ? field.rows : []);

/** How many rows a repeat table has: fixed, or the number typed in its "from" field (up to max). */
export const repeatRowCount = (field: TemplateField, answers: Record<string, Answer>): number => {
    const rows = Array.isArray(field.rows) ? {} : (field.rows ?? {});
    if (rows.from) {
        const count = Math.floor(Number(answers[rows.from] ?? 0)) || 0;
        return Math.max(0, Math.min(count, rows.max ?? 100));
    }
    return rows.count ?? 0;
};

/** Labels of what failed, as ChecklistItems::failures (a table names its cells). */
export const failures = (fields: TemplateField[], answers: Record<string, Answer>): string[] => {
    const failed: string[] = [];
    for (const field of fields) {
        const answer = answers[field.key];
        if (field.type === 'pass_fail' && answer === 'fail') failed.push(field.label);
        if ((field.type === 'number' || field.type === 'measure') && outOfRange(answer, field.min, field.max)) failed.push(field.label);
        if (field.type === 'measure_table' && answer && typeof answer === 'object' && !Array.isArray(answer)) {
            for (const row of tableRows(field)) {
                for (const column of field.columns ?? []) {
                    const [min, max] = cellRange(row, column);
                    if (outOfRange((answer as MeasureTableAnswer)[row.key]?.[column.key], min, max)) {
                        failed.push(`${field.label} – ${row.label} ${column.label}`);
                    }
                }
            }
        }
        if (field.type === 'repeat_table' && Array.isArray(answer)) {
            (answer as RepeatRow[]).forEach((row, index) => {
                for (const column of (field.columns ?? []) as RepeatColumn[]) {
                    const value = row?.[column.key];
                    const bad =
                        column.type === 'pass_fail' ? value === 'fail' : column.type === 'number' && outOfRange(value, column.min, column.max);
                    if (bad) failed.push(`${field.label} – ${index + 1} ${column.label}`);
                }
            });
        }
    }
    return failed;
};

/** The empty answer of a field, to start a form with. */
export const emptyAnswer = (field: TemplateField): Answer => {
    switch (field.type) {
        case 'check':
            return false;
        case 'checkbox_group':
            return [];
        case 'measure_table':
            return Object.fromEntries(tableRows(field).map((row) => [row.key, Object.fromEntries((field.columns ?? []).map((c) => [c.key, null]))]));
        case 'repeat_table':
            return [];
        case 'pass_fail':
        case 'photo':
        case 'readonly_from_asset':
            return null;
        default:
            return '';
    }
};

/** A saved answer fitted to the form: tables filled out to every row and column. */
export const formAnswer = (field: TemplateField, saved: Answer | undefined): Answer => {
    const empty = emptyAnswer(field);
    if (saved === undefined || saved === null) return empty;
    if (field.type === 'measure_table') {
        const table = empty as MeasureTableAnswer;
        for (const row of Object.keys(table)) {
            for (const column of Object.keys(table[row])) table[row][column] = (saved as MeasureTableAnswer)[row]?.[column] ?? null;
        }
        return table;
    }
    if (field.type === 'repeat_table') return Array.isArray(saved) ? (saved as RepeatRow[]).map((row) => ({ ...row })) : [];
    if (field.type === 'checkbox_group') return Array.isArray(saved) ? [...(saved as string[])] : [];
    return saved;
};

/** Fields grouped under their section headings, in template order. */
export const sectionsOf = <T extends { section?: string }>(fields: T[]): { title: string | null; fields: T[] }[] => {
    const groups: { title: string | null; fields: T[] }[] = [];
    for (const field of fields) {
        const title = field.section || null;
        const last = groups[groups.length - 1];
        if (last && last.title === title) last.fields.push(field);
        else groups.push({ title, fields: [field] });
    }
    return groups;
};
