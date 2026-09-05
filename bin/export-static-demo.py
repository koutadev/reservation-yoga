#!/usr/bin/env python3
"""
本体（reservation-yoga）の実画面を、静的サイトとして書き出す。

    docker compose up -d                            # 本体を起動しておく
    docker compose exec app npm run build           # アセットを最新に
    docker compose exec app php artisan migrate:fresh --seed
    python3 bin/export-static-demo.py               # → ../yoga-demo-static へ書き出す

やっていること:
  1. デモ用アカウント（会員 / 管理者）でログインし、対象ページの HTML を取得する（Blade 描画後の実物）
  2. ページ間のリンクを静的ファイル名へ張り替え、それ以外の遷移・送信は
     「デモでは動作しません（本番では〜が起きます）」に倒す
  3. ビルド済みアセット（public/build）をそのまま持っていき、参照を相対パスにする
  4. DEMO バナー（固定日つき）と、無効化用の CSS / JS、入口の index.html を作る
  5. ダミーの会員氏名・メールは伏字にする（静的 HTML に実在感のある個人情報を残さない）

本体を直したら、このスクリプトを流し直せば静的デモも追随する。
"""

from __future__ import annotations

import argparse
import datetime
import http.cookiejar
import re
import shutil
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

MEMBER = ("member@example.com", "password")
ADMIN = ("admin@example.com", "password")

# 書き出すページ。lesson.html / lesson-full.html の URL は実データから拾うので、ここでは空にしておく。
#   (ファイル名, 本体のパス, 見出し, ログインする人)
PAGES: list[tuple[str, str, str, tuple[str, str]]] = [
    ("lessons.html", "/lessons", "空き枠を探す（会員）", MEMBER),
    ("my-reservations.html", "/my/reservations", "マイ予約（会員）", MEMBER),
    ("reservations.html", "/reservations", "予約状況（管理）", ADMIN),
    ("lesson-slots.html", "/lesson-slots", "レッスン枠管理（管理）", ADMIN),
]

# 本体のパス → 静的ファイル名（クエリなしのものだけを素直に張り替える）
STATIC_ROUTES: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"^/lessons$"), "lessons.html"),
    (re.compile(r"^/my/reservations$"), "my-reservations.html"),
    (re.compile(r"^/reservations$"), "reservations.html"),
    (re.compile(r"^/lesson-slots$"), "lesson-slots.html"),
]

# 「押したら何が起きるか」の説明。静的デモで止める操作ごとに出し分ける。
ACTION_MESSAGES: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"/lessons/\d+/reserve$"),
     "本番では、枠の行をロックして残枠を数え直したうえで予約を確定し、予約完了画面に進みます。"),
    (re.compile(r"/lessons/\d+/waitlist$"),
     "本番では、枠をロックして待ち順を採番し、キャンセル待ちに登録します。"),
    (re.compile(r"/reservations/\d+$"),
     "本番では、予約をキャンセルし、空いた席をキャンセル待ちの先頭へ繰り上げます。"),
    (re.compile(r"/waitlists/\d+$"),
     "本番では、キャンセル待ちから外れます（並び直しもできます）。"),
    (re.compile(r"/logout$"), "デモではログイン・ログアウトはできません。"),
    (re.compile(r"^/lessons\?"), "本番では、選んだ日・週のレッスンに切り替わります（この静的デモは書き出した時点の 1 画面ぶんです）。"),
    (re.compile(r"^/lesson-slots\?"), "本番では、指定した条件で絞り込んだ一覧を表示します。"),
    (re.compile(r"^/reservations\?"), "本番では、その日の稼働（KPI と枠一覧）に切り替わります。"),
    (re.compile(r"/lesson-slots/create$"), "本番では、レッスン枠の開講フォーム（単発／繰り返し）が開きます。"),
    (re.compile(r"/lesson-slots/export$"), "本番では、いまの絞り込みのまま CSV を出力します。"),
]

DEFAULT_MESSAGE = "このデモには含まれていない画面です（表示専用の静的サイトです）。"

# 書き換えの対象にしないもの（アセット）
ASSET_PATH = re.compile(r"^/?build/|\.(css|js|mjs|woff2?|ttf|png|jpe?g|gif|svg|ico|webp)$")

# 会員のダミー氏名・メール（database/seeders/YogaSampleSeeder.php の MEMBERS と対応）。
# 静的 HTML には実在感のある個人情報を残さないよう、姓 + イニシャルに置き換える。
DEMO_MEMBERS: list[tuple[str, str]] = [
    ("田中 彩", "aya.tanaka@example.com"),
    ("鈴木 玲奈", "rena.suzuki@example.com"),
    ("高橋 直樹", "naoki.takahashi@example.com"),
    ("伊藤 さくら", "sakura.ito@example.com"),
    ("渡辺 結衣", "yui.watanabe@example.com"),
    ("小林 大輔", "daisuke.kobayashi@example.com"),
    ("中村 みゆき", "miyuki.nakamura@example.com"),
    ("加藤 早苗", "sanae.kato@example.com"),
    ("吉田 拓海", "takumi.yoshida@example.com"),
    ("山本 千尋", "chihiro.yamamoto@example.com"),
    ("森 奈々", "nana.mori@example.com"),
    ("岡田 涼太", "ryota.okada@example.com"),
    ("松本 遥", "haruka.matsumoto@example.com"),
    ("清水 芽衣", "mei.shimizu@example.com"),
]


def banner(as_of: str) -> str:
    return f"""
<div class="demo-banner" role="note">
    <span class="demo-banner__tag">DEMO</span>
    <span class="demo-banner__short">
        操作は動作しません（データは架空・<strong>{as_of} 時点</strong>の想定）
    </span>
    <span class="demo-banner__long">
        これはポートフォリオ用の<strong>静的デモ</strong>です。予約・キャンセルなどの操作は動作せず、
        押すと「本番では何が起きるか」の説明が出ます。データはすべて架空で、
        <strong>{as_of} 時点の想定</strong>で書き出しています。
    </span>
</div>
"""


DEMO_CSS = """/* 静的デモ用の追記。本体のスタイルには手を入れない。 */
.demo-banner {
    position: fixed;
    top: 0;
    inset-inline: 0;
    z-index: 70;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    background: #2b2723;
    color: #f8f5f2;
    font-size: 0.75rem;
    line-height: 1.5;
}

.demo-banner strong { color: #f0a58c; font-weight: 600; }

/* 狭い画面では短い注記だけにする（画面を覆わないように） */
.demo-banner__long { display: none; }

@media (min-width: 640px) {
    .demo-banner__short { display: none; }
    .demo-banner__long { display: inline; }
}

.demo-banner__tag {
    flex-shrink: 0;
    border-radius: 9999px;
    background: #c0442a;
    padding: 0.125rem 0.5rem;
    font-weight: 700;
    letter-spacing: 0.05em;
}

/* バナーのぶんだけ本文を下げる（高さは demo.js が実測して入れる） */
body { padding-top: var(--demo-banner-height, 2.5rem); }

/* 固定サイドバー・追従ヘッダーも、バナーに潜り込まないように下げる */
#app-sidebar,
.demo-app-sticky { top: var(--demo-banner-height, 2.5rem) !important; }

/* 端末枠（index.html の iframe）の中ではバナーを出さない */
.demo-embedded .demo-banner { display: none; }
.demo-embedded body { padding-top: 0; }

/* 動かない操作は、押せないことが見て分かるようにする */
[data-demo-disabled] { cursor: not-allowed !important; }

.demo-notice {
    position: fixed;
    left: 50%;
    bottom: 1.25rem;
    z-index: 90;
    transform: translateX(-50%);
    max-width: min(90vw, 32rem);
    border-radius: 0.75rem;
    background: #2b2723;
    color: #f8f5f2;
    padding: 0.75rem 1rem;
    font-size: 0.8125rem;
    line-height: 1.6;
    box-shadow: 0 12px 28px rgb(43 39 35 / 0.28);
}

.demo-notice__tag {
    display: inline-block;
    margin-inline-end: 0.5rem;
    border-radius: 9999px;
    background: #c0442a;
    padding: 0.0625rem 0.5rem;
    font-size: 0.6875rem;
    font-weight: 700;
}

@media (prefers-reduced-motion: no-preference) {
    .demo-notice { transition: opacity 0.2s ease; }
}
"""

DEMO_JS = """/* 静的デモ用。動かない操作に「本番では何が起きるか」を返すだけの薄い層。 */
(function () {
    'use strict';

    var DEFAULT = 'このデモでは動作しません（表示専用の静的サイトです）。';

    // 端末枠（index.html の iframe）の中ではバナーを出さない
    if (window.self !== window.top) {
        document.documentElement.classList.add('demo-embedded');
    }

    function measureBanner() {
        var banner = document.querySelector('.demo-banner');

        if (! banner) {
            return;
        }

        document.documentElement.style.setProperty(
            '--demo-banner-height',
            (window.self === window.top ? banner.offsetHeight : 0) + 'px'
        );
    }

    function notice(message) {
        var el = document.querySelector('.demo-notice');

        if (! el) {
            el = document.createElement('div');
            el.className = 'demo-notice';
            el.setAttribute('role', 'status');
            document.body.appendChild(el);
        }

        el.innerHTML = '<span class="demo-notice__tag">DEMO</span>';
        el.appendChild(document.createTextNode(message || DEFAULT));
        el.style.opacity = '1';

        clearTimeout(el.dataset.timer);
        el.dataset.timer = setTimeout(function () { el.style.opacity = '0'; }, 4200);
    }

    // サーバとの通信は行わない（一覧の行クリックで開く詳細など）
    window.fetch = function () {
        notice('本番では、ここでサーバから最新の内容を読み込みます。');

        return Promise.reject(new Error('static demo'));
    };

    document.addEventListener('DOMContentLoaded', function () {
        // 送信（予約・キャンセル・絞り込みなど）はすべて止める
        document.querySelectorAll('form').forEach(function (form) {
            form.setAttribute('data-demo-disabled', 'form');

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                notice(form.dataset.demoMessage);
            });
        });

        // 一覧の行クリック（本番では詳細モーダルが開く）
        document.querySelectorAll('tr[role="link"]').forEach(function (row) {
            row.setAttribute('data-demo-disabled', 'row');
            row.dataset.demoMessage = '本番では、この行の詳細と編集フォームがモーダルで開きます。';
            row.removeAttribute('onclick');
        });

        // 追従ヘッダー（管理の上部バー・会員のヘッダー）にも印をつけて、バナーのぶん下げる
        document.querySelectorAll('header.sticky, .sticky.top-0').forEach(function (element) {
            element.classList.add('demo-app-sticky');
        });

        measureBanner();
    }, { once: true });

    window.addEventListener('resize', measureBanner);

    // 静的サイトに無い遷移先はここで止める
    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-demo-disabled]');

        if (! target || target.tagName === 'FORM') {
            return;
        }

        event.preventDefault();
        notice(target.dataset.demoMessage);
    });
})();
"""

VERCEL_JSON = """{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "cleanUrls": false,
  "trailingSlash": false
}
"""


def index_html(as_of: str) -> str:
    return f"""<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>オンラインヨガ予約システム — 静的デモ</title>
    <link rel="stylesheet" href="demo.css">
    <style>
        :root {{
            --coral: #c0442a; --ink: #2b2723; --muted: #7c746c; --line: #ece6e0; --bg: #fbf9f7;
        }}
        * {{ box-sizing: border-box; margin: 0; padding: 0; }}
        body {{
            font-family: "Hiragino Kaku Gothic ProN", "Noto Sans JP", "Yu Gothic UI", system-ui, sans-serif;
            background: var(--bg); color: var(--ink); line-height: 1.7;
        }}
        .wrap {{ max-width: 1080px; margin: 0 auto; padding: 32px 20px 72px; }}
        h1 {{ font-size: 22px; }}
        h2 {{ font-size: 15px; margin: 40px 0 6px; }}
        p.lead {{ color: var(--muted); font-size: 14px; margin-top: 8px; max-width: 46rem; }}
        p.note {{ color: var(--muted); font-size: 13px; margin-top: 4px; }}
        .brand {{ display: flex; align-items: center; gap: 10px; }}
        .brand .mark {{
            width: 34px; height: 34px; border-radius: 10px; background: var(--coral); color: #fff;
            display: flex; align-items: center; justify-content: center; font-weight: 700;
        }}
        .phones {{ display: flex; gap: 20px; flex-wrap: wrap; margin-top: 14px; }}
        .phone {{ width: 390px; max-width: 100%; }}
        .phone iframe {{
            width: 100%; height: 720px; border: 1px solid var(--line); border-radius: 22px;
            background: #fff; box-shadow: 0 10px 28px rgb(60 45 35 / 0.10);
        }}
        .phone .cap {{ font-size: 12px; color: var(--muted); text-align: center; margin-top: 8px; }}
        .cards {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; margin-top: 14px; }}
        .card {{
            display: block; border: 1px solid var(--line); border-radius: 14px; background: #fff;
            padding: 16px 18px; text-decoration: none; color: inherit;
        }}
        .card:hover {{ border-color: #dcd2ca; }}
        .card .t {{ font-weight: 600; font-size: 15px; }}
        .card .d {{ font-size: 13px; color: var(--muted); margin-top: 4px; }}
        ul.points {{ margin: 10px 0 0 1.1rem; font-size: 14px; color: var(--muted); }}
        ul.points strong {{ color: var(--ink); }}
        footer {{ margin-top: 48px; border-top: 1px solid var(--line); padding-top: 16px; font-size: 12px; color: var(--muted); }}
        code {{ background: #f2ede8; border-radius: 4px; padding: 1px 5px; font-size: 12px; }}
    </style>
</head>
<body>
{banner(as_of)}
<div class="wrap">
    <div class="brand">
        <div class="mark">ヨ</div>
        <div>
            <h1>オンラインヨガ予約システム — 静的デモ</h1>
            <p class="note">ナギサ オンラインヨガ（架空）／ Laravel 13 + PostgreSQL 16</p>
        </div>
    </div>

    <p class="lead">
        会員がスマホで空き枠を見てその場で予約でき、<strong>二重予約・定員超過を起こさない</strong>こと、
        満席時の<strong>キャンセル待ちを繰り上げて機会損失を防ぐ</strong>ことを主役に据えた予約システムです。
        このページは、実際に動いているアプリの画面をそのまま静的サイトとして書き出したものです。
    </p>

    <ul class="points">
        <li><strong>予約の確定</strong>は枠の行を <code>SELECT … FOR UPDATE</code> で押さえてから残枠を数え直す（同時アクセスでも定員を超えない）</li>
        <li><strong>キャンセル待ちの繰り上げ</strong>はキャンセルと同じロック・同じトランザクションで、空いた席の数だけ</li>
        <li>会員はスマホ前提、運営は PC 前提で<strong>レイアウトから分離</strong>。同じデータを幅で出し分け</li>
        <li>残枠・稼働率は保持せず都度算出。件数が増えても<strong>クエリ本数は変わらない</strong></li>
    </ul>

    <h2>会員（スマホ）</h2>
    <p class="note">端末幅で表示しています。枠内でそのまま操作・画面遷移ができます。</p>

    <div class="phones">
        <div class="phone">
            <iframe src="lessons.html" title="空き枠一覧（会員・スマホ）" loading="lazy"></iframe>
            <p class="cap">空き枠を探す（日付切替 ＋ リスト）</p>
        </div>
        <div class="phone">
            <iframe src="lesson.html" title="レッスン詳細（会員・スマホ）" loading="lazy"></iframe>
            <p class="cap">レッスン詳細・予約</p>
        </div>
        <div class="phone">
            <iframe src="my-reservations.html" title="マイ予約（会員・スマホ）" loading="lazy"></iframe>
            <p class="cap">マイ予約（予約中／キャンセル待ち／履歴）</p>
        </div>
    </div>

    <h2>会員（PC）</h2>
    <div class="cards">
        <a class="card" href="lessons.html">
            <div class="t">空き枠カレンダー（週）</div>
            <div class="d">時間軸 × 日〜土。各コマは「予約数 ÷ 定員」のゲージで、面積・色・数値の三重表示。同じ時間帯は上下に積みます。</div>
        </a>
        <a class="card" href="lesson-full.html">
            <div class="t">レッスン詳細（満席）</div>
            <div class="d">満席の枠ではキャンセル待ちに並べます。押すと本番の挙動を説明します。</div>
        </a>
    </div>

    <h2>管理・講師（PC）</h2>
    <div class="cards">
        <a class="card" href="reservations.html">
            <div class="t">予約状況ダッシュボード</div>
            <div class="d">その日の KPI（レッスン数・予約数・平均稼働率・キャンセル待ち）と枠一覧。行クリックで枠の詳細。</div>
        </a>
        <a class="card" href="lesson-slots.html">
            <div class="t">レッスン枠管理</div>
            <div class="d">単発／繰り返しの開講、定員・オンライン URL・中止の管理。検索・絞り込み・CSV 出力。</div>
        </a>
    </div>

    <footer>
        表示されているスタジオ名・講師名・会員名・予約はすべて架空のダミーです（実在の団体・個人とは関係ありません）。
        会員の氏名は姓 + イニシャルに伏せています。デモデータは <strong>{as_of} 時点</strong>の想定です。<br>
        この静的デモは、本体アプリの画面を <code>bin/export-static-demo.py</code> で書き出して生成しています。
    </footer>
</div>
</body>
</html>
"""


class Exporter:
    def __init__(self, base: str, out: Path, project: Path, as_of: str) -> None:
        self.base = base.rstrip("/")
        self.out = out
        self.project = project
        self.as_of = as_of
        self.opener: urllib.request.OpenerDirector | None = None
        self.lesson_pages: dict[str, str] = {}   # 本体のパス → 静的ファイル名（レッスン詳細）

    # --- 取得 -------------------------------------------------------------

    def get(self, path: str) -> str:
        assert self.opener is not None, "先に login() を呼ぶこと"
        request = urllib.request.Request(self.base + path, headers={"User-Agent": "static-demo-export"})

        with self.opener.open(request, timeout=30) as response:
            return response.read().decode("utf-8")

    def login(self, account: tuple[str, str]) -> None:
        """アカウントごとに新しいセッションでログインする。"""
        email, password = account

        jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

        html = self.get("/login")
        token = re.search(r'name="_token" value="([^"]+)"', html)

        if token is None:
            raise SystemExit("ログイン画面から CSRF トークンを取得できませんでした。")

        data = urllib.parse.urlencode({
            "_token": token.group(1),
            "email": email,
            "password": password,
        }).encode()

        request = urllib.request.Request(
            self.base + "/login", data=data, headers={"User-Agent": "static-demo-export"}
        )

        with self.opener.open(request, timeout=30) as response:
            body = response.read().decode("utf-8")

        if "ログアウト" not in body and "Log Out" not in body:
            raise SystemExit(f"ログインに失敗しました（{email}）。")

    # --- レッスン詳細の対象を実データから拾う -----------------------------

    def pick_lessons(self, lessons_html: str) -> list[tuple[str, str, str]]:
        """空きのある枠と満席の枠を 1 本ずつ選ぶ。"""
        head, _, rest = lessons_html.partition('id="day-list"')
        section = rest.partition('id="week-calendar"')[0] if rest else lessons_html

        cards = re.findall(r'<a href="[^"]*?(/lessons/(\d+))"(.*?)</a>', section, re.S)

        picked: list[tuple[str, str, str]] = []
        found_open = found_full = False

        for path, _id, body in cards:
            if not found_open and ("残り" in body or "個人" in body):
                picked.append(("lesson.html", path, "レッスン詳細（会員・空きあり）"))
                found_open = True
            elif not found_full and "満席" in body:
                picked.append(("lesson-full.html", path, "レッスン詳細（会員・満席）"))
                found_full = True

        if not picked:
            raise SystemExit("空き枠一覧からレッスン詳細のリンクを拾えませんでした（シードを確認してください）。")

        return picked

    # --- 書き換え ---------------------------------------------------------

    def static_name_for(self, path: str) -> str | None:
        """本体のパス（クエリなし）に対応する静的ファイル名。無ければ None。"""
        if path in self.lesson_pages:
            return self.lesson_pages[path]

        # 書き出していないレッスン詳細は、空きありの詳細に寄せる
        if re.match(r"^/lessons/\d+$", path) and "lesson.html" in self.lesson_pages.values():
            return "lesson.html"

        for pattern, name in STATIC_ROUTES:
            if pattern.match(path):
                return name

        return None

    def message_for(self, url: str) -> str:
        """止める操作に添える「本番では何が起きるか」。"""
        target = url[len(self.base):] if url.startswith(self.base) else url

        for pattern, message in ACTION_MESSAGES:
            if pattern.search(target):
                return message

        return DEFAULT_MESSAGE

    def rewrite_links(self, html: str) -> str:
        """href / action の行き先を、静的ファイルか「デモでは動作しません」に振り分ける。"""

        def replace(match: re.Match[str]) -> str:
            attribute, quote, url = match.group(1), match.group(2), match.group(3)

            if url.startswith("#") or url.startswith("mailto:") or url.startswith("tel:"):
                return match.group(0)

            parsed = urllib.parse.urlparse(url)

            # アセット（CSS / JS / フォントなど）はそのまま残す
            if ASSET_PATH.search(parsed.path):
                return match.group(0)

            absolute = url.startswith(self.base)

            if not absolute and (url.startswith("http://") or url.startswith("https://")):
                return match.group(0)   # 外部リンク（オンライン参加 URL など）はそのまま

            message = self.message_for(url)

            # 送信（予約・キャンセル・絞り込み）は静的サイトでは行えない
            if attribute == "action":
                return f'{attribute}={quote}#{quote} data-demo-message={quote}{message}{quote}'

            # クエリつきの遷移（日付切替・絞り込み）は、行き先が変わらないので止める
            if parsed.query:
                return f'{attribute}={quote}#{quote} data-demo-disabled={quote}link{quote} data-demo-message={quote}{message}{quote}'

            name = self.static_name_for(parsed.path.rstrip("/") or "/")

            if name is not None:
                return f"{attribute}={quote}{name}{quote}"

            return f'{attribute}={quote}#{quote} data-demo-disabled={quote}link{quote} data-demo-message={quote}{message}{quote}'

        html = re.sub(r'\b(href|action)=(["\'])([^"\']*)\2', replace, html)

        # 残った絶対 URL（JS やインライン属性の中など）も相対にしておく
        return html.replace(self.base + "/", "").replace(self.base, "")

    def scrub(self, html: str) -> str:
        """セッション由来の値と、ダミーとはいえ実在感のある個人情報を残さない。"""
        html = re.sub(r'(name="csrf-token" content=")[^"]*(")', r"\1\2", html)
        html = re.sub(r'(name="_token" value=")[^"]*(")', r"\1\2", html)

        for name, email in DEMO_MEMBERS:
            family = name.split(" ")[0]
            initial = email[0].upper()

            html = html.replace(name, f"{family} {initial}")
            html = html.replace(email, f"{initial.lower()}***@example.com")

        return html

    def inject(self, html: str, title: str) -> str:
        html = html.replace(
            "</head>",
            '    <link rel="stylesheet" href="demo.css">\n'
            '    <script src="demo.js"></script>\n'
            "</head>",
            1,
        )

        html = html.replace("<body", f'<body data-demo-page="{title}"', 1)

        # バナーは body の直後（ページ内のどこにいても見える位置）
        return re.sub(r"(<body[^>]*>)", lambda m: m.group(1) + banner(self.as_of), html, count=1)

    # --- 出力 -------------------------------------------------------------

    def write_page(self, name: str, path: str, title: str) -> None:
        html = self.get(path)
        html = self.rewrite_links(html)
        html = self.scrub(html)
        html = self.inject(html, title)

        (self.out / name).write_text(html, encoding="utf-8")
        print(f"  書き出し: {name:22} ← {path:28} ({title})")

    def export(self) -> None:
        self.out.mkdir(parents=True, exist_ok=True)

        # 会員のページ（レッスン詳細の対象は、空き枠一覧の実データから拾う）
        self.login(MEMBER)
        lessons_html = self.get("/lessons")

        lesson_pages = self.pick_lessons(lessons_html)
        self.lesson_pages = {path: name for name, path, _ in lesson_pages}

        for name, path, title, account in PAGES:
            if account is not MEMBER:
                continue

            self.write_page(name, path, title)

        for name, path, title in lesson_pages:
            self.write_page(name, path, title)

        # 管理のページ
        self.login(ADMIN)

        for name, path, title, account in PAGES:
            if account is not ADMIN:
                continue

            self.write_page(name, path, title)

        # 入口とデモ用の追記
        (self.out / "index.html").write_text(index_html(self.as_of), encoding="utf-8")
        (self.out / "demo.css").write_text(DEMO_CSS, encoding="utf-8")
        (self.out / "demo.js").write_text(DEMO_JS, encoding="utf-8")
        (self.out / "vercel.json").write_text(VERCEL_JSON, encoding="utf-8")

        # アセット（ビルド済み）
        build_src = self.project / "public" / "build"
        build_dest = self.out / "build"

        if not build_src.is_dir():
            raise SystemExit("public/build がありません。先に npm run build を実行してください。")

        if build_dest.exists():
            shutil.rmtree(build_dest)

        shutil.copytree(build_src, build_dest)
        print(f"  アセット: build/ ({sum(1 for f in build_dest.rglob('*') if f.is_file())} ファイル)")

        favicon = self.project / "public" / "favicon.ico"

        if favicon.exists():
            shutil.copy2(favicon, self.out / "favicon.ico")


def main() -> int:
    project = Path(__file__).resolve().parent.parent

    parser = argparse.ArgumentParser(description="本体の実画面を静的デモとして書き出す")
    parser.add_argument("--base", default="http://localhost:8080", help="本体の URL")
    parser.add_argument("--out", default=str(project.parent / "yoga-demo-static"), help="書き出し先")
    parser.add_argument("--as-of", default=datetime.date.today().isoformat(), help="デモデータの基準日（注記に出す）")
    args = parser.parse_args()

    out = Path(args.out).resolve()

    print(f"本体 : {args.base}")
    print(f"出力 : {out}")
    print(f"基準日: {args.as_of}\n")

    try:
        Exporter(args.base, out, project, args.as_of).export()
    except urllib.error.URLError as error:
        raise SystemExit(f"本体に接続できません（docker compose up -d は済んでいますか）: {error}")

    print("\n完了しました。ローカル確認:")
    print(f"  python3 -m http.server 4173 --directory {out}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
