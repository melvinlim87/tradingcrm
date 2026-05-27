<script setup>
import { onMounted, onBeforeUnmount, reactive, ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PortfolioChart from '@/Components/PortfolioChart.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    overall: {
        type: Object,
        default: () => ({
            account_count: 0,
            balance: 0, equity: 0, margin: 0, free_margin: 0,
            floating_pnl: 0, floating_pct: 0, closed_profit: 0,
            max_drawdown: 0, max_abs_drawdown_pct: 0, max_eq_drawdown_pct: 0,
            profit_series: [],
        }),
    },
    viewer_role: { type: String, default: 'user' },
});

// ───────────────── chart info popover state ─────────────────
const openInfo = ref(null);   // 'overall' | <account_id> | null
const toggleInfo = (key) => { openInfo.value = openInfo.value === key ? null : key; };
const closeInfo = () => { openInfo.value = null; };

const isAdministrator = computed(() => props.viewer_role === 'administrator');

// ───────────────── Individual account selector ─────────────────
const STORAGE_KEY = 'tradingcrm.selected_account_id';
const selectedAccountId = ref(null);
const accountSearch = ref('');

// Initial pick: localStorage → first account → null
const initSelection = (accounts) => {
    if (!accounts?.length) { selectedAccountId.value = null; return; }
    const stored = Number(localStorage.getItem(STORAGE_KEY) || 0);
    if (stored && accounts.find((a) => a.id === stored)) {
        selectedAccountId.value = stored;
    } else {
        selectedAccountId.value = accounts[0].id;
    }
};

watch(() => props.accounts, (accs) => {
    if (!selectedAccountId.value || !accs?.find((a) => a.id === selectedAccountId.value)) {
        initSelection(accs);
    }
}, { immediate: true });

watch(selectedAccountId, (id) => {
    if (id) localStorage.setItem(STORAGE_KEY, String(id));
});

const filteredAccountOptions = computed(() => {
    const q = accountSearch.value.toLowerCase().trim();
    if (!q) return props.accounts;
    return props.accounts.filter((a) =>
        String(a.account_number).includes(q) ||
        (a.account_name || '').toLowerCase().includes(q) ||
        (a.broker || '').toLowerCase().includes(q),
    );
});

const selectedAccount = computed(() =>
    props.accounts.find((a) => a.id === selectedAccountId.value) || null,
);

const selectAccount = (id) => { selectedAccountId.value = id; };

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
// Returns line path, area-fill path, min/max markers and human-readable stats.
const sparkPath = (series, width = 320, height = 60) => {
    const data = (series || []).map((p) => Number(p.v));
    if (data.length < 2) {
        return { d: '', area: '', last: 0, first: 0, min: 0, max: 0, change: 0,
                 changePct: 0, color: '#9ca3af', points: [], minPt: null, maxPt: null };
    }
    const min = Math.min(...data);
    const max = Math.max(...data);
    const range = max - min || 1;
    const padY = 6;                              // leave space at top/bottom
    const usable = height - padY * 2;
    const step = width / (data.length - 1);
    const points = data.map((v, i) => ({
        x: +(i * step).toFixed(2),
        y: +(padY + (height - padY * 2) - ((v - min) / range) * usable).toFixed(2),
        v,
    }));
    const linePath = `M ${points.map(p => `${p.x},${p.y}`).join(' L ')}`;
    const areaPath = `M ${points[0].x},${height} L `
        + points.map(p => `${p.x},${p.y}`).join(' L ')
        + ` L ${points[points.length-1].x},${height} Z`;
    const last = data[data.length - 1];
    const first = data[0];
    const change = last - first;
    const changePct = first ? (change / first) * 100 : 0;
    const color = change >= 0 ? '#16a34a' : '#dc2626';
    const minIdx = data.indexOf(min);
    const maxIdx = data.indexOf(max);
    return {
        d: linePath, area: areaPath,
        last, first, min, max, change, changePct, color,
        points,
        minPt: points[minIdx],
        maxPt: points[maxIdx],
    };
};

// Pretty-print a delta with sign + % suffix
const fmtDelta = (n, pct) => {
    const sign = n > 0 ? '+' : n < 0 ? '' : '';
    return `${sign}${fmt(n)} (${sign}${fmt(pct, 2)}%)`;
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

                    <!-- Overall cumulative-profit chart (one line, summed across all visible accounts) -->
                    <div class="border-b border-gray-200 bg-white px-6 py-4">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <div class="relative flex items-center gap-2">
                                <p class="text-sm font-bold text-black">Cumulative Profit — Overall</p>
                                <button
                                    type="button"
                                    @click="toggleInfo('overall')"
                                    class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-gray-400 text-xs text-gray-600 hover:border-indigo-500 hover:bg-indigo-50 hover:text-indigo-700">
                                    ⓘ
                                </button>
                                <div v-if="openInfo === 'overall'"
                                     class="absolute left-0 top-7 z-30 w-80 rounded-lg border-2 border-gray-300 bg-white p-4 text-xs shadow-2xl">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-sm font-bold text-black">How this chart is built</p>
                                        <button @click="closeInfo" class="text-base text-gray-500 hover:text-black">×</button>
                                    </div>
                                    <ul class="mt-2 space-y-1.5 text-black">
                                        <li><strong>Source:</strong> <code class="rounded bg-gray-100 px-1 font-mono">orders_history</code></li>
                                        <li><strong>Scope:</strong> closed trades <strong>summed</strong> across all {{ overall.account_count }} visible account{{ overall.account_count === 1 ? '' : 's' }}</li>
                                        <li><strong>Metric:</strong> <code>pnl</code> = profit + swap + commission, grouped by close-day (GMT+8) then cumulated</li>
                                        <li><strong>Window:</strong> last 60 trading days with closes</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <PortfolioChart :series="overall.profit_series || []" label="Portfolio P&L" :height="280" />
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

                    <!-- Drawdown + ROI summary row -->
                    <div class="border-t border-gray-200 grid grid-cols-2 gap-px bg-gray-200 md:grid-cols-4">
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
                        <div class="bg-white px-6 py-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-black">ROI %
                                <span class="font-normal" :title="`Closed Profit / Total Deposits · deposits = ${fmt(overall.capital_base)} · realised return, ignores floating PnL`">ⓘ</span>
                            </p>
                            <p class="mt-1 font-mono text-lg font-bold"
                               :class="overall.roi_pct == null ? 'text-gray-400' : pctClass(overall.roi_pct)">
                                <template v-if="overall.roi_pct == null">—</template>
                                <template v-else>{{ overall.roi_pct >= 0 ? '+' : '' }}{{ fmt(overall.roi_pct, 2) }}%</template>
                            </p>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 bg-gray-50 px-6 py-2 text-sm text-black">
                        Margin used: <span class="ml-1 font-mono font-bold">{{ fmt(overall.margin) }}</span>
                        · Free margin: <span class="ml-1 font-mono font-bold">{{ fmt(overall.free_margin) }}</span>
                    </div>
                </section>

                <!-- ===== INDIVIDUAL ACCOUNT ===== -->
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

                    <template v-else>
                        <!-- Account picker (search + select) -->
                        <div class="mb-4 rounded-lg border-2 border-gray-300 bg-white p-4">
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <div>
                                    <label class="block text-sm font-bold text-black">Filter accounts</label>
                                    <input
                                        v-model="accountSearch"
                                        type="text"
                                        placeholder="Search by #number, name, broker..."
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-bold text-black">
                                        Viewing account
                                        <span class="ml-1 text-xs font-normal text-black">
                                            ({{ filteredAccountOptions.length }} of {{ accounts.length }} match)
                                        </span>
                                    </label>
                                    <select
                                        v-model="selectedAccountId"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm font-mono shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option v-if="!filteredAccountOptions.length" :value="null">— no match —</option>
                                        <option
                                            v-for="acc in filteredAccountOptions"
                                            :key="acc.id"
                                            :value="acc.id"
                                        >
                                            #{{ acc.account_number }}
                                            <template v-if="acc.account_name"> — {{ acc.account_name }}</template>
                                            · {{ acc.broker }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- Quick-pick chips for fast switching when there are <= 8 accounts -->
                            <div v-if="accounts.length > 1 && accounts.length <= 8" class="mt-3 flex flex-wrap gap-2">
                                <button
                                    v-for="acc in accounts"
                                    :key="acc.id"
                                    type="button"
                                    @click="selectAccount(acc.id)"
                                    :class="[
                                        'rounded-md border-2 px-3 py-1.5 text-xs font-bold font-mono',
                                        selectedAccountId === acc.id
                                            ? 'border-indigo-600 bg-indigo-600 text-white'
                                            : 'border-gray-300 bg-white text-black hover:bg-gray-100'
                                    ]"
                                >
                                    #{{ acc.account_number }}
                                </button>
                            </div>
                        </div>

                        <div v-if="!selectedAccount" class="rounded-lg border-2 border-dashed border-gray-300 bg-white p-8 text-center text-sm text-black">
                            No account matched your filter. Clear the search above or pick one from the dropdown.
                        </div>

                        <div v-else class="space-y-4">
                            <div v-for="acc in [selectedAccount]" :key="acc.id" class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <!-- Account header -->
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-6 py-3">
                                <div class="flex items-baseline gap-3">
                                    <span class="font-mono text-base font-bold text-black">#{{ acc.account_number }}</span>
                                    <template v-if="acc.account_name">
                                        <span class="text-base font-bold text-black">{{ acc.account_name }}</span>
                                    </template>
                                    <template v-else>
                                        <Link :href="route('accounts.index')"
                                              class="text-base font-bold text-amber-600 hover:underline"
                                              title="No account name set — click to add one">
                                            (Unnamed — click to set name)
                                        </Link>
                                    </template>
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

                            <!-- Profit chart per account -->
                            <div v-if="acc.profit_series?.length > 1" class="relative border-b border-gray-200 bg-white px-6 py-3">
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                                    <div class="relative flex items-center gap-2">
                                        <p class="text-sm font-bold text-black">Cumulative Profit</p>
                                        <button
                                            type="button"
                                            @click="toggleInfo(acc.id)"
                                            class="inline-flex h-5 w-5 items-center justify-center rounded-full border border-gray-400 text-xs text-gray-600 hover:border-indigo-500 hover:bg-indigo-50 hover:text-indigo-700">
                                            ⓘ
                                        </button>

                                        <!-- Popover -->
                                        <div v-if="openInfo === acc.id"
                                             class="absolute left-0 top-7 z-30 w-80 rounded-lg border-2 border-gray-300 bg-white p-4 text-xs shadow-2xl">
                                            <div class="flex items-start justify-between gap-2">
                                                <p class="text-sm font-bold text-black">How this chart is built</p>
                                                <button @click="closeInfo" class="text-base text-gray-500 hover:text-black">×</button>
                                            </div>
                                            <ul class="mt-2 space-y-1.5 text-black">
                                                <li><strong>Source:</strong> <code class="rounded bg-gray-100 px-1 font-mono">orders_history</code> filtered to <strong>#{{ acc.account_number }}</strong> only</li>
                                                <li><strong>Metric:</strong> <code>pnl</code> = profit + swap + commission per closed trade</li>
                                                <li><strong>Bucket:</strong> grouped by close-day (GMT+8), then cumulated</li>
                                                <li><strong>Window:</strong> last 60 trading days ({{ acc.profit_series.length }} day{{ acc.profit_series.length === 1 ? '' : 's' }} with closes)</li>
                                                <li><strong>Refresh:</strong> only when a new trade closes</li>
                                            </ul>
                                        </div>
                                    </div>

                                    <div class="flex flex-col items-end">
                                        <p class="font-mono text-sm font-bold"
                                           :class="pctClass(Number(acc.profit_series.at(-1)?.v || 0))">
                                            {{ fmt(Number(acc.profit_series.at(-1)?.v || 0)) }}
                                        </p>
                                        <p class="text-[11px] text-gray-600">cumulative PnL</p>
                                    </div>
                                </div>

                                <PortfolioChart :series="acc.profit_series || []" :label="`#${acc.account_number} P&L`" :height="220" />
                            </div>

                            <!-- Metric grid: 8 cards (Balance / Equity / Float% / Float$ / DD% / Max ABS / Max EQ / ROI) -->
                            <div class="grid grid-cols-2 gap-px bg-gray-200 md:grid-cols-8">
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
                                <div class="bg-white px-4 py-3">
                                    <p class="text-xs font-bold uppercase text-black"
                                       :title="`Closed Profit / Total Deposits · profit=${fmt(acc.closed_profit_total)} · deposits=${fmt(acc.capital_base)} (${acc.total_deposits != null && Number(acc.total_deposits) > 0 ? 'EA-reported' : 'initial balance fallback'}) · realised, ignores floating PnL`">
                                       ROI %
                                    </p>
                                    <p class="mt-1 font-mono text-base font-bold"
                                       :class="acc.roi_pct == null ? 'text-gray-400' : pctClass(acc.roi_pct)">
                                        <template v-if="acc.roi_pct == null">—</template>
                                        <template v-else>{{ acc.roi_pct >= 0 ? '+' : '' }}{{ fmt(acc.roi_pct, 2) }}%</template>
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
                    </template>
                </section>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
