import { t } from '@/lib/i18n';

/** A file that is not a picture, or one the browser cannot open: the message says which (Thai). */
export class PhotoError extends Error {}

const SENT_AS_IS = ['image/jpeg', 'image/png', 'image/webp'];

const decode = (file: File): Promise<HTMLImageElement> =>
    new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('decode'));
        };
        img.src = url;
    });

/**
 * Like shrinkImage, but a file that is not a picture or cannot be opened is refused with a
 * PhotoError instead of being sent. Longest side `max` px, JPEG at 0.8 (about 300-500 KB from a
 * phone camera), on white (a transparent PNG would turn black). A picture already small enough
 * is kept as it is when that is smaller. HEIC from an iPhone is converted when the browser opens it.
 */
export const prepareImage = async (file: File, max = 1600): Promise<File> => {
    if (!file.type.startsWith('image/') && !/\.(jpe?g|png|webp|gif|bmp|heic|heif)$/i.test(file.name)) {
        throw new PhotoError(t('photos.not_image', { name: file.name }));
    }

    let img: HTMLImageElement;
    try {
        img = await decode(file);
    } catch {
        throw new PhotoError(t('photos.unreadable', { name: file.name }));
    }
    if (!img.naturalWidth || !img.naturalHeight) {
        throw new PhotoError(t('photos.unreadable', { name: file.name }));
    }

    const scale = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(img.naturalWidth * scale);
    canvas.height = Math.round(img.naturalHeight * scale);
    const context = canvas.getContext('2d');
    if (!context) throw new PhotoError(t('photos.unreadable', { name: file.name }));
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(img, 0, 0, canvas.width, canvas.height);

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));
    if (!blob) throw new PhotoError(t('photos.unreadable', { name: file.name }));
    if (scale === 1 && SENT_AS_IS.includes(file.type) && file.size <= blob.size) return file;

    return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
};

/**
 * A photo made smaller on the device before it is sent (longest side `max` px, JPEG): a phone's
 * 12 MP picture becomes a few hundred KB. Anything that cannot be read is sent as it is.
 */
export const shrinkImage = (file: File, max = 1600): Promise<File> =>
    new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            const scale = Math.min(1, max / Math.max(img.width, img.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(img.width * scale);
            canvas.height = Math.round(img.height * scale);
            canvas.getContext('2d')!.drawImage(img, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(
                (blob) => resolve(blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file),
                'image/jpeg',
                0.8,
            );
            URL.revokeObjectURL(img.src);
        };
        img.onerror = () => resolve(file);
        img.src = URL.createObjectURL(file);
    });
