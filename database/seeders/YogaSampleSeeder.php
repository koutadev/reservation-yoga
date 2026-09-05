<?php

namespace Database\Seeders;

use App\Enums\LessonSlotStatus;
use App\Enums\LessonType;
use App\Enums\ReminderChannel;
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
        ['パワーヨガ（中級）', 1, [1, 5], '20:00', '21:00', 8],     // 毎週 月・金の夜
        ['肩こり改善ヨガ', 0, [6], '10:00', '10:45', 10],           // 毎週 土の午前
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
        $from = $day->copy()->addDay();
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

        // 中止になった枠も 1 本混ぜておく
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
        $open = $slots->filter(
            static fn (LessonSlot $slot): bool => $slot->status === LessonSlotStatus::Open
        )->values();

        foreach ($open as $index => $slot) {
            $capacity = $slot->capacity;

            // 3 枠に 1 つは満席、次の 1 つは残りわずか、残りは少しだけ埋める
            $count = match ($index % 3) {
                0 => $capacity,                       // 満席
                1 => max(1, $capacity - 1),           // 残り 1
                default => (int) floor($capacity / 3),
            };

            $reserved = $members->slice(0, min($count, $members->count()));

            foreach ($reserved as $position => $member) {
                $reservation = $this->reserve($slot, $member, ReservationStatus::Reserved, now()->subDays($position % 5));

                // 前日に送るリマインドを予定として作っておく（送信は拡張点）
                Reminder::create([
                    'lesson_slot_id' => $slot->id,
                    'reservation_id' => $reservation->id,
                    'scheduled_at' => $slot->starts_at->copy()->subDay()->setTime(20, 0),
                    'sent_at' => null,
                    'channel' => ReminderChannel::Email,
                    'is_active' => true,
                ]);
            }

            // 満席の枠にはキャンセル待ちを 2 名
            if ($count >= $capacity) {
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
