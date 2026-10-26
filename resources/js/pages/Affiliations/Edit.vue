<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Affiliation, type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Affiliations',
        href: '/affiliations',
    },
    {
        title: 'Edit',
        href: '#',
    },
];

interface Props {
    affiliation: Affiliation;
}

const props = defineProps<Props>();

const form = useForm({
    name: props.affiliation.name,
    type: props.affiliation.type,
    country: props.affiliation.country,
});

function submit() {
    form.put(`/affiliations/${props.affiliation.id}`, {
        onSuccess: () => {
            toast.success('Affiliation updated successfully.');
            router.visit('/affiliations');
        },
        onError: () => {
            // Errors will be shown via InputError components
        },
    });
}
</script>

<template>
    <Head title="Edit Affiliation" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <HeadingSmall
                title="Edit Affiliation"
                description="Update the name and description of the affiliation"
            />

            <form @submit.prevent="submit" class="flex flex-col gap-6">
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="type">Type</Label>
                        <Input id="type" v-model="form.type" required />
                        <InputError :message="form.errors.type" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="country">Country</Label>
                        <Input id="country" v-model="form.country" required />
                        <InputError :message="form.errors.country" />
                    </div>

                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="self-center"
                    >
                        <span
                            v-if="form.processing"
                            class="mr-2 inline-block h-4 w-4 animate-spin"
                            >⏳</span
                        >
                        Update
                    </Button>
                </div>

                <div class="mt-2 flex items-center gap-4">
                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="form.recentlySuccessful"
                            class="text-sm text-neutral-600"
                        >
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
