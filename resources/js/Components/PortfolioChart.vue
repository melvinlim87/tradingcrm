<script setup>
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import {
    Chart as ChartJS,
    Title,
    Tooltip,
    Legend,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Filler,
} from 'chart.js';

ChartJS.register(
    Title, Tooltip, Legend,
    LineElement, PointElement,
    LinearScale, CategoryScale,
    Filler,
);

/**
 * Single-line cumulative-profit chart in the dashboard's white theme.
 * Uses the same green/red semantics as the rest of the dashboard
 * (positive cumulative = emerald green, negative = rose red).
 *
 * Props:
 *   series:    [{t, v, label, day_pnl}, ...]   one line, oldest → newest
 *   label:     legend / tooltip label  (default 'Cumulative P&L')
 *   height:    px height               (default 260)
 *   unitLabel: Y axis suffix           (default '$')
 */
const props = defineProps({
    series:    { type: Array,  default: () => [] },
    label:     { type: String, default: 'Cumulative P&L' },
    height:    { type: Number, default: 260 },
    unitLabel: { type: String, default: '$' },
});

const last  = computed(() => Number(props.series.at?.(-1)?.v ?? 0));
const first = computed(() => Number(props.series[0]?.v ?? 0));
const isPositive = computed(() => last.value >= first.value);

const LINE_COLOR = computed(() => isPositive.value ? '#059669' : '#dc2626');   // emerald-600 / red-600
const FILL_COLOR = computed(() => isPositive.value ? 'rgba(5,150,105,0.10)' : 'rgba(220,38,38,0.10)');

const chartData = computed(() => {
    const labels = (props.series || []).map((p) => p.label);
    const data   = (props.series || []).map((p) => Number(p.v));

    return {
        labels,
        datasets: [{
            label: props.label,
            data,
            borderColor: LINE_COLOR.value,
            backgroundColor: FILL_COLOR.value,
            borderWidth: 2,
            tension: 0.32,
            pointRadius: 0,
            pointHoverRadius: 5,
            pointHoverBackgroundColor: LINE_COLOR.value,
            pointHoverBorderColor: '#ffffff',
            pointHoverBorderWidth: 2,
            fill: 'origin',
            spanGaps: true,
        }],
    };
});

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            enabled: true,
            backgroundColor: '#ffffff',
            borderColor: '#d1d5db',          // gray-300
            borderWidth: 1,
            titleColor: '#111827',           // gray-900
            titleFont: { weight: 'bold', size: 12 },
            bodyColor: '#374151',            // gray-700
            bodyFont: { family: "ui-monospace, 'SF Mono', Menlo, monospace", size: 12 },
            padding: 10,
            cornerRadius: 6,
            displayColors: true,
            usePointStyle: true,
            callbacks: {
                label: (ctx) => {
                    const v = ctx.parsed.y;
                    if (v === null || v === undefined) return null;
                    const sign = v >= 0 ? '+' : '';
                    return ` ${ctx.dataset.label}: ${sign}${v.toLocaleString(undefined, {
                        minimumFractionDigits: 2, maximumFractionDigits: 2,
                    })} ${props.unitLabel}`;
                },
            },
        },
    },
    scales: {
        x: {
            grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
            ticks: {
                color: '#4b5563',        // gray-600
                font: { size: 11 },
                maxRotation: 0,
                autoSkip: true,
                maxTicksLimit: 8,
            },
        },
        y: {
            grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
            ticks: {
                color: '#4b5563',
                font: { size: 11 },
                callback: (val) => {
                    const n = Number(val);
                    if (Math.abs(n) >= 1000) return (n / 1000).toFixed(1) + 'k ' + props.unitLabel;
                    return n.toLocaleString() + ' ' + props.unitLabel;
                },
            },
        },
    },
    elements: {
        line: { borderJoinStyle: 'round', borderCapStyle: 'round' },
    },
}));

const isEmpty = computed(() => !props.series || props.series.length < 2);
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-3">
        <div v-if="isEmpty"
             :style="{ height: height + 'px' }"
             class="flex items-center justify-center text-sm text-gray-500">
            No closed trades yet.
        </div>
        <div v-else :style="{ height: height + 'px' }">
            <Line :data="chartData" :options="chartOptions" />
        </div>
    </div>
</template>
