<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { IN_FLIGHT_STATUSES } from '@/types/generation';
import type { Generation, GenerationStatus } from '@/types/generation';

const props = defineProps<{ generation: Generation }>();

const statusLabels: Record<GenerationStatus, string> = {
    pending: 'Waiting for Pixelforge',
    queued: 'Queued',
    processing: 'Generating',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

const isInFlight = computed(() =>
    IN_FLIGHT_STATUSES.includes(props.generation.status),
);

const aspectClass = computed(
    () =>
        ({
            '1024x1024': 'aspect-square',
            '1536x1024': 'aspect-[3/2]',
            '1024x1536': 'aspect-[2/3]',
        })[props.generation.size],
);

const badgeVariant = computed(() => {
    switch (props.generation.status) {
        case 'succeeded':
            return 'default';
        case 'failed':
            return 'destructive';
        default:
            return 'secondary';
    }
});
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
    >
        <div
            :class="aspectClass"
            class="flex max-h-80 w-full items-center justify-center bg-muted"
        >
            <img
                v-if="generation.image_url"
                :src="generation.image_url"
                :alt="generation.prompt"
                class="h-full w-full object-contain"
                loading="lazy"
            />
            <p
                v-else-if="generation.is_image_expired"
                class="px-4 text-center text-sm text-muted-foreground"
            >
                Image link expired (Pixelforge keeps images for 24 hours).
            </p>
            <div
                v-else-if="isInFlight"
                class="flex flex-col items-center gap-2 text-sm text-muted-foreground"
            >
                <Spinner />
                <span v-if="generation.status === 'pending'">
                    Pixelforge is busy, retrying automatically…
                </span>
                <span v-else>{{ statusLabels[generation.status] }}…</span>
            </div>
            <p
                v-else-if="generation.status === 'failed'"
                class="px-4 text-center text-sm text-destructive"
            >
                {{ generation.error_message ?? 'Generation failed.' }}
            </p>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <Badge :variant="badgeVariant">
                    {{ statusLabels[generation.status] }}
                </Badge>
                <span class="text-xs text-muted-foreground">
                    {{ generation.quality }} · {{ generation.size }}
                </span>
            </div>

            <p class="line-clamp-3 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <dl
                class="mt-auto grid grid-cols-2 gap-2 border-t pt-3 text-xs text-muted-foreground"
            >
                <div>
                    <dt>Estimated</dt>
                    <dd
                        class="font-medium text-foreground"
                        :title="
                            formatUsdExact(generation.estimated_charge_nanos)
                        "
                    >
                        ≈ {{ formatUsd(generation.estimated_charge_nanos) }}
                    </dd>
                </div>
                <div>
                    <dt>Charged</dt>
                    <dd
                        v-if="generation.charge_nanos !== null"
                        class="font-medium text-foreground"
                        :title="formatUsdExact(generation.charge_nanos)"
                    >
                        {{ formatUsd(generation.charge_nanos) }}
                        <span
                            v-if="generation.status === 'failed'"
                            class="font-normal text-muted-foreground"
                            >(failed, free)</span
                        >
                    </dd>
                    <dd
                        v-else
                        :title="formatUsdExact(generation.max_charge_nanos)"
                    >
                        Up to {{ formatUsd(generation.max_charge_nanos) }} held
                    </dd>
                </div>
            </dl>
        </div>
    </article>
</template>
