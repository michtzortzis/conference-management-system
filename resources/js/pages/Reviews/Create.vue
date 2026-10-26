<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatFileSize } from '@/lib/utils';
import { type BreadcrumbItem, ReviewAssignment } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, FileText, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '../../components/ui/button';
import { Label } from '../../components/ui/label';

interface Props {
    assignment: ReviewAssignment;
    double_blind?: boolean;
    review_window_open?: boolean;
}

const props = defineProps<Props>();

const recommendationOptions = [
    { value: 'accept', label: 'Accept' },
    { value: 'reject', label: 'Reject' },
    { value: 'minor_revision', label: 'Minor Revision' },
    { value: 'major_revision', label: 'Major Revision' },
] as const;

const gradeOptions = [1, 2, 3, 4, 5] as const;

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Reviews',
        href: '/reviews',
    },
    {
        title: `Review: ${props.assignment?.submission?.title}`,
        href: '#',
    },
];

const form = useForm({
    submission_id: props.assignment?.submission?.id.toString(),
    assignment_id: props.assignment.id?.toString() ?? '',
    recommendation: '',
    comments: '',
    grade: '',
});

const authorName = computed(() => {
    if (!props.assignment.submission?.user) return 'Unknown';
    return `${props.assignment?.submission?.user?.first_name} ${props.assignment?.submission?.user.last_name}`;
});

function downloadUrl(submissionId: number, fileId: number): string {
    return `/submissions/${submissionId}/files/${fileId}/download`;
}

function getFileExtension(filename: string): string {
    return filename.split('.').pop()?.toUpperCase() || '';
}

function submit() {
    form.post('/reviews', {
        onSuccess: () => {
            form.reset();
        },
    });
}

function selfDeclareConflict() {
    const reason = prompt(
        'Reason for declaring a conflict of interest (optional):',
    );
    router.post(
        `/reviews/${props.assignment.id}/self-conflict`,
        { reason: reason || '' },
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <Head title="Create Review" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <HeadingSmall
                title="Write Review"
                description="Review the submission and provide your recommendation."
            />

            <div
                v-if="!assignment?.submission"
                class="max-w-3xl rounded-lg border border-red-200 bg-red-50 p-4"
            >
                <p class="text-sm font-medium text-red-800">
                    This submission could not be found. The assignment may have
                    been invalidated.
                </p>
            </div>

            <template v-if="assignment?.submission">
                <div
                    class="max-w-3xl rounded-lg border border-amber-200 bg-amber-50 p-4"
                >
                    <div class="flex items-center gap-2 text-amber-800">
                        <TriangleAlert class="h-5 w-5" />
                        <p class="text-sm font-medium">
                            Do you have a conflict of interest with this
                            submission? Declare it and the assignment will be
                            withdrawn for reassignment.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        class="mt-3"
                        @click="selfDeclareConflict"
                    >
                        Declare conflict of interest
                    </Button>
                </div>

                <div
                    v-if="review_window_open === false"
                    class="mb-4 max-w-3xl rounded-lg border border-amber-200 bg-amber-50 p-4"
                >
                    <p class="text-sm font-medium text-amber-800">
                        The review window for this track has closed. You can
                        still submit your review, but it may not be counted for
                        the final decision.
                    </p>
                </div>

                <div class="grid max-w-3xl gap-6">
                    <div class="space-y-4 rounded-lg border p-6">
                        <h2 class="text-xl font-semibold">
                            {{ assignment.submission?.title }}
                        </h2>
                        <div class="grid gap-2 text-sm">
                            <div v-if="!double_blind">
                                <span class="text-muted-foreground"
                                    >Author:</span
                                >
                                {{ authorName }}
                            </div>
                            <div v-else>
                                <span class="text-muted-foreground"
                                    >Author:</span
                                >
                                Author hidden for double-blind review
                            </div>
                            <div v-if="assignment.submission?.track">
                                <span class="text-muted-foreground"
                                    >Track:</span
                                >
                                {{ assignment.submission.track.name }}
                            </div>
                            <div v-if="assignment.submission?.conference">
                                <span class="text-muted-foreground"
                                    >Conference:</span
                                >
                                {{ assignment.submission.conference.title }}
                            </div>
                        </div>
                        <div>
                            <h3 class="mb-1 font-medium">Abstract</h3>
                            <p
                                class="text-sm whitespace-pre-wrap text-muted-foreground"
                            >
                                {{ assignment.submission?.abstract }}
                            </p>
                        </div>
                        <div v-if="assignment.submission?.keywords?.length">
                            <h3 class="mb-1 font-medium">Keywords</h3>
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="keyword in assignment.submission
                                        ?.keywords"
                                    :key="keyword"
                                    class="inline-flex items-center rounded-md bg-secondary px-2 py-1 text-xs font-medium ring-1 ring-secondary/10 ring-inset"
                                >
                                    {{ keyword }}
                                </span>
                            </div>
                        </div>
                        <div v-if="assignment.submission?.files?.length">
                            <h3 class="mb-1 font-medium">Attached Files</h3>
                            <div class="space-y-2">
                                <div
                                    v-for="file in assignment.submission?.files"
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
                                                {{ formatFileSize(file.size) }}
                                                ·
                                                {{
                                                    getFileExtension(
                                                        file.filename,
                                                    )
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
                                                        assignment.submission!
                                                            .id,
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
                        <h3 class="font-medium">Review</h3>
                        <form
                            @submit.prevent="submit"
                            class="flex flex-col gap-6"
                        >
                            <div class="grid gap-2">
                                <Label for="recommendation"
                                    >Recommendation</Label
                                >
                                <select
                                    id="recommendation"
                                    v-model="form.recommendation"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                    required
                                >
                                    <option value="">
                                        Select a recommendation
                                    </option>
                                    <option
                                        v-for="option in recommendationOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <InputError
                                    :message="form.errors.recommendation"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="grade">Grade</Label>
                                <select
                                    id="grade"
                                    v-model="form.grade"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                    required
                                >
                                    <option value="">
                                        Select a grade (1-5)
                                    </option>
                                    <option
                                        v-for="gradeOption in gradeOptions"
                                        :key="gradeOption"
                                        :value="gradeOption"
                                    >
                                        {{ gradeOption }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.grade" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="comments">Comments</Label>
                                <textarea
                                    id="comments"
                                    v-model="form.comments"
                                    rows="8"
                                    class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                    placeholder="Provide your review comments..."
                                ></textarea>
                                <InputError :message="form.errors.comments" />
                            </div>

                            <div class="flex gap-2">
                                <Button
                                    type="submit"
                                    :disabled="form.processing"
                                >
                                    Submit Review
                                </Button>
                                <Button variant="outline" as-child>
                                    <Link href="/reviews">Cancel</Link>
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
