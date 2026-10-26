<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Affiliation, type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
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
        href: '/affiliations',
    },
    {
        title: 'Show',
        href: '#',
    },
];

interface Props {
    affiliation: Affiliation & {
        users: Array<{
            id: number;
            first_name: string;
            last_name: string;
            email: string;
            pivot: { department: string; position: string };
        }>;
    };
}

defineProps<Props>();
</script>

<template>
    <Head title="Affiliation Details" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="mb-6">
                <h1 class="text-2xl font-bold">{{ affiliation.name }}</h1>
                <p class="mt-2 text-gray-600">{{ affiliation.type }}</p>
                <p class="mt-2 text-gray-600">{{ affiliation.country }}</p>
            </div>

            <Table>
                <TableCaption
                    >Users affiliated to {{ affiliation.name }}</TableCaption
                >
                <TableHeader>
                    <TableRow>
                        <TableHead>First Name</TableHead>
                        <TableHead>Last Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Department</TableHead>
                        <TableHead>Position</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in affiliation.users" :key="user.id">
                        <TableCell>{{ user.first_name }}</TableCell>
                        <TableCell>{{ user.last_name }}</TableCell>
                        <TableCell>{{ user.email }}</TableCell>
                        <TableCell>{{ user.pivot.department }}</TableCell>
                        <TableCell>{{ user.pivot.position }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
