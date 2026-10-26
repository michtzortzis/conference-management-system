import { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function urlIsActive(
    urlToCheck: NonNullable<InertiaLinkProps['href']>,
    currentUrl: string,
) {
    return toUrl(urlToCheck) === currentUrl;
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}

export function formatFileSize(bytes: number | null | undefined): string {
    if (bytes == null || bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const value = bytes / Math.pow(1024, i);
    return `${value.toFixed(i > 0 ? 1 : 0)} ${units[i]}`;
}

export function getFileIcon(mimeType?: string | null) {
    if (!mimeType) return 'FileText';
    const map: Record<string, string> = {
        'application/pdf': 'FileText',
        'application/msword': 'FileText',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
            'FileText',
        'text/plain': 'FileText',
    };
    return map[mimeType] || 'FileText';
}
