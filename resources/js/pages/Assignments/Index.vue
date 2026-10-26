<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { ReviewAssignment, type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardCheck, Inbox } from 'lucide-vue-next';
import { computed } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Assignments',
        href: '/assignments',
    },
];

interface Props {
    reviewer_assignments: ReviewAssignment[];
    assignments: ReviewAssignment[];
}

const props = defineProps<Props>();

const hasInbox = computed(() => props.reviewer_assignments.length > 0);
const hasOverview = computed(() => props.assignments.length > 0);

function acceptAssignment(assignmentId: number) {
    if (!confirm('Are you sure you want to accept this review assignment?')) {
        return;
    }
    router.post(
        `/reviews/${assignmentId}/accept`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function declineAssignment(assignmentId: number) {
    if (!confirm('Are you sure you want to decline this review assignment?')) {
        return;
    }
    router.post(
        `/reviews/${assignmentId}/decline`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function selfDeclareConflict(assignmentId: number) {
    const reason = prompt(
        'Reason for declaring a conflict of interest (optional):',
    );
    router.post(
        `/reviews/${assignmentId}/self-conflict`,
        { reason: reason || '' },
        {
            preserveScroll: true,
        },
    );
}

const statusBadgeVariant = (status: string) => {
    const variants = {
        pending: 'secondary',
        accepted: 'default',
        declined: 'destructive',
    } as Record<string, string>;
    return variants[status] || 'secondary';
};
</script>

<template>
    <Head title="Assignments" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="flex items-center gap-2">
                <ClipboardCheck class="h-5 w-5 text-muted-foreground" />
                <h1 class="text-2xl font-bold tracking-tight">Assignments</h1>
            </div>
            <p class="text-sm text-muted-foreground">
                Review invitations awaiting your decision, plus the review work
                you have already accepted.
            </p>

            <div
                v-if="!hasInbox && !hasOverview"
                class="flex flex-col items-center justify-center rounded-lg border border-dashed p-12"
            >
                <ClipboardCheck class="mb-4 h-12 w-12 text-muted-foreground" />
                <h3 class="text-lg font-medium">No assignments yet</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Review invitations will appear here as soon as a chair
                    assigns you to a submission.
                </p>
            </div>

            <section v-if="hasInbox" class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <Inbox class="h-4 w-4 text-muted-foreground" />
                    <h2 class="text-lg font-semibold tracking-tight">
                        Your review assignments
                    </h2>
                </div>

                <Table>
                    <TableCaption>
                        {{ reviewer_assignments.length }} assignment(s) assigned
                        to you
                    </TableCaption>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Submission</TableHead>
                            <TableHead>Conference</TableHead>
                            <TableHead>Track</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Action</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="assignment in reviewer_assignments"
                            :key="assignment.id"
                        >
                            <TableCell class="font-medium">
                                <Link
                                    v-if="assignment.submission"
                                    :href="`/submissions/${assignment.submission.id}`"
                                    class="hover:underline"
                                >
                                    {{ assignment.submission.title }}
                                </Link>
                                <span v-else class="text-muted-foreground"
                                    >Unknown</span
                                >
                            </TableCell>
                            <TableCell>
                                <span v-if="assignment.submission?.conference">
                                    {{ assignment.submission.conference.title }}
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                            <TableCell>
                                <span v-if="assignment.submission?.track">
                                    {{ assignment.submission.track.name }}
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        statusBadgeVariant(assignment.status)
                                    "
                                >
                                    {{ assignment.status }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <div
                                    v-if="assignment.status === 'pending'"
                                    class="flex gap-2"
                                >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="acceptAssignment(assignment.id)"
                                    >
                                        Accept
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        size="sm"
                                        @click="
                                            declineAssignment(assignment.id)
                                        "
                                    >
                                        Decline
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            selfDeclareConflict(assignment.id)
                                        "
                                    >
                                        Declare conflict
                                    </Button>
                                </div>
                                <div
                                    v-else-if="assignment.status === 'accepted'"
                                    class="flex gap-2"
                                >
                                    <Link
                                        v-if="assignment.reviews?.length"
                                        :href="`/reviews/${assignment.reviews[0].id}`"
                                    >
                                        <Button variant="outline" size="sm"
                                            >View</Button
                                        >
                                    </Link>
                                    <Link
                                        v-if="assignment.reviews?.length"
                                        :href="`/reviews/${assignment.reviews[0].id}/edit`"
                                    >
                                        <Button size="sm">Edit Review</Button>
                                    </Link>
                                    <Link
                                        v-else
                                        :href="`/reviews/create?assignment_id=${assignment.id}&submission_id=${assignment.submission_id}`"
                                    >
                                        <Button size="sm">Write Review</Button>
                                    </Link>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </section>

            <section v-if="hasOverview" class="flex flex-col gap-2">
                <h2 class="text-lg font-semibold tracking-tight">
                    Assignment overview
                </h2>
                <p class="text-sm text-muted-foreground">
                    All review assignments across your conferences. Use this to
                    review and fix anything auto-assign produced.
                </p>

                <Table>
                    <TableCaption>
                        {{ assignments.length }} review assignment(s) across
                        your conferences
                    </TableCaption>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Conference</TableHead>
                            <TableHead>Submission</TableHead>
                            <TableHead>Track</TableHead>
                            <TableHead>Reviewer</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Assigned</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="assignment in assignments"
                            :key="assignment.id"
                        >
                            <TableCell>
                                <span v-if="assignment.submission?.conference">
                                    {{ assignment.submission.conference.title }}
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                            <TableCell class="font-medium">
                                <Link
                                    v-if="assignment.submission"
                                    :href="`/submissions/${assignment.submission.id}`"
                                    class="hover:underline"
                                >
                                    {{ assignment.submission.title }}
                                </Link>
                                <span v-else class="text-muted-foreground"
                                    >Unknown</span
                                >
                            </TableCell>
                            <TableCell>
                                <span v-if="assignment.submission?.track">
                                    {{ assignment.submission.track.name }}
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                            <TableCell>
                                <span v-if="assignment.reviewer">
                                    {{ assignment.reviewer.first_name }}
                                    {{ assignment.reviewer.last_name }}
                                </span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        statusBadgeVariant(assignment.status)
                                    "
                                >
                                    {{ assignment.status }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <Link
                                    v-if="assignment.submission"
                                    :href="`/submissions/${assignment.submission.id}`"
                                >
                                    <Button variant="outline" size="sm"
                                        >Reassign</Button
                                    >
                                </Link>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </section>
        </div>
    </AppLayout>
</template>
