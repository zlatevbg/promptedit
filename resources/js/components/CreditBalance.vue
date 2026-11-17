<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CreditPurchaseController from '@/actions/App/Http/Controllers/CreditPurchaseController';
import { Button } from '@/components/ui/button';
import { formatUsd, formatUsdExact } from '@/lib/money';

defineProps<{ packs: number[] }>();

const page = usePage();
const balance = computed(() => page.props.auth.user.balance_nanos);
const isBuying = ref(false);

function buy(pack: number): void {
    router.post(
        CreditPurchaseController.store.url(),
        { pack },
        {
            preserveScroll: true,
            onStart: () => (isBuying.value = true),
            onFinish: () => (isBuying.value = false),
        },
    );
}
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
    >
        <div>
            <p class="text-xs text-muted-foreground">Available credits</p>
            <p class="text-2xl font-semibold" :title="formatUsdExact(balance)">
                {{ formatUsd(balance) }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-muted-foreground">Buy credits</span>
            <Button
                v-for="pack in packs"
                :key="pack"
                variant="outline"
                size="sm"
                :disabled="isBuying"
                @click="buy(pack)"
            >
                +${{ pack }}
            </Button>
        </div>
    </div>
</template>
