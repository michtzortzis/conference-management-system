<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import {
    formatDate,
    formatDayMonth,
    phaseClosesAt,
    phaseOpensAt,
    toDateInputValue,
} from '@/lib/date';
import { Conference, Track, User, type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '../../components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '../../components/ui/table';

interface Props {
    conference: Conference;
    chair: User | null;
    committee_members: User[];
}

const props = defineProps<Props>();

const page = usePage();
const auth = computed(() => page.props.auth);

function isAuthor(): boolean {
    const globalRoles = auth.value.roles?.global || [];
    return globalRoles.includes('author');
}

function isChair(): boolean {
    const conferenceRoles =
        auth.value.roles?.conferences[props.conference.id] || [];
    return conferenceRoles.includes('chair');
}

type Phase = 'submission' | 'review' | 'decision';

type PhaseWindowKey =
    | 'submission_open_at'
    | 'submission_close_at'
    | 'review_open_at'
    | 'review_close_at'
    | 'decision_open_at'
    | 'decision_close_at';

const phaseKeys: Record<Phase, [PhaseWindowKey, PhaseWindowKey]> = {
    submission: ['submission_open_at', 'submission_close_at'],
    review: ['review_open_at', 'review_close_at'],
    decision: ['decision_open_at', 'decision_close_at'],
};

function isPhaseOpen(track: Track, phase: Phase): boolean {
    const now = new Date();
    const [openKey, closeKey] = phaseKeys[phase];
    const openAt = phaseOpensAt(track[openKey]);
    const closeAt = phaseClosesAt(track[closeKey]);

    if (openAt && now < openAt) return false;
    if (closeAt && now > closeAt) return false;
    return true;
}

function isTrackOpen(track: Track): boolean {
    return isPhaseOpen(track, 'submission');
}

function formatPhaseRange(open?: string | null, close?: string | null): string {
    const openStr = formatDayMonth(open);
    const closeStr = formatDayMonth(close);
    if (!openStr && !closeStr) return 'N/A';
    if (openStr && closeStr) return `${openStr} – ${closeStr}`;
    return openStr || closeStr;
}

const hasZeroGapTrack = computed(() =>
    (props.conference.tracks || []).some(
        (t) =>
            t.submission_close_at &&
            t.review_open_at &&
            // The zero-length bidding window is a day-level rule: the CFP
            // closes on the same day review opens.
            toDateInputValue(t.review_open_at) ===
                toDateInputValue(t.submission_close_at),
    ),
);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Conferences',
        href: '/conferences',
    },
    {
        title: 'Show',
        href: '#',
    },
];
</script>

<template>
    <Head :title="conference.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="mb-2 flex items-center justify-between">
                <h1 class="text-2xl font-bold">{{ conference.title }}</h1>
                <Link :href="`/conferences/${conference.id}/edit`">
                    <Button class="cursor-pointer">Edit Conference</Button>
                </Link>
            </div>
            <div class="mb-2">
                <p>{{ conference.description }}</p>
                <p class="mt-2">
                    Start Date: {{ formatDate(conference.start_date) || 'N/A' }}
                </p>
                <p class="mt-2">
                    End Date: {{ formatDate(conference.end_date) || 'N/A' }}
                </p>
                <p class="mt-2">
                    Chair:
                    {{
                        chair ? `${chair.first_name} ${chair.last_name}` : 'N/A'
                    }}
                </p>
                <div class="mt-4">
                    <h2 class="mb-2 text-lg font-semibold">
                        Committee Members
                    </h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Email</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="member in committee_members"
                                :key="member.id"
                            >
                                <TableCell
                                    >{{ member.first_name }}
                                    {{ member.last_name }}</TableCell
                                >
                                <TableCell>{{ member.email }}</TableCell>
                            </TableRow>
                            <TableRow v-if="committee_members.length === 0">
                                <TableCell
                                    colspan="2"
                                    class="text-center text-gray-500"
                                    >No committee members available</TableCell
                                >
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </div>
            <div class="mb-2">
                <h2 class="mb-4 text-xl font-semibold">Fields</h2>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Description</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="field in conference.fields"
                            :key="field.id"
                        >
                            <TableCell>{{ field.name }}</TableCell>
                            <TableCell>{{
                                field.description || 'N/A'
                            }}</TableCell>
                        </TableRow>
                        <TableRow
                            v-if="
                                !conference.fields ||
                                conference.fields.length === 0
                            "
                        >
                            <TableCell
                                colspan="2"
                                class="text-center text-gray-500"
                                >No fields available</TableCell
                            >
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- Tracks Section -->
            <div class="mb-2">
                <h2 class="mb-4 text-xl font-semibold">Tracks</h2>
                <p
                    v-if="hasZeroGapTrack"
                    class="mb-2 text-sm text-muted-foreground"
                >
                    Bidding won't run — review starts immediately after the CFP
                    closes.
                </p>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead>Submission</TableHead>
                            <TableHead>Review</TableHead>
                            <TableHead>Decision</TableHead>
                            <TableHead v-if="isAuthor()">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="track in conference.tracks"
                            :key="track.id"
                        >
                            <TableCell>{{ track.name }}</TableCell>
                            <TableCell>{{
                                track.description || 'N/A'
                            }}</TableCell>
                            <TableCell>{{
                                formatPhaseRange(
                                    track.submission_open_at,
                                    track.submission_close_at,
                                )
                            }}</TableCell>
                            <TableCell>{{
                                formatPhaseRange(
                                    track.review_open_at,
                                    track.review_close_at,
                                )
                            }}</TableCell>
                            <TableCell>{{
                                formatPhaseRange(
                                    track.decision_open_at,
                                    track.decision_close_at,
                                )
                            }}</TableCell>
                            <TableCell v-if="isAuthor()">
                                <Link
                                    :href="`/submissions/create?conference_id=${conference.id}&track_id=${track.id}`"
                                    v-if="isTrackOpen(track)"
                                >
                                    <Button
                                        variant="secondary"
                                        class="cursor-pointer"
                                        >Submit Paper</Button
                                    >
                                </Link>
                                <span
                                    v-else
                                    class="text-sm text-muted-foreground"
                                    >Closed</span
                                >
                            </TableCell>
                        </TableRow>
                        <TableRow
                            v-if="
                                !conference.tracks ||
                                conference.tracks.length === 0
                            "
                        >
                            <TableCell
                                :colspan="isAuthor() ? 6 : 5"
                                class="text-center text-gray-500"
                                >No tracks available</TableCell
                            >
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- Submissions Section -->
            <div class="mb-2">
                <h2 class="mb-4 text-xl font-semibold">Submissions</h2>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Title</TableHead>
                            <TableHead>Track</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Submitted By</TableHead>
                            <TableHead v-if="isChair()">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="submission in conference.submissions"
                            :key="submission.id"
                        >
                            <TableCell>{{ submission.title }}</TableCell>
                            <TableCell>{{
                                submission.track?.name || 'N/A'
                            }}</TableCell>
                            <TableCell>{{ submission.status }}</TableCell>
                            <TableCell
                                >{{ submission.user?.first_name }}
                                {{ submission.user?.last_name }}</TableCell
                            >
                            <TableCell v-if="isChair()">
                                <Link :href="`/submissions/${submission.id}`">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="cursor-pointer"
                                        >View</Button
                                    >
                                </Link>
                            </TableCell>
                        </TableRow>
                        <TableRow
                            v-if="
                                !conference.submissions ||
                                conference.submissions.length === 0
                            "
                        >
                            <TableCell
                                :colspan="isChair() ? 5 : 4"
                                class="text-center text-gray-500"
                                >No submissions available</TableCell
                            >
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </div>
    </AppLayout>
</template>
