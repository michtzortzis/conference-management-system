<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { X } from 'lucide-vue-next';
import { cn } from '@/lib/utils';

interface Option {
    id: number | string;
    name: string;
}

interface Props {
    options?: Option[];
    modelValue?: (number | string)[];
    placeholder?: string;
    allowCreate?: boolean;
    name?: string;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    options: () => [],
    modelValue: () => [],
    placeholder: 'Type to search or add...',
    allowCreate: true,
});

const emit = defineEmits<{
    'update:modelValue': [value: (number | string)[]];
}>();

const searchQuery = ref('');
const showDropdown = ref(false);
const inputRef = ref<HTMLInputElement>();

// Get selected items details
const selectedItems = computed(() => {
    return props.modelValue.map((value) => {
        // Check if it's an existing option
        const option = props.options.find((opt) => opt.id === value);
        if (option) {
            return option;
        }
        // It's a custom string value
        return { id: value, name: String(value) };
    });
});

// Filter options based on search query
const filteredOptions = computed(() => {
    if (!searchQuery.value) return props.options;
    
    const query = searchQuery.value.toLowerCase();
    return props.options.filter((option) => 
        option.name.toLowerCase().includes(query)
    );
});

// Check if current search query can be added as new item
const canAddNew = computed(() => {
    if (!props.allowCreate || !searchQuery.value.trim()) return false;
    
    // Check if it already exists in options or selected items
    const query = searchQuery.value.trim().toLowerCase();
    const existsInOptions = props.options.some((opt) => opt.name.toLowerCase() === query);
    const existsInSelected = selectedItems.value.some((item) => item.name.toLowerCase() === query);
    
    return !existsInOptions && !existsInSelected;
});

function selectOption(option: Option) {
    if (!props.modelValue.includes(option.id)) {
        emit('update:modelValue', [...props.modelValue, option.id]);
    }
    searchQuery.value = '';
    inputRef.value?.focus();
}

function addNewItem() {
    if (canAddNew.value) {
        const trimmedValue = searchQuery.value.trim();
        emit('update:modelValue', [...props.modelValue, trimmedValue]);
        searchQuery.value = '';
    }
}

function removeItem(value: number | string) {
    emit('update:modelValue', props.modelValue.filter((v) => v !== value));
}

function handleInputKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter' && canAddNew.value) {
        event.preventDefault();
        addNewItem();
    } else if (event.key === 'Backspace' && !searchQuery.value && selectedItems.value.length > 0) {
        // Remove last item when backspace is pressed on empty input
        removeItem(selectedItems.value[selectedItems.value.length - 1].id);
    } else if (event.key === 'Escape') {
        showDropdown.value = false;
    }
}

function handleClickOutside(event: MouseEvent) {
    const target = event.target as HTMLElement;
    if (!target.closest('.multi-select-wrapper')) {
        showDropdown.value = false;
    }
}

// Close dropdown when clicking outside
watch(showDropdown, (isOpen) => {
    if (isOpen) {
        document.addEventListener('click', handleClickOutside);
    } else {
        document.removeEventListener('click', handleClickOutside);
    }
});
</script>

<template>
    <div :class="cn('multi-select-wrapper relative', props.class)">
        <!-- Hidden inputs for form submission -->
        <input
            v-for="(value, index) in modelValue"
            :key="`${name}-${index}`"
            type="hidden"
            :name="`${name}[]`"
            :value="value"
        />
        
        <!-- Selected items and input -->
        <div
            class="border-input bg-transparent focus-within:ring-1 focus-within:ring-ring flex min-h-9 w-full flex-wrap items-center gap-1 rounded-md border px-2 py-1.5"
            @click="() => { showDropdown = true; inputRef?.focus(); }"
        >
            <Badge
                v-for="item in selectedItems"
                :key="item.id"
                variant="secondary"
                class="gap-1"
            >
                <span>{{ item.name }}</span>
                <button
                    type="button"
                    @click.stop="removeItem(item.id)"
                    class="hover:bg-secondary-foreground/20 rounded-sm"
                >
                    <X :size="14" />
                </button>
            </Badge>
            
            <input
                ref="inputRef"
                v-model="searchQuery"
                type="text"
                :placeholder="selectedItems.length === 0 ? placeholder : ''"
                class="placeholder:text-muted-foreground flex-1 bg-transparent text-sm outline-none disabled:cursor-not-allowed disabled:opacity-50"
                @focus="showDropdown = true"
                @keydown="handleInputKeydown"
            />
        </div>

        <!-- Dropdown -->
        <div
            v-show="showDropdown && (filteredOptions.length > 0 || canAddNew)"
            class="bg-popover text-popover-foreground absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md border shadow-md"
        >
            <div class="p-1">
                <!-- Existing options -->
                <button
                    v-for="option in filteredOptions"
                    :key="option.id"
                    type="button"
                    @click="selectOption(option)"
                    :disabled="modelValue.includes(option.id)"
                    class="hover:bg-accent hover:text-accent-foreground w-full rounded-sm px-2 py-1.5 text-left text-sm outline-none disabled:pointer-events-none disabled:opacity-50"
                >
                    {{ option.name }}
                </button>

                <!-- Add new option -->
                <button
                    v-if="canAddNew"
                    type="button"
                    @click="addNewItem"
                    class="hover:bg-accent hover:text-accent-foreground text-primary w-full rounded-sm px-2 py-1.5 text-left text-sm outline-none"
                >
                    <span class="font-medium">Add "{{ searchQuery.trim() }}"</span>
                </button>

                <!-- No results message -->
                <div
                    v-if="filteredOptions.length === 0 && !canAddNew && searchQuery"
                    class="text-muted-foreground px-2 py-1.5 text-sm"
                >
                    No results found
                </div>
            </div>
        </div>
    </div>
</template>
