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
import { CoiFlag, Submission, type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Gavel, Hand, HandCoins, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Bidding',
        href: '/bidding',
    },
];

interface Props {
    submissions: Submission[];
    bid_submission_ids: number[];
    coi_flags: Record<number, CoiFlag>;
    max_reviewers: number;
}

const props = defineProps<Props>();

const bidSet = computed(() => new Set(props.bid_submission_ids));
const atCap = computed(
    () => props.bid_submission_ids.length >= props.max_reviewers,
);

function flagFor(submissionId: number): CoiFlag | undefined {
    return props.coi_flags[submissionId];
}

function bidBlocked(submissionId: number): boolean {
    const flag = flagFor(submissionId);
    if (!flag) return false;
    if (flag.declared) return true;
    if (flag.auto_level === 'hard' && !flag.overridden) return true;
    return false;
}

function bid(submissionId: number) {
    router.post(
        `/submissions/${submissionId}/bid`,
        {},
        {
            preserveScroll: true,
        },
    );
}

function unbid(submissionId: number) {
    if (!confirm('Withdraw your bid on this submission?')) {
        return;
    }
    router.delete(`/submissions/${submissionId}/unbid`, {
        preserveScroll: true,
    });
}

const statusVariants: Record<string, string> = {
    submitted: 'secondary',
    under_review: 'default',
    accepted: 'outline',
    rejected: 'destructive',
    minor_revision: 'default',
    major_revision: 'default',
};
</script>

<template>
    <Head title="Bidding" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="flex items-center gap-2">
                <Gavel class="h-5 w-5 text-muted-foreground" />
                <h1 class="text-2xl font-bold tracking-tight">
                    Bid on Submissions
                </h1>
            </div>
            <p class="text-sm text-muted-foreground">
                Choose the submissions you would like to review. Bidding is open
                between the CFP close and the start of the review period. You
                can bid on up to {{ max_reviewers }} submissions.
            </p>

            <Table v-if="props.submissions.length > 0">
                <TableCaption>
                    {{ props.submissions.length }} submission(s) open for
                    bidding
                </TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead>Abstract</TableHead>
                        <TableHead>Track</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Action</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="submission in props.submissions"
                        :key="submission.id"
                    >
                        <TableCell class="font-medium">
                            {{ submission.title }}
                        </TableCell>
                        <TableCell class="max-w-md">
                            <span class="line-clamp-2 text-muted-foreground">
                                {{ submission.abstract }}
                            </span>
                        </TableCell>
                        <TableCell>
                            <span v-if="submission.track">
                                {{ submission.track.name }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    statusVariants[submission.status] ||
                                    'secondary'
                                "
                            >
                                {{ submission.status.replace('_', ' ') }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <div
                                v-if="bidBlocked(submission.id)"
                                class="flex items-center gap-2"
                            >
                                <Badge variant="destructive">
                                    <TriangleAlert class="mr-1 h-3 w-3" />
                                    Conflict of interest
                                </Badge>
                                <Button
                                    v-if="bidSet.has(submission.id)"
                                    variant="outline"
                                    size="sm"
                                    @click="unbid(submission.id)"
                                >
                                    <HandCoins class="mr-1 h-4 w-4" />
                                    Unbid
                                </Button>
                            </div>
                            <div v-else class="flex items-center gap-2">
                                <Badge
                                    v-if="
                                        flagFor(submission.id)?.auto_level ===
                                        'soft'
                                    "
                                    variant="secondary"
                                >
                                    Flagged (soft)
                                </Badge>
                                <Button
                                    v-if="bidSet.has(submission.id)"
                                    variant="outline"
                                    size="sm"
                                    @click="unbid(submission.id)"
                                >
                                    <HandCoins class="mr-1 h-4 w-4" />
                                    Unbid
                                </Button>
                                <Button
                                    v-else
                                    size="sm"
                                    :disabled="atCap"
                                    @click="bid(submission.id)"
                                >
                                    <Hand class="mr-1 h-4 w-4" />
                                    Bid
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
                <Gavel class="mb-4 h-12 w-12 text-muted-foreground" />
                <h3 class="text-lg font-medium">
                    No submissions open for bidding
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Submissions become biddable after the CFP closes and before
                    the review period starts.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
