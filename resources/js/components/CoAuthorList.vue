<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Plus, Trash2, UserPlus } from 'lucide-vue-next';

interface CoAuthorRow {
    id?: number;
    user_id?: number | null;
    first_name: string;
    last_name: string;
    email: string;
}

const coAuthors = defineModel<CoAuthorRow[]>({ default: () => [] });

function addRow() {
    coAuthors.value.push({
        first_name: '',
        last_name: '',
        email: '',
    });
}

function removeRow(index: number) {
    coAuthors.value.splice(index, 1);
}
</script>

<template>
    <div class="grid gap-4">
        <Label>Co-authors</Label>

        <div
            v-if="coAuthors.length === 0"
            class="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
        >
            No co-authors added yet.
        </div>

        <div
            v-for="(coAuthor, index) in coAuthors"
            :key="index"
            class="space-y-2 rounded-lg border p-4"
        >
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label :for="`coauthor-first-${index}`">First name</Label>
                    <Input
                        :id="`coauthor-first-${index}`"
                        v-model="coAuthor.first_name"
                        required
                        placeholder="First name"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`coauthor-last-${index}`">Last name</Label>
                    <Input
                        :id="`coauthor-last-${index}`"
                        v-model="coAuthor.last_name"
                        required
                        placeholder="Last name"
                    />
                </div>
            </div>
            <div class="grid gap-1.5">
                <Label :for="`coauthor-email-${index}`">Email</Label>
                <Input
                    :id="`coauthor-email-${index}`"
                    v-model="coAuthor.email"
                    type="email"
                    required
                    placeholder="coauthor@example.com"
                />
            </div>
            <div class="flex justify-end">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="text-destructive hover:text-destructive"
                    @click="removeRow(index)"
                >
                    <Trash2 class="h-4 w-4" />
                    Remove
                </Button>
            </div>
        </div>

        <Button
            type="button"
            variant="outline"
            class="justify-self-start"
            @click="addRow"
        >
            <Plus class="h-4 w-4" />
            <UserPlus class="h-4 w-4" />
            Add co-author
        </Button>
    </div>
</template>
