/**
 * README 用スクリーンショットの撮影スクリプト。
 *
 * シードした状態のアプリに実際にログインして、代表画面を撮る。
 * ホスト側の Chrome を puppeteer-core で動かすので、コンテナには何も入れない。
 *
 *   # 1) アプリを起動して、デモデータを入れる
 *   docker compose up -d
 *   docker compose exec app php artisan migrate:fresh --seed
 *
 *   # 2) 撮影の道具を、プロジェクトの外に用意する（node_modules を汚さない）
 *   mkdir -p /tmp/yoga-shots && (cd /tmp/yoga-shots && npm install --no-save puppeteer-core)
 *
 *   # 3) 撮影（Chrome はホストにインストール済みのものを使う）
 *   PUPPETEER_HOME=/tmp/yoga-shots node docs/screenshots/capture.mjs
 *
 * 環境変数で差し替えられる。
 *   PUPPETEER_HOME … puppeteer-core を入れたディレクトリ
 *   BASE_URL       … 既定 http://localhost:8080
 *   CHROME_PATH    … 既定 /Applications/Google Chrome.app/Contents/MacOS/Google Chrome
 */

import { mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// puppeteer-core はこのプロジェクトの依存にしていない（コンテナ側の node_modules を
// ホストの都合で壊さないため）。別のディレクトリに入れて、その場所を教えてもらう。
const require = createRequire(
    process.env.PUPPETEER_HOME ? `${process.env.PUPPETEER_HOME}/` : import.meta.url,
);

const puppeteer = require('puppeteer-core');

const BASE_URL = process.env.BASE_URL ?? 'http://localhost:8080';
const CHROME_PATH =
    process.env.CHROME_PATH ?? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const OUT_DIR = resolve(dirname(fileURLToPath(import.meta.url)));

const MOBILE = { width: 390, height: 844, deviceScaleFactor: 2 };
const DESKTOP = { width: 1440, height: 900, deviceScaleFactor: 2 };

/** 撮る画面。順に上から撮っていく。 */
const SHOTS = [
    {
        file: 'member-lessons-mobile.png',
        login: 'member@example.com',
        path: '/lessons',
        viewport: MOBILE,
        caption: '会員（スマホ）: 空き枠一覧',
    },
    {
        file: 'member-lesson-detail-mobile.png',
        login: 'member@example.com',
        path: '/lessons',
        viewport: MOBILE,
        // 一覧（スマホのリスト）から、まだ空きのあるレッスンを開く
        follow: '#day-list a[href^="http"][href*="/lessons/"]',
        followText: '残り',
        caption: '会員（スマホ）: レッスン詳細・予約',
    },
    {
        file: 'member-my-reservations-mobile.png',
        login: 'member@example.com',
        path: '/my/reservations',
        viewport: MOBILE,
        caption: '会員（スマホ）: マイ予約',
    },
    {
        file: 'member-calendar-desktop.png',
        login: 'member@example.com',
        path: '/lessons',
        viewport: DESKTOP,
        fullPage: true,
        caption: '会員（PC）: 空き枠カレンダー（週）',
    },
    {
        file: 'admin-dashboard.png',
        login: 'admin@example.com',
        path: '/reservations',
        viewport: DESKTOP,
        fullPage: true,
        caption: '管理: 予約状況ダッシュボード',
    },
    {
        file: 'admin-lesson-slots.png',
        login: 'admin@example.com',
        path: '/lesson-slots',
        viewport: DESKTOP,
        caption: '管理: レッスン枠管理',
    },
];

async function login(page, email) {
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'networkidle0' });

    // すでにログイン済みならログイン画面から飛ばされる
    if (page.url().includes('/login')) {
        await page.type('#email', email);
        await page.type('#password', 'password');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('button[type="submit"]'),
        ]);
    }
}

const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: 'new',
    args: ['--hide-scrollbars', '--force-color-profile=srgb'],
});

await mkdir(OUT_DIR, { recursive: true });

// ログインする人ごとに、別のブラウザコンテキスト（＝別のセッション）で撮る
const logins = [...new Set(SHOTS.map((shot) => shot.login))];

for (const email of logins) {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();

    page.setDefaultTimeout(60_000);
    page.setDefaultNavigationTimeout(60_000);

    await login(page, email);

    for (const shot of SHOTS.filter((candidate) => candidate.login === email)) {
        await page.setViewport(shot.viewport);
        await page.goto(`${BASE_URL}${shot.path}`, { waitUntil: 'networkidle0' });

        if (shot.follow) {
            // クリックの代わりに、そのリンク先へ直接移動する（撮影が安定する）
            const href = await page.evaluate(
                (selector, needle) => {
                    const links = [...document.querySelectorAll(selector)];
                    const match = needle
                        ? links.find((link) => link.textContent.includes(needle))
                        : links[0];

                    return (match ?? links[0])?.href ?? null;
                },
                shot.follow,
                shot.followText ?? null,
            );

            if (href !== null) {
                await page.goto(href, { waitUntil: 'networkidle0' });
            }
        }

        // Alpine の初期化とトランジションが落ち着くのを待つ
        await new Promise((done) => setTimeout(done, 400));

        await page.screenshot({ path: resolve(OUT_DIR, shot.file), fullPage: shot.fullPage ?? false });

        console.log(`✓ ${shot.file}  (${shot.caption})`);
    }

    await context.close();
}

await browser.close();
