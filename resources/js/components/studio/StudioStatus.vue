<script setup lang="ts">
import { computed } from 'vue';

import { canRetry, displayLabel, isActive } from '@/components/studio/studio';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{ status: string }>();

const tone = computed(() => {
    if (canRetry(props.status))
        return 'bg-destructive/10 text-destructive-text';
    if (isActive(props.status))
        return 'bg-blue-500/10 text-blue-700 dark:text-blue-300';
    if (props.status === 'awaiting_storyboard')
        return 'bg-amber-500/10 text-amber-800 dark:text-amber-300';
    if (
        ['ready_for_review', 'needs_review', 'completed'].includes(props.status)
    ) {
        return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
    }
    return 'bg-muted text-muted-foreground';
});
</script>

<template>
    <Badge
        variant="outline"
        class="w-fit gap-1.5 border-transparent font-medium"
        :class="tone"
    >
        <span
            class="size-1.5 shrink-0 rounded-full bg-current"
            :class="{ 'motion-safe:animate-pulse': isActive(status) }"
        />
        {{ displayLabel(status) }}
    </Badge>
</template>
