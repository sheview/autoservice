/**
 * What an asset's QR sticker (Labeling) points at, read back from the scanned text:
 *
 *   https://acme.example.com/q/PC-00012?k=…        → { code: 'PC-00012' }   (sticker, own domain)
 *   https://app.example.com/t/1002/q/PC-00012?k=…  → { code: 'PC-00012' }   (sticker, company path)
 *   https://app.example.com/a/01HX…(26)            → { ulid: '01HX…' }      (older stickers)
 *   PC-00012                                       → { code: 'PC-00012' }   (typed, or a plain barcode)
 */
export type AssetKey = { code?: string; ulid?: string };

export const assetKeyFromScan = (text: string): AssetKey | null => {
    const value = text.trim();
    if (value === '') return null;

    let path = '';
    try {
        path = new URL(value).pathname;
    } catch {
        return { code: value };
    }
    const sticker = path.match(/\/q\/([^/]+)\/?$/);
    if (sticker) return { code: decodeURIComponent(sticker[1]) };
    const old = path.match(/\/a\/([0-9A-Za-z]{26})\/?$/);
    if (old) return { ulid: old[1] };

    return null;
};
