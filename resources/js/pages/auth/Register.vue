<script setup lang="ts">
import RegisteredUserController from '@/actions/App/Http/Controllers/Auth/RegisteredUserController';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelectWithFields } from '@/components/ui/multi-select-with-fields';
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
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
    affiliations: Affiliation[];
    expertise_areas: ExpertiseArea[];
}

defineProps<Props>();

const selectedAffiliations = ref<AffiliationItem[]>([]);
const selectedExpertiseAreas = ref<ExpertiseItem[]>([]);

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
    <AuthBase
        title="Create an account"
        description="Enter your details below to create your account"
    >
        <Head title="Register" />

        <Form
            v-bind="RegisteredUserController.store.form()"
            :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
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
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="affiliations"> Affiliations (optional) </Label>
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
                        Your institutional affiliations with optional department
                        and position details.
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

                <div class="grid gap-2">
                    <Label for="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        :tabindex="7"
                        autocomplete="new-password"
                        name="password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        :tabindex="8"
                        autocomplete="new-password"
                        name="password_confirmation"
                        placeholder="Confirm password"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full"
                    tabindex="9"
                    :disabled="processing"
                    data-test="register-user-button"
                >
                    <LoaderCircle
                        v-if="processing"
                        class="h-4 w-4 animate-spin"
                    />
                    Create account
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Already have an account?
                <TextLink
                    :href="login()"
                    class="underline underline-offset-4"
                    :tabindex="10"
                    >Log in</TextLink
                >
            </div>
        </Form>
    </AuthBase>
</template>
