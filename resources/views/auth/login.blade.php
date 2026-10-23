<x-layouts.app title="Sign in">
    <div class="flex min-h-full items-center justify-center px-4 py-12">
        <form method="POST" action="{{ route('login') }}"
              class="w-full max-w-sm space-y-5 rounded-lg border border-stone-200 bg-white p-7 shadow-sm dark:border-stone-800 dark:bg-stone-900">
            @csrf
            <div class="space-y-1">
                <h1 class="text-lg font-semibold">Planner</h1>
                <p class="text-sm text-stone-500 dark:text-stone-400">Sign in to plan your week.</p>
            </div>

            <label class="block space-y-1.5">
                <span class="text-xs font-medium uppercase tracking-wider text-stone-500">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-600 focus:ring-1 focus:ring-teal-600 dark:border-stone-700 dark:bg-stone-950">
            </label>

            <label class="block space-y-1.5">
                <span class="text-xs font-medium uppercase tracking-wider text-stone-500">Password</span>
                <input type="password" name="password" required autocomplete="current-password"
                       class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-600 focus:ring-1 focus:ring-teal-600 dark:border-stone-700 dark:bg-stone-950">
            </label>

            @error('email')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <button type="submit"
                    class="w-full rounded-md bg-teal-700 px-3 py-2 text-sm font-medium text-white hover:bg-teal-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                Sign in
            </button>
        </form>
    </div>
</x-layouts.app>
