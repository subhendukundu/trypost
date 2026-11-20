<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconCheck,
    IconClock,
    IconDownload,
    IconLock,
    IconMessage,
    IconMovie,
    IconVideo,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import InputError from '@/components/InputError.vue';
import {
    displayLabel,
    formats,
    formatTimestamp,
    isActive,
    type ActiveProject,
    type Channel,
    type Provider,
} from '@/components/studio/studio';
import StudioRuns from '@/components/studio/StudioRuns.vue';
import StudioStatus from '@/components/studio/StudioStatus.vue';
import StudioStoryboard from '@/components/studio/StudioStoryboard.vue';
import StudioTreatments from '@/components/studio/StudioTreatments.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import date from '@/date';
import { draft, finalist, notes, retry } from '@/routes/app/studio';

const props = defineProps<{
    project: ActiveProject;
    providers: Provider[];
    channels: Channel[];
    canCreate: boolean;
}>();
const emit = defineEmits<{ changed: [] }>();

const runs = computed(() =>
    [...props.project.runs].sort((a, b) => b.id - a.id),
);
const activeRun = computed(() =>
    runs.value.find((run) => isActive(run.status)),
);
const lastRun = computed(() => runs.value[0]);
const revisions = computed(() =>
    [...props.project.revisions].sort((a, b) => b.version - a.version),
);
const pickedVersion = ref<number | string>('latest');
const revision = computed(
    () =>
        revisions.value.find((cut) => cut.id === pickedVersion.value) ??
        revisions.value[0] ??
        null,
);
const video = computed(() =>
    revision.value?.artifacts.find((artifact) => artifact.kind === 'video'),
);
const cover = computed(() =>
    revision.value?.artifacts.find((artifact) => artifact.kind === 'cover'),
);
const extraArtifacts = computed(
    () =>
        revision.value?.artifacts.filter((artifact) =>
            ['cover', 'captions', 'source'].includes(artifact.kind),
        ) ?? [],
);
const currentNotes = computed(() =>
    props.project.notes.filter(
        (note) => note.revision_id === revision.value?.id,
    ),
);

const videoElement = ref<HTMLVideoElement | null>(null);
const playhead = ref(0);
const noteError = ref('');
const draftError = ref('');
const finalistError = ref('');
const retryError = ref('');
const retrying = ref<number | null>(null);
const noteSent = ref(false);
const playbackFailed = ref(false);
const tab = ref(
    props.project.storyboard
        ? 'storyboard'
        : revisions.value.length
          ? 'review'
          : 'runs',
);
const channel = ref(props.channels.length === 1 ? props.channels[0].id : '');
const noteForm = useHttp({ revision_id: 0, time_seconds: 0, note: '' });
const noteRequestError = computed(
    () =>
        noteForm.errors.note ||
        noteForm.errors.revision_id ||
        noteForm.errors.time_seconds ||
        (noteForm.errors as Record<string, string | undefined>).run_id ||
        noteError.value,
);
const retryForm = useHttp({ run_id: 0 });
const finalistForm = useHttp<{ revision_id: number }, { revision_id: number }>({
    revision_id: 0,
});
const selectedFinalist = ref(props.project.finalist_revision_id);
const isFinalist = computed(
    () => revision.value?.id === selectedFinalist.value,
);

watch(
    () => props.project.finalist_revision_id,
    (value) => {
        selectedFinalist.value = value;
    },
);
watch(
    () => props.channels,
    (channels) => {
        if (channels.some((item) => item.id === channel.value)) return;
        channel.value = channels.length === 1 ? channels[0].id : '';
    },
);
const draftForm = useHttp<
    { revision_id: number; channel_id: string },
    { redirect_url: string }
>({
    revision_id: 0,
    channel_id: '',
});

watch(
    () => props.project.storyboard?.fingerprint,
    (fingerprint) => {
        if (fingerprint) tab.value = 'storyboard';
        else if (tab.value === 'storyboard') tab.value = 'runs';
    },
);
watch(
    () => revision.value?.id,
    (next, previous) => {
        playhead.value = 0;
        playbackFailed.value = false;
        noteSent.value = false;
        noteForm.reset();
        noteForm.clearErrors();
        draftForm.clearErrors();
        noteError.value = '';
        draftError.value = '';
        finalistError.value = '';
        if (next && !previous) tab.value = 'review';
    },
);

const renderQueued = (): void => {
    tab.value = 'runs';
    emit('changed');
    toast.success('Treatment queued for a private render');
};

const updatePlayhead = (): void => {
    playhead.value = videoElement.value?.currentTime ?? 0;
};

const seek = (seconds: number): void => {
    if (!videoElement.value) return;
    videoElement.value.currentTime = seconds;
    updatePlayhead();
};

const markPlaybackError = (): void => {
    playbackFailed.value = true;
};

watch(tab, (activeTab) => {
    if (activeTab !== 'review') videoElement.value?.pause();
});

const setInitialTimestamp = (): void => {
    if (noteForm.note.length) return;
    capturePlayhead();
};

const capturePlayhead = (): void => {
    noteForm.time_seconds = Math.floor(playhead.value);
};

const requestRevision = async (): Promise<void> => {
    if (!revision.value || noteForm.processing || activeRun.value) return;
    noteError.value = '';
    noteSent.value = false;
    noteForm.revision_id = revision.value.id;
    try {
        await noteForm.post(notes.url(props.project.id));
        noteForm.reset();
        noteSent.value = true;
        emit('changed');
    } catch {
        if (!Object.keys(noteForm.errors).length)
            noteError.value = 'Couldn’t queue the revision. Try again.';
    }
};

const retryRun = async (id: number): Promise<void> => {
    if (retrying.value !== null || activeRun.value) return;
    retrying.value = id;
    retryError.value = '';
    retryForm.run_id = id;
    try {
        await retryForm.post(retry.url(props.project.id));
        emit('changed');
        toast.success('Production run queued');
    } catch {
        retryError.value =
            retryForm.errors.run_id || 'Couldn’t queue a retry. Try again.';
    } finally {
        retrying.value = null;
    }
};

const selectFinalist = async (): Promise<void> => {
    if (!revision.value || !video.value || finalistForm.processing) return;
    finalistError.value = '';
    finalistForm.revision_id = revision.value.id;
    try {
        const response = await finalistForm.patch(
            finalist.url(props.project.id),
        );
        selectedFinalist.value = response.revision_id;
        emit('changed');
        toast.success('Finalist selected');
    } catch {
        finalistError.value =
            finalistForm.errors.revision_id ||
            'Couldn’t select this cut. Try again.';
    }
};

const createDraft = async (): Promise<void> => {
    if (
        !revision.value ||
        !video.value ||
        !isFinalist.value ||
        draftForm.processing
    )
        return;
    draftError.value = '';
    if (!channel.value) {
        draftError.value = 'Choose a connected channel for the draft.';
        return;
    }
    draftForm.revision_id = revision.value.id;
    draftForm.channel_id = channel.value;
    try {
        const response = await draftForm.post(draft.url(props.project.id));
        router.visit(response.redirect_url);
    } catch {
        if (!Object.keys(draftForm.errors).length)
            draftError.value = 'Couldn’t open the release draft. Try again.';
    }
};
</script>

<template>
    <div class="min-w-0 space-y-5">
        <header class="space-y-2.5">
            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    variant="outline"
                    class="gap-1.5 font-normal text-muted-foreground"
                >
                    <IconMovie class="size-3.5" /> {{ formats[project.format] }}
                </Badge>
                <StudioStatus :status="project.status" />
            </div>
            <h2
                class="text-2xl font-bold tracking-tight break-words sm:text-3xl"
            >
                {{ project.title }}
            </h2>
            <details
                v-if="project.brief"
                class="group rounded-lg border bg-card px-3.5 py-2 text-sm"
            >
                <summary
                    class="w-fit cursor-pointer font-medium text-muted-foreground"
                >
                    Creative brief
                </summary>
                <p class="mt-2 leading-6 break-words whitespace-pre-wrap">
                    {{ project.brief }}
                </p>
            </details>
        </header>

        <div
            v-if="activeRun"
            class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-blue-500/20 bg-blue-500/5 px-3.5 py-2.5 text-sm"
            role="status"
        >
            <IconClock
                class="size-4 shrink-0 text-blue-600 dark:text-blue-300"
            />
            <p class="font-medium">{{ displayLabel(activeRun.stage) }}</p>
            <p
                v-if="activeRun.message"
                class="min-w-0 break-words text-muted-foreground"
            >
                {{ activeRun.message }}
            </p>
        </div>
        <p
            v-else-if="lastRun?.error && revision"
            class="rounded-lg border border-destructive/20 bg-destructive/5 p-3 text-sm text-destructive-text"
            role="status"
        >
            Latest run {{ displayLabel(lastRun.status).toLowerCase() }}.
            <button
                type="button"
                class="font-semibold underline underline-offset-2"
                @click="tab = 'runs'"
            >
                View run history
            </button>
        </p>

        <div
            v-if="revisions.length"
            class="flex flex-wrap items-center gap-x-3 gap-y-2 [&_[data-slot=native-select-wrapper]]:max-w-full"
        >
            <Label for="studio-version" class="text-muted-foreground"
                >Cut</Label
            >
            <NativeSelect
                id="studio-version"
                v-model="pickedVersion"
                class="max-w-full"
            >
                <option value="latest">Latest cut</option>
                <option v-for="cut in revisions" :key="cut.id" :value="cut.id">
                    Version {{ cut.version }} ·
                    {{ date.formatDateTime(cut.created_at)
                    }}{{ cut.id === selectedFinalist ? ' · Finalist' : '' }}
                </option>
            </NativeSelect>
        </div>

        <Tabs v-model="tab" class="gap-4">
            <TabsList variant="line" class="gap-3">
                <TabsTrigger v-if="project.storyboard" value="storyboard"
                    >Storyboard</TabsTrigger
                >
                <TabsTrigger value="review">Review</TabsTrigger>
                <TabsTrigger value="treatments">Treatments</TabsTrigger>
                <TabsTrigger value="runs">Run history</TabsTrigger>
            </TabsList>

            <TabsContent v-if="project.storyboard" value="storyboard">
                <StudioStoryboard
                    :key="project.storyboard.fingerprint"
                    :project-id="project.id"
                    :storyboard="project.storyboard"
                    :providers="providers"
                    :can-create="canCreate"
                    :busy="!!activeRun"
                    @rendered="renderQueued"
                />
            </TabsContent>

            <TabsContent
                value="review"
                :force-mount="true"
                class="data-[state=inactive]:hidden"
            >
                <div
                    v-if="revision"
                    class="grid items-start gap-4 min-[1410px]:grid-cols-[minmax(0,1.1fr)_minmax(17rem,1fr)]"
                >
                    <article
                        class="min-w-0 overflow-hidden rounded-xl border bg-card"
                    >
                        <header
                            class="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                <IconVideo
                                    class="size-4 text-muted-foreground"
                                />
                                Cut {{ revision.version }}
                            </h3>
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge
                                    v-if="isFinalist"
                                    variant="secondary"
                                    class="gap-1.5"
                                    ><IconCheck class="size-3.5" />
                                    Finalist</Badge
                                >
                                <Badge
                                    variant="outline"
                                    class="gap-1.5 font-normal text-muted-foreground"
                                    ><IconLock class="size-3.5" /> Private
                                    review</Badge
                                >
                            </div>
                        </header>
                        <div v-if="video" class="w-full bg-black">
                            <video
                                :key="revision.id"
                                ref="videoElement"
                                class="mx-auto block max-h-[32rem] w-full"
                                :src="video.url"
                                :poster="cover?.url"
                                preload="metadata"
                                controls
                                playsinline
                                @timeupdate="updatePlayhead"
                                @error="markPlaybackError"
                            >
                                Your browser does not support this video.
                                <a :href="video.url">Open the video file</a>.
                            </video>
                            <p
                                v-if="playbackFailed"
                                class="bg-muted p-3 text-sm text-foreground"
                            >
                                Video preview couldn’t load.
                                <a
                                    :href="video.url"
                                    class="font-medium underline underline-offset-2"
                                    >Open the video file</a
                                >.
                            </p>
                        </div>
                        <p
                            v-else
                            class="border-y p-4 text-sm text-muted-foreground"
                        >
                            Video preview is unavailable for this cut.
                        </p>
                        <div class="space-y-2 px-4 py-4">
                            <p
                                v-if="revision.title"
                                class="text-sm font-semibold break-words"
                            >
                                {{ revision.title }}
                            </p>
                            <p
                                v-if="revision.summary"
                                class="text-sm leading-6 break-words whitespace-pre-wrap text-muted-foreground"
                            >
                                {{ revision.summary }}
                            </p>
                            <details
                                v-if="revision.caption"
                                class="space-y-2 text-sm"
                            >
                                <summary
                                    class="w-fit cursor-pointer font-medium"
                                >
                                    Proposed caption
                                </summary>
                                <p
                                    class="break-words whitespace-pre-wrap text-muted-foreground"
                                >
                                    {{ revision.caption }}
                                </p>
                            </details>
                            <div
                                v-if="extraArtifacts.length"
                                class="flex flex-wrap gap-2"
                            >
                                <Button
                                    v-for="artifact in extraArtifacts"
                                    :key="artifact.id"
                                    variant="ghost"
                                    size="sm"
                                    as-child
                                >
                                    <a :href="artifact.url" download
                                        ><IconDownload class="size-3.5" />
                                        {{ displayLabel(artifact.kind) }}</a
                                    >
                                </Button>
                            </div>
                        </div>
                    </article>

                    <div class="min-w-0 space-y-4">
                        <article
                            class="space-y-4 rounded-xl border bg-card p-4"
                        >
                            <h3
                                class="flex items-center gap-2 text-sm font-semibold"
                            >
                                <IconMessage
                                    class="size-4 text-muted-foreground"
                                />
                                Timestamped feedback
                            </h3>
                            <ul
                                v-if="currentNotes.length"
                                class="space-y-2"
                                aria-label="Notes for this cut"
                            >
                                <li
                                    v-for="note in currentNotes"
                                    :key="note.id"
                                    class="flex items-start gap-2 rounded-lg bg-muted/40 p-2.5 text-sm"
                                >
                                    <button
                                        v-if="video"
                                        type="button"
                                        class="shrink-0 rounded border bg-card px-1.5 py-0.5 font-mono text-xs tabular-nums hover:bg-muted"
                                        @click="seek(note.time_seconds)"
                                    >
                                        {{ formatTimestamp(note.time_seconds) }}
                                    </button>
                                    <span
                                        v-else
                                        class="shrink-0 font-mono text-xs tabular-nums"
                                        >{{
                                            formatTimestamp(note.time_seconds)
                                        }}</span
                                    >
                                    <p
                                        class="min-w-0 break-words whitespace-pre-wrap"
                                    >
                                        {{ note.note }}
                                    </p>
                                </li>
                            </ul>
                            <form
                                v-if="canCreate && video"
                                class="space-y-2.5"
                                @submit.prevent="requestRevision"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <Label for="studio-note"
                                        >Note at
                                        <span class="font-mono tabular-nums">{{
                                            formatTimestamp(
                                                noteForm.time_seconds,
                                            )
                                        }}</span></Label
                                    >
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="capturePlayhead"
                                        ><IconClock class="size-3.5" /> Use
                                        {{ formatTimestamp(playhead) }}</Button
                                    >
                                </div>
                                <Textarea
                                    id="studio-note"
                                    :maxlength="1600"
                                    v-model="noteForm.note"
                                    rows="3"
                                    placeholder="What should change on screen at this moment?"
                                    @focus="setInitialTimestamp"
                                />
                                <InputError
                                    :message="noteRequestError"
                                    role="alert"
                                />
                                <p
                                    v-if="noteSent"
                                    role="status"
                                    class="text-sm text-muted-foreground"
                                >
                                    Revision requested. Watch Run history for
                                    progress.
                                </p>
                                <p
                                    v-if="activeRun && !noteSent"
                                    class="text-xs text-muted-foreground"
                                >
                                    A run is active. You can send feedback as
                                    soon as it finishes.
                                </p>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    :loading="noteForm.processing"
                                    :disabled="!!activeRun"
                                    >Request revision</Button
                                >
                            </form>
                            <p
                                v-else-if="!currentNotes.length"
                                class="text-sm text-muted-foreground"
                            >
                                This cut has no notes yet.
                            </p>
                        </article>

                        <article
                            v-if="video && revision.duration_seconds > 0"
                            class="space-y-3 rounded-xl border bg-card p-4"
                        >
                            <h3 class="text-sm font-semibold">Release draft</h3>
                            <ul
                                v-if="revision.releases?.length"
                                class="space-y-2"
                                aria-label="Release drafts for this cut"
                            >
                                <li
                                    v-for="release in revision.releases"
                                    :key="release.url"
                                >
                                    <Button
                                        variant="outline"
                                        class="h-auto min-h-8 w-full justify-between py-1.5 text-left whitespace-normal"
                                        as-child
                                    >
                                        <Link :href="release.url">
                                            <span class="min-w-0 break-words"
                                                >{{ release.channel }} ·
                                                {{
                                                    displayLabel(release.status)
                                                }}</span
                                            >
                                            <IconArrowRight
                                                class="size-4 shrink-0"
                                            />
                                        </Link>
                                    </Button>
                                </li>
                            </ul>
                            <div
                                v-if="canCreate && !isFinalist"
                                class="space-y-2"
                            >
                                <p class="text-sm text-muted-foreground">
                                    Select this cut as the finalist to prepare
                                    it for release.
                                </p>
                                <InputError
                                    :message="finalistError"
                                    role="alert"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="w-full"
                                    :loading="finalistForm.processing"
                                    @click="selectFinalist"
                                >
                                    <IconCheck class="size-4" /> Select as
                                    finalist
                                </Button>
                            </div>
                            <form
                                v-else-if="
                                    canCreate && isFinalist && channels.length
                                "
                                class="space-y-3"
                                @submit.prevent="createDraft"
                            >
                                <p class="text-sm text-muted-foreground">
                                    Send this cut to TryPost for approval,
                                    scheduling, and publishing.
                                </p>
                                <div
                                    class="space-y-2 [&_[data-slot=native-select-wrapper]]:max-w-full"
                                >
                                    <Label for="release-channel">Channel</Label>
                                    <NativeSelect
                                        id="release-channel"
                                        v-model="channel"
                                        class="w-full"
                                    >
                                        <option value="" disabled>
                                            Select a channel
                                        </option>
                                        <option
                                            v-for="item in channels"
                                            :key="item.id"
                                            :value="item.id"
                                        >
                                            {{ item.label }} ·
                                            {{ displayLabel(item.platform) }}
                                        </option>
                                    </NativeSelect>
                                </div>
                                <InputError
                                    :message="
                                        draftForm.errors.channel_id ||
                                        draftForm.errors.revision_id ||
                                        draftError
                                    "
                                    role="alert"
                                />
                                <Button
                                    type="submit"
                                    class="w-full justify-between"
                                    :loading="draftForm.processing"
                                    >Create release draft
                                    <IconArrowRight class="size-4"
                                /></Button>
                            </form>
                            <p
                                v-else-if="canCreate && isFinalist"
                                class="text-sm text-muted-foreground"
                            >
                                Connect a supported channel in TryPost to
                                prepare a release.
                            </p>
                            <p
                                v-else-if="
                                    !canCreate && !revision.releases?.length
                                "
                                class="text-sm text-muted-foreground"
                            >
                                A workspace editor can prepare this release.
                            </p>
                        </article>
                    </div>
                </div>
                <div
                    v-else
                    class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground"
                >
                    <IconVideo class="mb-2 size-5" />
                    The private cut will appear here after media verification.
                </div>
            </TabsContent>

            <TabsContent value="treatments">
                <StudioTreatments :revision="revision" :providers="providers" />
            </TabsContent>
            <TabsContent value="runs">
                <InputError :message="retryError" role="alert" class="mb-2" />
                <StudioRuns
                    :runs="runs"
                    :providers="providers"
                    :retryable="canCreate && !activeRun"
                    :retrying="retrying"
                    @retry="retryRun"
                />
            </TabsContent>
        </Tabs>
    </div>
</template>
