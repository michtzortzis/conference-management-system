<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { MultiSelectWithFields } from '@/components/ui/multi-select-with-fields';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, toDateInputValue } from '@/lib/date';
import type { Field } from '@/types';
import { Conference, type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';

interface User {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Conferences',
        href: '/conferences',
    },
    {
        title: 'Edit',
        href: '#',
    },
];

interface Props {
    conference: Conference;
    chair: User | null;
    committee_members: User[];
    user_candidates: User[];
}

const props = defineProps<Props>();

const form = useForm({
    title: props.conference.title,
    description: props.conference.description,
    // Boundaries arrive as ISO timestamps; date inputs need yyyy-MM-dd, and the
    // payload the server validates should look like the one Create sends.
    start_date: toDateInputValue(props.conference.start_date),
    end_date: toDateInputValue(props.conference.end_date),
    double_blind_review: props.conference.double_blind_review || false,
    coi_auto_detect: props.conference.coi_auto_detect !== false,
    fields:
        props.conference.fields?.map((f) => ({
            id: f.id,
            name: f.name,
            description: f.description,
        })) ||
        ([] as Array<{
            id: string | number;
            name: string;
            description?: string;
        }>),
    tracks:
        props.conference.tracks?.map((t) => ({
            id: t.id,
            name: t.name,
            description: t.description || '',
            submission_open_at: toDateInputValue(t.submission_open_at),
            submission_close_at: toDateInputValue(t.submission_close_at),
            review_open_at: toDateInputValue(t.review_open_at),
            review_close_at: toDateInputValue(t.review_close_at),
            decision_open_at: toDateInputValue(t.decision_open_at),
            decision_close_at: toDateInputValue(t.decision_close_at),
        })) ||
        ([] as Array<{
            id?: number;
            name: string;
            description: string;
            submission_open_at: string;
            submission_close_at: string;
            review_open_at: string;
            review_close_at: string;
            decision_open_at: string;
            decision_close_at: string;
        }>),
    chair_user_id: props.chair ? props.chair.id.toString() : '',
    committee_members:
        props.committee_members.map((u) => ({
            id: u.id,
            name: `${u.first_name} ${u.last_name} (${u.email})`,
            description: '',
        })) || [],
});

// Client-side validation

const conferenceDateErrors = computed(() => {
    const errors: string[] = [];
    if (form.start_date && form.end_date) {
        const start = new Date(form.start_date);
        const end = new Date(form.end_date);
        if (start > end) {
            errors.push('Start date must be before or equal to end date.');
        }
    }
    return errors;
});

const trackDateErrors = computed(() => {
    const errors: {
        [key: number]: {
            open: string[];
            close: string[];
            reviewOpen: string[];
            reviewClose: string[];
            decisionOpen: string[];
            decisionClose: string[];
        };
    } = {};
    form.tracks.forEach((track, idx) => {
        errors[idx] = {
            open: [],
            close: [],
            reviewOpen: [],
            reviewClose: [],
            decisionOpen: [],
            decisionClose: [],
        };
        if (!track.submission_open_at) {
            errors[idx].open.push('Submission open date is required.');
        }
        if (!track.submission_close_at) {
            errors[idx].close.push('Submission close date is required.');
        }
        if (track.submission_open_at && track.submission_close_at) {
            const open = new Date(track.submission_open_at);
            const close = new Date(track.submission_close_at);
            if (open > close) {
                errors[idx].close.push(
                    'Submission open date must be before or equal to close date.',
                );
            }
        }
        if (track.review_open_at && track.submission_close_at) {
            const reviewOpen = new Date(track.review_open_at);
            const close = new Date(track.submission_close_at);
            if (reviewOpen < close) {
                errors[idx].reviewOpen.push(
                    'Review open date must be after or equal to submission close date.',
                );
            }
        }
        if (track.review_open_at && track.review_close_at) {
            const reviewOpen = new Date(track.review_open_at);
            const reviewClose = new Date(track.review_close_at);
            if (reviewOpen > reviewClose) {
                errors[idx].reviewClose.push(
                    'Review open date must be before or equal to review close date.',
                );
            }
        }
        if (track.decision_open_at && track.review_close_at) {
            const decisionOpen = new Date(track.decision_open_at);
            const reviewClose = new Date(track.review_close_at);
            if (decisionOpen < reviewClose) {
                errors[idx].decisionOpen.push(
                    'Decision open date must be after or equal to review close date.',
                );
            }
        }
        if (track.decision_open_at && track.decision_close_at) {
            const decisionOpen = new Date(track.decision_open_at);
            const decisionClose = new Date(track.decision_close_at);
            if (decisionOpen > decisionClose) {
                errors[idx].decisionClose.push(
                    'Decision open date must be before or equal to decision close date.',
                );
            }
        }
    });
    return errors;
});

// Tracks where the review period starts the moment the CFP closes: the bidding
// window is zero-length, so bidding silently won't run for that track.
const zeroGapTracks = computed(() => {
    const indexes = new Set<number>();
    form.tracks.forEach((track, idx) => {
        if (
            track.submission_close_at &&
            track.review_open_at &&
            new Date(track.review_open_at).getTime() ===
                new Date(track.submission_close_at).getTime()
        ) {
            indexes.add(idx);
        }
    });
    return indexes;
});

const currentTrackErrors = computed(() => {
    const errors = {
        open: [] as string[],
        close: [] as string[],
        reviewOpen: [] as string[],
        reviewClose: [] as string[],
        decisionOpen: [] as string[],
        decisionClose: [] as string[],
    };
    if (!trackForm.submission_open_at) {
        errors.open.push('Submission open date is required.');
    }
    if (!trackForm.submission_close_at) {
        errors.close.push('Submission close date is required.');
    }
    if (trackForm.submission_open_at && trackForm.submission_close_at) {
        const open = new Date(trackForm.submission_open_at);
        const close = new Date(trackForm.submission_close_at);
        if (open > close) {
            errors.close.push(
                'Submission open date must be before or equal to close date.',
            );
        }
    }
    if (trackForm.review_open_at && trackForm.submission_close_at) {
        const reviewOpen = new Date(trackForm.review_open_at);
        const close = new Date(trackForm.submission_close_at);
        if (reviewOpen < close) {
            errors.reviewOpen.push(
                'Review open date must be after or equal to submission close date.',
            );
        }
    }
    if (trackForm.review_open_at && trackForm.review_close_at) {
        const reviewOpen = new Date(trackForm.review_open_at);
        const reviewClose = new Date(trackForm.review_close_at);
        if (reviewOpen > reviewClose) {
            errors.reviewClose.push(
                'Review open date must be before or equal to review close date.',
            );
        }
    }
    if (trackForm.decision_open_at && trackForm.review_close_at) {
        const decisionOpen = new Date(trackForm.decision_open_at);
        const reviewClose = new Date(trackForm.review_close_at);
        if (decisionOpen < reviewClose) {
            errors.decisionOpen.push(
                'Decision open date must be after or equal to review close date.',
            );
        }
    }
    if (trackForm.decision_open_at && trackForm.decision_close_at) {
        const decisionOpen = new Date(trackForm.decision_open_at);
        const decisionClose = new Date(trackForm.decision_close_at);
        if (decisionOpen > decisionClose) {
            errors.decisionClose.push(
                'Decision open date must be before or equal to decision close date.',
            );
        }
    }
    return errors;
});

const showTrackModal = ref(false);
const isEditingTrack = ref(false);
const editingTrackIndex = ref(-1);
const trackForm = reactive({
    id: undefined as number | undefined,
    name: '',
    description: '',
    submission_open_at: '',
    submission_close_at: '',
    review_open_at: '',
    review_close_at: '',
    decision_open_at: '',
    decision_close_at: '',
});

type TrackErrorKey =
    | 'open'
    | 'close'
    | 'reviewOpen'
    | 'reviewClose'
    | 'decisionOpen'
    | 'decisionClose';

function trackErrors(key: TrackErrorKey): string[] {
    if (!isEditingTrack.value) return currentTrackErrors.value[key];
    return trackDateErrors.value[editingTrackIndex.value]?.[key] ?? [];
}

const trackPhases = [
    {
        label: 'Submission',
        open: 'submission_open_at',
        close: 'submission_close_at',
        openErr: 'open',
        closeErr: 'close',
    },
    {
        label: 'Review',
        open: 'review_open_at',
        close: 'review_close_at',
        openErr: 'reviewOpen',
        closeErr: 'reviewClose',
    },
    {
        label: 'Decision',
        open: 'decision_open_at',
        close: 'decision_close_at',
        openErr: 'decisionOpen',
        closeErr: 'decisionClose',
    },
] as const;

function openAddTrackModal() {
    isEditingTrack.value = false;
    editingTrackIndex.value = -1;
    trackForm.id = undefined;
    trackForm.name = '';
    trackForm.description = '';
    trackForm.submission_open_at = '';
    trackForm.submission_close_at = '';
    trackForm.review_open_at = '';
    trackForm.review_close_at = '';
    trackForm.decision_open_at = '';
    trackForm.decision_close_at = '';
    showTrackModal.value = true;
}

function openEditTrackModal(idx: number) {
    isEditingTrack.value = true;
    editingTrackIndex.value = idx;
    const t = form.tracks[idx];
    trackForm.id = t.id;
    trackForm.name = t.name;
    trackForm.description = t.description;
    trackForm.submission_open_at = toDateInputValue(t.submission_open_at);
    trackForm.submission_close_at = toDateInputValue(t.submission_close_at);
    trackForm.review_open_at = toDateInputValue(t.review_open_at);
    trackForm.review_close_at = toDateInputValue(t.review_close_at);
    trackForm.decision_open_at = toDateInputValue(t.decision_open_at);
    trackForm.decision_close_at = toDateInputValue(t.decision_close_at);
    showTrackModal.value = true;
}

function saveTrack() {
    const errors = isEditingTrack.value
        ? trackDateErrors.value[editingTrackIndex.value]
        : currentTrackErrors.value;
    if (
        errors.open.length > 0 ||
        errors.close.length > 0 ||
        errors.reviewOpen.length > 0 ||
        errors.reviewClose.length > 0 ||
        errors.decisionOpen.length > 0 ||
        errors.decisionClose.length > 0
    ) {
        toast.error(
            'Please fix the validation errors before saving the track.',
        );
        return;
    }
    if (isEditingTrack.value && editingTrackIndex.value > -1) {
        form.tracks[editingTrackIndex.value] = { ...trackForm };
    } else {
        form.tracks.push({ ...trackForm });
    }
    showTrackModal.value = false;
}

function removeTrack(index: number) {
    form.tracks.splice(index, 1);
}

const allFields = ref<Field[]>([]);
const userOptions = computed(() =>
    props.user_candidates.map((u) => ({
        id: u.id,
        name: `${u.first_name} ${u.last_name} (${u.email})`,
        description: '',
    })),
);

onMounted(async () => {
    // Fetch all fields from backend (assume /fields returns array of {id, name, description})
    try {
        const res = await fetch('/fields');
        if (res.ok) {
            allFields.value = await res.json();
        }
    } catch {}
});

function submit() {
    // Transform committee_members to array of ids
    form.committee_members = form.committee_members.map((c: any) => c.id);
    form.put(`/conferences/${props.conference.id}`, {
        onSuccess: () => {
            toast.success('Conference updated successfully.');
            router.visit('/conferences');
        },
        onError: () => {
            // Errors will be shown via InputError components
        },
    });
}
</script>

<template>
    <Head title="Edit Conference" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <HeadingSmall
                title="Edit Conference"
                description="Update the details of the conference."
            />

            <form @submit.prevent="submit" class="flex flex-col gap-6">
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <Label for="title">Title</Label>
                        <Input id="title" v-model="form.title" required />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="description">Description</Label>
                        <Input id="description" v-model="form.description" />
                        <InputError :message="form.errors.description" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="start_date">Start Date</Label>
                        <Input
                            id="start_date"
                            v-model="form.start_date"
                            type="date"
                        />
                        <InputError :message="form.errors.start_date" />
                        <div
                            v-if="conferenceDateErrors.length > 0"
                            class="text-sm text-red-600"
                        >
                            <p
                                v-for="error in conferenceDateErrors"
                                :key="error"
                            >
                                {{ error }}
                            </p>
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="end_date">End Date</Label>
                        <Input
                            id="end_date"
                            v-model="form.end_date"
                            type="date"
                        />
                        <InputError :message="form.errors.end_date" />
                        <div
                            v-if="conferenceDateErrors.length > 0"
                            class="text-sm text-red-600"
                        >
                            <p
                                v-for="error in conferenceDateErrors"
                                :key="error"
                            >
                                {{ error }}
                            </p>
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="double_blind_review"
                                v-model="form.double_blind_review"
                            />
                            <Label for="double_blind_review"
                                >Double-blind review</Label
                            >
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Hide author identities from reviewers and reviewer
                            identities from authors.
                        </p>
                        <InputError
                            :message="form.errors.double_blind_review"
                        />
                    </div>
                    <div class="grid gap-2">
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="coi_auto_detect"
                                v-model="form.coi_auto_detect"
                            />
                            <Label for="coi_auto_detect"
                                >Auto-detect conflicts of interest</Label
                            >
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Automatically flag reviewers whose email or
                            affiliation matches an author. Declared conflicts
                            are always enforced.
                        </p>
                        <InputError :message="form.errors.coi_auto_detect" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Fields</Label>
                        <!-- TODO fix show dialog -->
                        <MultiSelectWithFields
                            v-model="form.fields"
                            :options="allFields"
                            name="fields"
                            placeholder="Add or create fields"
                            :allow-create="true"
                            :pivot-fields="[]"
                            empty-message="No fields added yet. Click the button below to add one."
                        />
                        <InputError :message="form.errors.fields" />
                    </div>
                    <!-- Tracks Section as Table -->
                    <div class="grid gap-2">
                        <Label
                            >Tracks <span class="text-red-500">*</span></Label
                        >
                        <div class="overflow-x-auto">
                            <table class="min-w-full border text-sm">
                                <thead>
                                    <tr class="bg-gray-100 dark:bg-neutral-800">
                                        <th class="border p-2">Name</th>
                                        <th class="border p-2">Description</th>
                                        <th class="border p-2">Submission</th>
                                        <th class="border p-2">Review</th>
                                        <th class="border p-2">Decision</th>
                                        <th class="border p-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(track, idx) in form.tracks"
                                        :key="idx"
                                    >
                                        <td class="border p-2">
                                            {{ track.name }}
                                        </td>
                                        <td class="border p-2">
                                            {{ track.description }}
                                        </td>
                                        <td class="border p-2">
                                            {{
                                                formatDate(
                                                    track.submission_open_at,
                                                ) &&
                                                formatDate(
                                                    track.submission_close_at,
                                                )
                                                    ? `${formatDate(track.submission_open_at)} – ${formatDate(track.submission_close_at)}`
                                                    : formatDate(
                                                          track.submission_open_at,
                                                      ) ||
                                                      formatDate(
                                                          track.submission_close_at,
                                                      ) ||
                                                      'N/A'
                                            }}
                                        </td>
                                        <td class="border p-2">
                                            {{
                                                formatDate(
                                                    track.review_open_at,
                                                ) &&
                                                formatDate(
                                                    track.review_close_at,
                                                )
                                                    ? `${formatDate(track.review_open_at)} – ${formatDate(track.review_close_at)}`
                                                    : formatDate(
                                                          track.review_open_at,
                                                      ) ||
                                                      formatDate(
                                                          track.review_close_at,
                                                      ) ||
                                                      'N/A'
                                            }}
                                        </td>
                                        <td class="border p-2">
                                            {{
                                                formatDate(
                                                    track.decision_open_at,
                                                ) &&
                                                formatDate(
                                                    track.decision_close_at,
                                                )
                                                    ? `${formatDate(track.decision_open_at)} – ${formatDate(track.decision_close_at)}`
                                                    : formatDate(
                                                          track.decision_open_at,
                                                      ) ||
                                                      formatDate(
                                                          track.decision_close_at,
                                                      ) ||
                                                      'N/A'
                                            }}
                                        </td>
                                        <td class="border p-2 text-nowrap">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                class="mr-2"
                                                @click="openEditTrackModal(idx)"
                                                >Edit</Button
                                            >
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="destructive"
                                                @click="removeTrack(idx)"
                                                >Remove</Button
                                            >
                                        </td>
                                    </tr>
                                    <tr v-if="form.tracks.length === 0">
                                        <td
                                            colspan="6"
                                            class="p-2 text-center text-muted-foreground"
                                        >
                                            No tracks added yet.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-2">
                            <Button
                                type="button"
                                variant="secondary"
                                @click="openAddTrackModal"
                            >
                                Add Track
                            </Button>
                        </div>
                        <div
                            v-if="zeroGapTracks.size > 0"
                            class="mt-2 text-sm text-muted-foreground"
                        >
                            <p>
                                Bidding won't run — review starts immediately
                                after the CFP closes.
                            </p>
                        </div>
                        <InputError :message="form.errors.tracks" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="chair_user_id">Chair</Label>
                        <select
                            v-model="form.chair_user_id"
                            id="chair_user_id"
                            class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <option value="">Select a chair</option>
                            <option
                                v-for="user in user_candidates"
                                :key="user.id"
                                :value="user.id"
                            >
                                {{ user.first_name }} {{ user.last_name }} ({{
                                    user.email
                                }})
                            </option>
                        </select>
                        <InputError :message="form.errors.chair_user_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Committee Members</Label>
                        <MultiSelectWithFields
                            v-model="form.committee_members"
                            :options="userOptions"
                            name="committee_members"
                            placeholder="Select committee members"
                            :allow-create="false"
                            :pivot-fields="[]"
                            empty-message="No committee members selected yet."
                            :use-dialog-for-adding="false"
                            :allow-editing="false"
                        />
                        <InputError :message="form.errors.committee_members" />
                    </div>

                    <!-- Track Modal -->
                    <Dialog v-model:open="showTrackModal">
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>{{
                                    isEditingTrack ? 'Edit Track' : 'Add Track'
                                }}</DialogTitle>
                                <DialogDescription>
                                    Give the track a name and set the windows
                                    during which authors submit and reviewers
                                    work.
                                </DialogDescription>
                            </DialogHeader>
                            <form
                                @submit.prevent="saveTrack"
                                class="mt-2 flex flex-col gap-4"
                            >
                                <div>
                                    <Label for="track-modal-name"
                                        >Track Name</Label
                                    >
                                    <Input
                                        id="track-modal-name"
                                        v-model="trackForm.name"
                                        required
                                        placeholder="Track name"
                                    />
                                    <InputError
                                        :message="form.errors['tracks.0.name']"
                                        v-if="!isEditingTrack"
                                    />
                                </div>
                                <div>
                                    <Label for="track-modal-description"
                                        >Description</Label
                                    >
                                    <Input
                                        id="track-modal-description"
                                        v-model="trackForm.description"
                                        placeholder="Track description"
                                    />
                                </div>
                                <div
                                    v-for="phase in trackPhases"
                                    :key="phase.label"
                                    class="grid gap-2"
                                >
                                    <Label>{{ phase.label }}</Label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div class="grid gap-2">
                                            <Label>Open</Label>
                                            <Input
                                                type="date"
                                                v-model="trackForm[phase.open]"
                                                :required="
                                                    phase.label === 'Submission'
                                                "
                                            />
                                            <div
                                                v-if="
                                                    trackErrors(phase.openErr)
                                                        .length > 0
                                                "
                                                class="text-sm text-red-600"
                                            >
                                                <p
                                                    v-for="error in trackErrors(
                                                        phase.openErr,
                                                    )"
                                                    :key="error"
                                                >
                                                    {{ error }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="grid gap-2">
                                            <Label>Close</Label>
                                            <Input
                                                type="date"
                                                v-model="trackForm[phase.close]"
                                                :required="
                                                    phase.label === 'Submission'
                                                "
                                            />
                                            <div
                                                v-if="
                                                    trackErrors(phase.closeErr)
                                                        .length > 0
                                                "
                                                class="text-sm text-red-600"
                                            >
                                                <p
                                                    v-for="error in trackErrors(
                                                        phase.closeErr,
                                                    )"
                                                    :key="error"
                                                >
                                                    {{ error }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <DialogFooter>
                                    <DialogClose as-child>
                                        <Button type="button" variant="outline"
                                            >Cancel</Button
                                        >
                                    </DialogClose>
                                    <Button type="submit">{{
                                        isEditingTrack
                                            ? 'Save Changes'
                                            : 'Add Track'
                                    }}</Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>

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
