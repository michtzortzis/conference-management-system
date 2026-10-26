<script setup lang="ts">
import CoAuthorList from '@/components/CoAuthorList.vue';
import FileList from '@/components/FileList.vue';
import FileUpload from '@/components/FileUpload.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Submission, type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';

const page = usePage();
const auth = computed(() => page.props.auth);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Submissions', href: '/submissions' },
    { title: 'Edit', href: '#' },
];

interface Props {
    submission: Submission;
}

const props = defineProps<Props>();

const form = useForm({
    title: props.submission.title,
    abstract: props.submission.abstract,
    keywords: (props.submission.keywords || []).join(', '),
    co_authors:
        props.submission.co_authors?.map((c) => ({
            id: c.id,
            user_id: c.user_id,
            first_name: c.first_name,
            last_name: c.last_name,
            email: c.email,
        })) ||
        ([] as Array<{
            id?: number;
            user_id?: number | null;
            first_name: string;
            last_name: string;
            email: string;
        }>),
});

const keywordsArray = computed(() => {
    if (!form.keywords.trim()) return [];
    return form.keywords
        .split(',')
        .map((k) => k.trim())
        .filter((k) => k.length > 0);
});

function submit() {
    form.put(`/submissions/${props.submission.id}`, {
        onSuccess: () => {
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Edit Submission" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <HeadingSmall
                title="Edit Submission"
                description="Update your submission details."
            />

            <form
                @submit.prevent="submit"
                class="flex max-w-3xl flex-col gap-6"
            >
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="title">Title</Label>
                        <Input
                            id="title"
                            v-model="form.title"
                            required
                            placeholder="Enter submission title"
                        />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="abstract">Abstract</Label>
                        <textarea
                            id="abstract"
                            v-model="form.abstract"
                            required
                            rows="6"
                            class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            placeholder="Enter your abstract"
                        ></textarea>
                        <InputError :message="form.errors.abstract" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="keywords">Keywords (comma-separated)</Label>
                        <Input
                            id="keywords"
                            v-model="form.keywords"
                            placeholder="e.g., machine learning, AI, neural networks"
                        />
                        <div
                            class="text-sm text-muted-foreground"
                            v-if="keywordsArray.length > 0"
                        >
                            Parsed keywords: {{ keywordsArray.join(', ') }}
                        </div>
                        <InputError :message="form.errors.keywords" />
                    </div>

                    <CoAuthorList v-model="form.co_authors" />

                    <template v-if="submission.user_id === auth.user.id">
                        <FileList
                            :submission-id="submission.id"
                            :files="submission.files || []"
                            :show-actions="true"
                            @remove-file="
                                (fileId: number) =>
                                    router.delete(
                                        `/submissions/${submission.id}/files/${fileId}`,
                                    )
                            "
                        />
                        <FileUpload
                            :submission-id="submission.id"
                            :existing-files="submission.files || []"
                        />
                        <InputError
                            v-if="$page.props.errors?.files"
                            :message="$page.props.errors.files"
                        />
                    </template>

                    <div class="flex gap-3">
                        <Button type="submit" :disabled="form.processing"
                            >Save Changes</Button
                        >
                        <Link :href="`/submissions/${submission.id}`">
                            <Button variant="outline" type="button"
                                >Cancel</Button
                            >
                        </Link>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
