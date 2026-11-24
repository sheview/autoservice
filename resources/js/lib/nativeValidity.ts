import { t } from '@/lib/i18n';

type Field = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

const MARK = 'thValidity';

const isField = (el: EventTarget | null): el is Field =>
    el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement;

/** The Thai text for why the browser refused this field, or '' when it is fine. */
const messageFor = (el: Field): string => {
    const v = el.validity;
    const type = el instanceof HTMLInputElement ? el.type : el.tagName.toLowerCase();

    if (v.valueMissing) {
        if (type === 'checkbox') return t('validity.check');
        if (type === 'radio' || type === 'select') return t('validity.choose');
        if (type === 'file') return t('validity.file');
        return t('validity.required');
    }
    if (v.typeMismatch) {
        if (type === 'email') return t('validity.email');
        if (type === 'url') return t('validity.url');
        return t('validity.invalid');
    }
    if (v.badInput) {
        return ['date', 'datetime-local', 'time', 'month'].includes(type) ? t('validity.date') : t('validity.number');
    }
    if (v.patternMismatch) return el.title || t('validity.pattern');
    if (v.tooShort && 'minLength' in el) return t('validity.too_short', { min: el.minLength, length: el.value.length });
    if (v.tooLong && 'maxLength' in el) return t('validity.too_long', { max: el.maxLength });
    if (v.rangeUnderflow && el instanceof HTMLInputElement) return t('validity.min', { min: el.min });
    if (v.rangeOverflow && el instanceof HTMLInputElement) return t('validity.max', { max: el.max });
    if (v.stepMismatch) return t('validity.step');

    return '';
};

const clear = (el: Field): void => {
    if (el.dataset[MARK]) {
        el.setCustomValidity('');
        delete el.dataset[MARK];
    }
};

const clearForm = (form: HTMLFormElement | null | undefined): void => {
    form?.querySelectorAll<Field>(`[data-th-validity]`).forEach(clear);
};

/**
 * The browser's own popup for a `required` / `type="email"` / min / max field speaks the browser's
 * language ("Please fill out this field."). Every such popup in the app says it in Thai instead.
 * The message is set while the browser checks the form and taken off again as soon as the field is
 * changed or the form is sent again, so it never keeps a fixed field from being sent.
 */
export const installNativeValidity = (): void => {
    document.addEventListener(
        'invalid',
        (event) => {
            const el = event.target;
            if (!isField(el)) return;
            // A message a page set itself: leave it.
            if (el.validity.customError && !el.dataset[MARK]) return;

            el.setCustomValidity('');
            const text = messageFor(el);
            if (text !== '') {
                el.setCustomValidity(text);
                el.dataset[MARK] = '1';
            }
        },
        true,
    );

    const onEdit = (event: Event) => {
        if (isField(event.target)) clear(event.target);
    };
    document.addEventListener('input', onEdit, true);
    document.addEventListener('change', onEdit, true);

    // Sending again (button or Enter): check afresh, a value set by the page without typing counts too.
    document.addEventListener(
        'click',
        (event) => {
            const button = (event.target as Element | null)?.closest?.('button, input[type="submit"]');
            if (button instanceof HTMLButtonElement || button instanceof HTMLInputElement) clearForm(button.form);
        },
        true,
    );
    document.addEventListener(
        'keydown',
        (event) => {
            if (event.key === 'Enter' && isField(event.target)) clearForm(event.target.form);
        },
        true,
    );
};
