<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import type { Provider, Settings } from '@/components/studio/studio';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { settings as updateSettings } from '@/routes/app/studio';

const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ settings: Settings; providers: Provider[] }>();
const emit = defineEmits<{ saved: [] }>();

type SettingsInput = Omit<
    Settings,
    'can_manage' | 'voice_id' | 'alternative_provider'
> & {
    voice_id: string;
    alternative_provider: string;
};

const snapshot = (): SettingsInput => ({
    enabled: props.settings.enabled,
    run_at: props.settings.run_at.slice(0, 5),
    timezone: props.settings.timezone,
    brand_guide: props.settings.brand_guide ?? '',
    creative_brief: props.settings.creative_brief ?? '',
    provider: props.settings.provider ?? '',
    alternative_provider: props.settings.alternative_provider ?? '',
    max_model_calls: props.settings.max_model_calls,
    max_voice_characters: props.settings.max_voice_characters,
    voice_id: props.settings.voice_id ?? '',
});

const form = useHttp<SettingsInput>(snapshot());
const fallback = computed(() =>
    props.providers.filter((provider) => provider.id !== form.provider),
);
const error = ref('');

watch(open, (visible) => {
    if (!visible) return;
    Object.assign(form, snapshot());
    form.clearErrors();
    error.value = '';
});

watch(
    () => form.provider,
    (provider) => {
        if (form.alternative_provider === provider)
            form.alternative_provider = '';
    },
);

const submit = async (): Promise<void> => {
    if (form.processing) return;
    error.value = '';
    try {
        await form
            .transform((values) => ({
                ...values,
                alternative_provider: values.alternative_provider || null,
                voice_id: values.voice_id || null,
            }))
            .patch(updateSettings.url());
        open.value = false;
        emit('saved');
    } catch {
        if (!Object.keys(form.errors).length)
            error.value = 'Couldn’t save the Studio settings. Try again.';
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Studio settings</DialogTitle>
                <DialogDescription
                    >Set the schedule, editorial direction, and limits for
                    private production.</DialogDescription
                >
            </DialogHeader>

            <form class="space-y-5" @submit.prevent="submit">
                <div
                    class="flex items-start justify-between gap-4 rounded-lg border bg-muted/30 p-3"
                >
                    <div class="space-y-1">
                        <Label for="studio-autopilot">Daily autopilot</Label>
                        <p class="text-xs text-muted-foreground">
                            Queue one production run per day for this workspace.
                        </p>
                    </div>
                    <Switch id="studio-autopilot" v-model="form.enabled" />
                </div>

                <div class="grid gap-4 sm:grid-cols-[9rem_1fr]">
                    <div class="space-y-2">
                        <Label for="studio-time">Run time</Label>
                        <Input
                            id="studio-time"
                            v-model="form.run_at"
                            type="time"
                        />
                        <InputError :message="form.errors.run_at" />
                    </div>
                    <div class="space-y-2">
                        <Label for="studio-zone">Time zone</Label>
                        <Input
                            id="studio-zone"
                            v-model="form.timezone"
                            placeholder="Asia/Kolkata"
                        />
                        <InputError :message="form.errors.timezone" />
                    </div>
                </div>

                <div class="space-y-2">
                    <Label for="studio-brand">Brand guide</Label>
                    <Textarea
                        id="studio-brand"
                        :maxlength="5000"
                        v-model="form.brand_guide"
                        rows="3"
                        placeholder="Voice, audience, recurring characters, and editorial boundaries"
                    />
                    <InputError :message="form.errors.brand_guide" />
                </div>
                <div class="space-y-2">
                    <Label for="studio-brief">Autopilot brief</Label>
                    <Textarea
                        id="studio-brief"
                        :maxlength="5000"
                        v-model="form.creative_brief"
                        rows="3"
                        placeholder="What should the Studio look for and make next?"
                    />
                    <InputError :message="form.errors.creative_brief" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="studio-model">Primary provider</Label>
                        <NativeSelect
                            id="studio-model"
                            v-model="form.provider"
                            class="w-full"
                        >
                            <option value="">Choose a provider</option>
                            <option
                                v-for="provider in providers"
                                :key="provider.id"
                                :value="provider.id"
                            >
                                {{ provider.label
                                }}{{
                                    provider.configured
                                        ? ''
                                        : ' (not connected)'
                                }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.provider" />
                    </div>
                    <div class="space-y-2">
                        <Label for="studio-alt-model"
                            >Second treatment provider</Label
                        >
                        <NativeSelect
                            id="studio-alt-model"
                            v-model="form.alternative_provider"
                            class="w-full"
                        >
                            <option value="">Use primary provider</option>
                            <option
                                v-for="provider in fallback"
                                :key="provider.id"
                                :value="provider.id"
                            >
                                {{ provider.label
                                }}{{
                                    provider.configured
                                        ? ''
                                        : ' (not connected)'
                                }}
                            </option>
                        </NativeSelect>
                        <InputError
                            :message="form.errors.alternative_provider"
                        />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="studio-call-limit"
                            >Model calls per run</Label
                        >
                        <Input
                            id="studio-call-limit"
                            v-model.number="form.max_model_calls"
                            type="number"
                        />
                        <p class="text-xs text-muted-foreground">
                            New production uses 3–8 model calls.
                        </p>
                        <InputError :message="form.errors.max_model_calls" />
                    </div>
                    <div class="space-y-2">
                        <Label for="studio-voice-limit"
                            >Voice characters per run</Label
                        >
                        <Input
                            id="studio-voice-limit"
                            v-model.number="form.max_voice_characters"
                            type="number"
                        />
                        <InputError
                            :message="form.errors.max_voice_characters"
                        />
                    </div>
                </div>
                <div class="space-y-2">
                    <Label for="studio-voice">Voice ID</Label>
                    <Input
                        id="studio-voice"
                        v-model="form.voice_id"
                        placeholder="Default production voice"
                    />
                    <InputError :message="form.errors.voice_id" />
                </div>

                <InputError
                    :message="form.errors.enabled || error"
                    role="alert"
                />

                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" type="button">Cancel</Button>
                    </DialogClose>
                    <Button type="submit" :loading="form.processing"
                        >Save settings</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
