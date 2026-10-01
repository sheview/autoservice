export interface BrandUse {
    name: string;
    count: number;
}

// Letters and digits only, lower case: "TP-LINK" and "tp link" are the same brand.
const normalize = (text: string) => text.toLowerCase().replace(/[^\p{L}\p{N}]/gu, '');

function distance(a: string, b: string): number {
    const row = Array.from({ length: b.length + 1 }, (_, j) => j);
    for (let i = 1; i <= a.length; i++) {
        let diagonal = row[0];
        row[0] = i;
        for (let j = 1; j <= b.length; j++) {
            const above = row[j];
            row[j] = Math.min(row[j] + 1, row[j - 1] + 1, diagonal + (a[i - 1] === b[j - 1] ? 0 : 1));
            diagonal = above;
        }
    }
    return row[b.length];
}

/**
 * The known brand that the typed one probably means (a different spelling or a typo, e.g. "Ciso" → "Cisco",
 * "ACER" → "Acer", "WESTER" → "Western"), or null. Only a brand used at least as often as the typed one
 * is suggested, so the common spelling is never "corrected" into a rare typo.
 */
export function similarBrand(typed: string, brands: BrandUse[]): BrandUse | null {
    const value = typed.trim();
    const key = normalize(value);
    if (key.length < 2) {
        return null;
    }

    const own = brands.find((brand) => brand.name === value)?.count ?? 0;
    const allowed = key.length <= 4 ? 1 : 2;

    let best: { brand: BrandUse; score: number } | null = null;
    for (const brand of brands) {
        if (brand.name === value || brand.count < own) {
            continue;
        }
        const other = normalize(brand.name);
        let score: number | null = null;
        if (other === key) {
            score = 0;
        } else if (key.length >= 4 && other.length >= 4 && (other.startsWith(key) || key.startsWith(other))) {
            score = 1;
        } else {
            const d = distance(key, other);
            score = d <= allowed ? d : null;
        }
        if (score !== null && (best === null || score < best.score || (score === best.score && brand.count > best.brand.count))) {
            best = { brand, score };
        }
    }

    return best?.brand ?? null;
}
