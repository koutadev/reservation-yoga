<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * 会員向け画面の外枠（<x-member-layout>）。
 *
 * 管理画面の <x-app-layout>（サイドナビ + パンくず + 高密度）とは別物で、
 * スマホでの利用を前提にしたシンプルなヘッダーだけを持つ。
 */
class MemberLayout extends Component
{
    public function render(): View
    {
        return view('layouts.member');
    }
}
