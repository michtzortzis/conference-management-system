<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Submission, type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '../../components/ui/button';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '../../components/ui/table';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Submissions',
        href: '/submissions',
    },
];

interface Props {
    submissions: Submission[];
}

defineProps<Props>();

const page = usePage();
const auth = computed(() => page.props.auth);

function canCreateSubmission(): boolean {
    const globalRoles = auth.value.roles?.global || [];
    return globalRoles.includes('author') || globalRoles.includes('chair');
}

function canEditSubmission(submission: Submission): boolean {
    return (
        submission.user_id === auth.value.user?.id &&
        submission.status === 'draft'
    );
}
</script>

<template>
    <Head title="Submissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="mb-4 flex justify-end" v-if="canCreateSubmission()">
                <Link href="/submissions/create">
                    <Button class="cursor-pointer">Create Submission</Button>
                </Link>
            </div>
            <Table>
                <TableCaption>List of submissions</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead>Conference</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="submission in submissions"
                        :key="submission.id"
                    >
                        <TableCell class="font-medium">{{
                            submission.title
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            submission.conference?.title ?? 'N/A'
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            submission.status
                        }}</TableCell>
                        <TableCell class="flex gap-2">
                            <Link :href="`/submissions/${submission.id}`">
                                <Button variant="outline" class="cursor-pointer"
                                    >View</Button
                                >
                            </Link>
                            <template v-if="canEditSubmission(submission)">
                                <Link
                                    :href="`/submissions/${submission.id}/edit`"
                                >
                                    <Button
                                        variant="outline"
                                        class="cursor-pointer"
                                        >Edit</Button
                                    >
                                </Link>
                            </template>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
