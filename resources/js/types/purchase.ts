// A purchase request as the server sends it (App\Modules\Inventory\Support\PurchaseRequestRow).
export type PurchaseStatus =
    | 'pending'
    | 'approved'
    | 'ordered'
    | 'partially_received'
    | 'received'
    | 'registered'
    | 'issued'
    | 'rejected'
    | 'cancelled';

export interface PurchaseRequestRow {
    ulid: string;
    pr_no: string;
    // The project (MA contract) it is for, if any.
    contract_id: number | null;
    // The issue/loan request it was asked from, if any.
    checkout_request_id: number | null;
    status: PurchaseStatus;
    item_name: string;
    description: string | null;
    quantity: number;
    // How far it got: delivered, put into the system, handed out.
    qty_received: number;
    qty_registered: number;
    qty_issued: number;
    unit: string;
    // What it becomes once it arrives: an asset of a category, or stock of a part; null = not said yet.
    item_kind: 'asset' | 'part' | null;
    asset_category_id: number | null;
    unit_price: string | null; // baht
    total: string | null; // baht
    links: string[];
    reason: string | null;
    needed_by: string | null;
    requested_by: number | null;
    requested_by_name: string | null;
    requested_at: string | null;
    decided_by_name: string | null;
    decided_at: string | null;
    decision_note: string | null;
    ordered_by_name: string | null;
    ordered_at: string | null;
    order_note: string | null;
    received_by_name: string | null;
    received_at: string | null;
    receive_note: string | null;
}

// One delivery, and what it was registered as.
export interface PurchaseReceiptRow {
    id: number;
    quantity: number;
    brand: string | null;
    model: string | null;
    unit_price: string | null; // baht, paid
    serials: string[];
    note: string | null;
    received_by_name: string | null;
    received_at: string | null;
    registered_as: 'asset' | 'part' | null;
    registered_by_name: string | null;
    registered_at: string | null;
    // One per serial in a category counted by serial, else one holding the quantity.
    assets: { ulid: string; asset_code: string; name: string }[];
    part: { id: number; code: string; name: string } | null;
}

// A step in the request's history.
export interface PurchaseEventRow {
    id: number;
    action: 'create' | 'approve' | 'reject' | 'order' | 'receive' | 'register' | 'issue' | 'cancel';
    from_status: PurchaseStatus | null;
    to_status: PurchaseStatus;
    actor_name: string | null;
    note: string | null;
    at: string | null;
}

// An issue/loan request tied to the purchase, with its papers.
export interface PurchaseCheckoutRow {
    ulid: string;
    request_no: string;
    status: string;
    borrower_name: string;
    source: boolean;
    printable: boolean;
    delivered: boolean;
    editable: boolean;
}
