<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { IconArrowLeft, IconLock, IconVideo } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { approve, index } from '@/routes/app/studio';

const props = defineProps<{
    release: {
        id: string;
        fingerprint: string;
        channel: string | null;
        handle: string | null;
        type: string;
        platform: string;
        content: string | null;
        media_url: string | null;
        cover_url: string | null;
        options: Record<string, unknown>;
        status: string;
        scheduled_at: string | null;
        owner: boolean;
        edit_url: string;
    };
}>();

const mode = ref<'now' | 'schedule'>('now');
const time = ref('');
const reviewed = ref(false);
const form = useForm({
    fingerprint: props.release.fingerprint,
    scheduled_at: null as string | null,
});
const canSubmit = computed(
    () => reviewed.value && (mode.value === 'now' || !!time.value),
);
const formattedTime = computed(() =>
    props.release.scheduled_at
        ? new Date(props.release.scheduled_at).toLocaleString()
        : '',
);
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const error = computed(() => Object.values(form.errors)[0]);
const submit = () => {
    if (!canSubmit.value || form.processing) return;
    form.fingerprint = props.release.fingerprint;
    form.scheduled_at =
        mode.value === 'schedule' ? new Date(time.value).toISOString() : null;
    form.post(approve.url(props.release.id), {
        preserveScroll: true,
        onError: () => {
            reviewed.value = false;
        },
    });
};
</script>

<template>
    <Head title="Review release" />
    <AppLayout title="Review release" :icon="IconVideo">
        <div class="mx-auto w-full max-w-5xl space-y-6 px-4 py-6 sm:px-7">
            <Link
                :href="index.url()"
                class="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
            >
                <IconArrowLeft class="size-4" />
                Studio
            </Link>

            <div
                class="grid gap-7 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]"
            >
                <section
                    aria-label="Video preview"
                    class="rounded-2xl border bg-muted/25 p-3 sm:p-4"
                >
                    <video
                        v-if="release.media_url"
                        :src="release.media_url"
                        :poster="release.cover_url || undefined"
                        controls
                        playsinline
                        preload="metadata"
                        class="mx-auto aspect-[9/16] max-h-[65vh] w-full rounded-xl bg-black"
                    />
                    <p v-else class="p-4 text-sm text-muted-foreground">
                        This draft has no video.
                    </p>
                </section>

                <section
                    class="min-w-0 space-y-6"
                    aria-labelledby="destination"
                >
                    <header
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div>
                            <p
                                class="text-sm font-medium text-muted-foreground capitalize"
                            >
                                {{ release.type?.replaceAll('_', ' ') }}
                            </p>
                            <h1
                                id="destination"
                                class="mt-1 text-2xl font-semibold tracking-tight"
                            >
                                {{ release.channel || 'Channel unavailable' }}
                            </h1>
                            <p
                                v-if="
                                    release.handle &&
                                    release.handle !== release.channel
                                "
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                {{ release.handle }}
                            </p>
                        </div>
                        <Button
                            v-if="release.status === 'draft'"
                            as-child
                            variant="outline"
                        >
                            <Link :href="release.edit_url"
                                >Edit in composer</Link
                            >
                        </Button>
                    </header>

                    <div class="space-y-3 rounded-xl border p-4">
                        <p class="text-sm font-semibold">Post caption</p>
                        <p
                            class="text-sm leading-relaxed break-words whitespace-pre-wrap"
                            dir="auto"
                        >
                            {{ release.content }}
                        </p>
                        <p
                            v-if="
                                typeof release.options.is_ai_generated ===
                                'boolean'
                            "
                            class="text-sm text-muted-foreground"
                        >
                            AI disclosure
                            {{
                                release.options.is_ai_generated
                                    ? 'enabled'
                                    : 'disabled'
                            }}
                        </p>
                        <a
                            v-if="release.cover_url"
                            :href="release.cover_url"
                            target="_blank"
                            rel="noopener"
                            class="inline-block text-sm underline"
                        >
                            View attached cover
                        </a>
                        <details
                            v-if="Object.keys(release.options).length"
                            class="text-sm text-muted-foreground"
                        >
                            <summary class="cursor-pointer">
                                Publishing settings
                            </summary>
                            <dl class="mt-2 divide-y">
                                <div
                                    v-for="(value, key) in release.options"
                                    :key="key"
                                    class="flex items-start justify-between gap-4 py-2"
                                >
                                    <dt class="capitalize">
                                        {{ String(key).replaceAll('_', ' ') }}
                                    </dt>
                                    <dd
                                        class="max-w-[60%] text-right break-words"
                                    >
                                        {{
                                            typeof value === 'object'
                                                ? JSON.stringify(value)
                                                : String(value)
                                        }}
                                    </dd>
                                </div>
                            </dl>
                        </details>
                    </div>

                    <form
                        v-if="release.owner && release.status === 'draft'"
                        class="space-y-4"
                        @submit.prevent="submit"
                    >
                        <fieldset class="space-y-3">
                            <legend class="mb-2 text-sm font-semibold">
                                Release timing
                            </legend>
                            <div class="flex flex-wrap gap-4 text-sm">
                                <label class="flex items-center gap-2"
                                    ><input
                                        v-model="mode"
                                        type="radio"
                                        value="now"
                                    />
                                    Publish now</label
                                >
                                <label class="flex items-center gap-2"
                                    ><input
                                        v-model="mode"
                                        type="radio"
                                        value="schedule"
                                    />
                                    Schedule</label
                                >
                            </div>
                            <label
                                v-if="mode === 'schedule'"
                                for="release-time"
                                class="block space-y-1"
                            >
                                <span class="text-xs text-muted-foreground">{{
                                    timezone
                                }}</span>
                                <Input
                                    id="release-time"
                                    v-model="time"
                                    type="datetime-local"
                                    required
                                />
                            </label>
                        </fieldset>
                        <label class="flex items-start gap-2 text-sm">
                            <input
                                v-model="reviewed"
                                type="checkbox"
                                class="mt-1"
                            />
                            I reviewed this video, caption, destination and
                            release time.
                        </label>
                        <p
                            v-if="error"
                            role="alert"
                            class="text-sm text-destructive"
                        >
                            {{ error }}
                        </p>
                        <Button
                            type="submit"
                            :disabled="!canSubmit || form.processing"
                        >
                            <IconLock class="size-4" />
                            {{
                                mode === 'schedule'
                                    ? 'Approve and schedule'
                                    : 'Approve and publish'
                            }}
                        </Button>
                    </form>

                    <p
                        v-else
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                        role="status"
                    >
                        <IconLock class="size-4" />
                        <template v-if="release.status === 'draft'"
                            >Awaiting account owner approval</template
                        >
                        <span v-else class="capitalize">{{
                            release.status.replaceAll('_', ' ')
                        }}</span>
                        <span
                            v-if="
                                release.status === 'scheduled' && formattedTime
                            "
                            >· {{ formattedTime }}</span
                        >
                    </p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
