<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    symbol: { type: String, default: 'AUDUSD' },
    analysis: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    chart_request: { type: Object, default: null },
    news: {
        type: Object,
        default: () => ({ past: [], this: [], upcoming: [], currencies: [] }),
    },
});

const impactClass = (impact) => ({
    HIGH: 'bg-red-100 text-red-700',
    MEDIUM: 'bg-amber-100 text-amber-700',
    LOW: 'bg-gray-100 text-gray-600',
    HOLIDAY: 'bg-blue-100 text-blue-700',
}[impact] || 'bg-gray-100 text-gray-600');

const newsTab = ref('this');

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

const TIMEFRAMES = ['H1', 'H4', 'Daily', 'Weekly'];
const selectedTimeframe = ref('H4');

const currentCurrency = computed(() => {
    const sym = String(props.symbol || 'AUDUSD').toUpperCase();
    return sym.substring(0, 3);
});

const selectCurrency = (cur) => {
    const pair = PAIR_MAP[cur] || `${cur}USD`;
    router.get(route('analysis.index', { symbol: pair }), {}, { preserveState: false });
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
                                <template v-if="chart_request">
                                    Chart export: <span class="font-mono">{{ chart_request.status }}</span> ·
                                    Waiting for ChartExporter EA to upload H4 / D1 / W1 screenshots.
                                </template>
                                <template v-else>
                                    Queued. Polling every 5s for updates.
                                </template>
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
                        <h3 class="text-sm font-medium uppercase tracking-wider text-gray-500">Currency</h3>
                    </div>
                    <div class="flex flex-wrap gap-2 p-4">
                        <button
                            v-for="cur in CURRENCIES"
                            :key="cur"
                            type="button"
                            @click="selectCurrency(cur)"
                            :class="[
                                'rounded-md border px-4 py-2 text-sm font-medium transition',
                                currentCurrency === cur
                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                            ]"
                        >
                            {{ cur }}
                        </button>
                    </div>
                    <div class="border-t border-gray-200 px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="mr-2 text-xs font-medium uppercase tracking-wider text-gray-500">Chart Timeframe</span>
                            <button
                                v-for="tf in TIMEFRAMES"
                                :key="tf"
                                type="button"
                                @click="selectedTimeframe = tf"
                                :class="[
                                    'rounded-md border px-3 py-1 text-xs font-medium',
                                    selectedTimeframe === tf
                                        ? 'border-gray-800 bg-gray-800 text-white'
                                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                                ]"
                            >
                                {{ tf }}
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

                            <!-- Current market price strip -->
                            <div v-if="priceSnapshot" class="mt-3 flex flex-wrap items-end gap-6 rounded-md bg-gray-50 px-4 py-3">
                                <div>
                                    <p class="text-sm font-semibold text-black">Bid</p>
                                    <p class="font-mono text-2xl font-bold text-black">
                                        {{ Number(priceSnapshot.bid).toFixed(priceSnapshot.digits || 5) }}
                                    </p>
                                </div>
                                <div v-if="priceSnapshot.ask">
                                    <p class="text-sm font-semibold text-black">Ask</p>
                                    <p class="font-mono text-2xl font-bold text-black">
                                        {{ Number(priceSnapshot.ask).toFixed(priceSnapshot.digits || 5) }}
                                    </p>
                                </div>
                                <div v-if="priceSnapshot.mid">
                                    <p class="text-sm font-semibold text-black">Mid</p>
                                    <p class="font-mono text-2xl font-bold text-black">
                                        {{ Number(priceSnapshot.mid).toFixed(priceSnapshot.digits || 5) }}
                                    </p>
                                </div>
                                <div v-if="priceSnapshot.captured_at" class="ml-auto text-xs text-black">
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

                <!-- News (past / this week / upcoming) -->
                <section class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900">
                                Economic News
                                <span class="ml-2 text-sm font-normal text-gray-500">
                                    ({{ (news.currencies || []).join(' + ') || currentCurrency }})
                                </span>
                            </h3>
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
                                'rounded-t-md px-4 py-2 text-xs font-medium',
                                newsTab === tab.key
                                    ? 'border border-b-0 border-gray-200 bg-white text-gray-900'
                                    : 'text-gray-500 hover:text-gray-700'
                            ]"
                        >
                            {{ tab.label }}
                            <span class="ml-1 rounded-full bg-gray-200 px-1.5 py-0.5 text-[10px] text-gray-700">
                                {{ (news[tab.key] || []).length }}
                            </span>
                        </button>
                    </nav>

                    <div class="overflow-x-auto">
                        <table v-if="(news[newsTab] || []).length" class="min-w-full divide-y divide-gray-200 text-xs">
                            <thead class="bg-gray-50 text-left text-[10px] uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th class="px-4 py-2">Date / Time</th>
                                    <th class="px-4 py-2">Currency</th>
                                    <th class="px-4 py-2">Impact</th>
                                    <th class="px-4 py-2">Event</th>
                                    <th class="px-4 py-2 text-right">Forecast</th>
                                    <th class="px-4 py-2 text-right">Previous</th>
                                    <th class="px-4 py-2 text-right">Actual</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="n in news[newsTab]" :key="n.id" class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-4 py-2 font-mono text-gray-700">{{ n.event_at }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 font-mono text-gray-900">{{ n.currency }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        <span :class="['rounded-full px-2 py-0.5 text-[10px] font-medium', impactClass(n.impact)]">
                                            {{ n.impact }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">{{ n.title }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono text-gray-600">{{ n.forecast || '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono text-gray-600">{{ n.previous || '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono"
                                        :class="n.actual ? 'text-gray-900 font-semibold' : 'text-gray-400'">
                                        {{ n.actual || '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="px-6 py-6 text-center text-xs text-gray-500">
                            No {{ newsTab === 'past' ? 'past' : newsTab === 'this' ? 'this week' : 'upcoming' }} news for
                            <span class="font-mono">{{ (news.currencies || []).join(' / ') || currentCurrency }}</span>.
                            Run <code class="rounded bg-gray-100 px-1 font-mono">php artisan news:scrape</code> to refresh.
                        </p>
                    </div>
                </section>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
