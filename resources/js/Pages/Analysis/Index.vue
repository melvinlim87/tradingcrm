<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    symbol: { type: String, default: 'AUDUSD' },
    currency: { type: String, default: '' },     // server-supplied, authoritative
    analysis: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    chart_request: { type: Object, default: null },
    news: {
        type: Object,
        default: () => ({ past: [], this: [], upcoming: [], currencies: [] }),
    },
    traded_symbols: { type: Array, default: () => [] },
});

const impactClass = (impact) => ({
    HIGH: 'bg-red-100 text-red-700',
    MEDIUM: 'bg-amber-100 text-amber-700',
    LOW: 'bg-gray-100 text-gray-600',
    HOLIDAY: 'bg-blue-100 text-blue-700',
}[impact] || 'bg-gray-100 text-gray-600');

const newsTab = ref('this');
const showAllImpacts = ref(false);
const selectedNews = ref(null);

// ───────────────── On-demand MT5 news refresh ─────────────────
const refreshState = ref({ id: null, status: null, message: '', imported: 0, updated: 0 });
let refreshPollTimer = null;

const refreshMt5News = async () => {
    refreshState.value = { id: null, status: 'requesting', message: 'Asking the EA to pull MT5 calendar...', imported: 0, updated: 0 };
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
            || decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const resp = await fetch(route('analysis.refresh-mt5-news'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await resp.json();
        refreshState.value = { ...refreshState.value, id: data.id, status: data.status, message: data.message };
        startRefreshPolling(data.id);
    } catch (e) {
        refreshState.value = { id: null, status: 'failed', message: 'Request failed: ' + e.message, imported: 0, updated: 0 };
    }
};

const startRefreshPolling = (id) => {
    if (refreshPollTimer) clearInterval(refreshPollTimer);
    let attempts = 0;
    refreshPollTimer = setInterval(async () => {
        attempts++;
        try {
            const r = await fetch(route('analysis.refresh-mt5-news.status', id), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            const d = await r.json();
            refreshState.value = {
                id,
                status: d.status,
                message: '',
                imported: d.events_imported || 0,
                updated:  d.events_updated  || 0,
            };
            if (d.status === 'completed') {
                clearInterval(refreshPollTimer); refreshPollTimer = null;
                refreshState.value.message = `✅ Imported ${d.events_imported}, updated ${d.events_updated} MT5 events`;
                router.reload({ only: ['news'], preserveScroll: true });
            } else if (d.status === 'failed') {
                clearInterval(refreshPollTimer); refreshPollTimer = null;
                refreshState.value.message = `❌ ${d.error_message || 'EA reported failure'}`;
            } else if (attempts > 30) {
                clearInterval(refreshPollTimer); refreshPollTimer = null;
                refreshState.value.status = 'timeout';
                refreshState.value.message = 'Timed out waiting for EA — is it running + WebRequest allowed?';
            }
        } catch (_) {}
    }, 3000);
};

onBeforeUnmount(() => { if (refreshPollTimer) clearInterval(refreshPollTimer); });

// Build a deep link to ForexFactory's calendar for the given day,
// pre-filtered by the event title so the trader lands close to the row.
const forexFactoryUrl = (item) => {
    if (!item) return 'https://www.forexfactory.com/calendar';
    const iso = item.event_at_iso || item.event_at;
    let d = iso ? new Date(iso) : null;
    if (!d || isNaN(d.getTime())) d = new Date();
    const months = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
    const m = months[d.getMonth()];
    const q = encodeURIComponent(item.title || '');
    return `https://www.forexfactory.com/calendar?day=${m}${d.getDate()}.${d.getFullYear()}&search=${q}`;
};

const openNewsDetail = (item) => {
    if (item.source === 'mt5_broker_news') {
        // MT5 broker-analyst news — open the full HTML body in a sandboxed iframe
        openBrokerNewsPreview(item);
    } else if (item.source === 'mt5') {
        // MT5 calendar event — show structured in-app popup
        selectedNews.value = item;
    } else {
        // ForexFactory news — redirect straight to the calendar day for this event
        window.open(forexFactoryUrl(item), '_blank', 'noopener');
    }
};

const closeNewsDetail = () => { selectedNews.value = null; };

// Source badge colors
const sourceLabel = (src) => ({
    forexfactory:    { text: 'ForexFactory', cls: 'bg-blue-100 text-blue-800 border border-blue-300' },
    mt5:             { text: 'MT5 Calendar', cls: 'bg-orange-100 text-orange-800 border border-orange-300' },
    mt5_broker_news: { text: 'MT5 News',     cls: 'bg-purple-100 text-purple-800 border border-purple-300' },
}[src] || { text: src || 'unknown', cls: 'bg-gray-100 text-gray-800 border border-gray-300' });

// ───────────────── Broker-news HTML preview ─────────────────
const previewNews = ref(null);          // { id, subject, category, body_html, ... }
const previewLoading = ref(false);

const openBrokerNewsPreview = async (item) => {
    previewLoading.value = true;
    previewNews.value = { ...item, body_html: null };       // placeholder
    try {
        const r = await fetch(route('analysis.news.body', item.id), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        const d = await r.json();
        previewNews.value = d;
    } catch (e) {
        previewNews.value = { ...item, body_html: '<p style="padding:1rem;color:red">Failed to load preview.</p>' };
    } finally {
        previewLoading.value = false;
    }
};

const closeBrokerNewsPreview = () => { previewNews.value = null; };

// Filter news by impact. By default only HIGH; toggle reveals MEDIUM/LOW.
const filteredNews = computed(() => {
    const out = {
        past: [], this: [], upcoming: [],
        currencies: props.news?.currencies || [],
    };
    for (const k of ['past', 'this', 'upcoming']) {
        const items = props.news?.[k] || [];
        out[k] = showAllImpacts.value
            ? items
            : items.filter((n) => (n.impact || '').toUpperCase() === 'HIGH');
    }
    return out;
});

const hiddenImpactCount = computed(() => {
    if (showAllImpacts.value) return 0;
    let count = 0;
    for (const k of ['past', 'this', 'upcoming']) {
        for (const n of (props.news?.[k] || [])) {
            if ((n.impact || '').toUpperCase() !== 'HIGH') count++;
        }
    }
    return count;
});

// === Bias gauge helpers =====================================================
const biasLabel = (score) => {
    if (score == null) return '—';
    if (score <= 20) return 'Strong Bearish';
    if (score <= 40) return 'Bearish';
    if (score < 60)  return 'Neutral';
    if (score < 80)  return 'Bullish';
    return 'Strong Bullish';
};

const biasColor = (score) => {
    if (score == null) return '#9ca3af';      // gray-400
    if (score <= 20) return '#dc2626';        // red-600
    if (score <= 40) return '#f97316';        // orange-500
    if (score < 60)  return '#eab308';        // yellow-500
    if (score < 80)  return '#84cc16';        // lime-500
    return '#16a34a';                          // green-600
};

const biasClampedPct = (score) => Math.max(0, Math.min(100, Number(score ?? 50)));

// Extract current price snapshot from news_snapshot.price for display
const priceSnapshot = computed(() => props.analysis?.news_snapshot?.price ?? null);

// Map textual momentum (strong/moderate/weak) → 0-100 gauge value
const momentumScore = computed(() => {
    const m = String(props.analysis?.market_structure?.momentum ?? '').toLowerCase();
    if (m === 'strong')   return 90;
    if (m === 'moderate') return 55;
    if (m === 'weak')     return 20;
    return null;
});

const flash = computed(() => usePage().props.flash || {});

// 9 base currencies (from your sketch)
const CURRENCIES = ['AUD', 'CAD', 'EUR', 'GBP', 'CHF', 'NZD', 'USD', 'SGD', 'JPY'];

// Each currency → default pair to chart (against USD where possible)
const PAIR_MAP = {
    AUD: 'AUDUSD',
    CAD: 'USDCAD',
    EUR: 'EURUSD',
    GBP: 'GBPUSD',
    CHF: 'USDCHF',
    NZD: 'NZDUSD',
    USD: 'EURUSD',
    SGD: 'USDSGD',
    JPY: 'USDJPY',
};

// Cross / commodity pairs the trader actively cares about — direct symbol picks
// (not currency-based, so they bypass PAIR_MAP and don't drive the currency tab).
const CROSS_PAIRS = [
    'AUDCAD', 'EURGBP', 'XAUUSD',
    'EURCHF', 'CADCHF', 'USDSGD',
    'NZDCHF', 'EURNZD',
];

const selectCrossPair = (symbol) => {
    // Try to figure out a sensible "currency" highlight from the pair
    const cur = symbol.startsWith('XAU') ? 'USD' : symbol.substring(0, 3);
    router.get(route('analysis.index', { symbol, currency: cur }), {}, { preserveState: false });
};

const TIMEFRAMES = ['H1', 'H4', 'Daily', 'Weekly'];
const selectedTimeframe = ref('H4');

const currentCurrency = computed(() => {
    // Use the server-supplied currency if provided (correct for SGD/CAD/CHF/JPY pairs)
    if (props.currency && props.currency.length === 3) {
        return props.currency.toUpperCase();
    }
    const sym = String(props.symbol || 'AUDUSD').toUpperCase();
    return sym.substring(0, 3);
});

const selectCurrency = (cur) => {
    const pair = PAIR_MAP[cur] || `${cur}USD`;
    // Pass `currency` explicitly so the page knows SGD ≠ USD even when symbol = USDSGD
    router.get(route('analysis.index', { symbol: pair, currency: cur }), {}, { preserveState: false });
};

const generating = ref(false);

const generate = () => {
    generating.value = true;
    router.post(
        route('analysis.generate'),
        { symbol: props.symbol },
        {
            preserveScroll: true,
            onFinish: () => { generating.value = false; },
        },
    );
};

// Auto-poll while a chart export is in progress (EA picks up within ~10s,
// uploads 3 PNGs, then AnalyzeCurrencyJob runs OpenRouter).
const isPending = computed(() => {
    if (!props.analysis) return false;
    return ['pending', 'processing'].includes(props.analysis.status);
});

let pollTimer = null;
const startPolling = () => {
    if (pollTimer) return;
    pollTimer = setInterval(() => {
        router.reload({ only: ['analysis', 'chart_request'], preserveScroll: true });
    }, 5000);
};
const stopPolling = () => {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
};

watch(isPending, (pending) => {
    pending ? startPolling() : stopPolling();
}, { immediate: true });

onBeforeUnmount(stopPolling);

const tradingViewSymbol = computed(() => `FX:${props.symbol}`);
</script>

<template>
    <Head :title="`Analysis — ${symbol}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">
                    Currency Analysis (Weekly)
                </h2>
                <button
                    type="button"
                    @click="generate"
                    :disabled="generating"
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    {{ generating ? 'Queued…' : 'Generate Now' }}
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- Flash success / progress -->
                <div v-if="flash.success" class="rounded-md bg-green-50 p-4 text-sm text-green-800">
                    {{ flash.success }}
                </div>

                <div v-if="isPending" class="rounded-md border border-blue-200 bg-blue-50 p-4">
                    <div class="flex items-center gap-3">
                        <svg class="h-5 w-5 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                        </svg>
                        <div class="text-sm text-blue-900">
                            <p class="font-semibold">
                                Analysis in progress (status: {{ analysis?.status }})
                            </p>
                            <p class="mt-0.5 text-xs">
                                Please wait — generating analysis. This usually takes about a minute.
                            </p>
                        </div>
                    </div>
                </div>

                <div v-if="analysis && analysis.status === 'failed'" class="rounded-md border border-red-200 bg-red-50 p-4">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div class="flex-1 text-sm text-red-900">
                            <p class="font-semibold">Last analysis for {{ analysis.symbol }} failed</p>
                            <p class="mt-0.5 text-xs">
                                <span class="font-mono">{{ analysis.error_message || 'Unknown error' }}</span>
                            </p>
                            <p v-if="(analysis.error_message || '').includes('symbol_not_available')" class="mt-2 text-xs">
                                💡 <strong>Tip:</strong> Your broker likely uses a suffixed symbol name
                                (e.g. <code class="rounded bg-red-100 px-1">{{ analysis.symbol }}.m</code>,
                                <code class="rounded bg-red-100 px-1">{{ analysis.symbol }}m</code>).
                                The latest EA build tries common suffixes automatically — make sure you've
                                rebuilt &amp; reloaded <code class="rounded bg-red-100 px-1">TradingCRM_EA.mq5</code> in MetaEditor.
                            </p>
                            <button
                                type="button"
                                @click="generate"
                                :disabled="generating"
                                class="mt-3 rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50"
                            >
                                Retry
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Currency selector -->
                <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-base font-bold uppercase tracking-wider text-black">Currency</h3>
                    </div>
                    <div class="flex flex-wrap gap-2 p-4">
                        <button
                            v-for="cur in CURRENCIES"
                            :key="cur"
                            type="button"
                            @click="selectCurrency(cur)"
                            :class="[
                                'rounded-md border-2 px-4 py-2 text-sm font-bold transition',
                                currentCurrency === cur
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 bg-white text-black hover:bg-gray-100'
                            ]"
                        >
                            {{ cur }}
                        </button>
                    </div>

                    <div class="border-t border-gray-200 px-4 py-3">
                        <p class="mb-2 text-base font-bold uppercase tracking-wider text-black">
                            Cross Pairs / Commodities
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="sym in CROSS_PAIRS"
                                :key="sym"
                                type="button"
                                @click="selectCrossPair(sym)"
                                :class="[
                                    'rounded-md border-2 px-3 py-1.5 text-sm font-bold font-mono',
                                    symbol === sym
                                        ? 'border-amber-600 bg-amber-600 text-white'
                                        : 'border-amber-200 bg-amber-50 text-black hover:bg-amber-100'
                                ]"
                            >
                                {{ sym }}
                            </button>
                        </div>
                    </div>

                    <div v-if="traded_symbols.length" class="border-t border-gray-200 px-4 py-3">
                        <p class="mb-2 text-base font-bold uppercase tracking-wider text-black">
                            Currently Trading
                            <span class="text-sm font-normal text-black">— pulled from open + pending orders</span>
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="sym in traded_symbols"
                                :key="sym"
                                type="button"
                                @click="router.get(route('analysis.index', { symbol: sym }), {}, { preserveState: false })"
                                :class="[
                                    'rounded-md border-2 px-3 py-1.5 text-sm font-bold font-mono',
                                    symbol === sym
                                        ? 'border-green-600 bg-green-600 text-white'
                                        : 'border-green-200 bg-green-50 text-black hover:bg-green-100'
                                ]"
                            >
                                {{ sym }}
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Empty state -->
                <section v-if="!analysis" class="rounded-lg border-2 border-dashed border-gray-300 bg-white p-12 text-center">
                    <p class="text-sm text-gray-600">
                        No completed analysis yet for <span class="font-mono font-semibold">{{ symbol }}</span>.
                    </p>
                    <p class="mt-1 text-xs text-gray-500">
                        Click <strong>Generate Now</strong> above to queue a new analysis. The ChartExporter EA on MT5 will capture H4/D1/W1 charts (with MACD + RSI + MA), then OpenRouter runs the analysis.
                    </p>
                </section>

                <!-- Analysis result -->
                <section v-else class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">
                            {{ currentCurrency }} —
                            <span :class="{
                                'text-green-600': analysis.outlook === 'bullish',
                                'text-red-600': analysis.outlook === 'bearish',
                                'text-gray-700': analysis.outlook === 'neutral' || !analysis.outlook,
                            }">
                                {{ (analysis.outlook || 'pending').toUpperCase() }} Outlook
                                <span v-if="analysis.outlook === 'bullish'">↑</span>
                                <span v-else-if="analysis.outlook === 'bearish'">↓</span>
                            </span>
                        </h3>
                        <p class="mt-1 text-xs text-black">
                            Generated {{ new Date(analysis.created_at).toLocaleString() }}
                        </p>
                    </div>

                    <div class="space-y-4 p-6">

                        <!-- ===== Bias Meter Gauge (horizontal line) ===== -->
                        <div v-if="analysis.bias_score != null" class="rounded-lg border-2 border-gray-300 bg-white p-5">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-base font-bold uppercase tracking-wider text-black">Market Bias</p>
                                <p class="font-mono text-2xl font-bold" :style="{ color: biasColor(analysis.bias_score) }">
                                    {{ analysis.bias_score }}<span class="text-base text-black">/100</span>
                                    <span class="ml-3 text-xl">— {{ biasLabel(analysis.bias_score) }}</span>
                                </p>
                            </div>

                            <!-- Horizontal gradient bar -->
                            <div class="mt-4 px-3">
                                <div class="relative h-4 w-full rounded-full bg-gradient-to-r from-red-600 via-yellow-400 to-green-600">
                                    <!-- Tick marks -->
                                    <div class="absolute top-full mt-1 h-2 w-px bg-black" style="left: 0%"></div>
                                    <div class="absolute top-full mt-1 h-2 w-px bg-black" style="left: 25%"></div>
                                    <div class="absolute top-full mt-1 h-2 w-px bg-black" style="left: 50%"></div>
                                    <div class="absolute top-full mt-1 h-2 w-px bg-black" style="left: 75%"></div>
                                    <div class="absolute top-full mt-1 h-2 w-px bg-black" style="left: 100%"></div>
                                    <!-- Needle marker -->
                                    <div
                                        class="absolute -top-2 h-8 w-1.5 -translate-x-1/2 rounded bg-black shadow-lg ring-2 ring-white"
                                        :style="{ left: `${biasClampedPct(analysis.bias_score)}%` }"
                                    ></div>
                                </div>
                                <div class="mt-4 flex justify-between text-sm font-semibold text-black">
                                    <span>0<br>Strong Bear</span>
                                    <span class="text-center">25<br>Bearish</span>
                                    <span class="text-center">50<br>Neutral</span>
                                    <span class="text-center">75<br>Bullish</span>
                                    <span class="text-right">100<br>Strong Bull</span>
                                </div>
                            </div>

                        </div>

                        <!-- ===== Market Structure (with momentum meter gauge) ===== -->
                        <div v-if="analysis.market_structure" class="rounded-lg border-2 border-gray-300 bg-white p-5">
                            <p class="text-base font-bold uppercase tracking-wider text-black">Market Structure</p>

                            <dl class="mt-3 grid grid-cols-3 gap-4">
                                <div>
                                    <dt class="text-sm font-semibold text-black">Trend</dt>
                                    <dd class="mt-1 text-lg font-bold capitalize text-black">{{ analysis.market_structure.trend || '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-semibold text-black">Phase</dt>
                                    <dd class="mt-1 text-lg font-bold capitalize text-black">{{ analysis.market_structure.phase || '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-semibold text-black">Momentum</dt>
                                    <dd class="mt-1 text-lg font-bold capitalize text-black">{{ analysis.market_structure.momentum || '—' }}</dd>
                                </div>
                            </dl>

                            <!-- Momentum meter gauge -->
                            <div v-if="momentumScore != null" class="mt-5 px-3">
                                <p class="mb-2 text-sm font-semibold text-black">Momentum Strength</p>
                                <div class="relative h-4 w-full rounded-full bg-gradient-to-r from-gray-300 via-yellow-400 to-purple-600">
                                    <div class="absolute top-full mt-1 h-2 w-px bg-black" style="left: 0%"></div>
                                    <div class="absolute top-full mt-1 h-2 w-px bg-black" style="left: 50%"></div>
                                    <div class="absolute top-full mt-1 h-2 w-px bg-black" style="left: 100%"></div>
                                    <div
                                        class="absolute -top-2 h-8 w-1.5 -translate-x-1/2 rounded bg-black shadow-lg ring-2 ring-white"
                                        :style="{ left: `${momentumScore}%` }"
                                    ></div>
                                </div>
                                <div class="mt-4 flex justify-between text-sm font-semibold text-black">
                                    <span>Weak</span>
                                    <span>Moderate</span>
                                    <span>Strong</span>
                                </div>
                            </div>

                            <ul v-if="analysis.market_structure.key_observations?.length" class="mt-5 list-disc space-y-1 pl-5 text-sm text-black">
                                <li v-for="(o, i) in analysis.market_structure.key_observations" :key="i">{{ o }}</li>
                            </ul>
                        </div>

                        <!-- ===== Support / Resistance — with current price + chart ===== -->
                        <div v-if="analysis.support_resistance" class="rounded-lg border-2 border-gray-300 bg-white p-5">
                            <p class="text-base font-bold uppercase tracking-wider text-black">Support / Resistance · {{ analysis.symbol }}</p>

                            <!-- Current market price strip — Ask only -->
                            <div v-if="priceSnapshot && priceSnapshot.ask" class="mt-3 flex flex-wrap items-end gap-6 rounded-md bg-gray-50 px-4 py-3">
                                <div>
                                    <p class="text-sm font-semibold text-black">Ask Price</p>
                                    <p class="font-mono text-3xl font-bold text-black">
                                        {{ Number(priceSnapshot.ask).toFixed(priceSnapshot.digits || 5) }}
                                    </p>
                                </div>
                                <div v-if="priceSnapshot.captured_at" class="ml-auto text-sm text-black">
                                    Captured: {{ new Date(priceSnapshot.captured_at).toLocaleString() }}
                                </div>
                            </div>

                            <!-- Chart -->
                            <div class="mt-3">
                                <div class="mb-1 flex items-center justify-between">
                                    <p class="text-sm font-semibold text-black">{{ symbol }} · {{ selectedTimeframe }}</p>
                                    <div class="flex gap-1">
                                        <button
                                            v-for="tf in TIMEFRAMES"
                                            :key="tf"
                                            type="button"
                                            @click="selectedTimeframe = tf"
                                            :class="[
                                                'rounded border px-2 py-0.5 text-xs font-medium',
                                                selectedTimeframe === tf
                                                    ? 'border-black bg-black text-white'
                                                    : 'border-gray-300 bg-white text-black hover:bg-gray-100'
                                            ]"
                                        >{{ tf }}</button>
                                    </div>
                                </div>
                                <iframe
                                    :key="symbol + selectedTimeframe"
                                    :src="`https://s.tradingview.com/widgetembed/?symbol=${encodeURIComponent(tradingViewSymbol)}&interval=${selectedTimeframe === 'Daily' ? 'D' : selectedTimeframe === 'Weekly' ? 'W' : selectedTimeframe}&theme=light&style=1`"
                                    class="h-[320px] w-full rounded border border-gray-300"
                                    frameborder="0"
                                    allowfullscreen
                                ></iframe>
                            </div>

                            <!-- S/R levels -->
                            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <p class="text-sm font-bold text-black">Supports</p>
                                    <ul class="mt-1 space-y-1">
                                        <li v-for="(s, i) in (analysis.support_resistance.supports || [])" :key="i" class="font-mono text-sm text-black">
                                            {{ s.price }} <span class="text-xs text-black">({{ s.strength }})</span>
                                            <span v-if="s.note" class="block pl-3 text-xs text-black">{{ s.note }}</span>
                                        </li>
                                    </ul>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-black">Resistances</p>
                                    <ul class="mt-1 space-y-1">
                                        <li v-for="(r, i) in (analysis.support_resistance.resistances || [])" :key="i" class="font-mono text-sm text-black">
                                            {{ r.price }} <span class="text-xs text-black">({{ r.strength }})</span>
                                            <span v-if="r.note" class="block pl-3 text-xs text-black">{{ r.note }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- ===== News Impact (past vs upcoming) ===== -->
                        <div v-if="analysis.news_impact" class="rounded-lg border-2 border-gray-300 bg-white p-5">
                            <p class="text-base font-bold uppercase tracking-wider text-black">News Impact</p>

                            <div class="mt-3 grid grid-cols-1 gap-5 md:grid-cols-2">
                                <div>
                                    <p class="text-sm font-bold text-black">
                                        <span class="mr-1">⏮</span> Past Events
                                    </p>
                                    <ul v-if="(analysis.news_impact.past_events || []).length" class="mt-2 list-disc space-y-1 pl-5 text-sm text-black">
                                        <li v-for="(e, i) in analysis.news_impact.past_events" :key="i">{{ e }}</li>
                                    </ul>
                                    <p v-else class="mt-2 text-sm text-black">No notable past events.</p>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-black">
                                        <span class="mr-1">⏭</span> Upcoming Events
                                    </p>
                                    <ul v-if="(analysis.news_impact.upcoming_events || []).length" class="mt-2 list-disc space-y-1 pl-5 text-sm text-black">
                                        <li v-for="(e, i) in analysis.news_impact.upcoming_events" :key="i">{{ e }}</li>
                                    </ul>
                                    <p v-else class="mt-2 text-sm text-black">No notable upcoming events.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- News (past / this week / upcoming) — HIGH only by default -->
                <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-lg font-bold text-black">
                                Economic News · HIGH impact
                                <span class="ml-2 text-base font-normal text-black">
                                    ({{ (news.currencies || []).join(' + ') || currentCurrency }})
                                </span>
                            </h3>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    @click="refreshMt5News"
                                    :disabled="refreshState.status === 'requesting' || refreshState.status === 'pending' || refreshState.status === 'in_progress'"
                                    class="rounded-md border-2 border-orange-500 bg-orange-50 px-3 py-1.5 text-sm font-bold text-orange-800 hover:bg-orange-100 disabled:opacity-60"
                                    title="Queue an on-demand MT5 calendar fetch — EA picks it up within ~10s"
                                >
                                    <template v-if="refreshState.status === 'requesting' || refreshState.status === 'pending' || refreshState.status === 'in_progress'">
                                        ⏳ Refreshing MT5 News...
                                    </template>
                                    <template v-else>
                                        🔄 Refresh MT5 News
                                    </template>
                                </button>
                                <button
                                    type="button"
                                    @click="showAllImpacts = !showAllImpacts"
                                    class="rounded-md border-2 border-black bg-white px-3 py-1.5 text-sm font-semibold text-black hover:bg-gray-100"
                                >
                                    <template v-if="showAllImpacts">▲ Hide medium / low impact</template>
                                    <template v-else>▼ Show all impacts ({{ hiddenImpactCount }} hidden)</template>
                                </button>
                            </div>
                        </div>

                        <!-- Refresh status banner -->
                        <div v-if="refreshState.status && refreshState.status !== 'completed' || refreshState.message" class="mt-3">
                            <div v-if="refreshState.status === 'completed'" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">
                                {{ refreshState.message }}
                            </div>
                            <div v-else-if="refreshState.status === 'failed' || refreshState.status === 'timeout'" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">
                                {{ refreshState.message }}
                            </div>
                            <div v-else-if="refreshState.status" class="rounded-md bg-blue-50 px-3 py-2 text-sm text-blue-900">
                                <span class="font-bold">{{ refreshState.status }}</span> — {{ refreshState.message || 'Polling backend / waiting for EA...' }}
                            </div>
                        </div>
                    </div>

                    <nav class="flex space-x-1 border-b border-gray-200 bg-gray-50 px-4 pt-2">
                        <button
                            v-for="tab in [
                                {key:'past', label:'Past Week'},
                                {key:'this', label:'This Week'},
                                {key:'upcoming', label:'Upcoming'},
                            ]"
                            :key="tab.key"
                            @click="newsTab = tab.key"
                            :class="[
                                'rounded-t-md px-4 py-2 text-sm font-semibold',
                                newsTab === tab.key
                                    ? 'border border-b-0 border-gray-300 bg-white text-black'
                                    : 'text-black hover:text-black hover:bg-gray-100'
                            ]"
                        >
                            {{ tab.label }}
                            <span class="ml-1 rounded-full bg-black px-1.5 py-0.5 text-[10px] text-white">
                                {{ (filteredNews[tab.key] || []).length }}
                            </span>
                        </button>
                    </nav>

                    <div class="overflow-x-auto">
                        <table v-if="(filteredNews[newsTab] || []).length" class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs font-bold uppercase tracking-wider text-black">
                                <tr>
                                    <th class="px-4 py-2">Date / Time</th>
                                    <th class="px-4 py-2">Currency</th>
                                    <th class="px-4 py-2">Impact</th>
                                    <th class="px-4 py-2">Source</th>
                                    <th class="px-4 py-2">Category</th>
                                    <th class="px-4 py-2">Subject / Event</th>
                                    <th class="px-4 py-2 text-right">Forecast</th>
                                    <th class="px-4 py-2 text-right">Previous</th>
                                    <th class="px-4 py-2 text-right">Actual</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="n in filteredNews[newsTab]" :key="n.id"
                                    class="cursor-pointer hover:bg-yellow-50"
                                    :title="n.source === 'mt5'
                                        ? `Click to view MetaTrader details for '${n.title}'`
                                        : `Click to open '${n.title}' on ForexFactory (new tab)`"
                                    @click="openNewsDetail(n)">
                                    <td class="whitespace-nowrap px-4 py-2 font-mono text-black">{{ n.event_at }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 font-mono font-bold text-black">{{ n.currency }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-bold', impactClass(n.impact)]">
                                            {{ n.impact }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-bold', sourceLabel(n.source).cls]">
                                            {{ sourceLabel(n.source).text }}
                                            <span v-if="n.source === 'forexfactory'" class="ml-0.5">↗</span>
                                            <span v-if="n.source === 'mt5_broker_news'" class="ml-0.5">📰</span>
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-black">{{ n.category || '—' }}</td>
                                    <td class="px-4 py-2 text-black underline decoration-dotted">{{ n.title }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono text-black">{{ n.forecast || '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono text-black">{{ n.previous || '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono"
                                        :class="n.actual ? 'text-black font-bold' : 'text-black'">
                                        {{ n.actual || '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="px-6 py-6 text-center text-sm text-black">
                            <template v-if="!showAllImpacts && (news[newsTab] || []).length > 0">
                                No HIGH-impact news in {{ newsTab === 'past' ? 'past week' : newsTab === 'this' ? 'this week' : 'upcoming' }} —
                                <button type="button" @click="showAllImpacts = true" class="font-bold underline">show all {{ (news[newsTab] || []).length }} events</button>.
                            </template>
                            <template v-else>
                                No {{ newsTab === 'past' ? 'past' : newsTab === 'this' ? 'this week' : 'upcoming' }} news for
                                <span class="font-mono">{{ (news.currencies || []).join(' / ') || currentCurrency }}</span>.
                                Run <code class="rounded bg-gray-100 px-1 font-mono">php artisan news:scrape</code> to refresh.
                            </template>
                        </p>
                    </div>
                </section>

                <!-- ===== MT5 BROKER NEWS HTML preview (like the MT5 terminal News tab) ===== -->
                <div v-if="previewNews"
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
                     @click.self="closeBrokerNewsPreview">
                    <div class="flex w-full max-w-4xl flex-col rounded-lg bg-white shadow-2xl"
                         style="max-height: 90vh">
                        <!-- Header bar -->
                        <div class="flex items-start justify-between gap-4 border-b border-gray-200 bg-gray-50 px-5 py-3 rounded-t-lg">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded bg-purple-600 px-2 py-0.5 text-xs font-bold uppercase text-white">📰 MT5 News</span>
                                    <span v-if="previewNews.category" class="text-sm font-bold text-black">{{ previewNews.category }}</span>
                                    <span class="font-mono text-sm text-black">{{ previewNews.event_at }} GMT+8</span>
                                </div>
                                <h3 class="mt-1 text-lg font-bold text-black">{{ previewNews.subject || previewNews.title }}</h3>
                            </div>
                            <button @click="closeBrokerNewsPreview" class="text-3xl text-gray-500 hover:text-red-600">×</button>
                        </div>

                        <!-- HTML body sandboxed in iframe (no JS, no parent access) -->
                        <div class="flex-1 overflow-hidden bg-white p-2">
                            <div v-if="previewLoading" class="flex h-full items-center justify-center p-12 text-black">
                                <svg class="mr-3 h-5 w-5 animate-spin text-purple-600" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                                </svg>
                                Loading article...
                            </div>
                            <iframe
                                v-else-if="previewNews.body_html"
                                :srcdoc="previewNews.body_html"
                                sandbox="allow-same-origin allow-popups"
                                referrerpolicy="no-referrer"
                                class="h-full min-h-[60vh] w-full rounded border border-gray-200"
                            ></iframe>
                            <p v-else class="px-6 py-10 text-center text-sm italic text-black">
                                No HTML body available for this item.
                                <br>(The MT5 broker hadn't pushed a body when this row was created — try refreshing later.)
                            </p>
                        </div>

                        <!-- Footer -->
                        <div class="border-t border-gray-200 bg-gray-50 px-5 py-2 rounded-b-lg">
                            <p class="text-xs text-black">
                                Source: MT5 broker News feed
                                <span v-if="previewNews.external_id"> · ID <code class="rounded bg-gray-200 px-1 font-mono">{{ previewNews.external_id }}</code></span>
                                · iframe sandboxed (no script execution) for safety
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ===== MT5 News detail popup (styled like MetaTrader's terminal calendar) ===== -->
                <div v-if="selectedNews"
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
                     @click.self="closeNewsDetail">
                    <div class="w-full max-w-2xl rounded-lg bg-[#1e1e1e] text-gray-100 shadow-2xl">
                        <!-- Header — MT5-style dark bar -->
                        <div class="flex items-start justify-between gap-4 border-b border-gray-700 bg-[#252526] px-5 py-3 rounded-t-lg">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-bold', impactClass(selectedNews.impact)]">
                                        {{ selectedNews.impact }}
                                    </span>
                                    <span class="rounded bg-orange-600 px-2 py-0.5 text-xs font-bold text-white">
                                        MetaTrader Calendar
                                    </span>
                                    <span class="font-mono text-base font-bold text-yellow-300">{{ selectedNews.currency }}</span>
                                    <span class="font-mono text-sm text-gray-300">{{ selectedNews.event_at }} GMT+8</span>
                                </div>
                                <h3 class="mt-2 text-xl font-bold text-white">{{ selectedNews.title }}</h3>
                            </div>
                            <button @click="closeNewsDetail" class="text-3xl text-gray-400 hover:text-red-400">×</button>
                        </div>

                        <div class="space-y-4 p-5">
                            <!-- Values grid (with unit suffix when MT5 provides one) -->
                            <dl class="grid grid-cols-3 gap-3">
                                <div class="rounded-md bg-[#2d2d30] p-3">
                                    <dt class="text-xs font-bold uppercase tracking-wider text-gray-400">Forecast</dt>
                                    <dd class="mt-1 font-mono text-lg font-bold text-blue-300">
                                        {{ selectedNews.forecast || '—' }}
                                        <span v-if="selectedNews.forecast && selectedNews.unit" class="text-xs font-normal text-gray-400">{{ selectedNews.unit }}</span>
                                    </dd>
                                </div>
                                <div class="rounded-md bg-[#2d2d30] p-3">
                                    <dt class="text-xs font-bold uppercase tracking-wider text-gray-400">Previous</dt>
                                    <dd class="mt-1 font-mono text-lg font-bold text-gray-300">
                                        {{ selectedNews.previous || '—' }}
                                        <span v-if="selectedNews.previous && selectedNews.unit" class="text-xs font-normal text-gray-400">{{ selectedNews.unit }}</span>
                                    </dd>
                                </div>
                                <div class="rounded-md bg-[#2d2d30] p-3">
                                    <dt class="text-xs font-bold uppercase tracking-wider text-gray-400">Actual</dt>
                                    <dd class="mt-1 font-mono text-lg font-bold"
                                        :class="selectedNews.actual ? 'text-yellow-300' : 'text-gray-500'">
                                        {{ selectedNews.actual || '—' }}
                                        <span v-if="selectedNews.actual && selectedNews.unit" class="text-xs font-normal text-gray-400">{{ selectedNews.unit }}</span>
                                    </dd>
                                </div>
                            </dl>

                            <!-- MT5 calendar metadata strip -->
                            <div v-if="selectedNews.sector || selectedNews.frequency || selectedNews.event_type" class="flex flex-wrap gap-2">
                                <span v-if="selectedNews.sector"
                                      class="rounded-md bg-blue-900/40 px-3 py-1 text-xs font-bold text-blue-200">
                                    📊 {{ selectedNews.sector }}
                                </span>
                                <span v-if="selectedNews.frequency"
                                      class="rounded-md bg-purple-900/40 px-3 py-1 text-xs font-bold text-purple-200">
                                    🔄 {{ selectedNews.frequency }}
                                </span>
                                <span v-if="selectedNews.event_type"
                                      class="rounded-md bg-emerald-900/40 px-3 py-1 text-xs font-bold text-emerald-200">
                                    🏷️ {{ selectedNews.event_type }}
                                </span>
                                <span v-if="selectedNews.unit"
                                      class="rounded-md bg-amber-900/40 px-3 py-1 text-xs font-bold text-amber-200">
                                    📏 unit: {{ selectedNews.unit }}
                                </span>
                            </div>

                            <!-- Source URL link (when MT5 provides one — biggest content win) -->
                            <a v-if="selectedNews.source_url"
                               :href="selectedNews.source_url"
                               target="_blank" rel="noopener"
                               class="flex items-center justify-between rounded-md border border-blue-500/40 bg-blue-900/30 p-3 text-sm font-bold text-blue-200 transition hover:bg-blue-900/60 hover:text-blue-100">
                                <span>🔗 Open official source page</span>
                                <span class="font-mono text-xs text-blue-300">{{ selectedNews.source_url }}</span>
                            </a>

                            <!-- Enrichment context (ForexFactory-style) -->
                            <div v-if="selectedNews.measures" class="rounded-md bg-[#2d2d30] p-3">
                                <p class="text-xs font-bold uppercase tracking-wider text-gray-400">What it measures</p>
                                <p class="mt-1 text-sm leading-relaxed text-gray-200" v-html="selectedNews.measures"></p>
                            </div>
                            <div v-if="selectedNews.usual_effect" class="rounded-md bg-[#2d2d30] p-3">
                                <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Usual market effect</p>
                                <p class="mt-1 text-sm leading-relaxed text-gray-200" v-html="selectedNews.usual_effect"></p>
                            </div>
                            <div v-if="selectedNews.traders_care" class="rounded-md bg-[#2d2d30] p-3">
                                <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Why traders care</p>
                                <p class="mt-1 text-sm leading-relaxed text-gray-200" v-html="selectedNews.traders_care"></p>
                            </div>
                            <div v-if="selectedNews.notes" class="rounded-md bg-[#2d2d30] p-3">
                                <p class="text-xs font-bold uppercase tracking-wider text-gray-400">Notes</p>
                                <p class="mt-1 text-sm leading-relaxed text-gray-200" v-html="selectedNews.notes"></p>
                            </div>

                            <p v-if="!selectedNews.measures && !selectedNews.usual_effect && !selectedNews.traders_care && !selectedNews.notes && !selectedNews.source_url && !selectedNews.sector"
                               class="rounded-md bg-[#2d2d30] p-3 text-center text-sm italic text-gray-400">
                                No additional context provided by the MT5 calendar feed for this event.
                                <br>
                                <span class="text-xs">(Re-attach EA v3.70+ to pull richer metadata.)</span>
                            </p>

                            <p class="text-right text-xs text-gray-500">
                                Source: MetaTrader 5 Economic Calendar
                                <span v-if="selectedNews.mt5_event_id">· event #{{ selectedNews.mt5_event_id }}</span>
                                <span v-if="selectedNews.currency"> · {{ selectedNews.currency }}</span>
                            </p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
