<script setup lang="ts">
import FileList from '@/components/FileList.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    Submission,
    type BreadcrumbItem,
    type CoiFlag,
    type DecisionOutcome,
} from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Calendar,
    Check,
    FileText,
    MessageSquare,
    Tag,
    User,
    UserPlus,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Submissions',
        href: '/submissions',
    },
    {
        title: 'Show',
        href: '#',
    },
];

interface Props {
    submission: Submission;
    committee_members?: {
        id: number;
        first_name: string;
        last_name: string;
        email: string;
    }[];
    double_blind_review?: boolean;
    coi_flags?: Record<number, CoiFlag>;
    bidder_ids?: number[];
    viewer_role?: 'chair' | 'reviewer' | 'author' | 'committee';
}

const page = usePage();
const auth = computed(() => page.props.auth);

const props = defineProps<Props>();
const isAuthor = computed(
    () => props.submission.user_id === auth.value.user.id,
);

const isChair = computed(() => {
    const globalRoles = auth.value.roles?.global || [];
    if (globalRoles.includes('admin')) return true;
    const conferenceRoles =
        auth.value.roles?.conferences[props.submission.conference_id] || [];
    return conferenceRoles.includes('chair');
});

const anonymizeReviewers = computed(
    () => props.double_blind_review && props.viewer_role !== 'chair',
);

function retract(id: number) {
    if (
        !confirm(
            'Are you sure you want to retract this submission? This action can be undone.',
        )
    ) {
        return;
    }
    router.delete(`/submissions/${id}`);
}

function submitForReview(id: number) {
    if (
        !confirm(
            'Are you sure you want to submit this paper for review? You will not be able to edit it after submission.',
        )
    ) {
        return;
    }
    router.post(
        `/submissions/${id}/submit`,
        {},
        {
            preserveScroll: true,
        },
    );
}

const startingReview = ref(false);

function startReview(id: number) {
    if (
        !confirm(
            'Move this submission into review? It leaves the submitted queue and the decision becomes editable.',
        )
    ) {
        return;
    }
    router.post(
        `/submissions/${id}/start-review`,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                startingReview.value = true;
            },
            onFinish: () => {
                startingReview.value = false;
            },
        },
    );
}

function removeFile(fileId: number) {
    if (!confirm('Are you sure you want to delete this file?')) {
        return;
    }
    router.delete(`/submissions/${props.submission.id}/files/${fileId}`, {
        preserveScroll: true,
    });
}

/**
 * Conference and track dates are days stored in UTC, so they are rendered in UTC
 * too — a browser west of UTC would otherwise show the day before the one the
 * server enforces.
 */
const dateFormatter = new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    timeZone: 'UTC',
});

const formatDate = (dateString?: string) => {
    if (!dateString) return 'Not set';

    const date = new Date(dateString);

    return isNaN(date.getTime()) ? dateString : dateFormatter.format(date);
};

const statusVariants: Record<
    string,
    'secondary' | 'default' | 'outline' | 'destructive'
> = {
    draft: 'secondary',
    submitted: 'secondary',
    under_review: 'default',
    accepted: 'outline',
    rejected: 'destructive',
    minor_revision: 'default',
    major_revision: 'default',
};

// Reviewer assignment state
const showAssignDialog = ref(false);
const selectedReviewerId = ref<number | null>(null);
const reassigningAssignmentId = ref<number | null>(null);
const searchQuery = ref('');
const overrideConflict = ref(false);
const assignError = ref('');

const assignments = computed(() => props.submission.assignments || []);

const coiFlag = (memberId: number): CoiFlag | undefined =>
    props.coi_flags?.[memberId];

const bidderIds = computed(() => new Set(props.bidder_ids || []));

const hasBid = (memberId: number): boolean => bidderIds.value.has(memberId);

const filteredCommitteeMembers = computed(() => {
    if (!props.committee_members) return [];
    const assignedReviewerIds = new Set(
        assignments.value.map((a) => a.reviewer_id),
    );
    const query = searchQuery.value.toLowerCase();
    return props.committee_members.filter(
        (m) =>
            !assignedReviewerIds.has(m.id) &&
            (!query ||
                m.first_name.toLowerCase().includes(query) ||
                m.last_name.toLowerCase().includes(query) ||
                m.email.toLowerCase().includes(query)),
    );
});

const selectedReviewer = computed(() => {
    if (!selectedReviewerId.value || !props.committee_members) return null;
    return (
        props.committee_members.find(
            (m) => m.id === selectedReviewerId.value,
        ) || null
    );
});

const statusBadgeVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    const variants: Record<
        string,
        'default' | 'secondary' | 'destructive' | 'outline'
    > = {
        pending: 'secondary',
        accepted: 'default',
        declined: 'destructive',
    };
    return variants[status] || 'secondary';
};

// Authors to declare a conflict against: the submitter + co-authors.
const declareableAuthors = computed(() => {
    const list: {
        key: string;
        authorId: number | null;
        coauthorId: number | null;
        label: string;
    }[] = [];
    if (props.submission.user) {
        list.push({
            key: `author:${props.submission.user.id}`,
            authorId: props.submission.user.id,
            coauthorId: null,
            label: `${props.submission.user.first_name} ${props.submission.user.last_name}`,
        });
    }
    for (const co of props.submission.co_authors || []) {
        list.push({
            key: `coauthor:${co.id}`,
            authorId: co.user_id ?? null,
            coauthorId: co.id,
            label: `${co.first_name} ${co.last_name}`,
        });
    }
    return list;
});

const declareAuthorKey = ref<string>('');
const declareReason = ref('');

const selectedAuthorForDeclare = computed(() =>
    declareableAuthors.value.find((a) => a.key === declareAuthorKey.value),
);

const declareAuthorId = computed(
    () => selectedAuthorForDeclare.value?.authorId ?? null,
);
const declareCoauthorId = computed(
    () => selectedAuthorForDeclare.value?.coauthorId ?? null,
);

function resetDeclareState() {
    declareAuthorKey.value = '';
    declareReason.value = '';
    overrideConflict.value = false;
}

function assignReviewer() {
    if (!selectedReviewerId.value) return;
    const payload: {
        reviewer_id: number;
        assignment_id?: number;
        override_conflict?: boolean;
    } = { reviewer_id: selectedReviewerId.value };
    if (reassigningAssignmentId.value) {
        payload.assignment_id = reassigningAssignmentId.value;
    }
    if (overrideConflict.value) {
        payload.override_conflict = true;
    }
    router.post(
        `/submissions/${props.submission.id}/assign-reviewer`,
        payload,
        {
            preserveScroll: true,
            onError: (errors) => {
                assignError.value =
                    errors.reviewer_id ||
                    errors.submission ||
                    Object.values(errors)[0] ||
                    '';
            },
            onSuccess: () => {
                showAssignDialog.value = false;
                selectedReviewerId.value = null;
                searchQuery.value = '';
                reassigningAssignmentId.value = null;
                resetDeclareState();
            },
        },
    );
}

function autoAssignBidders() {
    if (
        !confirm(
            'Auto-assign reviewers from committee members who bid on this submission?',
        )
    ) {
        return;
    }
    router.post(
        `/submissions/${props.submission.id}/auto-assign`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function declareConflict() {
    if (!selectedReviewerId.value) return;
    const payload: Record<string, string | number> = {
        reviewer_id: selectedReviewerId.value,
    };
    if (declareAuthorId.value) {
        payload.author_id = declareAuthorId.value;
    }
    if (declareCoauthorId.value) {
        payload.coauthor_id = declareCoauthorId.value;
    }
    if (declareReason.value) {
        payload.reason = declareReason.value;
    }
    router.post(`/submissions/${props.submission.id}/conflicts`, payload, {
        preserveScroll: true,
        onSuccess: () => resetDeclareState(),
    });
}

function overrideAutoConflict() {
    if (!selectedReviewerId.value) return;
    router.post(
        `/submissions/${props.submission.id}/conflicts/${selectedReviewerId.value}/override`,
        {},
        {
            preserveScroll: true,
        },
    );
}

// Decision form
const decisionForm = useForm({
    decision: '' as DecisionOutcome | '',
    rationale: '',
});

const decisionOptions = computed(() => {
    const status = props.submission.status;
    if (status === 'under_review') {
        return [
            { value: 'accepted', label: 'Accepted' },
            { value: 'rejected', label: 'Rejected' },
            { value: 'minor_revision', label: 'Minor Revision' },
            { value: 'major_revision', label: 'Major Revision' },
        ] as const;
    }
    if (status === 'minor_revision' || status === 'major_revision') {
        return [
            { value: 'accepted', label: 'Accepted' },
            { value: 'rejected', label: 'Rejected' },
        ] as const;
    }
    return [];
});

const canDecide = computed(
    () => isChair.value && decisionOptions.value.length > 0,
);

/**
 * `isChair` mirrors SubmissionPolicy::startReview's actor set exactly, so no
 * extra Inertia prop is needed to gate this button.
 */
const canStartReview = computed(
    () => isChair.value && props.submission.status === 'submitted',
);

function submitDecision() {
    decisionForm.post(`/submissions/${props.submission.id}/decision`, {
        preserveScroll: true,
        onSuccess: () => {
            decisionForm.reset();
        },
    });
}

const outcomeBadgeVariant: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    accepted: 'outline',
    rejected: 'destructive',
    minor_revision: 'default',
    major_revision: 'default',
};

const recommendationVariant = (
    recommendation: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    const variants: Record<
        string,
        'default' | 'secondary' | 'destructive' | 'outline'
    > = {
        accept: 'default',
        reject: 'destructive',
        minor_revision: 'secondary',
        major_revision: 'secondary',
    };
    return variants[recommendation] || 'secondary';
};
</script>

<template>
    <Head title="Submission Details" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div
                class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between"
            >
                <div class="space-y-2">
                    <h1 class="text-2xl font-bold tracking-tight">
                        {{ props.submission.title }}
                    </h1>
                    <p class="text-muted-foreground">
                        {{ props.submission.abstract }}
                    </p>
                </div>
                <Badge
                    :variant="
                        statusVariants[
                            props.submission
                                .status as keyof typeof statusVariants
                        ]
                    "
                >
                    {{ props.submission.status.replace('_', ' ') }}
                </Badge>
            </div>

            <div
                v-if="props.submission.status === 'draft' && isAuthor"
                class="flex gap-3"
            >
                <Link :href="`/submissions/${props.submission.id}/edit`">
                    <Button variant="outline" class="cursor-pointer"
                        >Edit</Button
                    >
                </Link>
                <Button
                    variant="default"
                    @click="submitForReview(props.submission.id)"
                >
                    Submit for Review
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    @click="retract(props.submission.id)"
                    >Retract</Button
                >
            </div>

            <div
                v-if="props.submission.conference"
                class="grid grid-cols-1 gap-6 md:grid-cols-2"
            >
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Calendar class="h-5 w-5" />
                            Conference Information
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div>
                            <p class="text-sm text-muted-foreground">Title</p>
                            <Link
                                :href="`/conferences/${props.submission.conference.id}`"
                                class="font-medium text-primary hover:underline"
                            >
                                {{ props.submission.conference.title }}
                            </Link>
                        </div>
                        <div v-if="props.submission.conference.description">
                            <p class="text-sm text-muted-foreground">
                                Description
                            </p>
                            <p class="mt-1">
                                {{ props.submission.conference.description }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Start Date
                                </p>
                                <p class="font-medium">
                                    {{
                                        formatDate(
                                            props.submission.conference
                                                .start_date,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    End Date
                                </p>
                                <p class="font-medium">
                                    {{
                                        formatDate(
                                            props.submission.conference
                                                .end_date,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="props.submission.track">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <FileText class="h-5 w-5" />
                            Track Information
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div>
                            <p class="text-sm text-muted-foreground">
                                Track Name
                            </p>
                            <p class="font-medium">
                                {{ props.submission.track.name }}
                            </p>
                        </div>
                        <div v-if="props.submission.track.description">
                            <p class="text-sm text-muted-foreground">
                                Description
                            </p>
                            <p class="mt-1">
                                {{ props.submission.track.description }}
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Submission Opens
                                </p>
                                <p class="font-medium">
                                    {{
                                        formatDate(
                                            props.submission.track
                                                .submission_open_at,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-muted-foreground">
                                    Submission Closes
                                </p>
                                <p class="font-medium">
                                    {{
                                        formatDate(
                                            props.submission.track
                                                .submission_close_at,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <FileList
                :submission-id="props.submission.id"
                :files="props.submission.files"
                :show-actions="isAuthor"
                @remove-file="removeFile"
            />

            <Card
                v-if="
                    props.submission.keywords &&
                    props.submission.keywords.length > 0
                "
            >
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Tag class="h-5 w-5" />
                        Keywords
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-wrap gap-2">
                        <Badge
                            v-for="keyword in props.submission.keywords"
                            :key="keyword"
                            variant="secondary"
                        >
                            {{ keyword }}
                        </Badge>
                    </div>
                </CardContent>
            </Card>

            <Card
                v-if="
                    props.submission.co_authors &&
                    props.submission.co_authors.length > 0
                "
            >
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <UserPlus class="h-5 w-5" />
                        Co-authors
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-2">
                    <div
                        v-for="coAuthor in props.submission.co_authors"
                        :key="coAuthor.id"
                        class="flex items-center justify-between rounded-lg border px-4 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium">
                                {{ coAuthor.first_name }}
                                {{ coAuthor.last_name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ coAuthor.email }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                coAuthor.user_id ? 'default' : 'secondary'
                            "
                        >
                            {{
                                coAuthor.user_id
                                    ? 'Registered'
                                    : coAuthor.invitation_sent_at
                                      ? 'Invited'
                                      : 'Pending'
                            }}
                        </Badge>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="isChair && props.submission.status !== 'draft'">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <User class="h-5 w-5" />
                        Reviewers
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="canStartReview"
                        class="mb-4 flex flex-wrap gap-2"
                    >
                        <Button
                            size="sm"
                            :disabled="startingReview"
                            @click="startReview(props.submission.id)"
                        >
                            Move to review
                        </Button>
                    </div>
                    <div v-if="assignments.length > 0" class="space-y-4">
                        <div
                            v-for="assignment in assignments"
                            :key="assignment.id"
                            class="rounded-lg border p-4"
                        >
                            <div class="flex items-center justify-between">
                                <div class="space-y-1">
                                    <p class="text-sm text-muted-foreground">
                                        Reviewer
                                    </p>
                                    <p class="font-medium">
                                        {{ assignment.reviewer?.first_name }}
                                        {{ assignment.reviewer?.last_name }}
                                    </p>
                                </div>
                                <Badge
                                    :variant="
                                        statusBadgeVariant(assignment.status)
                                    "
                                >
                                    {{ assignment.status }}
                                </Badge>
                            </div>
                            <div v-if="assignment.reviewer" class="mt-2">
                                <p class="text-sm text-muted-foreground">
                                    Email
                                </p>
                                <p class="font-medium">
                                    {{ assignment.reviewer.email }}
                                </p>
                            </div>
                            <div class="mt-3">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        reassigningAssignmentId = assignment.id;
                                        assignError = '';
                                        showAssignDialog = true;
                                    "
                                >
                                    Reassign
                                </Button>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                @click="
                                    reassigningAssignmentId = null;
                                    assignError = '';
                                    showAssignDialog = true;
                                "
                            >
                                Assign Another Reviewer
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="autoAssignBidders"
                            >
                                Auto-assign bidders
                            </Button>
                        </div>
                    </div>
                    <div v-else class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            No reviewers assigned yet.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                @click="
                                    reassigningAssignmentId = null;
                                    assignError = '';
                                    showAssignDialog = true;
                                "
                            >
                                Assign Reviewer
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                @click="autoAssignBidders"
                            >
                                Auto-assign bidders
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Latest Decision Card -->
            <Card v-if="props.submission.decision">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <FileText class="h-5 w-5" />
                        Decision
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex items-center gap-3">
                        <Badge
                            :variant="
                                outcomeBadgeVariant[
                                    props.submission.decision.decision
                                ]
                            "
                        >
                            {{
                                props.submission.decision.decision.replace(
                                    '_',
                                    ' ',
                                )
                            }}
                        </Badge>
                        <span class="text-sm text-muted-foreground">
                            by
                            {{
                                props.submission.decision.decidedBy?.first_name
                            }}
                            {{ props.submission.decision.decidedBy?.last_name }}
                        </span>
                    </div>
                    <div v-if="props.submission.decision.rationale">
                        <p class="text-sm text-muted-foreground">Rationale</p>
                        <p class="mt-1">
                            {{ props.submission.decision.rationale }}
                        </p>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ formatDate(props.submission.decision.created_at) }}
                    </p>
                </CardContent>
            </Card>

            <!-- Decision Form (chair only) -->
            <Card v-if="canDecide">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <FileText class="h-5 w-5" />
                        Make a Decision
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submitDecision" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="decision">Outcome</Label>
                            <select
                                id="decision"
                                v-model="decisionForm.decision"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                <option value="" disabled>
                                    Select outcome...
                                </option>
                                <option
                                    v-for="option in decisionOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <p
                                v-if="decisionForm.errors.decision"
                                class="text-sm text-destructive"
                            >
                                {{ decisionForm.errors.decision }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <Label for="rationale">Rationale (optional)</Label>
                            <textarea
                                id="rationale"
                                v-model="decisionForm.rationale"
                                class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                placeholder="Optional rationale..."
                            />
                        </div>
                        <Button
                            type="submit"
                            :disabled="
                                !decisionForm.decision ||
                                decisionForm.processing
                            "
                        >
                            Submit Decision
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <!-- Reviews Section -->
            <Card
                v-if="
                    props.submission.reviews &&
                    props.submission.reviews.length > 0
                "
            >
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <MessageSquare class="h-5 w-5" />
                        Reviews
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div
                        v-for="review in props.submission.reviews"
                        :key="review.id"
                        class="rounded-lg border p-4"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <User class="h-4 w-4 text-muted-foreground" />
                                <span
                                    v-if="anonymizeReviewers"
                                    class="text-sm font-medium"
                                >
                                    Anonymous reviewer
                                </span>
                                <span v-else class="text-sm font-medium">
                                    {{ review.reviewer?.first_name }}
                                    {{ review.reviewer?.last_name }}
                                </span>
                            </div>
                            <Link
                                :href="`/reviews/${review.id}`"
                                class="text-sm text-primary hover:underline"
                            >
                                View Details
                            </Link>
                        </div>
                        <div class="flex items-center gap-2">
                            <Badge
                                :variant="
                                    recommendationVariant(review.recommendation)
                                "
                            >
                                {{ review.recommendation.replace('_', ' ') }}
                            </Badge>
                            <Badge v-if="review.grade" variant="outline">
                                {{ review.grade }} / 5
                            </Badge>
                        </div>
                        <p
                            v-if="review.comments"
                            class="mt-2 text-sm text-muted-foreground"
                        >
                            {{ review.comments }}
                        </p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ formatDate(review.created_at) }}
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Assign Reviewer Dialog -->
        <Dialog v-model:open="showAssignDialog">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        reassigningAssignmentId
                            ? 'Reassign Reviewer'
                            : 'Assign Reviewer'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            reassigningAssignmentId
                                ? `Select a new committee member to replace the current reviewer.`
                                : 'Select a committee member to review this submission.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3">
                    <Input
                        v-model="searchQuery"
                        placeholder="Search committee members..."
                        type="text"
                    />

                    <div
                        class="max-h-60 space-y-1 overflow-y-auto rounded-md border"
                    >
                        <div
                            v-for="member in filteredCommitteeMembers"
                            :key="member.id"
                            class="flex cursor-pointer items-center gap-3 rounded-md px-3 py-2 hover:bg-accent"
                            :class="{
                                'bg-accent': selectedReviewerId === member.id,
                            }"
                            @click="selectedReviewerId = member.id"
                        >
                            <Check
                                v-if="selectedReviewerId === member.id"
                                class="h-4 w-4 shrink-0 text-primary"
                            />
                            <X
                                v-else
                                class="h-4 w-4 shrink-0 text-muted-foreground"
                            />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ member.first_name }}
                                    {{ member.last_name }}
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ member.email }}
                                </p>
                            </div>
                            <div
                                v-if="coiFlag(member.id)?.declared"
                                class="shrink-0"
                            >
                                <Badge variant="destructive">
                                    Declared conflict
                                </Badge>
                            </div>
                            <div
                                v-else-if="
                                    coiFlag(member.id)?.auto_level === 'hard'
                                "
                                class="shrink-0"
                            >
                                <Badge variant="destructive">
                                    Auto conflict
                                </Badge>
                            </div>
                            <div
                                v-else-if="
                                    coiFlag(member.id)?.auto_level === 'soft'
                                "
                                class="shrink-0"
                            >
                                <Badge variant="secondary">
                                    Auto conflict (soft)
                                </Badge>
                            </div>
                            <div v-if="hasBid(member.id)" class="shrink-0">
                                <Badge variant="outline">Bid</Badge>
                            </div>
                        </div>

                        <div
                            v-if="filteredCommitteeMembers.length === 0"
                            class="px-3 py-6 text-center"
                        >
                            <p class="text-sm text-muted-foreground">
                                No committee members found.
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="selectedReviewer"
                        class="space-y-3 rounded-md bg-muted p-3 text-sm"
                    >
                        <div>
                            <span class="text-muted-foreground"
                                >Selected:
                            </span>
                            <span class="font-medium">
                                {{ selectedReviewer.first_name }}
                                {{ selectedReviewer.last_name }}
                            </span>
                        </div>

                        <div
                            v-if="coiFlag(selectedReviewer.id)?.declared"
                            class="text-xs text-destructive"
                        >
                            This reviewer has a declared conflict of interest
                            and cannot be assigned.
                        </div>

                        <div
                            v-else-if="
                                coiFlag(selectedReviewer.id)?.auto_level ===
                                'hard'
                            "
                            class="text-xs text-destructive"
                        >
                            Auto-detected hard conflict:
                            {{
                                coiFlag(selectedReviewer.id)?.auto_reason ||
                                'matches an author'
                            }}.
                        </div>
                        <div
                            v-else-if="
                                coiFlag(selectedReviewer.id)?.auto_level ===
                                'soft'
                            "
                            class="text-xs text-muted-foreground"
                        >
                            Auto-detected soft conflict:
                            {{
                                coiFlag(selectedReviewer.id)?.auto_reason ||
                                'weak match with an author'
                            }}.
                        </div>

                        <div
                            v-if="coiFlag(selectedReviewer.id)?.overridden"
                            class="text-xs text-muted-foreground"
                        >
                            Auto conflict overridden by the chair.
                        </div>

                        <div
                            v-if="
                                selectedReviewer &&
                                !coiFlag(selectedReviewer.id)?.declared
                            "
                            class="space-y-2"
                        >
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    id="override_conflict"
                                    v-model="overrideConflict"
                                />
                                <Label for="override_conflict"
                                    >Assign anyway (override auto
                                    conflict)</Label
                                >
                            </div>
                        </div>

                        <div
                            v-if="
                                selectedReviewer &&
                                !coiFlag(selectedReviewer.id)?.declared
                            "
                            class="space-y-2 border-t pt-2"
                        >
                            <Label>Declare a conflict against an author</Label>
                            <select
                                v-model="declareAuthorKey"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                <option value="" disabled>
                                    Select an author...
                                </option>
                                <option
                                    v-for="author in declareableAuthors"
                                    :key="author.key"
                                    :value="author.key"
                                >
                                    {{ author.label }}
                                </option>
                            </select>
                            <Input
                                v-model="declareReason"
                                placeholder="Reason (optional)"
                                type="text"
                            />
                            <Button
                                variant="outline"
                                :disabled="
                                    !selectedReviewerId ||
                                    (!declareAuthorId && !declareCoauthorId)
                                "
                                @click="declareConflict"
                            >
                                Declare conflict
                            </Button>
                        </div>

                        <div
                            v-if="
                                selectedReviewer &&
                                coiFlag(selectedReviewer.id)?.auto_level &&
                                !coiFlag(selectedReviewer.id)?.declared &&
                                !coiFlag(selectedReviewer.id)?.overridden
                            "
                            class="border-t pt-2"
                        >
                            <Button
                                variant="outline"
                                @click="overrideAutoConflict"
                            >
                                Override auto conflict
                            </Button>
                        </div>
                    </div>
                </div>

                <p v-if="assignError" class="text-sm text-destructive">
                    {{ assignError }}
                </p>

                <DialogFooter>
                    <Button variant="outline" @click="showAssignDialog = false"
                        >Cancel</Button
                    >
                    <Button
                        :disabled="!selectedReviewerId"
                        @click="assignReviewer"
                    >
                        Assign
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
