<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconArrowRight, IconMovie } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import {
    displayLabel,
    providerLabel,
    type Provider,
    type Storyboard,
} from '@/components/studio/studio';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { storyboard as renderStoryboard } from '@/routes/app/studio';

const props = defineProps<{
    projectId: number;
    storyboard: Storyboard;
    providers: Provider[];
    canCreate: boolean;
    busy: boolean;
}>();
const emit = defineEmits<{ rendered: [] }>();

const treatmentIndex = ref(props.storyboard.review.selected);
const treatment = computed(
    () => props.storyboard.treatments[treatmentIndex.value],
);
const form = useHttp<
    { treatment: 0 | 1; fingerprint: string },
    { run_id: number }
>({
    treatment: props.storyboard.review.selected,
    fingerprint: props.storyboard.fingerprint,
});
const error = ref('');
const requestError = computed(
    () =>
        form.errors.treatment ||
        form.errors.fingerprint ||
        (form.errors as Record<string, string | undefined>).run_id ||
        error.value,
);

const submit = async (): Promise<void> => {
    if (!props.canCreate || props.busy || form.processing || !treatment.value)
        return;

    error.value = '';
    form.clearErrors();
    form.treatment = treatmentIndex.value;
    try {
        const result = await form.post(
            renderStoryboard.url({
                project: props.projectId,
                run: props.storyboard.run_id,
            }),
        );
        if (result) emit('rendered');
    } catch {
        if (!Object.keys(form.errors).length)
            error.value =
                'Couldn’t queue rendering. Refresh Studio and try again.';
    }
};
</script>

<template>
    <div class="space-y-4">
        <section
            aria-labelledby="studio-storyboard-heading"
            class="@container space-y-4 rounded-xl border bg-card p-4 sm:p-5"
        >
            <div class="space-y-1">
                <h3
                    id="studio-storyboard-heading"
                    class="text-base font-semibold"
                >
                    Choose a treatment
                </h3>
                <p class="text-sm text-muted-foreground">
                    Compare the story directions and read each shot plan.
                </p>
            </div>

            <fieldset
                :disabled="form.processing"
                class="grid min-w-0 gap-3 @xl:grid-cols-2"
            >
                <legend class="sr-only">Storyboard treatments</legend>
                <label
                    v-for="(option, index) in storyboard.treatments"
                    :key="index"
                    :for="`storyboard-treatment-${index}`"
                    class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition-colors focus-within:border-ring hover:bg-muted/25 sm:p-4"
                    :class="{
                        'border-primary/60 bg-primary/5':
                            treatmentIndex === index,
                    }"
                >
                    <input
                        :id="`storyboard-treatment-${index}`"
                        v-model="treatmentIndex"
                        type="radio"
                        name="storyboard-treatment"
                        :value="index"
                        :aria-labelledby="`storyboard-title-${index}`"
                        class="mt-1 size-4 shrink-0 accent-primary-strong"
                    />
                    <span class="block min-w-0 flex-1 space-y-2">
                        <span
                            class="flex flex-wrap items-start justify-between gap-x-2 gap-y-1.5"
                        >
                            <span
                                :id="`storyboard-title-${index}`"
                                class="font-semibold break-words"
                                dir="auto"
                            >
                                {{ option.title }}
                            </span>
                            <Badge
                                v-if="storyboard.review.selected === index"
                                variant="secondary"
                                class="shrink-0"
                                >Editor’s pick</Badge
                            >
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ option.shots.length }} shots<span
                                v-if="option.provider"
                            >
                                · {{ providerLabel(providers, option.provider)
                                }}<span v-if="option.model">
                                    · {{ option.model }}</span
                                >
                            </span>
                        </span>
                        <span
                            class="block text-sm leading-6 break-words text-muted-foreground"
                            dir="auto"
                        >
                            {{ option.angle }}
                        </span>
                        <span
                            v-if="option.assessment"
                            class="block border-t pt-2 text-sm leading-6 break-words"
                            dir="auto"
                        >
                            {{ option.assessment }}
                        </span>
                    </span>
                </label>
            </fieldset>

            <div class="rounded-lg bg-muted/50 p-3 text-sm sm:px-4">
                <h4 class="font-medium">Why the editor chose it</h4>
                <p
                    class="mt-1 leading-6 break-words text-muted-foreground"
                    dir="auto"
                >
                    {{ storyboard.review.rationale }}
                </p>
            </div>
        </section>

        <section
            v-if="treatment"
            aria-labelledby="studio-shot-plan-heading"
            class="@container space-y-4 rounded-xl border bg-card p-4 sm:p-5"
        >
            <header class="space-y-1">
                <h3
                    id="studio-shot-plan-heading"
                    class="text-base font-semibold"
                >
                    Shot plan
                </h3>
                <p class="text-sm text-muted-foreground" dir="auto">
                    {{ treatment.title }}
                </p>
            </header>

            <dl
                class="grid gap-3 rounded-lg bg-muted/35 p-3 text-sm sm:px-4 @lg:grid-cols-2"
            >
                <div>
                    <dt class="font-medium">Opening hook</dt>
                    <dd
                        class="mt-1 break-words text-muted-foreground"
                        dir="auto"
                    >
                        {{ treatment.hook }}
                    </dd>
                </div>
                <div>
                    <dt class="font-medium">Payoff</dt>
                    <dd
                        class="mt-1 break-words text-muted-foreground"
                        dir="auto"
                    >
                        {{ treatment.payoff }}
                    </dd>
                </div>
            </dl>

            <ol class="space-y-2.5">
                <li
                    v-for="(shot, index) in treatment.shots"
                    :key="index"
                    class="rounded-lg border p-3 sm:p-4"
                >
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <h4 class="text-sm font-semibold">
                            Shot {{ index + 1 }}
                        </h4>
                        <Badge variant="secondary" class="font-normal">{{
                            displayLabel(shot.camera)
                        }}</Badge>
                        <span class="text-xs text-muted-foreground">{{
                            displayLabel(shot.setting)
                        }}</span>
                    </div>
                    <div
                        class="mt-2 grid gap-x-4 gap-y-2 text-sm @lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]"
                    >
                        <dl class="space-y-1 text-muted-foreground">
                            <div>
                                <dt class="inline font-medium text-foreground">
                                    Cast
                                </dt>
                                <dd class="ml-1 inline">
                                    {{ displayLabel(shot.character)
                                    }}<span v-if="shot.secondary !== 'none'">
                                        and
                                        {{
                                            displayLabel(
                                                shot.secondary,
                                            ).toLowerCase()
                                        }}</span
                                    >
                                </dd>
                            </div>
                            <div>
                                <dt class="inline font-medium text-foreground">
                                    Action
                                </dt>
                                <dd class="ml-1 inline">
                                    {{ displayLabel(shot.action) }} ·
                                    {{
                                        displayLabel(
                                            shot.expression,
                                        ).toLowerCase()
                                    }}
                                </dd>
                            </div>
                            <div v-if="shot.prop !== 'none'">
                                <dt class="inline font-medium text-foreground">
                                    Prop
                                </dt>
                                <dd class="ml-1 inline">
                                    {{ displayLabel(shot.prop) }}
                                </dd>
                            </div>
                        </dl>
                        <div class="rounded-md bg-muted/55 px-3 py-2">
                            <p
                                class="mb-0.5 text-xs font-medium text-muted-foreground"
                            >
                                Dialogue
                            </p>
                            <blockquote
                                class="leading-6 break-words whitespace-pre-wrap"
                                dir="auto"
                            >
                                {{ shot.speech }}
                            </blockquote>
                        </div>
                    </div>
                </li>
            </ol>

            <form
                v-if="canCreate"
                class="space-y-2 border-t pt-4"
                @submit.prevent="submit"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0 space-y-0.5 text-sm">
                        <p class="font-medium break-words" dir="auto">
                            {{ treatment.title }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                busy
                                    ? 'Wait for the active run to finish.'
                                    : 'Creates a private review cut.'
                            }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        :disabled="busy"
                    >
                        <IconMovie class="size-4" /> Render this treatment
                        <IconArrowRight class="size-4" />
                    </Button>
                </div>
                <InputError :message="requestError" role="alert" />
            </form>
            <p v-else class="border-t pt-4 text-sm text-muted-foreground">
                A workspace editor can render this treatment.
            </p>
        </section>
    </div>
</template>
