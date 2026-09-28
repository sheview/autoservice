import { Config, RouteParams } from 'ziggy-js';

declare global {
    function route(): Config;
    // A single parameter may be passed as a plain value, e.g. route('asset.assets.show', asset.ulid).
    function route(name: string, params?: RouteParams<typeof name> | string | number, absolute?: boolean): string;
}

declare module '@vue/runtime-core' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}
