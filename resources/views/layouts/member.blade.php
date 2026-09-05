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
        {{--
            外枠に Alpine のルート(x-data)を置く。

            画面の中のボタンが $dispatch('open-modal', …) でモーダルを開けるのは、
            そのボタンが Alpine のコンポーネントの中にあるときだけ。ルートが無いと
            クリックしても何も起きない（キャンセルの確認ダイアログが開かなかった）。
            管理画面は appShell() が同じ役割を果たしている。
        --}}
        <div x-data="{}" class="flex min-h-screen flex-col">
            <header class="sticky top-0 z-30 bg-primary text-white shadow-sm">
                <div class="mx-auto flex w-full max-w-6xl items-center gap-3 px-4 py-3">
                    <a href="{{ route('lessons.index') }}" class="flex min-w-0 items-center gap-2">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white/20 text-sm font-bold">
                            {{ \App\Support\Theme\Theme::initial() }}
                        </span>
                        {{-- 幅の狭い端末ではマークだけにして、ナビに場所を譲る --}}
                        <span class="hidden truncate text-sm font-semibold sm:inline">{{ \App\Support\Theme\Theme::name() }}</span>
                    </a>

                    @auth
                        {{-- 会員が使う画面は 2 つだけなので、ヘッダーに並べて出す --}}
                        <nav class="ms-auto flex items-center gap-1 text-xs">
                            @foreach ([
                                ['label' => 'レッスンを探す', 'route' => 'lessons.index', 'active' => 'lessons.*'],
                                ['label' => 'マイ予約', 'route' => 'my-reservations.index', 'active' => 'my-reservations.*'],
                            ] as $link)
                                <a href="{{ route($link['route']) }}"
                                   @class([
                                       'whitespace-nowrap rounded-full px-2.5 py-1.5 transition motion-reduce:transition-none sm:px-3',
                                       'bg-white/20 font-semibold' => request()->routeIs($link['active']),
                                       'hover:bg-white/10' => ! request()->routeIs($link['active']),
                                   ])>{{ $link['label'] }}</a>
                            @endforeach
                        </nav>

                        <div>
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

                                    <x-dropdown-link :href="route('my-reservations.index')">マイ予約</x-dropdown-link>
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
