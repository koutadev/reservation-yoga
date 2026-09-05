<?php

namespace Database\Seeders;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Enums\ReminderChannel;
use App\Enums\ReminderType;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Enums\WaitlistStatus;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Models\Reminder;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Waitlist;
use App\Support\Lessons\RecurringSlots;
use App\Support\Reminders\ReminderSchedule;
use App\Support\Reservations\BookingDenied;
use App\Support\Reservations\ReservationBooking;
use App\Support\Reservations\ReservationCancellation;
use App\Support\Reservations\WaitlistRegistration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * オンラインヨガのサンプルデータ（本番環境では実行しない）。
 *
 * 画面を作る前でも状態を確認できるよう、次の並びを用意する。
 *   - これからの枠：空きあり / 残りわずか / 満席（キャンセル待ちつき）/ マンツーマン
 *   - 過去の枠：出席済みの履歴
 *   - 中止になった枠
 *
 * 乱数は使わず固定の並びで作るので、何度シードしても同じデータになる。
 */
class YogaSampleSeeder extends Seeder
{
    /** インストラクター: [氏名, プロフィール, ログインするユーザーのメール(任意)] */
    private const INSTRUCTORS = [
        // 講師・運営(staff)としてログインし、自分の枠だけを編集できることを確かめられるようにする
        ['佐倉 みなと', 'RYT200 取得。呼吸を整えるベーシックなクラスを担当。オンライン指導歴 5 年。', 'staff@example.com'],
        ['如月 あかり', 'アシュタンガ・パワーヨガ担当。運動量のあるクラスで体を動かしたい方向け。', null],
        ['南 ひなた', 'マタニティ・リラックスヨガ担当。はじめての方や、休息を取りたい方に。', null],
        ['白石 かえで', 'ピラティス出身。姿勢改善・肩こり向けのパーソナルレッスンが中心。', null],
    ];

    /**
     * 定期スケジュール: [レッスン名, 講師の番号, 曜日(0=日〜6=土), 開始, 終了, 定員]
     *
     * 繰り返し登録(RecurringSlots)にそのまま渡す。実際の教室の週次スケジュールに近い形。
     */
    private const WEEKLY_SCHEDULE = [
        ['朝のベーシックヨガ', 0, [2, 4], '07:30', '08:15', 12],   // 毎週 火・木の朝
        ['夜のリラックスヨガ', 2, [3], '20:00', '21:00', 12],       // 毎週 水の夜
        ['おやすみ前ストレッチ', 1, [3], '20:00', '21:00', 10],     // 水の夜は 2 クラス並行(カレンダーで上下に積まれる)
        ['パワーヨガ（中級）', 1, [1, 5], '20:00', '21:00', 8],     // 毎週 月・金の夜
        ['肩こり改善ヨガ', 0, [6], '10:00', '10:45', 10],           // 毎週 土の午前
        ['週末モーニングフロー', 1, [0, 6], '08:30', '09:30', 12], // 毎週 土・日の朝
        ['やさしいヨガ（初心者向け）', 2, [1, 3, 5], '10:00', '10:45', 12], // 毎週 月・水・金の午前
        ['マタニティヨガ', 2, [0], '16:00', '16:45', 6],           // 毎週 日の午後
    ];

    /** 定期スケジュールを流し込む週数 */
    private const WEEKS_AHEAD = 6;

    /** レッスンのメニュー: [レッスン名, 所要分, 定員] */
    private const LESSONS = [
        ['朝のベーシックヨガ', 45, 12],
        ['夜のリラックスヨガ', 60, 12],
        ['パワーヨガ（中級）', 60, 8],
        ['肩こり改善ヨガ', 45, 10],
        ['マタニティヨガ', 45, 6],
        ['ランチタイムヨガ', 30, 10],
    ];

    /** 会員: [氏名, メール] */
    private const MEMBERS = [
        ['田中 彩', 'aya.tanaka@example.com'],
        ['鈴木 玲奈', 'rena.suzuki@example.com'],
        ['高橋 直樹', 'naoki.takahashi@example.com'],
        ['伊藤 さくら', 'sakura.ito@example.com'],
        ['渡辺 結衣', 'yui.watanabe@example.com'],
        ['小林 大輔', 'daisuke.kobayashi@example.com'],
        ['中村 みゆき', 'miyuki.nakamura@example.com'],
        ['加藤 早苗', 'sanae.kato@example.com'],
        ['吉田 拓海', 'takumi.yoshida@example.com'],
        ['山本 千尋', 'chihiro.yamamoto@example.com'],
        ['森 奈々', 'nana.mori@example.com'],
        ['岡田 涼太', 'ryota.okada@example.com'],
        ['松本 遥', 'haruka.matsumoto@example.com'],
        ['清水 芽衣', 'mei.shimizu@example.com'],
    ];

    public function run(): void
    {
        if (Instructor::query()->exists()) {
            return;
        }

        // 大量投入なので操作ログは止める（デモでは利用者自身の操作だけを残す）
        config(['activity_log.enabled' => false]);

        try {
            $instructors = $this->createInstructors();
            $members = $this->createMembers();

            $upcoming = $this->createUpcomingSlots($instructors);
            $this->createPastSlots($instructors, $members);

            $this->createReservations($upcoming, $members);

            // デモ用の会員（member@example.com）に、ひととおりの状態を作る
            $this->createDemoMemberJourney($instructors, $members, $upcoming);

            // 前日リマインドの送信予定（日次コマンドと同じ処理を通す）
            ReminderSchedule::scheduleLessonReminders(days: 14);
        } finally {
            config(['activity_log.enabled' => true]);
        }
    }

    /**
     * @return Collection<int, Instructor>
     */
    private function createInstructors(): Collection
    {
        return collect(self::INSTRUCTORS)->map(fn (array $row): Instructor => Instructor::create([
            'name' => $row[0],
            'profile' => $row[1],
            // 講師本人がログインする場合は users と紐付ける(自分の枠だけ編集できるようにするため)
            'user_id' => $row[2] === null ? null : User::firstWhere('email', $row[2])?->id,
            'is_active' => true,
        ]));
    }

    /**
     * 会員（users の role=member）。
     *
     * @return Collection<int, User>
     */
    private function createMembers(): Collection
    {
        return collect(self::MEMBERS)->map(function (array $row): User {
            $user = User::firstWhere('email', $row[1]);

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $row[0],
                    'email' => $row[1],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ])->save();
            }

            $user->syncRoles([RoleName::Member->value]);

            return $user;
        });
    }

    /**
     * これからの枠。
     *
     * 定期スケジュール（毎週の固定クラス）は繰り返し登録（RecurringSlots）で作り、
     * マンツーマンや単発のクラスはそのあとに 1 件ずつ足す。
     * 画面から「繰り返しで開講 → 個別に調整」した状態と同じデータになる。
     *
     * @param  Collection<int, Instructor>  $instructors
     * @return Collection<int, LessonSlot>
     */
    private function createUpcomingSlots(Collection $instructors): Collection
    {
        $slots = collect();
        $day = Carbon::today();

        // 定期スケジュール（毎週火・木の朝ヨガ、水の夜クラス など）
        // 当日ぶんも作る（管理のダッシュボードで「本日の稼働」を確認できるように）
        $from = $day->copy();
        $to = $day->copy()->addWeeks(self::WEEKS_AHEAD);

        foreach (self::WEEKLY_SCHEDULE as $row) {
            [$title, $instructorIndex, $weekdays, $startTime, $endTime, $capacity] = $row;

            $instructor = $instructors[$instructorIndex];

            $result = RecurringSlots::generate(
                attributes: [
                    'instructor_id' => $instructor->id,
                    'title' => $title,
                    'lesson_type' => LessonType::Group,
                    'capacity' => $capacity,
                    'online_url' => 'https://example.com/meet/'.strtolower($instructor->code).'-weekly',
                    'status' => LessonSlotStatus::Open,
                ],
                weekdays: $weekdays,
                from: $from,
                to: $to,
                startTime: $startTime,
                endTime: $endTime,
            );

            $slots = $slots->concat($result->created);
        }

        // 単発の枠：マンツーマン（定員 1 名）を数本
        for ($offset = 3; $offset <= 21; $offset += 6) {
            $slots->push($this->slot(
                $instructors[3],
                ['パーソナルヨガ（姿勢改善）', 60, 1],
                $day->copy()->addDays($offset)->setTime(19, 0),
                LessonType::Personal,
            ));
        }

        // 単発の枠：期間限定のクラス
        $slots->push($this->slot(
            $instructors[2],
            self::LESSONS[4],
            $day->copy()->addDays(9)->setTime(13, 0),
        ));

        // 受付を締め切った枠（会員の一覧には出るが予約はできない。DEC-016）
        $slots->push($this->slot(
            $instructors[1],
            self::LESSONS[5],
            $day->copy()->addDays(2)->setTime(12, 0),
            LessonType::Group,
            LessonSlotStatus::Closed,
        ));

        // 中止になった枠も 1 本混ぜておく（こちらは一覧に出さない）
        $slots->push($this->slot(
            $instructors[2],
            self::LESSONS[4],
            $day->copy()->addDays(5)->setTime(10, 0),
            LessonType::Group,
            LessonSlotStatus::Canceled,
        ));

        return $slots;
    }

    /**
     * 過去の枠（履歴の確認用）。予約は出席済みとして残す。
     *
     * @param  Collection<int, Instructor>  $instructors
     * @param  Collection<int, User>  $members
     */
    private function createPastSlots(Collection $instructors, Collection $members): void
    {
        $day = Carbon::today();

        for ($offset = 1; $offset <= 10; $offset++) {
            $slot = $this->slot(
                $instructors[$offset % $instructors->count()],
                self::LESSONS[$offset % count(self::LESSONS)],
                $day->copy()->subDays($offset)->setTime(19, 0),
                LessonType::Group,
                LessonSlotStatus::Closed,
            );

            // 過去の枠には数名の参加者
            foreach ($members->slice($offset % 4, 3) as $index => $member) {
                $this->reserve($slot, $member, ReservationStatus::Reserved, $slot->starts_at->copy()->subDays(3 + $index));
            }
        }
    }

    /**
     * これからの枠に、空きあり / 残りわずか / 満席 + キャンセル待ち を作る。
     *
     * @param  Collection<int, LessonSlot>  $slots
     * @param  Collection<int, User>  $members
     */
    private function createReservations(Collection $slots, Collection $members): void
    {
        // 中止の枠には予約を入れない（締切の枠は、締め切る前に入った予約として埋める）
        $reservable = $slots->filter(
            static fn (LessonSlot $slot): bool => $slot->status !== LessonSlotStatus::Canceled
        )->values();

        foreach ($reservable as $index => $slot) {
            $capacity = $slot->capacity;

            // 空きあり / 残りわずか / 満席 がどの週にも混ざるように、4 通りを順に回す
            $count = match ($index % 4) {
                0 => $capacity,                        // 満席
                1 => max(1, $capacity - 1),            // 残りわずか
                2 => (int) floor($capacity / 3),       // 空きあり
                default => (int) floor($capacity / 2), // 空きあり（半分ほど）
            };

            $reserved = $members->slice(0, min($count, $members->count()));

            foreach ($reserved as $position => $member) {
                $reservation = $this->reserve($slot, $member, ReservationStatus::Reserved, now()->subDays($position % 5));

                // 前日に送るリマインドを予定として作っておく（送信は拡張点）
                Reminder::create([
                    'lesson_slot_id' => $slot->id,
                    'reservation_id' => $reservation->id,
                    'type' => ReminderType::LessonReminder,
                    'scheduled_at' => $slot->starts_at->copy()->subDay()->setTime(20, 0),
                    'sent_at' => null,
                    'channel' => ReminderChannel::Email,
                    'is_active' => true,
                ]);
            }

            // 満席の枠にはキャンセル待ちを 2 名（受付中の枠だけ）
            if ($count >= $capacity && $slot->status === LessonSlotStatus::Open) {
                $waiting = $members->slice($capacity, 2)->values();

                foreach ($waiting as $position => $member) {
                    Waitlist::create([
                        'lesson_slot_id' => $slot->id,
                        'user_id' => $member->id,
                        'position' => $position + 1,
                        'status' => WaitlistStatus::Waiting,
                        'requested_at' => now()->subHours($position + 1),
                        'is_active' => true,
                    ]);
                }
            }

            // 1 枠だけ「キャンセル済み」も残しておく（履歴の見え方の確認用）
            if ($index === 1 && $members->count() > $count) {
                $this->reserve(
                    $slot,
                    $members->last(),
                    ReservationStatus::Canceled,
                    now()->subDays(2),
                );
            }
        }
    }

    /**
     * デモ用の会員（member@example.com）の状態。
     *
     * マイ予約で「予約中 / 繰り上がり / キャンセル待ち / 履歴」がひととおり見えるように、
     * 実際の予約・キャンセル待ち・繰り上げの処理をそのまま通して作る
     * （画面から操作した状態と同じデータになる）。
     *
     * @param  Collection<int, Instructor>  $instructors
     * @param  Collection<int, User>  $members
     * @param  Collection<int, LessonSlot>  $upcoming
     */
    private function createDemoMemberJourney(Collection $instructors, Collection $members, Collection $upcoming): void
    {
        $demo = User::firstWhere('email', 'member@example.com');

        if ($demo === null) {
            return;
        }

        // 繰り上げを先に作る（あとから入れる予約が、繰り上がった枠と時間で
        // ぶつかった場合は、その枠を飛ばして次の枠を予約する）
        $this->promoteFromWaitlist($demo, $instructors, $members);
        $this->bookTwoLessons($demo, $upcoming);
        $this->joinAnotherWaitlist($demo, $upcoming);
        $this->addPastLessons($demo);
    }

    /**
     * 予約中の例：空きのある枠を 2 本予約する。
     *
     * @param  Collection<int, LessonSlot>  $upcoming
     */
    private function bookTwoLessons(User $demo, Collection $upcoming): void
    {
        $booked = 0;

        foreach ($this->refreshed($upcoming) as $slot) {
            if ($booked >= 2) {
                return;
            }

            try {
                ReservationBooking::book($slot, $demo);
                $booked++;
            } catch (BookingDenied) {
                // 満席・時間帯の重なりなどは飛ばして次の枠へ
                continue;
            }
        }
    }

    /**
     * 繰上確定の例：満席の枠に並び、ほかの会員のキャンセルで繰り上がる。
     *
     * デモ会員が確実に繰り上がるよう、この例のためだけの枠を 1 本作る。
     *
     * @param  Collection<int, Instructor>  $instructors
     * @param  Collection<int, User>  $members
     */
    private function promoteFromWaitlist(User $demo, Collection $instructors, Collection $members): void
    {
        $slot = $this->slot(
            $instructors[1],
            ['夜のリラックスヨガ', 60, 4],
            Carbon::today()->addDays(4)->setTime(20, 30),
        );

        // 4 名で満席にする
        foreach ($members->take($slot->capacity) as $member) {
            $this->reserve($slot, $member, ReservationStatus::Reserved, now()->subDays(2));
        }

        WaitlistRegistration::join($slot, $demo);

        // 先に予約していた会員が 1 名キャンセル → 待ち行列の先頭（デモ会員）が繰り上がる
        $reservation = $slot->activeReservations()->orderBy('id')->first();

        if ($reservation !== null) {
            ReservationCancellation::cancel($reservation);
        }
    }

    /**
     * キャンセル待ちの例：満席の枠にもう 1 本並んでおく。
     *
     * @param  Collection<int, LessonSlot>  $upcoming
     */
    private function joinAnotherWaitlist(User $demo, Collection $upcoming): void
    {
        foreach ($this->refreshed($upcoming) as $slot) {
            try {
                WaitlistRegistration::join($slot, $demo);

                return;
            } catch (BookingDenied) {
                // 空きがある枠・予約済みの枠は並べないので次へ
                continue;
            }
        }
    }

    /**
     * 履歴の例：過去に受けたレッスンを何本か（日をばらけさせる）。
     */
    private function addPastLessons(User $demo): void
    {
        $past = LessonSlot::query()
            ->where('starts_at', '<', now())
            ->withCount('activeReservations as reserved_count')
            ->orderByDesc('starts_at')
            ->limit(12)
            ->get()
            // 定員はここでも超えない（当日の朝など、すでに満席の枠がある）
            ->filter(static fn (LessonSlot $slot): bool => $slot->remainingSeats() > 0)
            ->values()
            ->filter(static fn (LessonSlot $slot, int $index): bool => $index % 2 === 0)
            ->take(4);

        foreach ($past as $slot) {
            $this->reserve($slot, $demo, ReservationStatus::Reserved, $slot->starts_at->copy()->subDays(3));
        }
    }

    /**
     * 予約数を数え直した状態で、開始の早い順に枠を並べる。
     *
     * @param  Collection<int, LessonSlot>  $slots
     * @return Collection<int, LessonSlot>
     */
    private function refreshed(Collection $slots): Collection
    {
        return LessonSlot::query()
            ->whereIn('id', $slots->pluck('id'))
            ->where('starts_at', '>', now()->addDay())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  array{0: string, 1: int, 2: int}  $lesson  [レッスン名, 所要分, 定員]
     */
    private function slot(
        Instructor $instructor,
        array $lesson,
        Carbon $startsAt,
        LessonType $type = LessonType::Group,
        LessonSlotStatus $status = LessonSlotStatus::Open,
    ): LessonSlot {
        return LessonSlot::create([
            'instructor_id' => $instructor->id,
            'title' => $lesson[0],
            'lesson_type' => $type,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($lesson[1]),
            'capacity' => $type->fixedCapacity() ?? $lesson[2],
            'online_url' => 'https://example.com/meet/'.strtolower($instructor->code).'-'.$startsAt->format('mdHi'),
            'status' => $status,
            'is_active' => true,
        ]);
    }

    private function reserve(LessonSlot $slot, User $user, ReservationStatus $status, Carbon $reservedAt): Reservation
    {
        return Reservation::create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $user->id,
            'status' => $status,
            'reserved_at' => $reservedAt,
            'canceled_at' => $status === ReservationStatus::Canceled ? $reservedAt->copy()->addDay() : null,
            'is_active' => true,
        ]);
    }
}
