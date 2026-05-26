<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    users: { type: Array, default: () => [] },
    assignable_accounts: { type: Array, default: () => [] },
    assignable_roles: { type: Array, default: () => [] },
    viewer_role: { type: String, default: 'user' },
    viewer_id: { type: Number, default: 0 },
});

const flash = computed(() => usePage().props.flash || {});
const isAdministrator = computed(() => props.viewer_role === 'administrator');

const showForm = ref(false);
const editingId = ref(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: '',
    assigned_account_ids: [],
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.role = props.assignable_roles[0] || 'user';
    showForm.value = true;
};

const openEdit = (u) => {
    editingId.value = u.id;
    form.name = u.name;
    form.email = u.email;
    form.password = '';
    form.role = u.role;
    form.assigned_account_ids = (u.assigned_accounts || []).map((a) => a.id);
    showForm.value = true;
};

const cancel = () => {
    showForm.value = false;
    editingId.value = null;
    form.reset();
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

const destroy = (u) => {
    if (!confirm(`Delete user "${u.email}"? This cannot be undone.`)) return;
    router.delete(route('users.destroy', u.id));
};

const roleBadge = (r) => ({
    administrator: 'bg-purple-100 text-purple-800 border border-purple-300',
    admin: 'bg-indigo-100 text-indigo-800 border border-indigo-300',
    user: 'bg-gray-100 text-gray-800 border border-gray-300',
}[r] || 'bg-gray-100 text-gray-800');

const canEdit = (u) => {
    if (props.viewer_role === 'administrator') return u.id !== props.viewer_id;
    if (props.viewer_role === 'admin') return u.role === 'user' && u.created_by === props.viewer_id;
    return false;
};

const toggleAccount = (id) => {
    const idx = form.assigned_account_ids.indexOf(id);
    if (idx >= 0) form.assigned_account_ids.splice(idx, 1);
    else form.assigned_account_ids.push(id);
};
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-bold text-black">User Management</h2>
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
                                Users
                                <span class="ml-2 text-sm font-normal text-black">({{ users.length }})</span>
                            </h3>
                            <p class="mt-0.5 text-sm text-black">
                                <template v-if="isAdministrator">
                                    You see <strong>all users</strong>. Sub-admins only see users they created.
                                </template>
                                <template v-else>
                                    You see <strong>only users you created</strong>. You can manage their account access.
                                </template>
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="openCreate"
                            class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700"
                        >
                            + Add User
                        </button>
                    </div>

                    <!-- Form -->
                    <div v-if="showForm" class="border-b border-gray-200 bg-gray-50 px-6 py-5">
                        <h4 class="mb-4 text-base font-bold text-black">
                            {{ editingId ? 'Edit User' : 'New User' }}
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
                            <div>
                                <label class="block text-sm font-bold text-black">
                                    Password {{ editingId ? '(leave blank to keep current)' : '*' }}
                                </label>
                                <input v-model="form.password" type="password" :required="!editingId" minlength="8"
                                       autocomplete="new-password"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                                <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-black">Role *</label>
                                <select v-model="form.role" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option v-for="r in assignable_roles" :key="r" :value="r">{{ r }}</option>
                                </select>
                                <p v-if="form.errors.role" class="mt-1 text-xs text-red-600">{{ form.errors.role }}</p>
                            </div>

                            <!-- Account assignment — only shown if role = user -->
                            <div v-if="form.role === 'user'" class="md:col-span-2">
                                <label class="block text-sm font-bold text-black">
                                    Assigned Accounts
                                    <span class="ml-1 text-xs font-normal">(only role = user gets explicit assignment)</span>
                                </label>
                                <div v-if="assignable_accounts.length === 0" class="mt-1 rounded-md bg-yellow-50 p-3 text-sm text-yellow-900">
                                    No accounts available to assign. Add accounts first under <em>Accounts</em>.
                                </div>
                                <div v-else class="mt-1 max-h-48 overflow-y-auto rounded-md border border-gray-300 bg-white p-3">
                                    <label
                                        v-for="acc in assignable_accounts"
                                        :key="acc.id"
                                        class="flex cursor-pointer items-center gap-2 py-1 hover:bg-gray-50">
                                        <input
                                            type="checkbox"
                                            :checked="form.assigned_account_ids.includes(acc.id)"
                                            @change="toggleAccount(acc.id)"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <span class="font-mono text-sm font-bold text-black">#{{ acc.account_number }}</span>
                                        <span class="text-sm text-black">{{ acc.account_name || '—' }}</span>
                                        <span class="text-xs text-black">· {{ acc.broker }}</span>
                                    </label>
                                </div>
                                <p v-if="form.errors.assigned_account_ids" class="mt-1 text-xs text-red-600">
                                    {{ form.errors.assigned_account_ids }}
                                </p>
                            </div>

                            <div class="md:col-span-2 flex justify-end space-x-3">
                                <button type="button" @click="cancel"
                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-bold text-black hover:bg-gray-100">
                                    Cancel
                                </button>
                                <button type="submit" :disabled="form.processing"
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700 disabled:opacity-50">
                                    {{ form.processing ? 'Saving…' : (editingId ? 'Update' : 'Create') }}
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
                                    <th class="px-6 py-3">Role</th>
                                    <th class="px-6 py-3">Assigned Accounts</th>
                                    <th v-if="isAdministrator" class="px-6 py-3">Created By</th>
                                    <th class="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-if="users.length === 0">
                                    <td :colspan="isAdministrator ? 6 : 5" class="px-6 py-10 text-center text-sm text-black">
                                        No users to show.
                                    </td>
                                </tr>
                                <tr v-for="u in users" :key="u.id" class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-6 py-4 text-sm font-bold text-black">{{ u.name }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-black">{{ u.email }}</td>
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <span :class="['rounded-full px-2 py-0.5 text-xs font-bold', roleBadge(u.role)]">
                                            {{ u.role }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-black">
                                        <template v-if="u.role === 'user'">
                                            <span v-if="!u.assigned_accounts?.length" class="italic">— none —</span>
                                            <span v-else class="flex flex-wrap gap-1">
                                                <span v-for="a in u.assigned_accounts" :key="a.id"
                                                    class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs">
                                                    #{{ a.account_number }}
                                                </span>
                                            </span>
                                        </template>
                                        <span v-else class="text-xs italic text-black">
                                            (auto — sees own / all per role)
                                        </span>
                                    </td>
                                    <td v-if="isAdministrator" class="whitespace-nowrap px-6 py-4 text-sm text-black">
                                        <span v-if="u.creator">{{ u.creator.name }} <span class="text-xs">({{ u.creator.role }})</span></span>
                                        <span v-else class="italic">—</span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                        <template v-if="canEdit(u)">
                                            <button type="button" @click="openEdit(u)"
                                                class="mr-3 font-bold text-indigo-600 hover:text-indigo-900">
                                                Edit
                                            </button>
                                            <button type="button" @click="destroy(u)"
                                                class="font-bold text-red-600 hover:text-red-900">
                                                Delete
                                            </button>
                                        </template>
                                        <span v-else class="text-xs italic text-black">read-only</span>
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
