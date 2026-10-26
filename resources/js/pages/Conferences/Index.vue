<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/date';
import { Conference, type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
        title: 'Conferences',
        href: '/conferences',
    },
];

interface Props {
    conferences: Conference[];
}

defineProps<Props>();

const page = usePage();
const auth = computed(() => page.props.auth);

const showDeleteDialog = ref(false);
const conferenceToDelete = ref<number | null>(null);

function openDeleteDialog(id: number) {
    conferenceToDelete.value = id;
    showDeleteDialog.value = true;
}

function confirmDelete() {
    if (conferenceToDelete.value !== null) {
        router.delete(`/conferences/${conferenceToDelete.value}`, {
            onSuccess: () => {
                showDeleteDialog.value = false;
                conferenceToDelete.value = null;
            },
        });
    }
}

function getConferenceRoles(conferenceId: number): string[] {
    return auth.value.roles?.conferences[conferenceId] || [];
}

function canEditConference(conferenceId: number): boolean {
    const roles = getConferenceRoles(conferenceId);
    return roles.includes('chair');
}

function canDeleteConference(conferenceId: number): boolean {
    const roles = getConferenceRoles(conferenceId);
    return roles.includes('chair');
}

function canCreateConference(): boolean {
    const globalRoles = auth.value.roles?.global || [];
    return globalRoles.includes('organizer');
}

function isAuthor(): boolean {
    const globalRoles = auth.value.roles?.global || [];
    return globalRoles.includes('author');
}
</script>

<template>
    <Head title="Conferences" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="mb-4 flex justify-end" v-if="canCreateConference()">
                <Link href="/conferences/create">
                    <Button class="cursor-pointer">Add Conference</Button>
                </Link>
            </div>
            <Table>
                <TableCaption>A list of conferences.</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead>Description</TableHead>
                        <TableHead>Start Date</TableHead>
                        <TableHead>End Date</TableHead>
                        <TableHead>Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="conference in conferences"
                        :key="conference.id"
                    >
                        <TableCell class="font-medium">{{
                            conference.title
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            conference.description
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            formatDate(conference.start_date) || '-'
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            formatDate(conference.end_date) || '-'
                        }}</TableCell>
                        <TableCell class="flex gap-2">
                            <Link :href="`/conferences/${conference.id}`">
                                <Button variant="outline" class="cursor-pointer"
                                    >View</Button
                                >
                            </Link>
                            <Link
                                :href="`/submissions/create?conference_id=${conference.id}`"
                                v-if="isAuthor()"
                            >
                                <Button
                                    variant="secondary"
                                    class="cursor-pointer"
                                    >Submit Paper</Button
                                >
                            </Link>
                            <Link
                                :href="`/conferences/${conference.id}/edit`"
                                v-if="canEditConference(conference.id)"
                            >
                                <Button class="cursor-pointer">Edit</Button>
                            </Link>
                            <Button
                                variant="destructive"
                                class="cursor-pointer"
                                @click="openDeleteDialog(conference.id)"
                                v-if="canDeleteConference(conference.id)"
                                >Delete</Button
                            >
                            <Dialog v-model:open="showDeleteDialog">
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle
                                            >Delete Conference</DialogTitle
                                        >
                                    </DialogHeader>
                                    <div class="py-4">
                                        Are you sure you want to delete this
                                        conference? This action cannot be
                                        undone.
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            variant="outline"
                                            @click="showDeleteDialog = false"
                                            >Cancel</Button
                                        >
                                        <Button
                                            variant="destructive"
                                            @click="confirmDelete"
                                            >Delete</Button
                                        >
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
