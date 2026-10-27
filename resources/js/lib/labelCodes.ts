import { ref } from 'vue';

/**
 * Barcodes and QR codes read from a photo of a device label or a part box, so the form can be
 * filled from the picture: the S/N, the MAC, the part number. The browser's own detector reads
 * every code at once where there is one (Chrome on Android); otherwise ZXing reads the whole
 * picture and then strips of it (one code per pass), so a label with several codes still gives
 * them all. Nothing leaves the device.
 */

/** What a code most likely is: by its printed prefix ("S/N:", "P/N:"), its shape (MAC) or its format (EAN). */
export type CodeKind = 'serial' | 'part_number' | 'mac' | 'product' | 'other';

export interface LabelCode {
    value: string;
    kind: CodeKind;
}

type Detected = { text: string; format: string };

// Shop barcodes of a product (the same on every box), not of one device.
const PRODUCT_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e'];

const PREFIXED: [RegExp, CodeKind][] = [
    [/^(?:s\/n|sn[\s:#]|ser(?:ial)?(?:\s*(?:no|number))?\.?[\s:#])\s*[:#.-]?\s*/i, 'serial'],
    [/^(?:p\/n|pn[\s:#]|part\s*(?:no|number)\.?[\s:#]?)\s*[:#.-]?\s*/i, 'part_number'],
    [/^mac(?:\s*addr(?:ess)?)?\s*[:#.-]?\s*/i, 'mac'],
];

const isMac = (value: string) =>
    /^[0-9a-f]{12}$/i.test(value.replace(/[:.\-\s]/g, '')) && /^[0-9a-f:.\-\s]+$/i.test(value) && (/[:.-]/.test(value) || /[a-f]/i.test(value));

export const classify = ({ text, format }: Detected): LabelCode => {
    for (const [prefix, kind] of PREFIXED) {
        const value = text.replace(prefix, '').trim();
        // "MAC" must be followed by a MAC ("MACBOOK1" is not one).
        if (kind === 'mac' && !/^[0-9a-f]{12}$/i.test(value.replace(/[:.\-\s]/g, ''))) continue;
        if (value !== text && value !== '') return { value, kind };
    }
    if (isMac(text)) return { value: text, kind: 'mac' };
    if (PRODUCT_FORMATS.includes(format)) return { value: text, kind: 'product' };

    return { value: text, kind: 'other' };
};

type NativeDetector = { detect: (image: ImageBitmapSource) => Promise<{ rawValue: string; format: string }[]> };

const nativeDetect = async (bitmap: ImageBitmap): Promise<Detected[]> => {
    const Detector = (window as unknown as { BarcodeDetector?: new () => NativeDetector }).BarcodeDetector;
    if (!Detector) return [];
    try {
        return (await new Detector().detect(bitmap)).map((code) => ({ text: code.rawValue, format: code.format }));
    } catch {
        return [];
    }
};

/** The part of the picture (fractions of it) on a canvas, longest side at most `max` px. */
const region = (bitmap: ImageBitmap, x: number, y: number, w: number, h: number, max = 1600): HTMLCanvasElement => {
    const sw = bitmap.width * w;
    const sh = bitmap.height * h;
    const scale = Math.min(1, max / Math.max(sw, sh));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(sw * scale);
    canvas.height = Math.round(sh * scale);
    canvas.getContext('2d')!.drawImage(bitmap, bitmap.width * x, bitmap.height * y, sw, sh, 0, 0, canvas.width, canvas.height);
    return canvas;
};

const zxingDetect = async (bitmap: ImageBitmap): Promise<Detected[]> => {
    const [{ BrowserMultiFormatReader }, { BarcodeFormat, DecodeHintType }] = await Promise.all([import('@zxing/browser'), import('@zxing/library')]);
    const reader = new BrowserMultiFormatReader(new Map([[DecodeHintType.TRY_HARDER, true]]));
    // The whole picture, then overlapping thirds across and down.
    const parts: [number, number, number, number][] = [[0, 0, 1, 1]];
    for (let i = 0; i < 5; i++) {
        parts.push([0, i / 6, 1, 1 / 3], [i / 6, 0, 1 / 3, 1]);
    }
    const found: Detected[] = [];
    for (const [x, y, w, h] of parts) {
        try {
            const result = reader.decodeFromCanvas(region(bitmap, x, y, w, h));
            found.push({ text: result.getText(), format: BarcodeFormat[result.getBarcodeFormat()].toLowerCase() });
        } catch {
            // no code in this part
        }
        await new Promise((resolve) => setTimeout(resolve)); // keep the page responsive
    }
    return found;
};

/** Every code in the photo, once each, links left out (a QR of a web page says nothing about the device). */
export const readCodes = async (file: File): Promise<LabelCode[]> => {
    let bitmap: ImageBitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return [];
    }
    try {
        let detected = await nativeDetect(bitmap);
        if (detected.length === 0) detected = await zxingDetect(bitmap);
        const seen = new Set<string>();
        return detected
            .map((code) => ({ ...code, text: code.text.trim() }))
            .filter((code) => code.text !== '' && !/^https?:\/\//i.test(code.text) && !seen.has(code.text) && seen.add(code.text))
            .map(classify);
    } finally {
        bitmap.close();
    }
};

/** A field of the form a code can go into. */
export interface CodeTarget {
    key: string;
    label: string;
    fill: (value: string) => void;
}

export interface FoundCode extends LabelCode {
    // The target it went into, if any.
    placed: string | null;
}

/**
 * The codes read from the photos of a form. `guess` picks for each new code the target it goes
 * into on its own (or null: the user taps where it goes); the panel (LabelCodes.vue) shows them all.
 */
export const useLabelCodes = (targets: () => CodeTarget[], guess: (codes: LabelCode[]) => (string | null)[]) => {
    const found = ref<FoundCode[]>([]);
    const reading = ref(0);
    // The last photo read had no code.
    const missed = ref(false);

    const place = (code: FoundCode, key: string) => {
        targets()
            .find((target) => target.key === key)
            ?.fill(code.value);
        code.placed = key;
    };

    const read = async (file: File) => {
        reading.value++;
        try {
            const all = await readCodes(file);
            missed.value = all.length === 0;
            const codes = all.filter((code) => !found.value.some((known) => known.value === code.value));
            const keys = guess(codes);
            codes.forEach((code, i) => {
                const item: FoundCode = { ...code, placed: null };
                found.value.push(item);
                if (keys[i]) place(found.value[found.value.length - 1], keys[i]!);
            });
        } finally {
            reading.value--;
        }
    };

    return { found, reading, missed, read, place, clear: () => (found.value = []) };
};

export type LabelCodeReader = ReturnType<typeof useLabelCodes>;
