<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
// import { affiliations } from '@/routes';
import { ExpertiseArea, type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
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
        title: 'Expertise Areas',
        // href: expertiseAreas().url,
        href: '/expertise-areas',
    },
];

interface Props {
    expertise_areas: ExpertiseArea[];
}
defineProps<Props>();
</script>

<template>
    <Head title="Expertise Areas" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <Table>
                <TableCaption>All available expertise areas.</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Description</TableHead>
                        <TableHead>Users</TableHead>
                        <TableHead>Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="expertise_area in expertise_areas"
                        :key="expertise_area.id"
                    >
                        <TableCell class="font-medium">{{
                            expertise_area.name
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            expertise_area.description
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            expertise_area?.users?.length ?? 0
                        }}</TableCell>
                        <TableCell class="flex gap-2">
                            <Link
                                :href="`/expertise-areas/${expertise_area.id}`"
                            >
                                <Button variant="outline" class="cursor-pointer"
                                    >View</Button
                                >
                            </Link>
                            <Link
                                :href="`/expertise-areas/${expertise_area.id}/edit`"
                            >
                                <Button class="cursor-pointer">Edit</Button>
                            </Link>
                            <Button variant="destructive" class="cursor-pointer"
                                >Delete</Button
                            >
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
