<script setup lang="ts">
import { computed } from 'vue';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatFileSize } from '@/lib/utils';
import type { SubmissionFile } from '@/types';
import { Download, FileText, Trash2 } from 'lucide-vue-next';

interface Props {
    submissionId: number;
    files?: SubmissionFile[];
    showActions?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    files: () => [],
    showActions: false,
});

defineEmits<{
    (e: 'removeFile', fileId: number): void;
}>();

const formattedFiles = computed(() =>
    props.files.map((file) => ({
        ...file,
        sizeFormatted: formatFileSize(file.size),
    })),
);

function getFileExtension(filename: string): string {
    return filename.split('.').pop()?.toUpperCase() || '';
}

function downloadUrl(submissionId: number, fileId: number): string {
    return `/submissions/${submissionId}/files/${fileId}/download`;
}
</script>

<template>
    <Card v-if="files && files.length > 0">
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                <FileText class="h-5 w-5" />
                Attached Files
            </CardTitle>
        </CardHeader>
        <CardContent>
            <div class="space-y-2">
                <div
                    v-for="file in formattedFiles"
                    :key="file.id"
                    class="flex items-center justify-between rounded-lg border px-4 py-3"
                >
                    <div class="flex items-center gap-3">
                        <FileText class="h-5 w-5 text-muted-foreground" />
                        <div>
                            <p class="text-sm font-medium">
                                {{ file.filename }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ file.sizeFormatted }} ·
                                {{ getFileExtension(file.filename) }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge variant="secondary">{{
                            getFileExtension(file.filename)
                        }}</Badge>
                        <Button variant="ghost" size="icon" as-child>
                            <a :href="downloadUrl(submissionId, file.id)">
                                <Download class="h-4 w-4" />
                            </a>
                        </Button>
                        <Button
                            v-if="showActions"
                            variant="ghost"
                            size="icon"
                            class="text-destructive hover:text-destructive"
                            @click="$emit('removeFile', file.id)"
                        >
                            <Trash2 class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
