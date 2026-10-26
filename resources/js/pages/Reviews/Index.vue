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
import { FileText } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Reviews',
        href: '/reviews',
    },
];

interface Props {
    assignments: ReviewAssignment[];
}

defineProps<Props>();

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
    <Head title="Reviews" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="flex items-center gap-2">
                <FileText class="h-5 w-5 text-muted-foreground" />
                <h1 class="text-2xl font-bold tracking-tight">
                    Reviews to Complete
                </h1>
            </div>
            <p class="text-sm text-muted-foreground">
                Assignments you have accepted. New invitations show up under
                <Link href="/assignments" class="font-medium hover:underline"
                    >Assignments</Link
                >
                until you accept them.
            </p>

            <Table v-if="assignments.length > 0">
                <TableCaption>Reviews you agreed to write</TableCaption>
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
                        v-for="assignment in assignments"
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
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            <span v-if="assignment.submission?.track">
                                {{ assignment.submission.track.name }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="statusBadgeVariant(assignment.status)"
                            >
                                {{ assignment.status }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <div class="flex gap-2">
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
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="selfDeclareConflict(assignment.id)"
                                >
                                    Declare conflict
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <div
                v-else
                class="flex flex-col items-center justify-center rounded-lg border border-dashed p-12"
            >
                <FileText class="mb-4 h-12 w-12 text-muted-foreground" />
                <h3 class="text-lg font-medium">No accepted reviews yet</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Accept an invitation under
                    <Link
                        href="/assignments"
                        class="font-medium hover:underline"
                        >Assignments</Link
                    >
                    and the submission will appear here for you to review.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
