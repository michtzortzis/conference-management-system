<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { ExpertiseArea, type BreadcrumbItem } from '@/types';
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
        title: 'Expertise Areas',
        href: '/expertise-areas',
    },
    {
        title: 'Show',
        href: '#',
    },
];

interface Props {
    expertise_area: ExpertiseArea & {
        users: Array<{
            id: number;
            first_name: string;
            last_name: string;
            email: string;
            pivot: { years_experience: number };
        }>;
    };
}

defineProps<Props>();
</script>

<template>
    <Head title="Expertise Area Details" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="mb-6">
                <h1 class="text-2xl font-bold">{{ expertise_area.name }}</h1>
                <p class="mt-2 text-gray-600">
                    {{ expertise_area.description }}
                </p>
            </div>

            <Table>
                <TableCaption
                    >Users with expertise in
                    {{ expertise_area.name }}</TableCaption
                >
                <TableHeader>
                    <TableRow>
                        <TableHead>First Name</TableHead>
                        <TableHead>Last Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Years of Expertise</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="user in expertise_area.users"
                        :key="user.id"
                    >
                        <TableCell>{{ user.first_name }}</TableCell>
                        <TableCell>{{ user.last_name }}</TableCell>
                        <TableCell>{{ user.email }}</TableCell>
                        <TableCell>{{ user.pivot.years_experience }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
