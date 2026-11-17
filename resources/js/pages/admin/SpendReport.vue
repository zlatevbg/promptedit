<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Check, TriangleAlert } from '@lucide/vue';
import SpendReportController from '@/actions/App/Http/Controllers/Admin/SpendReportController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { spendReport } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Spend report', href: spendReport() }],
    },
});

type Amounts = {
    generations: number;
    charged_nanos: number;
    provider_cost_nanos: number;
    profit_nanos: number;
};

type UserRow = Amounts & { user_id: number; email: string };

type Day = Amounts & {
    date: string;
    users: UserRow[];
    pixelforge_cost_nanos: number | null;
};

defineProps<{
    from: string;
    to: string;
    isPixelforgeAvailable: boolean;
    days: Day[];
    totals: Amounts & { pixelforge_cost_nanos: number | null };
}>();
</script>

<template>
    <Head title="Spend report" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Spend report</h1>
            <p class="text-sm text-muted-foreground">
                Succeeded generations per UTC day of completion, the day
                Pixelforge bills on. "Pixelforge" is what GET /v1/usage reports
                for this app's API key; it should equal our provider cost.
            </p>
        </div>

        <Form
            v-bind="SpendReportController.form()"
            class="flex flex-wrap items-end gap-3"
            v-slot="{ errors }"
        >
            <div class="grid gap-1">
                <Label for="from">From</Label>
                <Input
                    id="from"
                    name="from"
                    type="date"
                    :default-value="from"
                />
                <InputError :message="errors.from" />
            </div>
            <div class="grid gap-1">
                <Label for="to">To</Label>
                <Input id="to" name="to" type="date" :default-value="to" />
                <InputError :message="errors.to" />
            </div>
            <Button type="submit" variant="outline">Show</Button>
        </Form>

        <p v-if="!isPixelforgeAvailable" class="text-sm text-destructive">
            Pixelforge usage could not be loaded, so costs can't be reconciled
            right now.
        </p>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="p-3">Day / user</th>
                        <th class="p-3 text-right">Generations</th>
                        <th class="p-3 text-right">Credit spend</th>
                        <th class="p-3 text-right">Provider cost</th>
                        <th class="p-3 text-right">Profit</th>
                        <th class="p-3 text-right">Pixelforge</th>
                    </tr>
                </thead>
                <tbody v-for="day in days" :key="day.date" class="border-t">
                    <tr class="font-medium">
                        <td class="p-3">{{ day.date }}</td>
                        <td class="p-3 text-right">{{ day.generations }}</td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(day.charged_nanos)"
                        >
                            {{ formatUsd(day.charged_nanos) }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(day.provider_cost_nanos)"
                        >
                            {{ formatUsd(day.provider_cost_nanos) }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(day.profit_nanos)"
                        >
                            {{ formatUsd(day.profit_nanos) }}
                        </td>
                        <td class="p-3 text-right">
                            <span
                                v-if="day.pixelforge_cost_nanos !== null"
                                class="inline-flex items-center gap-1"
                                :class="
                                    day.pixelforge_cost_nanos ===
                                    day.provider_cost_nanos
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-destructive'
                                "
                                :title="
                                    formatUsdExact(day.pixelforge_cost_nanos)
                                "
                            >
                                <Check
                                    v-if="
                                        day.pixelforge_cost_nanos ===
                                        day.provider_cost_nanos
                                    "
                                    class="size-4"
                                />
                                <TriangleAlert v-else class="size-4" />
                                {{ formatUsd(day.pixelforge_cost_nanos) }}
                            </span>
                            <span v-else class="text-muted-foreground">–</span>
                        </td>
                    </tr>
                    <tr
                        v-for="row in day.users"
                        :key="row.user_id"
                        class="text-muted-foreground"
                    >
                        <td class="p-3 pl-8">{{ row.email }}</td>
                        <td class="p-3 text-right">{{ row.generations }}</td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(row.charged_nanos)"
                        >
                            {{ formatUsd(row.charged_nanos) }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(row.provider_cost_nanos)"
                        >
                            {{ formatUsd(row.provider_cost_nanos) }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(row.profit_nanos)"
                        >
                            {{ formatUsd(row.profit_nanos) }}
                        </td>
                        <td />
                    </tr>
                </tbody>
                <tbody v-if="days.length === 0">
                    <tr>
                        <td
                            colspan="6"
                            class="p-6 text-center text-muted-foreground"
                        >
                            No billed generations in this range.
                        </td>
                    </tr>
                </tbody>
                <tfoot class="border-t bg-muted font-semibold">
                    <tr>
                        <td class="p-3">Total</td>
                        <td class="p-3 text-right">{{ totals.generations }}</td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(totals.charged_nanos)"
                        >
                            {{ formatUsd(totals.charged_nanos) }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(totals.provider_cost_nanos)"
                        >
                            {{ formatUsd(totals.provider_cost_nanos) }}
                        </td>
                        <td
                            class="p-3 text-right"
                            :title="formatUsdExact(totals.profit_nanos)"
                        >
                            {{ formatUsd(totals.profit_nanos) }}
                        </td>
                        <td class="p-3 text-right">
                            <span
                                v-if="totals.pixelforge_cost_nanos !== null"
                                :title="
                                    formatUsdExact(totals.pixelforge_cost_nanos)
                                "
                            >
                                {{ formatUsd(totals.pixelforge_cost_nanos) }}
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
