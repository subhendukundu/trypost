<script setup lang="ts">
import { IconArrowRight, IconHistory, IconRotate } from '@tabler/icons-vue';

import {
    canRetry,
    displayLabel,
    providerLabel,
    type Provider,
    type Run,
} from '@/components/studio/studio';
import StudioStatus from '@/components/studio/StudioStatus.vue';
import { Button } from '@/components/ui/button';
import date from '@/date';

defineProps<{
    runs: Run[];
    providers: Provider[];
    retryable: boolean;
    retrying: number | null;
}>();
const emit = defineEmits<{ retry: [id: number] }>();
</script>

<template>
    <div class="space-y-3">
        <div
            v-if="!runs.length"
            class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
        >
            <IconHistory class="mb-2 size-5" />
            Production events will appear as the Studio starts work.
        </div>
        <article
            v-for="run in runs"
            :key="run.id"
            class="overflow-hidden rounded-xl border bg-card"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5 sm:px-5"
            >
                <div>
                    <h3 class="text-sm font-semibold">
                        {{ displayLabel(run.kind) }}
                    </h3>
                    <time
                        v-if="run.started_at"
                        :datetime="run.started_at"
                        class="text-xs text-muted-foreground"
                    >
                        {{ date.formatDateTime(run.started_at) }}
                    </time>
                </div>
                <StudioStatus :status="run.status" />
            </header>
            <div class="space-y-3 border-t px-4 py-4 sm:px-5">
                <ol
                    v-if="run.events?.length"
                    aria-label="Production handoffs"
                    class="space-y-3"
                >
                    <li
                        v-for="(event, index) in run.events"
                        :key="index"
                        class="flex items-start gap-2 text-sm"
                    >
                        <span
                            aria-hidden="true"
                            class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"
                        >
                            <IconArrowRight class="size-3" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 break-words"
                            >
                                <span class="font-medium">{{
                                    displayLabel(event.stage)
                                }}</span>
                                <span
                                    v-if="event.provider"
                                    class="text-xs text-muted-foreground"
                                    >{{
                                        providerLabel(providers, event.provider)
                                    }}</span
                                >
                                <time
                                    v-if="event.at"
                                    :datetime="event.at"
                                    class="text-xs text-muted-foreground"
                                    >{{ date.formatDateTime(event.at) }}</time
                                >
                            </p>
                            <p
                                v-if="event.message"
                                class="mt-0.5 break-words whitespace-pre-wrap text-muted-foreground"
                            >
                                {{ event.message }}
                            </p>
                        </div>
                    </li>
                </ol>
                <div v-else>
                    <p class="text-sm font-medium">
                        {{ displayLabel(run.stage) }}
                    </p>
                    <p
                        v-if="run.message"
                        class="mt-0.5 text-sm break-words whitespace-pre-wrap text-muted-foreground"
                    >
                        {{ run.message }}
                    </p>
                </div>
                <p
                    v-if="run.error"
                    class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm break-words whitespace-pre-wrap text-destructive-text"
                >
                    {{ run.error }}
                </p>
                <Button
                    v-if="retryable && canRetry(run.status)"
                    variant="outline"
                    size="sm"
                    :disabled="retrying !== null"
                    :loading="retrying === run.id"
                    @click="emit('retry', run.id)"
                >
                    <IconRotate class="size-4" /> Retry run
                </Button>
            </div>
        </article>
    </div>
</template>
