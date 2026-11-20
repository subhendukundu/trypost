<script setup lang="ts">
import { IconGitBranch } from '@tabler/icons-vue';

import {
    providerLabel,
    type Provider,
    type Revision,
} from '@/components/studio/studio';
import { Badge } from '@/components/ui/badge';

defineProps<{ revision: Revision | null; providers: Provider[] }>();
</script>

<template>
    <div v-if="revision?.treatments.length" class="space-y-4">
        <div
            v-if="
                revision.review?.rationale ||
                revision.review?.quality_score != null
            "
            class="rounded-xl border bg-muted/25 p-4"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">Editor’s recommendation</h3>
                <p
                    v-if="revision.review.quality_score != null"
                    class="text-xs text-muted-foreground"
                >
                    Review score {{ revision.review.quality_score }}
                </p>
            </div>
            <p
                v-if="revision.review.rationale"
                class="mt-1.5 text-sm break-words whitespace-pre-wrap text-muted-foreground"
            >
                {{ revision.review.rationale }}
            </p>
        </div>
        <div class="grid items-start gap-4 lg:grid-cols-2">
            <article
                v-for="(treatment, index) in revision.treatments"
                :key="index"
                class="min-w-0 overflow-hidden rounded-xl border bg-card"
                :class="{ 'border-primary/50': treatment.selected }"
            >
                <div class="space-y-2 border-b p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <p
                            class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                        >
                            <IconGitBranch class="size-3.5" />
                            Treatment {{ index + 1 }}
                        </p>
                        <Badge v-if="treatment.selected" variant="secondary"
                            >Rendered cut</Badge
                        >
                    </div>
                    <h3 class="font-semibold break-words">
                        {{ treatment.title }}
                    </h3>
                    <p
                        v-if="treatment.provider"
                        class="text-xs text-muted-foreground"
                    >
                        {{ providerLabel(providers, treatment.provider) }}
                        <span v-if="treatment.model">
                            · {{ treatment.model }}</span
                        >
                    </p>
                </div>
                <div class="space-y-4 p-4">
                    <div v-if="treatment.assessment" class="space-y-1">
                        <h4 class="text-xs font-semibold text-muted-foreground">
                            Editorial assessment
                        </h4>
                        <p class="text-sm break-words whitespace-pre-wrap">
                            {{ treatment.assessment }}
                        </p>
                    </div>
                    <div v-if="treatment.script" class="space-y-1">
                        <h4 class="text-xs font-semibold text-muted-foreground">
                            Script
                        </h4>
                        <p
                            class="text-sm leading-6 break-words whitespace-pre-wrap"
                        >
                            {{ treatment.script }}
                        </p>
                    </div>
                </div>
            </article>
        </div>
    </div>
    <div
        v-else
        class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
    >
        <IconGitBranch class="mb-2 size-5" />
        Treatments appear here when a review cut is ready.
    </div>
</template>
