export type CheckoutRequestStatus = 'draft' | 'pending' | 'approved' | 'partial' | 'fulfilled' | 'closed' | 'rejected' | 'cancelled';

export type CheckoutLineStatus = 'pending' | 'approved' | 'partial' | 'fulfilled' | 'backordered' | 'rejected' | 'cancelled';

// The request a line belongs to, on the line tabs and the asset/part page panel.
export interface CheckoutLineRequestRef {
    ulid: string;
    request_no: string;
    status: CheckoutRequestStatus;
    borrower_name: string;
    requester_name: string | null;
    needed_by: string | null;
}

// One line of an issue/loan request (App\Modules\Asset\Support\RequestRow::item).
export interface CheckoutLineRow {
    id: number;
    item_type: 'asset' | 'part';
    asset_id: number | null;
    part_id: number | null;
    item_code: string | null;
    item_name: string;
    unit: string | null;
    checkout_type: 'issue' | 'loan';
    qty_requested: number;
    qty_approved: number | null;
    qty_fulfilled: number;
    qty_returned: number;
    status: CheckoutLineStatus;
    return_condition: string | null;
    returned_by_name: string | null;
    reject_reason: string | null;
    note: string | null;
    asset_ulid: string | null;
    due_return_date: string | null;
    returned_at: string | null;
    // Approved but not handed out yet / handed out and not back yet.
    remaining: number;
    outstanding: number;
    overdue: boolean;
    // Parts only: stock on hand now.
    on_hand: number | null;
    // The purchase request it waits for, or that bought what it hands out.
    purchase_request_id: number | null;
    purchase_request: { ulid: string; pr_no: string; status: string } | null;
    // The request page: each hand-out of this line.
    fulfillments?: { qty: number; by: string | null; at: string | null }[];
    // The line tabs and the asset/part page panel.
    request?: CheckoutLineRequestRef;
}

// An issue/loan request with its lines (App\Modules\Asset\Support\RequestRow::of).
export interface CheckoutRequestRow {
    ulid: string;
    request_no: string;
    status: CheckoutRequestStatus;
    requester_id: number | null;
    requester_name: string | null;
    borrower_user_id: number | null;
    borrower_name: string;
    borrower_department: string | null;
    borrower_phone: string | null;
    ticket_id: number | null;
    contract_id: number | null;
    purpose: string | null;
    approved_by_name: string | null;
    auto_approved: boolean;
    reject_reason: string | null;
    needed_by: string | null;
    requested_at: string | null;
    submitted_at: string | null;
    approved_at: string | null;
    closed_at: string | null;
    items: CheckoutLineRow[];
    // The request page.
    ticket?: CheckoutTicketOption | null;
    contract?: { id: number; contract_no: string; title: string } | null;
}

// An open ticket a request can be for (Service module: TicketsForCheckout).
export interface CheckoutTicketOption {
    id: number;
    ulid: string;
    ticket_no: string;
    title: string;
    customer_id: number | null;
}

// An asset or a part offered by the line picker (CheckoutRequestController::assetOption / partOption).
export interface CheckoutItemOption {
    item_type: 'asset' | 'part';
    asset_id: number | null;
    asset_ulid: string | null;
    part_id: number | null;
    code: string | null;
    name: string;
    detail: string | null;
    unit: string | null;
    // Asked for by quantity (a lot asset or a part); otherwise one.
    lot: boolean;
    available: number;
}

// The issue/loan panel on an asset or part page.
export interface ItemRequestsPanelData {
    lines: (CheckoutLineRow & { request: CheckoutLineRequestRef })[];
    available: boolean;
    available_quantity: number;
    quantity: number;
    unit: string | null;
    can: { create: boolean };
}
