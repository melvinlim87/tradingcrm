<script setup>
import { ref, computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link, usePage } from '@inertiajs/vue3';

const showingMobileSidebar = ref(false);
const userMenuOpen = ref(false);

const user = computed(() => usePage().props.auth?.user || {});
const role = computed(() => user.value.role || 'user');

const isAdministrator = computed(() => role.value === 'administrator');
const canManageUsers = computed(() => isAdministrator.value || role.value === 'admin');

const navItems = computed(() => {
    const items = [
        { name: 'Dashboard', route: 'dashboard',       icon: 'M3 12l9-9 9 9M5 10v10h4v-6h6v6h4V10' },
        { name: 'Analysis',  route: 'analysis.index',  icon: 'M3 3v18h18M7 14l4-4 4 4 6-6' },
        { name: 'Accounts',  route: 'accounts.index',  icon: 'M3 7h18M3 12h18M3 17h18' },
    ];
    if (canManageUsers.value) {
        items.push({ name: 'Users', route: 'users.index', icon: 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-4a4 4 0 11-8 0 4 4 0 018 0zm6 0a4 4 0 11-8 0 4 4 0 018 0z' });
    }
    if (isAdministrator.value) {
        items.push({ name: 'Admins', route: 'admins.index', icon: 'M12 11a4 4 0 100-8 4 4 0 000 8zm0 2c-4 0-8 2-8 6v1h16v-1c0-4-4-6-8-6z' });
    }
    return items;
});

const roleBadge = computed(() => ({
    administrator: 'bg-purple-100 text-purple-800',
    admin:         'bg-indigo-100 text-indigo-800',
    user:          'bg-gray-200 text-gray-800',
}[role.value] || 'bg-gray-200 text-gray-800'));
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <!-- ===== Mobile overlay ===== -->
        <div v-if="showingMobileSidebar"
             class="fixed inset-0 z-30 bg-black/50 md:hidden"
             @click="showingMobileSidebar = false">
        </div>

        <!-- ===== Sidebar ===== -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-gray-900 text-gray-100 transition-transform md:translate-x-0',
                showingMobileSidebar ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
            ]">
            <!-- Brand -->
            <div class="flex items-center gap-3 border-b border-gray-800 px-5 py-4">
                <Link :href="route('dashboard')" class="flex items-center gap-3">
                    <ApplicationLogo class="h-8 w-auto fill-current text-white" />
                    <span class="text-lg font-bold tracking-tight text-white">QuantATM</span>
                </Link>
            </div>

            <!-- Nav -->
            <nav class="flex-1 space-y-1 overflow-y-auto p-3">
                <Link
                    v-for="item in navItems"
                    :key="item.route"
                    :href="route(item.route)"
                    :class="[
                        'group flex items-center gap-3 rounded-md px-3 py-2.5 text-sm font-semibold transition',
                        route().current(item.route) || route().current(item.route + '.*')
                            ? 'bg-indigo-600 text-white'
                            : 'text-gray-300 hover:bg-gray-800 hover:text-white',
                    ]"
                    @click="showingMobileSidebar = false">
                    <svg class="h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                    </svg>
                    {{ item.name }}
                </Link>
            </nav>

            <!-- User block -->
            <div class="relative border-t border-gray-800 p-3">
                <button
                    type="button"
                    @click="userMenuOpen = !userMenuOpen"
                    class="flex w-full items-center gap-3 rounded-md p-2 text-left transition hover:bg-gray-800">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-500 text-sm font-bold uppercase text-white">
                        {{ (user.name || '?').charAt(0) }}
                    </div>
                    <div class="flex-1 overflow-hidden">
                        <p class="truncate text-sm font-bold text-white">{{ user.name }}</p>
                        <span :class="['inline-block rounded px-1.5 py-0.5 text-[10px] font-bold', roleBadge]">
                            {{ role }}
                        </span>
                    </div>
                    <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <!-- Dropdown -->
                <div v-if="userMenuOpen"
                     class="absolute bottom-full left-3 right-3 mb-2 rounded-md border border-gray-700 bg-gray-800 py-1 shadow-xl">
                    <Link :href="route('profile.edit')"
                        class="block px-4 py-2 text-sm text-gray-200 hover:bg-gray-700"
                        @click="userMenuOpen = false">
                        Profile
                    </Link>
                    <Link :href="route('logout')" method="post" as="button"
                        class="block w-full px-4 py-2 text-left text-sm text-red-400 hover:bg-gray-700"
                        @click="userMenuOpen = false">
                        Log Out
                    </Link>
                </div>
            </div>
        </aside>

        <!-- ===== Main column ===== -->
        <div class="md:pl-60">
            <!-- Mobile top bar -->
            <header class="sticky top-0 z-20 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 md:hidden">
                <button @click="showingMobileSidebar = true" class="rounded p-2 text-gray-700 hover:bg-gray-100">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <span class="text-base font-bold text-gray-900">QuantATM</span>
                <div class="w-9"></div>
            </header>

            <!-- Page heading -->
            <header v-if="$slots.header" class="border-b border-gray-200 bg-white shadow-sm">
                <div class="px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
