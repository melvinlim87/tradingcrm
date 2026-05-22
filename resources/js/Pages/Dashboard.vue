<script setup>
import { onMounted, onBeforeUnmount, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    overall: {
        type: Object,
        default: () => ({
            account_count: 0,
            balance: 0,
            equity: 0,
            margin: 0,
            free_margin: 0,
            floating_pnl: 0,
            floating_pct: 0,
            closed_profit: 0,
            max_drawdown: 0,
        }),
    },
});

// Track active tab per account (default 'open')
const activeTab = reactive({});
const tabOf = (id) => activeTab[id] || 'open';
const setTab = (id, tab) => { activeTab[id] = tab; };

const fmt = (v, digits = 2) =>
    Number(v ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });

const pctClass = (v) =>
    Number(v) > 0
        ? 'text-green-600'
        : Number(v) < 0
            ? 'text-red-600'
            : 'text-gray-700';

const statusBadge = (status) => ({
    online: 'bg-green-100 text-green-700',
    offline: 'bg-gray-100 text-gray-600',
    disabled: 'bg-red-100 text-red-700',
}[status] || 'bg-gray-100 text-gray-600');

const accountFloatingPct = (acc) => {
    const bal = Number(acc.balance);
    if (!bal) return 0;
    return ((Number(acc.floating_pnl) / bal) * 100);
};

const refresh = () => {
    router.reload({ only: ['accounts', 'overall'], preserveScroll: true });
};

let timer;
onMounted(() => { timer = setInterval(refresh, 10000); });
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">
                    Enterprise Portfolio Monitoring
                </h2>
                <button
                    type="button"
                    @click="refresh"
                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    ↻ Refresh
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- ===== OVERALL ===== -->
                <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">Overall Portfolio Performance</h3>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Summed across {{ overall.account_count }} {{ overall.account_count === 1 ? 'account' : 'accounts' }} · refreshes every 10s
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-px bg-gray-200 md:grid-cols-5">
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Profit (Closed)</p>
                            <p class="mt-1 font-mono text-2xl font-semibold" :class="pctClass(overall.closed_profit)">
                                {{ fmt(overall.closed_profit) }}
                            </p>
                        </div>
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Balance</p>
                            <p class="mt-1 font-mono text-2xl font-semibold text-gray-900">
                                {{ fmt(overall.balance) }}
                            </p>
                        </div>
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Equity</p>
                            <p class="mt-1 font-mono text-2xl font-semibold text-gray-900">
                                {{ fmt(overall.equity) }}
                            </p>
                        </div>
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Floating $</p>
                            <p class="mt-1 font-mono text-2xl font-semibold" :class="pctClass(overall.floating_pnl)">
                                {{ fmt(overall.floating_pnl) }}
                            </p>
                        </div>
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Floating %</p>
                            <p class="mt-1 font-mono text-2xl font-semibold" :class="pctClass(overall.floating_pct)">
                                {{ fmt(overall.floating_pct, 2) }}%
                            </p>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 bg-gray-50 px-6 py-3 text-xs text-gray-500">
                        Worst current drawdown:
                        <span class="ml-1 font-mono font-medium" :class="overall.max_drawdown > 0 ? 'text-red-600' : 'text-gray-700'">
                            {{ fmt(overall.max_drawdown, 2) }}%
                        </span>
                        · Margin used:
                        <span class="ml-1 font-mono font-medium text-gray-700">{{ fmt(overall.margin) }}</span>
                        · Free margin:
                        <span class="ml-1 font-mono font-medium text-gray-700">{{ fmt(overall.free_margin) }}</span>
                    </div>
                </section>

                <!-- ===== INDIVIDUAL ACCOUNTS ===== -->
                <section>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">Individual Account Performance</h3>
                        <Link
                            :href="route('accounts.index')"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            Manage Accounts →
                        </Link>
                    </div>

                    <div v-if="accounts.length === 0" class="rounded-lg border-2 border-dashed border-gray-300 bg-white p-12 text-center">
                        <p class="text-sm text-gray-600">
                            No MT5 accounts registered yet.
                        </p>
                        <Link
                            :href="route('accounts.index')"
                            class="mt-3 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            + Add Your First Account
                        </Link>
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="acc in accounts"
                            :key="acc.id"
                            class="overflow-hidden bg-white shadow-sm sm:rounded-lg"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-6 py-3">
                                <div class="flex items-baseline gap-3">
                                    <span class="font-mono text-base font-semibold text-gray-900">
                                        #{{ acc.account_number }}
                                    </span>
                                    <span v-if="acc.nickname" class="text-sm text-gray-600">
                                        {{ acc.nickname }}
                                    </span>
                                    <span class="text-xs text-gray-400">·</span>
                                    <span class="text-sm text-gray-500">{{ acc.broker }}</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span
                                        :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusBadge(acc.status)]"
                                    >
                                        {{ acc.status }}
                                    </span>
                                    <span class="text-xs text-gray-400" v-if="acc.last_ping_at">
                                        last ping: {{ new Date(acc.last_ping_at).toLocaleTimeString() }}
                                    </span>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-px bg-gray-200 md:grid-cols-5">
                                <div class="bg-white px-6 py-4">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Balance</p>
                                    <p class="mt-1 font-mono text-lg font-semibold text-gray-900">
                                        {{ fmt(acc.balance) }}
                                    </p>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Equity</p>
                                    <p class="mt-1 font-mono text-lg font-semibold text-gray-900">
                                        {{ fmt(acc.equity) }}
                                    </p>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Floating %</p>
                                    <p class="mt-1 font-mono text-lg font-semibold" :class="pctClass(accountFloatingPct(acc))">
                                        {{ fmt(accountFloatingPct(acc), 2) }}%
                                    </p>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Floating $</p>
                                    <p class="mt-1 font-mono text-lg font-semibold" :class="pctClass(acc.floating_pnl)">
                                        {{ fmt(acc.floating_pnl) }}
                                    </p>
                                </div>
                                <div class="bg-white px-6 py-4">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">DD %</p>
                                    <p class="mt-1 font-mono text-lg font-semibold"
                                       :class="Number(acc.drawdown_percent) > Number(acc.drawdown_alert_threshold) ? 'text-red-600' : 'text-gray-700'">
                                        {{ fmt(acc.drawdown_percent, 2) }}%
                                        <span class="ml-1 text-xs font-normal text-gray-400">
                                            / alert ≥ {{ fmt(acc.drawdown_alert_threshold, 2) }}%
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <!-- Tabs -->
                            <div class="border-t border-gray-200">
                                <nav class="flex space-x-1 bg-gray-50 px-4 pt-2">
                                    <button
                                        v-for="tab in ['open', 'pending', 'history']"
                                        :key="tab"
                                        @click="setTab(acc.id, tab)"
                                        :class="[
                                            'rounded-t-md px-4 py-2 text-xs font-medium',
                                            tabOf(acc.id) === tab
                                                ? 'border border-b-0 border-gray-200 bg-white text-gray-900'
                                                : 'text-gray-500 hover:text-gray-700'
                                        ]"
                                    >
                                        {{ tab === 'open' ? 'Current' : tab === 'pending' ? 'Pending' : 'History' }}
                                        <span class="ml-1 rounded-full bg-gray-200 px-1.5 py-0.5 text-[10px] text-gray-700">
                                            {{ tab === 'open' ? (acc.open_orders?.length || 0)
                                              : tab === 'pending' ? (acc.pending_orders?.length || 0)
                                              : (acc.history_orders?.length || 0) }}
                                        </span>
                                    </button>
                                </nav>

                                <!-- Open Orders -->
                                <div v-if="tabOf(acc.id) === 'open'" class="overflow-x-auto">
                                    <table v-if="acc.open_orders?.length" class="min-w-full divide-y divide-gray-200 text-xs">
                                        <thead class="bg-gray-50 text-left text-[10px] uppercase tracking-wider text-gray-500">
                                            <tr>
                                                <th class="px-4 py-2">Ticket</th>
                                                <th class="px-4 py-2">Symbol</th>
                                                <th class="px-4 py-2">Type</th>
                                                <th class="px-4 py-2 text-right">Volume</th>
                                                <th class="px-4 py-2 text-right">Open</th>
                                                <th class="px-4 py-2 text-right">Current</th>
                                                <th class="px-4 py-2 text-right">SL</th>
                                                <th class="px-4 py-2 text-right">TP</th>
                                                <th class="px-4 py-2 text-right">PnL</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            <tr v-for="o in acc.open_orders" :key="o.id">
                                                <td class="px-4 py-2 font-mono">#{{ o.ticket }}</td>
                                                <td class="px-4 py-2 font-mono">{{ o.symbol }}</td>
                                                <td class="px-4 py-2">
                                                    <span :class="o.type === 'buy' ? 'text-green-600' : 'text-red-600'" class="font-semibold uppercase">
                                                        {{ o.type }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-2 text-right font-mono">{{ fmt(o.volume, 2) }}</td>
                                                <td class="px-4 py-2 text-right font-mono">{{ Number(o.open_price).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono">{{ Number(o.current_price).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-gray-500">{{ Number(o.sl).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-gray-500">{{ Number(o.tp).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono font-semibold" :class="pctClass(o.pnl)">{{ fmt(o.pnl) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="px-6 py-4 text-xs text-gray-500">No open positions.</p>
                                </div>

                                <!-- Pending Orders -->
                                <div v-else-if="tabOf(acc.id) === 'pending'" class="overflow-x-auto">
                                    <table v-if="acc.pending_orders?.length" class="min-w-full divide-y divide-gray-200 text-xs">
                                        <thead class="bg-gray-50 text-left text-[10px] uppercase tracking-wider text-gray-500">
                                            <tr>
                                                <th class="px-4 py-2">Ticket</th>
                                                <th class="px-4 py-2">Symbol</th>
                                                <th class="px-4 py-2">Type</th>
                                                <th class="px-4 py-2 text-right">Volume</th>
                                                <th class="px-4 py-2 text-right">Entry</th>
                                                <th class="px-4 py-2 text-right">SL</th>
                                                <th class="px-4 py-2 text-right">TP</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            <tr v-for="o in acc.pending_orders" :key="o.id">
                                                <td class="px-4 py-2 font-mono">#{{ o.ticket }}</td>
                                                <td class="px-4 py-2 font-mono">{{ o.symbol }}</td>
                                                <td class="px-4 py-2 uppercase">{{ o.type }}</td>
                                                <td class="px-4 py-2 text-right font-mono">{{ fmt(o.volume, 2) }}</td>
                                                <td class="px-4 py-2 text-right font-mono">{{ Number(o.entry).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-gray-500">{{ Number(o.sl).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-gray-500">{{ Number(o.tp).toFixed(5) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="px-6 py-4 text-xs text-gray-500">No pending orders.</p>
                                </div>

                                <!-- History -->
                                <div v-else class="overflow-x-auto">
                                    <table v-if="acc.history_orders?.length" class="min-w-full divide-y divide-gray-200 text-xs">
                                        <thead class="bg-gray-50 text-left text-[10px] uppercase tracking-wider text-gray-500">
                                            <tr>
                                                <th class="px-4 py-2">Ticket</th>
                                                <th class="px-4 py-2">Symbol</th>
                                                <th class="px-4 py-2">Type</th>
                                                <th class="px-4 py-2 text-right">Volume</th>
                                                <th class="px-4 py-2 text-right">Open</th>
                                                <th class="px-4 py-2 text-right">Close</th>
                                                <th class="px-4 py-2 text-right">PnL</th>
                                                <th class="px-4 py-2">Closed At</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            <tr v-for="o in acc.history_orders" :key="o.id">
                                                <td class="px-4 py-2 font-mono">#{{ o.ticket }}</td>
                                                <td class="px-4 py-2 font-mono">{{ o.symbol }}</td>
                                                <td class="px-4 py-2">
                                                    <span :class="o.type === 'buy' ? 'text-green-600' : 'text-red-600'" class="font-semibold uppercase">
                                                        {{ o.type }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-2 text-right font-mono">{{ fmt(o.volume, 2) }}</td>
                                                <td class="px-4 py-2 text-right font-mono">{{ Number(o.open_price).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono">{{ Number(o.close_price).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono font-semibold" :class="pctClass(o.pnl)">{{ fmt(o.pnl) }}</td>
                                                <td class="px-4 py-2 text-gray-500">
                                                    {{ o.closed_at ? new Date(o.closed_at).toLocaleString() : '—' }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="px-6 py-4 text-xs text-gray-500">No closed trades yet.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
