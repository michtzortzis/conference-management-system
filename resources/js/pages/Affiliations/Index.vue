<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
// import { affiliations } from '@/routes';
import { Affiliation, type BreadcrumbItem } from '@/types';
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
        title: 'Affiliations',
        // href: affiliations().url,
        href: '/affiliations',
    },
];

interface Props {
    affiliations: Affiliation[];
}

defineProps<Props>();
</script>

<template>
    <Head title="Affiliations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <Table>
                <TableCaption>
                    All registered institutions and organizations.
                </TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Country</TableHead>
                        <TableHead>Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="affiliation in affiliations"
                        :key="affiliation.id"
                    >
                        <TableCell class="font-medium">{{
                            affiliation.name
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            affiliation.type
                        }}</TableCell>
                        <TableCell class="font-medium">{{
                            affiliation.country ?? '-'
                        }}</TableCell>
                        <TableCell class="flex gap-2">
                            <Link :href="`/affiliations/${affiliation.id}`">
                                <Button variant="outline" class="cursor-pointer"
                                    >View</Button
                                >
                            </Link>
                            <Link
                                :href="`/affiliations/${affiliation.id}/edit`"
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
