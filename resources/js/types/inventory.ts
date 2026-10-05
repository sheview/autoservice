/** One piece of a part tracked by serial number (PartUnitHistory::row). */
export interface PartUnitRow {
    id: number;
    part_id: number;
    serial_number: string;
    status: 'in_stock' | 'issued' | 'removed';
    source: 'purchase' | 'receive' | 'backfill';
    supplier: string | null;
    ticket_id: number | null;
    asset_id: number | null;
    checkout_item_id: number | null;
    note: string | null;
    unit_cost: string | null;
    received_on: string | null;
    warranty_until: string | null;
}

/** One change of a piece (PartUnitHistory::handle). */
export interface PartUnitEventRow {
    id: number;
    action: 'receive' | 'backfill' | 'issue' | 'return' | 'remove' | 'correct';
    from_status: string | null;
    to_status: string;
    serial_number: string;
    reference: string | null;
    reason: string | null;
    user_name: string | null;
    ticket: { ulid: string; ticket_no: string; status: string } | null;
    asset: { ulid: string; asset_code: string; name: string } | null;
    request: { ulid: string; request_no: string; borrower_name: string } | null;
    at: string;
}
