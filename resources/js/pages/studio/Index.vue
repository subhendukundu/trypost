<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    IconChevronRight,
    IconClock,
    IconMovie,
    IconPlus,
    IconSettings,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import {
    formats,
    isActive,
    providerLabel,
    type Studio,
} from '@/components/studio/studio';
import StudioNewProject from '@/components/studio/StudioNewProject.vue';
import StudioProject from '@/components/studio/StudioProject.vue';
import StudioSettings from '@/components/studio/StudioSettings.vue';
import StudioStatus from '@/components/studio/StudioStatus.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { index } from '@/routes/app/studio';

const props = defineProps<{ studio: Studio }>();
const newProjectOpen = ref(false);
const settingsOpen = ref(false);

const settings = computed(() => props.studio.settings);
const primaryProvider = computed(() =>
    props.studio.providers.find(
        (provider) => provider.id === settings.value.provider,
    ),
);
const providersAvailable = computed(() =>
    props.studio.providers.some((provider) => provider.configured),
);
const activeProject = computed(() => props.studio.activeProject);
const localRunTime = computed(() =>
    date.formatClockTime(settings.value.run_at.slice(0, 5)),
);
const activeProduction = computed(
    () =>
        props.studio.projects.some((project) => isActive(project.status)) ||
        activeProject.value?.runs.some((run) => isActive(run.status)) === true,
);

const projectUrl = (id: number): string =>
    index.url({ query: { project: id } });
const selectedProject = computed({
    get: () => activeProject.value?.id,
    set: (id) => {
        if (id) router.visit(projectUrl(id), { preserveScroll: true });
    },
});

const refresh = (): void => {
    router.reload({ only: ['studio'] });
};

const { start, stop } = usePoll(
    4000,
    { only: ['studio'] },
    { autoStart: false },
);
watch(
    activeProduction,
    (active) => {
        if (active) start();
        else stop();
    },
    { immediate: true },
);

const idleAutopilot = computed(
    () => settings.value.enabled && !activeProduction.value,
);
const { start: startIdlePoll, stop: stopIdlePoll } = usePoll(
    30000,
    { only: ['studio'] },
    { autoStart: false },
);
watch(
    idleAutopilot,
    (idle) => {
        if (idle) startIdlePoll();
        else stopIdlePoll();
    },
    { immediate: true },
);

const openNewProject = (): void => {
    newProjectOpen.value = true;
};
const openSettings = (): void => {
    settingsOpen.value = true;
};
const projectCreated = (id: number): void => {
    router.visit(projectUrl(id));
    toast.success('Production run queued');
};
const settingsSaved = (): void => {
    refresh();
    toast.success('Studio settings saved');
};
</script>

<template>
    <AppLayout title="Studio" :icon="IconMovie" full-width>
        <Head title="Studio" />

        <div
            class="mx-auto w-full max-w-[96rem] min-w-0 space-y-5 px-3.5 py-4 sm:px-6 sm:py-6"
        >
            <section
                aria-label="Autopilot"
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-card p-4 sm:px-5 sm:py-4"
            >
                <div class="flex min-w-0 items-start gap-3.5">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary-strong dark:text-primary"
                    >
                        <IconClock class="size-5" />
                    </span>
                    <div class="min-w-0 space-y-1">
                        <p
                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold"
                        >
                            Autopilot
                            <span
                                v-if="settings.enabled"
                                class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                                ><span
                                    class="size-1.5 rounded-full bg-current"
                                />
                                On</span
                            >
                            <span
                                v-else
                                class="text-xs font-medium text-muted-foreground"
                                >Off</span
                            >
                        </p>
                        <p
                            v-if="settings.enabled"
                            class="text-sm break-words text-muted-foreground"
                        >
                            Daily at {{ localRunTime }} ·
                            {{ settings.timezone }}
                        </p>
                        <p
                            v-if="settings.can_manage && !providersAvailable"
                            class="text-xs text-destructive-text"
                        >
                            Model setup required
                        </p>
                        <p
                            v-else-if="primaryProvider?.configured === false"
                            class="text-xs break-words text-destructive-text"
                        >
                            {{
                                providerLabel(
                                    studio.providers,
                                    settings.provider,
                                )
                            }}
                            isn’t connected for production.
                        </p>
                    </div>
                </div>
                <div class="flex max-w-full flex-wrap gap-2">
                    <Button
                        v-if="settings.can_manage"
                        variant="outline"
                        size="sm"
                        @click="openSettings"
                    >
                        <IconSettings class="size-4" /> Settings
                    </Button>
                    <Button
                        v-if="studio.can_create"
                        size="sm"
                        @click="openNewProject"
                    >
                        <IconPlus class="size-4" /> New project
                    </Button>
                </div>
            </section>

            <div
                v-if="studio.projects.length"
                class="grid min-w-0 items-start gap-5 xl:grid-cols-[15rem_minmax(0,1fr)]"
            >
                <div
                    class="min-w-0 xl:hidden [&_[data-slot=native-select-wrapper]]:max-w-full"
                >
                    <Label
                        for="studio-project-mobile"
                        class="mb-2 text-muted-foreground"
                        >Project</Label
                    >
                    <NativeSelect
                        id="studio-project-mobile"
                        v-model="selectedProject"
                        class="w-full"
                    >
                        <option
                            v-for="project in studio.projects"
                            :key="project.id"
                            :value="project.id"
                        >
                            {{ project.title }} · {{ formats[project.format] }}
                        </option>
                    </NativeSelect>
                </div>
                <nav
                    aria-label="Studio projects"
                    class="hidden max-h-[min(70dvh,50rem)] overflow-y-auto rounded-xl border bg-card xl:block"
                >
                    <div
                        class="sticky top-0 z-10 flex items-center justify-between gap-2 border-b bg-card px-3.5 py-3"
                    >
                        <h2
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Projects
                        </h2>
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                            >{{ studio.projects.length }}</span
                        >
                    </div>
                    <ul class="space-y-1 p-1.5">
                        <li
                            v-for="project in studio.projects"
                            :key="project.id"
                        >
                            <Link
                                :href="projectUrl(project.id)"
                                preserve-scroll
                                :aria-current="
                                    activeProject?.id === project.id
                                        ? 'page'
                                        : undefined
                                "
                                class="flex items-center gap-1.5 rounded-lg border border-transparent p-2.5 hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-[current=page]:border-border aria-[current=page]:bg-muted"
                            >
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <p class="truncate text-sm font-medium">
                                        {{ project.title }}
                                    </p>
                                    <StudioStatus :status="project.status" />
                                </div>
                                <IconChevronRight
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                            </Link>
                        </li>
                    </ul>
                </nav>

                <StudioProject
                    v-if="activeProject"
                    :key="activeProject.id"
                    :project="activeProject"
                    :providers="studio.providers"
                    :channels="studio.channels"
                    :can-create="studio.can_create"
                    @changed="refresh"
                />
            </div>

            <section
                v-else
                class="grid min-h-64 place-items-center rounded-2xl border border-dashed p-6 text-center"
            >
                <div class="mx-auto max-w-sm space-y-3">
                    <div
                        class="mx-auto grid size-12 place-items-center rounded-xl bg-muted text-muted-foreground"
                    >
                        <IconMovie class="size-6" />
                    </div>
                    <h2 class="text-base font-semibold">
                        No Studio projects yet
                    </h2>
                    <p class="text-sm leading-6 text-muted-foreground">
                        Start with a brief. The Studio develops two approaches,
                        reviews them, and prepares a private cut for feedback.
                    </p>
                    <Button
                        v-if="studio.can_create"
                        size="sm"
                        @click="openNewProject"
                        ><IconPlus class="size-4" /> New project</Button
                    >
                </div>
            </section>
        </div>

        <StudioNewProject
            v-if="studio.can_create"
            v-model:open="newProjectOpen"
            @created="projectCreated"
        />
        <StudioSettings
            v-if="settings.can_manage"
            v-model:open="settingsOpen"
            :settings="settings"
            :providers="studio.providers"
            @saved="settingsSaved"
        />
    </AppLayout>
</template>
