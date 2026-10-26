<script setup lang="ts">
import CoAuthorList from '@/components/CoAuthorList.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDayMonth, phaseClosesAt, phaseOpensAt } from '@/lib/date';
import { type BreadcrumbItem, Conference, Track } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';

const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Submissions',
        href: '/submissions',
    },
    {
        title: 'Create',
        href: '#',
    },
];

interface Props {
    conferences: Conference[];
    conference_id?: string;
    track_id?: string;
}

const props = defineProps<Props>();

const urlParams = new URLSearchParams(page.url.split('?')[1]);
const initialConferenceId =
    urlParams.get('conference_id') || props.conference_id || '';
const initialTrackId = urlParams.get('track_id') || props.track_id || '';

const form = useForm({
    conference_id: initialConferenceId,
    track_id: initialTrackId,
    title: '',
    abstract: '',
    keywords: '',
    co_authors: [] as {
        first_name: string;
        last_name: string;
        email: string;
    }[],
});

const now = computed(() => new Date());

const selectedConference = computed(() => {
    return props.conferences.find((c) => c.id === Number(form.conference_id));
});

function isTrackOpen(track: Track): boolean {
    const nowDate = now.value;
    const openAt = phaseOpensAt(track.submission_open_at);
    const closeAt = phaseClosesAt(track.submission_close_at);

    if (openAt && nowDate < openAt) return false;
    if (closeAt && nowDate > closeAt) return false;
    return true;
}

const availableTracks = computed(() => {
    const tracks = selectedConference.value?.tracks || [];
    return tracks.filter((track) => isTrackOpen(track));
});

const allTracks = computed(() => {
    return selectedConference.value?.tracks || [];
});

function resetTrack() {
    if (!initialTrackId && !urlParams.get('track_id')) {
        form.track_id = '';
    }
}

const submissionClosedMessage = computed(() => {
    if (!selectedConference.value) return null;

    const hasOpenTrack = availableTracks.value.length > 0;
    if (hasOpenTrack) return null;

    const allTracksForConf = selectedConference.value.tracks || [];
    const hasAnyTrack = allTracksForConf.length > 0;

    if (!hasAnyTrack) return 'This conference has no tracks.';

    return 'All submission periods for this conference have closed or have not yet started.';
});

const keywordsArray = computed(() => {
    if (!form.keywords.trim()) return [];
    return form.keywords
        .split(',')
        .map((k) => k.trim())
        .filter((k) => k.length > 0);
});

function submit() {
    form.post('/submissions', {
        onSuccess: () => {
            form.reset();
        },
        onError: (errors) => {
            console.error('Submission errors:', errors);
        },
    });
}
</script>

<template>
    <Head title="Create Submission" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <HeadingSmall
                title="Create Submission"
                description="Submit your paper to a conference."
            />

            <form
                @submit.prevent="submit"
                class="flex max-w-3xl flex-col gap-6"
            >
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="conference">Conference</Label>
                        <select
                            id="conference"
                            v-model="form.conference_id"
                            @change="resetTrack"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background"
                            required
                        >
                            <option value="">Select a conference</option>
                            <option
                                v-for="conf in conferences"
                                :key="conf.id"
                                :value="conf.id"
                            >
                                {{ conf.title }}
                            </option>
                        </select>
                        <InputError :message="form.errors.conference_id" />
                    </div>

                    <div class="grid gap-2" v-if="allTracks.length > 0">
                        <Label for="track">Track</Label>
                        <select
                            id="track"
                            v-model="form.track_id"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background"
                            required
                        >
                            <option value="">Select a track</option>
                            <template
                                v-for="track in allTracks"
                                :key="track.id"
                            >
                                <option
                                    v-if="isTrackOpen(track)"
                                    :value="track.id"
                                >
                                    {{ track.name }}
                                    ({{
                                        formatDayMonth(
                                            track.submission_open_at,
                                        ) || 'Open'
                                    }}
                                    -
                                    {{
                                        formatDayMonth(
                                            track.submission_close_at,
                                        ) || 'Ongoing'
                                    }})
                                </option>
                                <option v-else :value="track.id" disabled>
                                    {{ track.name }} (Closed)
                                </option>
                            </template>
                        </select>
                        <div
                            v-if="submissionClosedMessage"
                            class="text-sm text-red-600"
                        >
                            {{ submissionClosedMessage }}
                        </div>
                        <InputError :message="form.errors.track_id" />
                    </div>

                    <div
                        v-if="selectedConference && allTracks.length === 0"
                        class="text-sm text-yellow-600"
                    >
                        This conference has no tracks available.
                    </div>

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

                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="self-start"
                    >
                        <span
                            v-if="form.processing"
                            class="mr-2 inline-block h-4 w-4 animate-spin"
                            >⏳</span
                        >
                        Submit
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
