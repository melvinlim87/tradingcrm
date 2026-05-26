<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, usePage, router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    admins: { type: Array, default: () => [] },
    viewer_id: { type: Number, default: 0 },
});

const flash = computed(() => usePage().props.flash || {});

const showForm = ref(false);
const editingId = ref(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'admin',
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.role = 'admin';
    showForm.value = true;
};

const openEdit = (a) => {
    editingId.value = a.id;
    form.name = a.name;
    form.email = a.email;
    form.password = '';
    form.role = 'admin';
    showForm.value = true;
};

const cancel = () => {
    showForm.value = false;
    editingId.value = null;
    form.reset();
    form.role = 'admin';
};

const submit = () => {
    if (editingId.value) {
        form.put(route('users.update', editingId.value), {
            onSuccess: () => { showForm.value = false; editingId.value = null; },
        });
    } else {
        form.post(route('users.store'), {
            onSuccess: () => { showForm.value = false; },
        });
    }
};

const destroy = (a) => {
    if (!confirm(`Delete admin "${a.email}"? This will also unlink the accounts they created from being theirs (they remain in the system).`)) return;
    router.delete(route('users.destroy', a.id));
};
</script>

<template>
    <Head title="Admins" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold text-black">Admin Management</h2>
                <Link :href="route('users.index')" class="text-sm font-bold text-indigo-600 hover:text-indigo-800">
                    → All Users (incl. viewers)
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

                <div v-if="flash.success" class="mb-4 rounded-md bg-green-50 p-4 text-sm text-green-800">
                    {{ flash.success }}
                </div>

                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                        <div>
                            <h3 class="text-lg font-bold text-black">
                                Admins
                                <span class="ml-2 text-sm font-normal text-black">({{ admins.length }})</span>
                            </h3>
                            <p class="mt-0.5 text-sm text-black">
                                Admins manage their own MT5 accounts + their own users.
                                Administrator (you) sees everything.
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openCreate"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700"
                        >
                            + Add Admin
                        </button>
                    </div>

                    <!-- Form -->
                    <div v-if="showForm" class="border-b border-gray-200 bg-gray-50 px-6 py-5">
                        <h4 class="mb-4 text-base font-bold text-black">
                            {{ editingId ? 'Edit Admin' : 'New Admin' }}
                        </h4>
                        <form @submit.prevent="submit" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="block text-sm font-bold text-black">Name *</label>
                                <input v-model="form.name" type="text" required
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                                <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-black">Email *</label>
                                <input v-model="form.email" type="email" required
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-bold text-black">
                                    Password {{ editingId ? '(leave blank to keep current)' : '*' }}
                                </label>
                                <input v-model="form.password" type="password" :required="!editingId" minlength="8"
                                       autocomplete="new-password"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                                <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                            </div>

                            <div class="md:col-span-2 flex justify-end space-x-3">
                                <button type="button" @click="cancel"
                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-bold text-black hover:bg-gray-100">
                                    Cancel
                                </button>
                                <button type="submit" :disabled="form.processing"
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700 disabled:opacity-50">
                                    {{ form.processing ? 'Saving…' : (editingId ? 'Update' : 'Create Admin') }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-xs font-bold uppercase tracking-wider text-black">
                                    <th class="px-6 py-3">Name</th>
                                    <th class="px-6 py-3">Email</th>
                                    <th class="px-6 py-3 text-right">Accounts Added</th>
                                    <th class="px-6 py-3 text-right">Users Managed</th>
                                    <th class="px-6 py-3">Added By</th>
                                    <th class="px-6 py-3">Created</th>
                                    <th class="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-if="admins.length === 0">
                                    <td colspan="7" class="px-6 py-10 text-center text-sm text-black">
                                        No admins yet. Add one with the button above.
                                    </td>
                                </tr>
                                <tr v-for="a in admins" :key="a.id" class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-6 py-4 text-sm font-bold text-black">{{ a.name }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-black">{{ a.email }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-mono font-bold text-black">
                                        {{ a.created_accounts_count }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm font-mono font-bold text-black">
                                        {{ a.managed_users_count }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-black">
                                        <span v-if="a.creator">{{ a.creator.name }}</span>
                                        <span v-else class="italic">—</span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-black">
                                        {{ new Date(a.created_at).toLocaleDateString() }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                        <button type="button" @click="openEdit(a)"
                                            class="mr-3 font-bold text-indigo-600 hover:text-indigo-900">
                                            Edit
                                        </button>
                                        <button type="button" @click="destroy(a)"
                                            class="font-bold text-red-600 hover:text-red-900"
                                            :disabled="a.id === viewer_id">
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
