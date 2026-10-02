import type { CheckoutLineRow } from '@/types/checkout';
import type { PurchaseRequestRow } from '@/types/purchase';

// The counts of a person or a project (App\Modules\Reporting\Support\SummaryTotals).
export interface SummaryTotals {
    issues: number;
    issues_open: number;
    loans: number;
    loans_open: number;
    // Left out for customer accounts, who never see purchases or amounts.
    purchases?: number;
    purchases_open?: number;
    purchase_amount?: string; // baht
    open_count: number;
    total: number;
    last_at: string | null;
}

// One row of the summary by person: a user of the company, or a name from outside.
export interface PersonSummary extends SummaryTotals {
    user_id: number | null;
    outside_name: string | null;
    name: string;
}

// One row of the summary by project (an MA contract).
export interface ProjectSummary extends SummaryTotals {
    id: number;
    contract_no: string;
    title: string;
    customer: string | null;
    phase: string;
    starts_on: string;
    ends_on: string;
}

export type ContractLabel = { id: number; contract_no: string; title: string } | null;

// A request line in a person's or project's lists (App\Modules\Asset\Actions\SearchSummaryLines).
export type SummaryCheckout = CheckoutLineRow & {
    request: {
        ulid: string;
        request_no: string;
        status: string;
        borrower_user_id: number | null;
        borrower_name: string;
        borrower_department: string | null;
        requester_name: string | null;
        contract_id: number | null;
        needed_by: string | null;
        requested_at: string | null;
    };
    contract: ContractLabel;
};

export type SummaryPurchase = PurchaseRequestRow & { contract: ContractLabel };

// Where a person's page is: a user by id, or someone from outside by name.
export const personParams = (person: { user_id: number | null; outside_name: string | null }) =>
    person.user_id ? { user: person.user_id } : { name: person.outside_name ?? '' };
