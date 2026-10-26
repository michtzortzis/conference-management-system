<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { ExpertiseArea, type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Expertise Areas',
        href: '/expertise-areas',
    },
    {
        title: 'Edit',
        href: '#',
    },
];

interface Props {
    expertise_area: ExpertiseArea;
}

const props = defineProps<Props>();

const form = useForm({
    name: props.expertise_area.name,
    description: props.expertise_area.description,
});

function submit() {
    form.put(`/expertise-areas/${props.expertise_area.id}`, {
        onSuccess: () => {
            toast.success('Expertise Area updated successfully.');
            router.visit('/expertise-areas');
        },
        onError: () => {
            // Errors will be shown via InputError components
        },
    });
}
</script>

<template>
    <Head title="Edit Expertise Area" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <HeadingSmall
                title="Edit Expertise Area"
                description="Update the name and description of the expertise area"
            />

            <form @submit.prevent="submit" class="flex flex-col gap-6">
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="description">Description</Label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            class="flex min-h-[80px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                        ></textarea>
                        <InputError :message="form.errors.description" />
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
