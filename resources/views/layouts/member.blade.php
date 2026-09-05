<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ \App\Support\Theme\Theme::name() }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @include('partials.theme')
        @stack('head')
    </head>

    {{--
        会員向けの外枠。

        管理画面（layouts/app.blade.php）とは共有せず、スマホでの利用を前提に
        「ヘッダー + 本文」だけの構成にしている。ナビゲーションは会員が使う
        画面だけを並べ、マスタ・ダッシュボードなど管理側の導線は出さない。
    --}}
    <body class="bg-gray-50 font-sans antialiased dark:bg-gray-900">
        <div class="flex min-h-screen flex-col">
            <header class="sticky top-0 z-30 bg-primary text-white shadow-sm">
                <div class="mx-auto flex w-full max-w-6xl items-center gap-3 px-4 py-3">
                    <a href="{{ route('lessons.index') }}" class="flex min-w-0 items-center gap-2">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/20 text-sm font-bold">
                            {{ \App\Support\Theme\Theme::initial() }}
                        </span>
                        <span class="truncate text-sm font-semibold">{{ \App\Support\Theme\Theme::name() }}</span>
                    </a>

                    @auth
                        <div class="ms-auto">
                            <x-dropdown align="right" width="48">
                                <x-slot name="trigger">
                                    <button type="button"
                                            class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-white/25 motion-reduce:transition-none">
                                        <span class="max-w-24 truncate">{{ auth()->user()->name }}</span>
                                        <svg class="h-3.5 w-3.5 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </x-slot>

                                <x-slot name="content">
                                    <div class="border-b border-gray-100 px-4 py-2 dark:border-gray-700">
                                        <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ auth()->user()->name }}</p>
                                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()->email }}</p>
                                    </div>

                                    <x-dropdown-link :href="route('lessons.index')">レッスンを探す</x-dropdown-link>
                                    <x-dropdown-link :href="route('profile.edit')">プロフィール</x-dropdown-link>

                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf

                                        <x-dropdown-link :href="route('logout')"
                                                         onclick="event.preventDefault(); this.closest('form').submit();">
                                            ログアウト
                                        </x-dropdown-link>
                                    </form>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    @endauth
                </div>
            </header>

            <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-5">
                {{ $slot }}
            </main>

            <x-toast-container />
        </div>
    </body>
</html>
