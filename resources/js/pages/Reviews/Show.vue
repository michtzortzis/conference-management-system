<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatFileSize } from '@/lib/utils';
import { Review, Submission, type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Download, FileText } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '../../components/ui/button';

interface Props {
    review: Review & { submission: Submission };
    double_blind?: boolean;
    viewer_role?: 'chair' | 'reviewer' | 'author' | 'committee';
}

const props = defineProps<Props>();
const page = usePage();

const currentUser = computed(() => page.props.auth.user);

const isReviewer = computed(
    () => currentUser.value?.id === props.review.reviewer_id,
);

const isDoubleBlindReviewer = computed(
    () => props.double_blind && props.viewer_role === 'reviewer',
);

const isDoubleBlindAuthor = computed(
    () => props.double_blind && props.viewer_role === 'author',
);

const isDoubleBlindCommittee = computed(
    () => props.double_blind && props.viewer_role === 'committee',
);

const recommendationBadgeVariant = (
    recommendation: string,
): 'default' | 'destructive' | 'secondary' | 'outline' => {
    const variants: Record<
        string,
        'default' | 'destructive' | 'secondary' | 'outline'
    > = {
        accept: 'default',
        reject: 'destructive',
        minor_revision: 'secondary',
        major_revision: 'outline',
    };
    return variants[recommendation] || 'secondary';
};

const recommendationLabel = (recommendation: string) => {
    const labels: Record<string, string> = {
        accept: 'Accept',
        reject: 'Reject',
        minor_revision: 'Minor Revision',
        major_revision: 'Major Revision',
    };
    return labels[recommendation] || recommendation;
};

function deleteReview(id: number) {
    if (
        !confirm(
            'Are you sure you want to delete this review? This action can be undone.',
        )
    ) {
        return;
    }
    router.delete(`/reviews/${id}`);
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Reviews',
        href: '/reviews',
    },
    {
        title: `Review: ${props.review.submission.title}`,
        href: '#',
    },
];

const authorName = computed(() => {
    if (!props.review.submission.user) return 'Unknown';
    return `${props.review.submission.user.first_name} ${props.review.submission.user.last_name}`;
});

const reviewerName = computed(() => {
    if (!props.review.reviewer) return 'Unknown';
    return `${props.review.reviewer.first_name} ${props.review.reviewer.last_name}`;
});

function downloadUrl(submissionId: number, fileId: number): string {
    return `/submissions/${submissionId}/files/${fileId}/download`;
}

function getFileExtension(filename: string): string {
    return filename.split('.').pop()?.toUpperCase() || '';
}
</script>

<template>
    <Head title="Review Details" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="flex items-center justify-between">
                <HeadingSmall
                    title="Review Details"
                    description="Submission review and recommendation."
                />
                <div class="flex gap-2">
                    <Button v-if="isReviewer" size="sm" as-child>
                        <Link :href="`/reviews/${review.id}/edit`"
                            >Edit Review</Link
                        >
                    </Button>
                    <Button
                        v-if="isReviewer"
                        variant="destructive"
                        size="sm"
                        @click="deleteReview(review.id)"
                    >
                        Delete
                    </Button>
                </div>
            </div>

            <div class="grid max-w-3xl gap-6">
                <div class="space-y-4 rounded-lg border p-6">
                    <h2 class="text-xl font-semibold">
                        {{ review.submission.title }}
                    </h2>
                    <div class="grid gap-2 text-sm">
                        <div
                            v-if="
                                isDoubleBlindReviewer || isDoubleBlindCommittee
                            "
                        >
                            <span class="text-muted-foreground">Author:</span>
                            Anonymous author
                        </div>
                        <div v-else>
                            <span class="text-muted-foreground">Author:</span>
                            {{ authorName }}
                        </div>
                        <div
                            v-if="isDoubleBlindAuthor || isDoubleBlindCommittee"
                        >
                            <span class="text-muted-foreground">Reviewer:</span>
                            Anonymous reviewer
                        </div>
                        <div v-else>
                            <span class="text-muted-foreground">Reviewer:</span>
                            {{ reviewerName }}
                        </div>
                        <div v-if="review.submission.track">
                            <span class="text-muted-foreground">Track:</span>
                            {{ review.submission.track.name }}
                        </div>
                        <div v-if="review.submission.conference">
                            <span class="text-muted-foreground"
                                >Conference:</span
                            >
                            {{ review.submission.conference.title }}
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-1 font-medium">Abstract</h3>
                        <p
                            class="text-sm whitespace-pre-wrap text-muted-foreground"
                        >
                            {{ review.submission.abstract }}
                        </p>
                    </div>
                    <div v-if="review.submission.keywords?.length">
                        <h3 class="mb-1 font-medium">Keywords</h3>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-for="keyword in review.submission.keywords"
                                :key="keyword"
                                class="inline-flex items-center rounded-md bg-secondary px-2 py-1 text-xs font-medium ring-1 ring-secondary/10 ring-inset"
                            >
                                {{ keyword }}
                            </span>
                        </div>
                    </div>
                    <div v-if="review.submission.files?.length">
                        <h3 class="mb-1 font-medium">Attached Files</h3>
                        <div class="space-y-2">
                            <div
                                v-for="file in review.submission.files"
                                :key="file.id"
                                class="flex items-center justify-between rounded-lg border px-4 py-3"
                            >
                                <div class="flex items-center gap-3">
                                    <FileText
                                        class="h-5 w-5 text-muted-foreground"
                                    />
                                    <div>
                                        <p class="text-sm font-medium">
                                            {{ file.filename }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ formatFileSize(file.size) }} ·
                                            {{
                                                getFileExtension(file.filename)
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Badge variant="secondary">{{
                                        getFileExtension(file.filename)
                                    }}</Badge>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        as-child
                                    >
                                        <a
                                            :href="
                                                downloadUrl(
                                                    review.submission.id,
                                                    file.id,
                                                )
                                            "
                                        >
                                            <Download class="h-4 w-4" />
                                        </a>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-4 rounded-lg border p-6">
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium">Recommendation</h3>
                        <Badge
                            :variant="
                                recommendationBadgeVariant(
                                    review.recommendation,
                                )
                            "
                        >
                            {{ recommendationLabel(review.recommendation) }}
                        </Badge>
                    </div>
                    <div v-if="review.grade" class="flex items-center gap-2">
                        <h3 class="font-medium">Grade</h3>
                        <Badge variant="outline">{{ review.grade }} / 5</Badge>
                    </div>
                    <div>
                        <h3 class="mb-1 font-medium">Comments</h3>
                        <p
                            class="text-sm whitespace-pre-wrap text-muted-foreground"
                        >
                            {{ review.comments || 'No comments provided.' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
