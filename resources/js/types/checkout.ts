// An issue/loan form as the server sends it (App\Modules\Asset\Support\CheckoutRow).
export interface CheckoutRow {
    ulid: string;
    checkout_no: string;
    type: 'issue' | 'loan';
    status: 'pending' | 'approved' | 'rejected' | 'returned' | 'cancelled';
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
    asset: { ulid: string; asset_code: string; name: string; serial_number: string | null } | null;
}
