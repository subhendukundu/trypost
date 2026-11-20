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
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/app/studio';

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ created: [id: number] }>();
const form = useHttp<
    { title: string; brief: string; format: Format },
    { project_id: number }
>({
    title: '',
    brief: '',
    format: 'reel',
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
        const result = await form.post(store.url());
        open.value = false;
        form.reset();
        emit('created', result.project_id);
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
                <InputError :message="requestError" role="alert" />

                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" type="button">Cancel</Button>
                    </DialogClose>
                    <Button type="submit" :loading="form.processing"
                        >Start production</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
