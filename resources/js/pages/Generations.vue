<script setup lang="ts">
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import CreditBalance from '@/components/CreditBalance.vue';
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { countCharacters, estimateCost } from '@/lib/estimate';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { index } from '@/routes/generations';
import { IN_FLIGHT_STATUSES } from '@/types/generation';
import type {
    Generation,
    Paginated,
    Pricing,
    Quality,
    Size,
} from '@/types/generation';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: index() }],
    },
});

const props = defineProps<{
    pricing: Pricing;
    markupPercent: number;
    limits: { maxPromptLength: number; maxReferenceImages: number };
    creditPacks: number[];
    generations: Paginated<Generation>;
}>();

const qualities: Quality[] = ['low', 'medium', 'high'];
const sizes: { value: Size; label: string }[] = [
    { value: '1024x1024', label: 'Square' },
    { value: '1536x1024', label: 'Landscape' },
    { value: '1024x1536', label: 'Portrait' },
];
const POLL_INTERVAL_MS = 3000;

const page = usePage();
const balance = computed(() => page.props.auth.user.balance_nanos);

const form = useForm({
    prompt: '',
    size: '1024x1024' as Size,
    quality: 'medium' as Quality,
    reference_images: [] as string[],
});

const filledReferenceImages = computed(() =>
    form.reference_images.map((url) => url.trim()).filter(Boolean),
);

// Errors for individual URLs come back keyed "reference_images.N".
const referenceImageErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([field]) => field.startsWith('reference_images'))
        .map(([, message]) => message),
);

const canAddReferenceImage = computed(
    () => form.reference_images.length < props.limits.maxReferenceImages,
);

// The server trims input (TrimStrings), so count and price the prompt the same way.
const trimmedPrompt = computed(() => form.prompt.trim());

const promptLength = computed(() => countCharacters(trimmedPrompt.value));

const estimate = computed(() =>
    estimateCost(
        props.pricing,
        props.markupPercent,
        trimmedPrompt.value,
        form.size,
        form.quality,
        filledReferenceImages.value.length,
    ),
);

const canAfford = computed(() => balance.value >= estimate.value.maxNanos);

function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: filledReferenceImages.value,
    })).submit(GenerationController.store(), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}

// Only poll while something is still in flight.
const { start, stop } = usePoll(
    POLL_INTERVAL_MS,
    { only: ['generations', 'auth'] },
    { autoStart: false },
);

watch(
    () =>
        props.generations.data.some((generation) =>
            IN_FLIGHT_STATUSES.includes(generation.status),
        ),
    (hasInFlight) => (hasInFlight ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head title="Generate" />

    <div class="grid gap-6 p-4 lg:grid-cols-[minmax(0,26rem)_1fr]">
        <section class="flex flex-col gap-4 lg:sticky lg:top-4 lg:self-start">
            <CreditBalance :packs="creditPacks" />

            <form
                class="flex flex-col gap-5 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span
                            class="text-xs"
                            :class="
                                promptLength > limits.maxPromptLength
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ promptLength }} / {{ limits.maxPromptLength }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="4"
                        required
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <Button
                            v-for="quality in qualities"
                            :key="quality"
                            type="button"
                            class="capitalize"
                            :variant="
                                form.quality === quality ? 'default' : 'outline'
                            "
                            :aria-pressed="form.quality === quality"
                            @click="form.quality = quality"
                        >
                            {{ quality }}
                        </Button>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <Button
                            v-for="size in sizes"
                            :key="size.value"
                            type="button"
                            :variant="
                                form.size === size.value ? 'default' : 'outline'
                            "
                            :aria-pressed="form.size === size.value"
                            :title="size.value"
                            @click="form.size = size.value"
                        >
                            {{ size.label }}
                        </Button>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <div class="grid gap-2">
                    <Label>Reference images (optional)</Label>
                    <div
                        v-for="(url, position) in form.reference_images"
                        :key="position"
                        class="flex gap-2"
                    >
                        <Input
                            :model-value="url"
                            @update:model-value="
                                form.reference_images[position] = String($event)
                            "
                            type="url"
                            placeholder="Image URL, e.g. example.com/style.png"
                            :aria-label="
                                'Reference image URL ' + (position + 1)
                            "
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Remove reference image"
                            @click="form.reference_images.splice(position, 1)"
                        >
                            <X />
                        </Button>
                    </div>
                    <Button
                        v-if="canAddReferenceImage"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="justify-self-start"
                        @click="form.reference_images.push('')"
                    >
                        <Plus /> Add reference image
                    </Button>
                    <InputError
                        v-for="message in referenceImageErrors"
                        :key="message"
                        :message="message"
                    />
                </div>

                <div class="rounded-lg bg-muted p-3 text-sm">
                    <div class="flex items-baseline justify-between">
                        <span>Estimated cost</span>
                        <span
                            class="text-lg font-semibold"
                            :title="formatUsdExact(estimate.expectedNanos)"
                        >
                            ≈ {{ formatUsd(estimate.expectedNanos) }}
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        At most
                        <span :title="formatUsdExact(estimate.maxNanos)">{{
                            formatUsd(estimate.maxNanos)
                        }}</span
                        >, held from your credits while it runs. You pay the
                        actual cost once it finishes; failed generations are
                        free.
                    </p>
                </div>

                <InputError :message="page.props.errors.credits" />
                <p v-if="!canAfford" class="text-sm text-destructive">
                    Not enough credits for this generation. Buy credits above.
                </p>

                <Button
                    type="submit"
                    :disabled="form.processing || !canAfford || !trimmedPrompt"
                >
                    Generate
                </Button>
            </form>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold">Your generations</h2>

            <p
                v-if="generations.data.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nothing yet. Write a prompt and press Generate.
            </p>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>

            <nav
                v-if="generations.meta.last_page > 1"
                class="flex items-center justify-between text-sm"
            >
                <Link
                    v-if="generations.links.prev"
                    :href="generations.links.prev"
                    preserve-scroll
                    class="underline"
                    >Newer</Link
                >
                <span v-else />
                <span class="text-muted-foreground">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>
                <Link
                    v-if="generations.links.next"
                    :href="generations.links.next"
                    preserve-scroll
                    class="underline"
                    >Older</Link
                >
                <span v-else />
            </nav>
        </section>
    </div>
</template>
