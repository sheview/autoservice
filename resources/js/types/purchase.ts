// A purchase request as the server sends it (App\Modules\Inventory\Support\PurchaseRequestRow).
export interface PurchaseRequestRow {
    ulid: string;
    pr_no: string;
    status: 'pending' | 'approved' | 'ordered' | 'received' | 'rejected' | 'cancelled';
    item_name: string;
    description: string | null;
    quantity: number;
    unit: string;
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
