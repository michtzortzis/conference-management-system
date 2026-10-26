<script setup lang="ts">
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelectWithFields } from '@/components/ui/multi-select-with-fields';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import { type BreadcrumbItem } from '@/types';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Affiliation {
    id: number;
    name: string;
}

interface ExpertiseArea {
    id: number;
    name: string;
}

interface AffiliationItem {
    id: number | string;
    name: string;
    department?: string;
    position?: string;
}

interface ExpertiseItem {
    id: number | string;
    name: string;
    years_experience?: number;
}

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    affiliations: Affiliation[];
    expertise_areas: ExpertiseArea[];
    user_affiliations: AffiliationItem[];
    user_expertise_areas: ExpertiseItem[];
}

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: edit().url,
    },
];

const page = usePage();
const user = page.props.auth.user;

const selectedAffiliations = ref<AffiliationItem[]>([
    ...(props.user_affiliations ?? []),
]);
const selectedExpertiseAreas = ref<ExpertiseItem[]>([
    ...(props.user_expertise_areas ?? []),
]);

const affiliationPivotFields = [
    {
        key: 'department',
        label: 'Department',
        type: 'text' as const,
        placeholder: 'e.g., Computer Science',
        required: false,
    },
    {
        key: 'position',
        label: 'Position',
        type: 'text' as const,
        placeholder: 'e.g., Professor, Student',
        required: false,
    },
];

const expertisePivotFields = [
    {
        key: 'years_experience',
        label: 'Years of Experience',
        type: 'number' as const,
        placeholder: 'e.g., 5',
        required: false,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Profile settings" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="Profile information"
                    description="Update your name and email address"
                />

                <Form
                    v-bind="ProfileController.update.form()"
                    class="flex flex-col gap-6"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div class="grid gap-6">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="grid gap-2">
                                <Label for="first_name">First name</Label>
                                <Input
                                    id="first_name"
                                    type="text"
                                    required
                                    autofocus
                                    :tabindex="1"
                                    autocomplete="given-name"
                                    name="first_name"
                                    :default-value="user.first_name"
                                    placeholder="John"
                                />
                                <InputError :message="errors.first_name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="last_name">Last name</Label>
                                <Input
                                    id="last_name"
                                    type="text"
                                    required
                                    :tabindex="2"
                                    autocomplete="family-name"
                                    name="last_name"
                                    :default-value="user.last_name"
                                    placeholder="Doe"
                                />
                                <InputError :message="errors.last_name" />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="phone">Phone number (optional)</Label>
                            <Input
                                id="phone"
                                type="tel"
                                :tabindex="3"
                                autocomplete="tel"
                                name="phone"
                                :default-value="user.phone"
                                placeholder="+1234567890"
                            />
                            <InputError :message="errors.phone" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                :tabindex="4"
                                autocomplete="email"
                                name="email"
                                :default-value="user.email"
                                placeholder="email@example.com"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="affiliations">
                                Affiliations (optional)
                            </Label>
                            <MultiSelectWithFields
                                v-model="selectedAffiliations"
                                :options="affiliations"
                                :pivot-fields="affiliationPivotFields"
                                name="affiliations"
                                placeholder="Add affiliations"
                                :allow-create="true"
                                empty-message="No affiliations added yet. Click the button below to add one."
                            />
                            <p class="text-xs text-muted-foreground">
                                Your institutional affiliations with optional
                                department and position details.
                            </p>
                            <InputError :message="errors.affiliations" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="expertise_areas">
                                Expertise Areas (optional)
                            </Label>
                            <MultiSelectWithFields
                                v-model="selectedExpertiseAreas"
                                :options="expertise_areas"
                                :pivot-fields="expertisePivotFields"
                                name="expertise_areas"
                                placeholder="Add expertise areas"
                                :allow-create="true"
                                empty-message="No expertise areas added yet. Click the button below to add one."
                            />
                            <p class="text-xs text-muted-foreground">
                                Your areas of expertise with optional years of
                                experience.
                            </p>
                            <InputError :message="errors.expertise_areas" />
                        </div>

                        <Button
                            type="submit"
                            class="mt-2 w-full"
                            tabindex="9"
                            :disabled="processing"
                            data-test="update-profile-button"
                        >
                            <span
                                v-if="processing"
                                class="mr-2 inline-block h-4 w-4 animate-spin"
                                >⏳</span
                            >
                            Save
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
                                v-show="recentlySuccessful"
                                class="text-sm text-neutral-600"
                            >
                                Saved.
                            </p>
                        </Transition>
                    </div>

                    <div
                        v-if="mustVerifyEmail && !user.email_verified_at"
                        class="mt-2"
                    >
                        <p class="-mt-4 text-sm text-muted-foreground">
                            Your email address is unverified.
                            <Link
                                :href="send()"
                                as="button"
                                class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            >
                                Click here to resend the verification email.
                            </Link>
                        </p>

                        <div
                            v-if="status === 'verification-link-sent'"
                            class="mt-2 text-sm font-medium text-green-600"
                        >
                            A new verification link has been sent to your email
                            address.
                        </div>
                    </div>
                </Form>
            </div>

            <DeleteUser />
        </SettingsLayout>
    </AppLayout>
</template>
