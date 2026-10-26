import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

export interface Roles {
    global: string[];
    conferences: Record<number, string[]>;
}

export interface Auth {
    user: User;
    roles?: Roles;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
};

export interface User {
    id: number;
    first_name: string;
    last_name: string;
    phone?: string;
    email: string;
    email_verified_at?: string;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export interface Affiliation {
    id: number;
    name: string;
    type?: string;
    country?: string;
    users?: User[];
    created_at: string;
    updated_at: string;
}

export interface ExpertiseArea {
    id: number;
    name: string;
    description?: string;
    users?: User[];
    created_at: string;
    updated_at: string;
}

export interface Conference {
    id: number;
    title: string;
    description?: string;
    start_date?: string;
    end_date?: string;
    double_blind_review?: boolean;
    coi_auto_detect?: boolean;
    fields?: Field[];
    tracks?: Track[];
    submissions?: Submission[];
    created_at: string;
    updated_at: string;
}

export interface Field {
    id: number;
    name: string;
    description?: string;
    created_at: string;
    updated_at: string;
}

export interface Track {
    id: number;
    conference_id: number;
    name: string;
    description?: string;
    submission_open_at?: string;
    submission_close_at?: string;
    review_open_at?: string | null;
    review_close_at?: string | null;
    decision_open_at?: string | null;
    decision_close_at?: string | null;
    created_at: string;
    updated_at: string;
}

export interface SubmissionFile {
    id: number;
    submission_id: number;
    filename: string;
    path: string;
    mime_type?: string | null;
    size?: number | null;
    created_at: string;
}

export interface SubmissionCoAuthor {
    id: number;
    submission_id: number;
    user_id?: number | null;
    first_name: string;
    last_name: string;
    email: string;
    invitation_sent_at?: string | null;
    invitation_accepted_at?: string | null;
    created_at: string;
    updated_at: string;
}

export interface SubmissionBid {
    id: number;
    submission_id: number;
    user_id: number;
    created_at: string;
    updated_at: string;
    user?: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
    };
}

export interface Submission {
    id: number;
    conference_id: number;
    track_id: number;
    user_id: number;
    title: string;
    abstract: string;
    keywords: string[];
    status:
        | 'draft'
        | 'submitted'
        | 'under_review'
        | 'accepted'
        | 'rejected'
        | 'minor_revision'
        | 'major_revision';
    conference?: Conference;
    track?: Track;
    user?: User;
    files?: SubmissionFile[];
    assignment?: ReviewAssignment;
    assignments?: ReviewAssignment[];
    decision?: Decision;
    reviews?: Review[];
    co_authors?: SubmissionCoAuthor[];
    bids?: SubmissionBid[];
    created_at: string;
    updated_at: string;
}

export type ReviewRecommendation =
    'accept' | 'reject' | 'minor_revision' | 'major_revision';

export interface Review {
    id: number;
    submission_id: number;
    reviewer_id: number;
    recommendation: ReviewRecommendation;
    assignment_id?: number;
    comments?: string;
    grade?: number | null;
    created_at: string;
    updated_at: string;
    reviewer?: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
    };
}

export type ReviewAssignmentStatus = 'pending' | 'accepted' | 'declined';

export interface ReviewAssignment {
    id: number;
    submission_id: number;
    reviewer_id: number;
    status: ReviewAssignmentStatus;
    deadline_at?: string;
    assigned_at?: string;
    accepted_at?: string;
    declined_at?: string;
    created_at: string;
    updated_at: string;
    reviewer?: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
    };
    submission?: Submission;
    reviews?: Review[];
}

export type DecisionOutcome =
    'accepted' | 'rejected' | 'minor_revision' | 'major_revision';

export interface Decision {
    id: number;
    submission_id: number;
    decided_by_id: number;
    decision: DecisionOutcome;
    rationale?: string;
    revision_round?: number | null;
    created_at: string;
    updated_at: string;
    decidedBy?: {
        id: number;
        first_name: string;
        last_name: string;
    };
}

export type CoiLevel = 'declared' | 'hard' | 'soft' | null;

export interface CoiFlag {
    declared: boolean;
    auto_level: CoiLevel;
    auto_reason?: string | null;
    overridable: boolean;
    overridden: boolean;
}

export interface ReviewerConflict {
    id: number;
    submission_id: number;
    reviewer_id: number;
    author_id?: number | null;
    coauthor_id?: number | null;
    level: 'declared' | 'hard' | 'soft';
    reason?: string | null;
    overridden: boolean;
    created_by_id?: number | null;
    created_at: string;
    updated_at: string;
}
