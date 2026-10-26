<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { dashboard } from '@/routes';
import { index as assignmentsIndex } from '@/routes/assignments';
import { type BreadcrumbItem, type Submission } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

interface Props {
    stats: {
        my_submissions: number;
        pending_reviews: number;
        my_conferences: number;
    };
    myConferences: { id: number; title: string }[];
    recentSubmissions: Submission[];
    globalCounts: { conferences: number; submissions: number } | null;
}

defineProps<Props>();
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="grid gap-4 md:grid-cols-3">
                <Card class="justify-between">
                    <CardHeader>
                        <CardTitle>My Submissions</CardTitle>
                        <CardDescription
                            >Papers you have authored.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="text-3xl font-semibold">{{
                        stats.my_submissions
                    }}</CardContent>
                </Card>

                <Card class="justify-between">
                    <CardHeader>
                        <CardTitle>
                            <Link
                                :href="assignmentsIndex().url"
                                class="hover:underline"
                                >Pending Reviews</Link
                            >
                        </CardTitle>
                        <CardDescription
                            >Review invitations awaiting your decision in
                            Assignments.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="text-3xl font-semibold">{{
                        stats.pending_reviews
                    }}</CardContent>
                </Card>

                <Card class="justify-between">
                    <CardHeader>
                        <CardTitle>My Conferences</CardTitle>
                        <CardDescription
                            >Conferences you are involved in.</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="text-3xl font-semibold">{{
                        stats.my_conferences
                    }}</CardContent>
                </Card>
            </div>

            <div
                class="flex-1 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            >
                <p
                    v-if="globalCounts"
                    class="px-6 pt-4 text-sm text-muted-foreground"
                >
                    Global totals:
                    {{ globalCounts.conferences }} conferences,
                    {{ globalCounts.submissions }} submissions
                </p>
                <Table>
                    <TableCaption>Recent submissions</TableCaption>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Title</TableHead>
                            <TableHead>Conference</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="submission in recentSubmissions"
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
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AppLayout>
</template>
