// An issue/loan form as the server sends it (App\Modules\Asset\Support\CheckoutRow).
export interface CheckoutRow {
    // "part" = a part form (Inventory module; the part is under "asset"); absent = an asset form.
    kind?: 'part';
    ulid: string;
    checkout_no: string;
    // The project (MA contract) it is for, if any.
    contract_id: number | null;
    type: 'issue' | 'loan';
    // How many of the asset the form takes (1 for a single device).
    quantity: number;
    status: 'pending' | 'approved' | 'rejected' | 'returned' | 'cancelled';
    borrower_user_id: number | null;
    borrower_name: string;
    borrower_department: string | null;
    borrower_phone: string | null;
    purpose: string | null;
    requested_by: number | null;
    requested_by_name: string | null;
    decided_by_name: string | null;
    decision_note: string | null;
    returned_by_name: string | null;
    return_note: string | null;
    due_on: string | null;
    overdue: boolean;
    requested_at: string | null;
    decided_at: string | null;
    returned_at: string | null;
    asset: { ulid: string; asset_code: string; name: string; serial_number: string | null; unit: string | null } | null;
}
