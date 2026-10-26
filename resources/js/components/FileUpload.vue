<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatFileSize } from '@/lib/utils';
import type { SubmissionFile } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { FileText, FileUp, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

interface Props {
    submissionId: number;
    existingFiles?: SubmissionFile[];
    maxSizeMB?: number;
}

const props = withDefaults(defineProps<Props>(), {
    existingFiles: () => [],
    maxSizeMB: 20,
});

const form = useForm({ files: [] as unknown as File[] });

const page = usePage();
const flashHandled = ref(false);

const hiddenInput = ref<HTMLInputElement | null>(null);

const isDragging = ref(false);
const localFiles = ref<File[]>([]);

const fileError = computed(() => form.errors.files || '');
const maxFilesReached = computed(
    () => props.existingFiles.length + localFiles.value.length >= 5,
);

watch(
    () => (page.props.flash as Record<string, string>)?.success,
    (message) => {
        if (message && !flashHandled.value) {
            flashHandled.value = true;
            toast.success(message);
        }
    },
    { flush: 'post' },
);

function handleFileSelect(event: Event) {
    const input = event.target as HTMLInputElement;
    if (input.files) {
        appendFiles(Array.from(input.files));
    }
    input.value = '';
}

function handleDrop(event: DragEvent) {
    isDragging.value = false;
    event.preventDefault();
    if (event.dataTransfer?.files) {
        appendFiles(Array.from(event.dataTransfer.files));
    }
}

function appendFiles(newFiles: File[]) {
    for (const file of newFiles) {
        const sizeLimit = props.maxSizeMB * 1024 * 1024;
        if (file.size > sizeLimit) {
            form.errors.files = `File "${file.name}" exceeds the ${props.maxSizeMB}MB limit.`;
            continue;
        }
        localFiles.value.push(file);
    }
}

function removeLocalFile(index: number) {
    localFiles.value.splice(index, 1);
    form.errors.files = '';
}

function submit() {
    if (localFiles.value.length === 0) return;

    form.files = localFiles.value as unknown as File[];

    form.post(`/submissions/${props.submissionId}/files`, {
        forceFormData: true,
        onSuccess: () => {
            localFiles.value = [];
            form.reset();
        },
        onFinish: () => {
            flashHandled.value = false;
        },
    });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                <FileUp class="h-5 w-5" />
                Upload Files
            </CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
            <div
                class="relative flex min-h-30 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-6 transition-colors"
                :class="
                    isDragging
                        ? 'border-primary bg-muted/50'
                        : 'border-muted-foreground/25 hover:border-muted-foreground/50'
                "
                @dragenter.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @dragover.prevent
                @drop.prevent="handleDrop"
                @click="hiddenInput?.click()"
            >
                <FileUp class="mb-2 h-8 w-8 text-muted-foreground" />
                <p class="text-sm text-muted-foreground">
                    Drag &amp; drop files here, or click to browse
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    PDF, DOC, DOCX, TXT — max {{ maxSizeMB }}MB each
                </p>
                <input
                    ref="hiddenInput"
                    type="file"
                    multiple
                    accept=".pdf,.doc,.docx,.txt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain"
                    class="hidden"
                    @change="handleFileSelect"
                />
            </div>

            <InputError :message="fileError" />

            <div v-if="localFiles.length > 0" class="space-y-2">
                <p class="text-sm font-medium">
                    Selected files ({{ localFiles.length }})
                </p>
                <div
                    v-for="(file, index) in localFiles"
                    :key="index"
                    class="flex items-center justify-between rounded-lg border px-3 py-2"
                >
                    <div class="flex items-center gap-2">
                        <FileText class="h-4 w-4 text-muted-foreground" />
                        <span class="text-sm">{{ file.name }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            formatFileSize(file.size)
                        }}</span>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="h-6 w-6"
                        @click="removeLocalFile(index)"
                    >
                        <X class="h-3 w-3" />
                    </Button>
                </div>
            </div>

            <div v-if="localFiles.length > 0" class="flex justify-end">
                <Button
                    :disabled="form.processing || maxFilesReached"
                    @click="submit"
                >
                    {{
                        form.processing
                            ? 'Uploading...'
                            : `Upload ${localFiles.length} file(s)`
                    }}
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
