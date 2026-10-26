<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { X, Plus, Edit2 } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

interface Option {
    id: number | string;
    name: string;
}

interface PivotField {
    key: string;
    label: string;
    type: 'text' | 'number';
    placeholder?: string;
    required?: boolean;
}

interface SelectedItem {
    id: number | string;
    name: string;
    [key: string]: any; // For pivot fields
}

interface Props {
    options?: Option[];
    modelValue?: SelectedItem[];
    placeholder?: string;
    allowCreate?: boolean;
    name?: string;
    class?: string;
    pivotFields?: PivotField[];
    emptyMessage?: string;
    useDialogForAdding?: boolean;
    allowEditing?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    options: () => [],
    modelValue: () => [],
    placeholder: 'Click to add items...',
    allowCreate: true,
    pivotFields: () => [],
    emptyMessage: 'No items added yet',
    useDialogForAdding: true,
    allowEditing: true,
});

const emit = defineEmits<{
    'update:modelValue': [value: SelectedItem[]];
}>();

const showDialog = ref(false);
const showDropdown = ref(false);
const searchQuery = ref('');
const editingIndex = ref<number | null>(null);
const currentItem = ref<Partial<SelectedItem>>({});

// Filter options based on search query
const filteredOptions = computed(() => {
    if (!searchQuery.value) return props.options;

    const query = searchQuery.value.toLowerCase();
    return props.options.filter((option) =>
        option.name.toLowerCase().includes(query)
    );
});

// Check if search query can be added as new item
const canAddNew = computed(() => {
    if (!props.allowCreate || !searchQuery.value.trim()) return false;

    const query = searchQuery.value.trim().toLowerCase();
    const existsInOptions = props.options.some((opt) => opt.name.toLowerCase() === query);
    const existsInSelected = props.modelValue.some((item) => item.name.toLowerCase() === query);

    return !existsInOptions && !existsInSelected;
});

function openDialog(option?: Option, index?: number) {
    if (index !== undefined) {
        // Editing existing item
        editingIndex.value = index;
        currentItem.value = { ...props.modelValue[index] };
    } else if (option) {
        // Adding from dropdown
        editingIndex.value = null;
        currentItem.value = {
            id: option.id,
            name: option.name,
        };
    } else {
        // Adding new custom item
        editingIndex.value = null;
        currentItem.value = {
            id: searchQuery.value.trim(),
            name: searchQuery.value.trim(),
        };
    }

    showDialog.value = true;
    showDropdown.value = false;
}

function saveItem() {
    if (!currentItem.value.id || !currentItem.value.name) return;

    const newItem = { ...currentItem.value } as SelectedItem;

    if (editingIndex.value !== null) {
        // Update existing item
        const updated = [...props.modelValue];
        updated[editingIndex.value] = newItem;
        emit('update:modelValue', updated);
    } else {
        // Add new item
        emit('update:modelValue', [...props.modelValue, newItem]);
    }

    closeDialog();
}

function closeDialog() {
    showDialog.value = false;
    editingIndex.value = null;
    currentItem.value = {};
    searchQuery.value = '';
}

function addItemDirectly(option?: Option) {
    let newItem: SelectedItem;

    if (option) {
        newItem = {
            id: option.id,
            name: option.name,
        };
    } else {
        // Adding new custom item
        const name = searchQuery.value.trim();
        newItem = {
            id: name,
            name: name,
        };
    }

    // Add default values for pivot fields if any
    props.pivotFields.forEach(field => {
        if (field.type === 'number') {
            newItem[field.key] = 0;
        } else {
            newItem[field.key] = '';
        }
    });

    emit('update:modelValue', [...props.modelValue, newItem]);
    searchQuery.value = '';
    showDropdown.value = false;
}

function removeItem(index: number) {
    emit('update:modelValue', props.modelValue.filter((_, i) => i !== index));
}

function handleClickOutside(event: MouseEvent) {
    const target = event.target as HTMLElement;
    if (!target.closest('.multi-select-wrapper')) {
        showDropdown.value = false;
    }
}

watch(showDropdown, (isOpen) => {
    if (isOpen) {
        document.addEventListener('click', handleClickOutside);
    } else {
        document.removeEventListener('click', handleClickOutside);
    }
});
</script>

<template>
    <div :class="cn('multi-select-wrapper', props.class)">
        <!-- Hidden inputs for form submission -->
        <template v-for="(item, index) in modelValue" :key="`${name}-${index}`">
            <input type="hidden" :name="`${name}[${index}][id]`" :value="item.id" />
            <input
                v-for="field in pivotFields"
                :key="`${name}-${index}-${field.key}`"
                type="hidden"
                :name="`${name}[${index}][${field.key}]`"
                :value="item[field.key] ?? ''"
            />
        </template>

        <!-- Selected items display -->
        <div class="space-y-3">
            <div v-if="modelValue.length === 0" class="text-muted-foreground text-sm">
                {{ emptyMessage }}
            </div>

            <div
                v-for="(item, index) in modelValue"
                :key="`item-${index}`"
                class="bg-muted/50 flex items-start justify-between gap-2 rounded-lg border p-3"
            >
                <div class="flex-1 space-y-1">
                    <div class="font-medium">{{ item.name }}</div>
                    <div v-if="pivotFields.length > 0" class="text-muted-foreground text-xs space-y-0.5">
                        <div v-for="field in pivotFields" :key="field.key">
                            <span v-if="item[field.key]">
                                <span class="font-medium">{{ field.label }}:</span> {{ item[field.key] }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex gap-1">
                    <Button
                        v-if="allowEditing"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 w-8 p-0"
                        @click="openDialog(undefined, index)"
                    >
                        <Edit2 :size="14" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 w-8 p-0"
                        @click="removeItem(index)"
                    >
                        <X :size="14" />
                    </Button>
                </div>
            </div>
        </div>

        <!-- Add button with dropdown -->
        <div class="relative mt-3">
            <Button
                type="button"
                variant="outline"
                class="w-full"
                @click="showDropdown = !showDropdown"
            >
                <Plus :size="16" class="mr-2" />
                {{ placeholder }}
            </Button>

            <!-- Dropdown -->
            <div
                v-show="showDropdown"
                class="bg-popover text-popover-foreground absolute z-50 mt-2 w-full rounded-md border shadow-md"
            >
                <div class="p-2">
                    <Input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search or type new item..."
                        class="mb-2"
                        @keydown.enter.prevent="canAddNew ? (useDialogForAdding ? openDialog() : addItemDirectly()) : null"
                    />

                    <div class="max-h-60 space-y-1 overflow-auto">
                        <!-- Existing options -->
                        <button
                            v-for="option in filteredOptions"
                            :key="option.id"
                            type="button"
                            @click="useDialogForAdding ? openDialog(option) : addItemDirectly(option)"
                            :disabled="modelValue.some(item => item.id === option.id)"
                            class="hover:bg-accent hover:text-accent-foreground w-full rounded-sm px-2 py-1.5 text-left text-sm outline-none disabled:pointer-events-none disabled:opacity-50"
                        >
                            {{ option.name }}
                        </button>

                        <!-- Add new option -->
                        <button
                            v-if="canAddNew"
                            type="button"
                            @click="useDialogForAdding ? openDialog() : addItemDirectly()"
                            class="hover:bg-accent hover:text-accent-foreground text-primary w-full rounded-sm px-2 py-1.5 text-left text-sm font-medium outline-none"
                        >
                            Add "{{ searchQuery.trim() }}"
                        </button>

                        <!-- No results -->
                        <div
                            v-if="filteredOptions.length === 0 && !canAddNew && searchQuery"
                            class="text-muted-foreground px-2 py-1.5 text-sm"
                        >
                            No results found
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit/Add Dialog -->
        <Dialog v-model:open="showDialog">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{ editingIndex !== null ? 'Edit' : 'Add' }} {{ currentItem.name }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ pivotFields.length > 0 ? 'Fill in the additional details below.' : 'Confirm to add this item.' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 py-4">
                    <div v-for="field in pivotFields" :key="field.key" class="grid gap-2">
                        <Label :for="field.key">
                            {{ field.label }}
                            <span v-if="!field.required" class="text-muted-foreground font-normal">(optional)</span>
                        </Label>
                        <Input
                            :id="field.key"
                            v-model="currentItem[field.key]"
                            :type="field.type"
                            :placeholder="field.placeholder"
                            :required="field.required"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="closeDialog">
                        Cancel
                    </Button>
                    <Button type="button" @click="saveItem">
                        {{ editingIndex !== null ? 'Update' : 'Add' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
