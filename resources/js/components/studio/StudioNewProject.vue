<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import type { Format } from '@/components/studio/studio';
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
import { store } from '@/routes/app/studio';

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ created: [id: number, storyboard: boolean] }>();
const form = useHttp<
    {
        title: string;
        brief: string;
        format: Format;
        review_storyboard: boolean;
    },
    { project_id: number }
>({
    title: '',
    brief: '',
    format: 'reel',
    review_storyboard: false,
});
const error = ref('');
const requestError = computed(
    () =>
        (form.errors as Record<string, string | undefined>).run_id ||
        error.value,
);

watch(open, (visible) => {
    if (!visible) return;
    form.clearErrors();
    error.value = '';
});

const submit = async (): Promise<void> => {
    if (form.processing) return;
    error.value = '';
    try {
        const storyboard = form.review_storyboard;
        const result = await form.post(store.url());
        if (!result) return;
        open.value = false;
        form.reset();
        emit('created', result.project_id, storyboard);
    } catch {
        if (!Object.keys(form.errors).length)
            error.value = 'Couldn’t queue the project. Try again.';
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>New Studio project</DialogTitle>
                <DialogDescription
                    >Give the Studio a starting point for two treatments and a
                    private review cut.</DialogDescription
                >
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="project-brief">Creative brief</Label>
                    <Textarea
                        id="project-brief"
                        :maxlength="5000"
                        v-model="form.brief"
                        rows="5"
                        placeholder="Describe the audience, topic, tone, or a question worth exploring."
                    />
                    <InputError :message="form.errors.brief" />
                </div>
                <div class="space-y-2">
                    <Label for="project-title"
                        >Working title
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></Label
                    >
                    <Input
                        id="project-title"
                        v-model="form.title"
                        :maxlength="160"
                    />
                    <InputError :message="form.errors.title" />
                </div>
                <div class="space-y-2">
                    <Label for="project-format">Format</Label>
                    <NativeSelect
                        id="project-format"
                        v-model="form.format"
                        class="w-full"
                    >
                        <option value="reel">Reel</option>
                    </NativeSelect>
                    <InputError :message="form.errors.format" />
                </div>
                <div
                    class="flex items-start justify-between gap-4 rounded-lg border bg-muted/30 p-3"
                >
                    <div class="space-y-1">
                        <Label for="project-storyboard"
                            >Review the shot plan before rendering</Label
                        >
                        <p
                            id="project-storyboard-description"
                            class="text-xs text-muted-foreground"
                        >
                            Compare two treatments and choose which one to
                            render.
                        </p>
                        <InputError :message="form.errors.review_storyboard" />
                    </div>
                    <Switch
                        id="project-storyboard"
                        v-model="form.review_storyboard"
                        aria-describedby="project-storyboard-description"
                    />
                </div>
                <InputError :message="requestError" role="alert" />

                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" type="button">Cancel</Button>
                    </DialogClose>
                    <Button type="submit" :loading="form.processing">{{
                        form.review_storyboard
                            ? 'Plan storyboard'
                            : 'Start production'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
