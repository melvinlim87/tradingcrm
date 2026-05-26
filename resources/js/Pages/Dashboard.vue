<script setup>
import { onMounted, onBeforeUnmount, reactive, ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    overall: {
        type: Object,
        default: () => ({
            account_count: 0,
            balance: 0, equity: 0, margin: 0, free_margin: 0,
            floating_pnl: 0, floating_pct: 0, closed_profit: 0,
            max_drawdown: 0, max_abs_drawdown_pct: 0, max_eq_drawdown_pct: 0,
            equity_series: [],
        }),
    },
    viewer_role: { type: String, default: 'user' },
});

const isAdministrator = computed(() => props.viewer_role === 'administrator');

// ───────────────── helpers ─────────────────
const fmt = (v, digits = 2) =>
    Number(v ?? 0).toLocaleString(undefined, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });

const pctClass = (v) =>
    Number(v) > 0 ? 'text-green-600'
    : Number(v) < 0 ? 'text-red-600'
    : 'text-black';

const statusBadge = (status) => ({
    online: 'bg-green-100 text-green-700',
    offline: 'bg-gray-100 text-black',
    disabled: 'bg-red-100 text-red-700',
}[status] || 'bg-gray-100 text-black');

const accountFloatingPct = (acc) => {
    const bal = Number(acc.balance);
    if (!bal) return 0;
    return ((Number(acc.floating_pnl) / bal) * 100);
};

// ───────────────── sparkline (SVG line chart) ─────────────────
const sparkPath = (series, width = 260, height = 50) => {
    const data = (series || []).map((p) => Number(p.v));
    if (data.length < 2) return { d: '', last: 0, color: '#000' };
    const min = Math.min(...data);
    const max = Math.max(...data);
    const range = max - min || 1;
    const step = width / (data.length - 1);
    const points = data.map((v, i) => {
        const x = (i * step).toFixed(1);
        const y = (height - ((v - min) / range) * height).toFixed(1);
        return `${x},${y}`;
    });
    const last = data[data.length - 1];
    const first = data[0];
    const color = last >= first ? '#16a34a' : '#dc2626';
    return { d: `M ${points.join(' L ')}`, last, color };
};

// ───────────────── per-account tabs ─────────────────
const activeTab = reactive({});
const tabOf = (id) => activeTab[id] || 'open';
const setTab = (id, tab) => { activeTab[id] = tab; };

// ───────────────── per-account symbol filter + sort ─────────────────
const filterSymbol = reactive({});      // account_id → filter string
const sortKey = reactive({});           // account_id → 'symbol' | 'pnl' | 'volume' | 'time'
const sortDir = reactive({});           // account_id → 'asc' | 'desc'

const setFilter = (accId, val) => { filterSymbol[accId] = val; };
const setSort = (accId, key) => {
    if (sortKey[accId] === key) {
        sortDir[accId] = sortDir[accId] === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey[accId] = key;
        sortDir[accId] = key === 'symbol' ? 'asc' : 'desc';
    }
};

const filteredAndSorted = (rows, accId, timeField) => {
    const filter = (filterSymbol[accId] || '').toUpperCase().trim();
    let out = (rows || []).filter((r) =>
        filter === '' || (r.symbol || '').toUpperCase().includes(filter),
    );
    const key = sortKey[accId];
    if (key) {
        const dir = sortDir[accId] === 'asc' ? 1 : -1;
        out = [...out].sort((a, b) => {
            const av = key === 'time' ? new Date(a[timeField] || 0).getTime()
                     : key === 'symbol' ? (a.symbol || '')
                     : Number(a[key] ?? 0);
            const bv = key === 'time' ? new Date(b[timeField] || 0).getTime()
                     : key === 'symbol' ? (b.symbol || '')
                     : Number(b[key] ?? 0);
            if (av < bv) return -1 * dir;
            if (av > bv) return  1 * dir;
            return 0;
        });
    }
    return out;
};

// Unique symbols across this account's open + pending + history (for filter chips)
const accountSymbols = (acc) => {
    const set = new Set();
    [...(acc.open_orders || []), ...(acc.pending_orders || []), ...(acc.history_orders || [])]
        .forEach((o) => o.symbol && set.add(o.symbol));
    return Array.from(set).sort();
};

// ───────────────── refresh ─────────────────
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
                <h2 class="text-xl font-bold text-black">Enterprise Portfolio Monitoring</h2>
                <button
                    type="button"
                    @click="refresh"
                    class="rounded-md border-2 border-black bg-white px-3 py-1.5 text-sm font-bold text-black hover:bg-gray-100"
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
                        <div class="flex flex-wrap items-baseline justify-between gap-3">
                            <h3 class="text-lg font-bold text-black">Overall Portfolio Performance</h3>
                            <p class="text-sm text-black">
                                Summed across {{ overall.account_count }} {{ overall.account_count === 1 ? 'account' : 'accounts' }} · refreshes every 10s
                            </p>
                        </div>
                    </div>

                    <!-- Sparkline -->
                    <div v-if="overall.equity_series?.length > 1" class="border-b border-gray-200 bg-gray-50 px-6 py-3">
                        <div class="flex items-center gap-4">
                            <p class="text-sm font-bold text-black whitespace-nowrap">Equity ({{ overall.equity_series.length }} snapshots)</p>
                            <svg :viewBox="`0 0 260 50`" class="h-12 flex-1" preserveAspectRatio="none">
                                <path
                                    :d="sparkPath(overall.equity_series).d"
                                    fill="none"
                                    :stroke="sparkPath(overall.equity_series).color"
                                    stroke-width="2"
                                />
                            </svg>
                            <p class="font-mono text-base font-bold text-black whitespace-nowrap">
                                {{ fmt(sparkPath(overall.equity_series).last) }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-px bg-gray-200 md:grid-cols-5">
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">Profit (Closed)</p>
                            <p class="mt-1 font-mono text-2xl font-bold" :class="pctClass(overall.closed_profit)">
                                {{ fmt(overall.closed_profit) }}
                            </p>
                        </div>
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">Balance</p>
                            <p class="mt-1 font-mono text-2xl font-bold text-black">{{ fmt(overall.balance) }}</p>
                        </div>
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">Equity</p>
                            <p class="mt-1 font-mono text-2xl font-bold text-black">{{ fmt(overall.equity) }}</p>
                        </div>
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">Floating $</p>
                            <p class="mt-1 font-mono text-2xl font-bold" :class="pctClass(overall.floating_pnl)">
                                {{ fmt(overall.floating_pnl) }}
                            </p>
                        </div>
                        <div class="bg-white px-6 py-5">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">Floating %</p>
                            <p class="mt-1 font-mono text-2xl font-bold" :class="pctClass(overall.floating_pct)">
                                {{ fmt(overall.floating_pct, 2) }}%
                            </p>
                        </div>
                    </div>

                    <!-- Drawdown summary row -->
                    <div class="border-t border-gray-200 grid grid-cols-1 gap-px bg-gray-200 md:grid-cols-3">
                        <div class="bg-white px-6 py-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">Current DD%</p>
                            <p class="mt-1 font-mono text-lg font-bold"
                               :class="overall.max_drawdown > 0 ? 'text-red-600' : 'text-black'">
                                {{ fmt(overall.max_drawdown, 2) }}%
                            </p>
                        </div>
                        <div class="bg-white px-6 py-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">Max ABS DD%
                                <span class="font-normal" title="Equity vs initial deposit, never recovers">ⓘ</span>
                            </p>
                            <p class="mt-1 font-mono text-lg font-bold"
                               :class="overall.max_abs_drawdown_pct > 0 ? 'text-red-600' : 'text-black'">
                                {{ fmt(overall.max_abs_drawdown_pct, 2) }}%
                            </p>
                        </div>
                        <div class="bg-white px-6 py-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">Max EQ DD%
                                <span class="font-normal" title="Peak-to-valley equity drop">ⓘ</span>
                            </p>
                            <p class="mt-1 font-mono text-lg font-bold"
                               :class="overall.max_eq_drawdown_pct > 0 ? 'text-red-600' : 'text-black'">
                                {{ fmt(overall.max_eq_drawdown_pct, 2) }}%
                            </p>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 bg-gray-50 px-6 py-2 text-sm text-black">
                        Margin used: <span class="ml-1 font-mono font-bold">{{ fmt(overall.margin) }}</span>
                        · Free margin: <span class="ml-1 font-mono font-bold">{{ fmt(overall.free_margin) }}</span>
                    </div>
                </section>

                <!-- ===== INDIVIDUAL ACCOUNTS ===== -->
                <section>
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-lg font-bold text-black">Individual Account Performance</h3>
                        <Link :href="route('accounts.index')" class="text-sm font-bold text-indigo-600 hover:text-indigo-800">
                            Manage Accounts →
                        </Link>
                    </div>

                    <div v-if="accounts.length === 0" class="rounded-lg border-2 border-dashed border-gray-300 bg-white p-12 text-center">
                        <p class="text-sm text-black">No MT5 accounts registered yet.</p>
                        <Link :href="route('accounts.index')"
                              class="mt-3 inline-block rounded-md bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">
                            + Add Your First Account
                        </Link>
                    </div>

                    <div v-else class="space-y-4">
                        <div v-for="acc in accounts" :key="acc.id" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <!-- Account header -->
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-6 py-3">
                                <div class="flex items-baseline gap-3">
                                    <span class="font-mono text-base font-bold text-black">#{{ acc.account_number }}</span>
                                    <span v-if="acc.account_name" class="text-base font-bold text-black">{{ acc.account_name }}</span>
                                    <span class="text-sm text-black">·</span>
                                    <span class="text-sm text-black">{{ acc.broker }}</span>
                                    <span v-if="isAdministrator && acc.creator" class="ml-2 rounded bg-indigo-50 px-2 py-0.5 text-xs font-bold text-indigo-700">
                                        added by {{ acc.creator.name }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-bold', statusBadge(acc.status)]">
                                        {{ acc.status }}
                                    </span>
                                    <span class="text-xs text-black" v-if="acc.last_ping_at">
                                        last ping: {{ new Date(acc.last_ping_at).toLocaleTimeString() }}
                                    </span>
                                </div>
                            </div>

                            <!-- Sparkline per account -->
                            <div v-if="acc.equity_series?.length > 1" class="border-b border-gray-200 bg-gray-50 px-6 py-2">
                                <div class="flex items-center gap-4">
                                    <p class="text-sm font-bold text-black whitespace-nowrap">Equity</p>
                                    <svg viewBox="0 0 260 40" class="h-10 flex-1" preserveAspectRatio="none">
                                        <path :d="sparkPath(acc.equity_series, 260, 40).d"
                                              fill="none"
                                              :stroke="sparkPath(acc.equity_series, 260, 40).color"
                                              stroke-width="2"/>
                                    </svg>
                                </div>
                            </div>

                            <!-- Metric grid: 7 cards now (added Max ABS / Max EQ) -->
                            <div class="grid grid-cols-2 gap-px bg-gray-200 md:grid-cols-7">
                                <div class="bg-white px-4 py-3">
                                    <p class="text-xs font-bold uppercase text-black">Balance</p>
                                    <p class="mt-1 font-mono text-base font-bold text-black">{{ fmt(acc.balance) }}</p>
                                </div>
                                <div class="bg-white px-4 py-3">
                                    <p class="text-xs font-bold uppercase text-black">Equity</p>
                                    <p class="mt-1 font-mono text-base font-bold text-black">{{ fmt(acc.equity) }}</p>
                                </div>
                                <div class="bg-white px-4 py-3">
                                    <p class="text-xs font-bold uppercase text-black">Floating %</p>
                                    <p class="mt-1 font-mono text-base font-bold" :class="pctClass(accountFloatingPct(acc))">
                                        {{ fmt(accountFloatingPct(acc), 2) }}%
                                    </p>
                                </div>
                                <div class="bg-white px-4 py-3">
                                    <p class="text-xs font-bold uppercase text-black">Floating $</p>
                                    <p class="mt-1 font-mono text-base font-bold" :class="pctClass(acc.floating_pnl)">
                                        {{ fmt(acc.floating_pnl) }}
                                    </p>
                                </div>
                                <div class="bg-white px-4 py-3">
                                    <p class="text-xs font-bold uppercase text-black">DD %</p>
                                    <p class="mt-1 font-mono text-base font-bold"
                                       :class="Number(acc.drawdown_percent) > Number(acc.drawdown_alert_threshold) ? 'text-red-600' : 'text-black'">
                                        {{ fmt(acc.drawdown_percent, 2) }}%
                                    </p>
                                </div>
                                <div class="bg-white px-4 py-3">
                                    <p class="text-xs font-bold uppercase text-black" title="Max equity drop below initial deposit">Max ABS DD%</p>
                                    <p class="mt-1 font-mono text-base font-bold"
                                       :class="Number(acc.max_abs_drawdown_pct) > 0 ? 'text-red-600' : 'text-black'">
                                        {{ fmt(acc.max_abs_drawdown_pct, 2) }}%
                                    </p>
                                </div>
                                <div class="bg-white px-4 py-3">
                                    <p class="text-xs font-bold uppercase text-black" title="Worst peak-to-valley equity drop">Max EQ DD%</p>
                                    <p class="mt-1 font-mono text-base font-bold"
                                       :class="Number(acc.max_eq_drawdown_pct) > 0 ? 'text-red-600' : 'text-black'">
                                        {{ fmt(acc.max_eq_drawdown_pct, 2) }}%
                                    </p>
                                </div>
                            </div>

                            <!-- Tabs -->
                            <div class="border-t border-gray-200">
                                <nav class="flex items-center justify-between bg-gray-50 px-4 pt-2">
                                    <div class="flex space-x-1">
                                        <button v-for="tab in ['open', 'pending', 'history']"
                                            :key="tab"
                                            @click="setTab(acc.id, tab)"
                                            :class="[
                                                'rounded-t-md px-4 py-2 text-sm font-bold',
                                                tabOf(acc.id) === tab
                                                    ? 'border border-b-0 border-gray-300 bg-white text-black'
                                                    : 'text-black hover:bg-gray-100'
                                            ]">
                                            {{ tab === 'open' ? 'Current' : tab === 'pending' ? 'Pending' : 'History' }}
                                            <span class="ml-1 rounded-full bg-black px-1.5 py-0.5 text-xs text-white">
                                                {{ tab === 'open' ? (acc.open_orders?.length || 0)
                                                  : tab === 'pending' ? (acc.pending_orders?.length || 0)
                                                  : (acc.history_orders?.length || 0) }}
                                            </span>
                                        </button>
                                    </div>

                                    <!-- Symbol filter -->
                                    <div class="flex items-center gap-2 py-1">
                                        <select
                                            :value="filterSymbol[acc.id] || ''"
                                            @change="setFilter(acc.id, $event.target.value)"
                                            class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-bold text-black">
                                            <option value="">All symbols</option>
                                            <option v-for="s in accountSymbols(acc)" :key="s" :value="s">{{ s }}</option>
                                        </select>
                                    </div>
                                </nav>

                                <!-- Open Orders -->
                                <div v-if="tabOf(acc.id) === 'open'" class="overflow-x-auto">
                                    <table v-if="(acc.open_orders || []).length" class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-bold uppercase text-black">
                                            <tr>
                                                <th class="px-4 py-2">Ticket</th>
                                                <th class="px-4 py-2 cursor-pointer select-none" @click="setSort(acc.id, 'symbol')">Symbol ⇅</th>
                                                <th class="px-4 py-2">Type</th>
                                                <th class="px-4 py-2 text-right cursor-pointer select-none" @click="setSort(acc.id, 'volume')">Volume ⇅</th>
                                                <th class="px-4 py-2 text-right">Open</th>
                                                <th class="px-4 py-2 text-right">Current</th>
                                                <th class="px-4 py-2 text-right">SL</th>
                                                <th class="px-4 py-2 text-right">TP</th>
                                                <th class="px-4 py-2 text-right cursor-pointer select-none" @click="setSort(acc.id, 'pnl')">PnL ⇅</th>
                                                <th class="px-4 py-2 cursor-pointer select-none" @click="setSort(acc.id, 'time')">Opened At ⇅</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            <tr v-for="o in filteredAndSorted(acc.open_orders, acc.id, 'opened_at')" :key="o.id">
                                                <td class="px-4 py-2 font-mono text-black">#{{ o.ticket }}</td>
                                                <td class="px-4 py-2 font-mono font-bold text-black">{{ o.symbol }}</td>
                                                <td class="px-4 py-2">
                                                    <span :class="o.type === 'buy' ? 'text-green-600' : 'text-red-600'" class="font-bold uppercase">{{ o.type }}</span>
                                                </td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ fmt(o.volume, 2) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.open_price).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.current_price).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.sl).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.tp).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono font-bold" :class="pctClass(o.pnl)">{{ fmt(o.pnl) }}</td>
                                                <td class="px-4 py-2 text-black">{{ o.opened_at ? new Date(o.opened_at).toLocaleString() : '—' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="px-6 py-4 text-sm text-black">No open positions.</p>
                                </div>

                                <!-- Pending Orders -->
                                <div v-else-if="tabOf(acc.id) === 'pending'" class="overflow-x-auto">
                                    <table v-if="(acc.pending_orders || []).length" class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-bold uppercase text-black">
                                            <tr>
                                                <th class="px-4 py-2">Ticket</th>
                                                <th class="px-4 py-2 cursor-pointer select-none" @click="setSort(acc.id, 'symbol')">Symbol ⇅</th>
                                                <th class="px-4 py-2">Type</th>
                                                <th class="px-4 py-2 text-right cursor-pointer select-none" @click="setSort(acc.id, 'volume')">Volume ⇅</th>
                                                <th class="px-4 py-2 text-right">Entry</th>
                                                <th class="px-4 py-2 text-right">SL</th>
                                                <th class="px-4 py-2 text-right">TP</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            <tr v-for="o in filteredAndSorted(acc.pending_orders, acc.id, 'created_ea_at')" :key="o.id">
                                                <td class="px-4 py-2 font-mono text-black">#{{ o.ticket }}</td>
                                                <td class="px-4 py-2 font-mono font-bold text-black">{{ o.symbol }}</td>
                                                <td class="px-4 py-2 font-bold uppercase text-black">{{ o.type }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ fmt(o.volume, 2) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.entry).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.sl).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.tp).toFixed(5) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="px-6 py-4 text-sm text-black">No pending orders.</p>
                                </div>

                                <!-- History (now with Opened At + Closed At) -->
                                <div v-else class="overflow-x-auto">
                                    <table v-if="(acc.history_orders || []).length" class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50 text-left text-xs font-bold uppercase text-black">
                                            <tr>
                                                <th class="px-4 py-2">Ticket</th>
                                                <th class="px-4 py-2 cursor-pointer select-none" @click="setSort(acc.id, 'symbol')">Symbol ⇅</th>
                                                <th class="px-4 py-2">Type</th>
                                                <th class="px-4 py-2 text-right cursor-pointer select-none" @click="setSort(acc.id, 'volume')">Volume ⇅</th>
                                                <th class="px-4 py-2 text-right">Open</th>
                                                <th class="px-4 py-2 text-right">Close</th>
                                                <th class="px-4 py-2 text-right cursor-pointer select-none" @click="setSort(acc.id, 'pnl')">PnL ⇅</th>
                                                <th class="px-4 py-2 cursor-pointer select-none" @click="setSort(acc.id, 'time')">Opened At ⇅</th>
                                                <th class="px-4 py-2">Closed At</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                            <tr v-for="o in filteredAndSorted(acc.history_orders, acc.id, 'closed_at')" :key="o.id">
                                                <td class="px-4 py-2 font-mono text-black">#{{ o.ticket }}</td>
                                                <td class="px-4 py-2 font-mono font-bold text-black">{{ o.symbol }}</td>
                                                <td class="px-4 py-2">
                                                    <span :class="o.type === 'buy' ? 'text-green-600' : 'text-red-600'" class="font-bold uppercase">{{ o.type }}</span>
                                                </td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ fmt(o.volume, 2) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.open_price).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-black">{{ Number(o.close_price).toFixed(5) }}</td>
                                                <td class="px-4 py-2 text-right font-mono font-bold" :class="pctClass(o.pnl)">{{ fmt(o.pnl) }}</td>
                                                <td class="px-4 py-2 text-black">{{ o.opened_at ? new Date(o.opened_at).toLocaleString() : '—' }}</td>
                                                <td class="px-4 py-2 text-black">{{ o.closed_at ? new Date(o.closed_at).toLocaleString() : '—' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="px-6 py-4 text-sm text-black">No closed trades yet.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
