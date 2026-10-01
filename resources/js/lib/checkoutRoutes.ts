// Issue/loan forms are of an asset (Asset module) or of a part (Inventory module, kind "part"):
// the same actions under each module's routes.
export const checkoutRoute = (checkout: { kind?: 'part' }, action: string) =>
    `${checkout.kind === 'part' ? 'inventory.part-checkouts' : 'asset.checkouts'}.${action}`;
