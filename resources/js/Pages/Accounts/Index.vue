<script setup>
import { ref, computed } from 'vue';
// (computed already imported)
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    viewer_role: { type: String, default: 'user' },
    can_create: { type: Boolean, default: false },
});

const isAdministrator = computed(() => props.viewer_role === 'administrator');

const flash = computed(() => usePage().props.flash || {});

const showForm = ref(false);
const editingId = ref(null);

const form = useForm({
    account_number: '',
    broker: 'RS Finance',
    drawdown_alert_threshold: 2.0,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.broker = 'RS Finance';
    form.drawdown_alert_threshold = 2.0;
    showForm.value = true;
};

const openEdit = (account) => {
    editingId.value = account.id;
    form.account_number = account.account_number;
    form.broker = account.broker;
    form.drawdown_alert_threshold = account.drawdown_alert_threshold;
    showForm.value = true;
};

const cancel = () => {
    showForm.value = false;
    editingId.value = null;
    form.reset();
};

const submit = () => {
    if (editingId.value) {
        form.put(route('accounts.update', editingId.value), {
            onSuccess: () => { showForm.value = false; editingId.value = null; },
        });
    } else {
        form.post(route('accounts.store'), {
            onSuccess: () => { showForm.value = false; },
        });
    }
};

const destroy = (account) => {
    if (!confirm(`Delete account #${account.account_number}? This cannot be undone.`)) return;
    router.delete(route('accounts.destroy', account.id));
};

const statusClass = (status) => ({
    online: 'bg-green-100 text-green-700',
    offline: 'bg-gray-100 text-gray-600',
    disabled: 'bg-red-100 text-red-700',
}[status] || 'bg-gray-100 text-gray-600');
</script>

<template>
    <Head title="MT5 Accounts" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">MT5 Accounts</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

                <div v-if="flash.success" class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">
                    {{ flash.success }}
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                        <h3 class="text-lg font-medium text-gray-900">
                            Registered Accounts
                            <span class="ml-2 text-sm font-normal text-gray-500">({{ accounts.length }})</span>
                        </h3>
                        <button
                            v-if="can_create"
                            type="button"
                            @click="openCreate"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                        >
                            + Add Account
                        </button>
                        <span v-else class="text-sm italic text-black">Your role ({{ viewer_role }}) cannot add accounts.</span>
                    </div>

                    <!-- Form -->
                    <div v-if="showForm" class="border-b border-gray-200 bg-gray-50 px-6 py-5">
                        <h4 class="mb-4 text-base font-semibold text-gray-800">
                            {{ editingId ? 'Edit Account' : 'New Account' }}
                        </h4>
                        <form @submit.prevent="submit" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Account Number *</label>
                                <input
                                    v-model="form.account_number"
                                    type="number"
                                    inputmode="numeric"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                                <p v-if="form.errors.account_number" class="mt-1 text-xs text-red-600">
                                    {{ form.errors.account_number }}
                                </p>
                            </div>


                            <div>
                                <label class="block text-sm font-medium text-gray-700">Broker *</label>
                                <input
                                    v-model="form.broker"
                                    type="text"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                                <p v-if="form.errors.broker" class="mt-1 text-xs text-red-600">
                                    {{ form.errors.broker }}
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Drawdown Alert Threshold (%) *
                                </label>
                                <input
                                    v-model="form.drawdown_alert_threshold"
                                    type="number"
                                    step="0.1"
                                    min="0.1"
                                    max="50"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                                <p v-if="form.errors.drawdown_alert_threshold" class="mt-1 text-xs text-red-600">
                                    {{ form.errors.drawdown_alert_threshold }}
                                </p>
                            </div>

                            <div class="md:col-span-2 flex justify-end space-x-3">
                                <button
                                    type="button"
                                    @click="cancel"
                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {{ form.processing ? 'Saving…' : (editingId ? 'Update' : 'Create') }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    <th class="px-6 py-3">Account #</th>
                                    <th class="px-6 py-3">Account Name</th>
                                    <th class="px-6 py-3">Broker</th>
                                    <th class="px-6 py-3 text-right">Balance</th>
                                    <th class="px-6 py-3 text-right">Equity</th>
                                    <th class="px-6 py-3 text-right">DD %</th>
                                    <th class="px-6 py-3 text-right">Alert ≥</th>
                                    <th class="px-6 py-3">Status</th>
                                    <th v-if="isAdministrator" class="px-6 py-3">Added By</th>
                                    <th class="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-if="accounts.length === 0">
                                    <td colspan="9" class="px-6 py-10 text-center text-sm text-gray-500">
                                        No accounts yet. Click <strong>Add Account</strong> above to register the first MT5 account.
                                    </td>
                                </tr>
                                <tr v-for="acc in accounts" :key="acc.id" class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-6 py-4 text-sm font-mono text-gray-900">
                                        #{{ acc.account_number }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                        {{ acc.account_name || '—' }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">
                                        {{ acc.broker }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-mono text-gray-900">
                                        {{ Number(acc.balance).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-mono text-gray-900">
                                        {{ Number(acc.equity).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-mono"
                                        :class="acc.drawdown_percent > acc.drawdown_alert_threshold ? 'text-red-600 font-semibold' : 'text-gray-700'">
                                        {{ Number(acc.drawdown_percent).toFixed(2) }}%
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-mono text-gray-700">
                                        {{ Number(acc.drawdown_alert_threshold).toFixed(2) }}%
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusClass(acc.status)]">
                                            {{ acc.status }}
                                        </span>
                                    </td>
                                    <td v-if="isAdministrator" class="whitespace-nowrap px-6 py-4 text-sm text-black">
                                        <span v-if="acc.creator">
                                            {{ acc.creator.name }} <span class="text-xs">({{ acc.creator.role }})</span>
                                        </span>
                                        <span v-else class="italic text-black">—</span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                        <button
                                            type="button"
                                            @click="openEdit(acc)"
                                            class="mr-3 text-indigo-600 hover:text-indigo-900"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            @click="destroy(acc)"
                                            class="text-red-600 hover:text-red-900"
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
