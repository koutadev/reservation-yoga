<?php

namespace Tests\Concerns;

use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Enums\WaitlistStatus;
use App\Models\LessonSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Waitlist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 予約まわりのテストで使う下ごしらえ（会員・枠・予約・キャンセル待ち）。
 */
trait MakesReservations
{
    protected function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleName::Member->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function slot(string $startsAt, string $endsAt, array $attributes = []): LessonSlot
    {
        return LessonSlot::factory()->create(array_merge([
            'starts_at' => Carbon::parse($startsAt),
            'ends_at' => Carbon::parse($endsAt),
            'capacity' => 5,
        ], $attributes));
    }

    /**
     * 席を占める予約で枠を埋める（会員はそれぞれ別人）。
     *
     * @return list<User>
     */
    protected function fill(LessonSlot $slot, int $count): array
    {
        $members = [];

        for ($i = 0; $i < $count; $i++) {
            $member = $this->member();

            Reservation::factory()->create([
                'lesson_slot_id' => $slot->id,
                'user_id' => $member->id,
                'status' => ReservationStatus::Reserved,
            ]);

            $members[] = $member;
        }

        return $members;
    }

    /**
     * キャンセル待ちに並ばせる（待ち順を明示する）。
     */
    protected function waiting(LessonSlot $slot, User $user, int $position): Waitlist
    {
        return Waitlist::factory()->create([
            'lesson_slot_id' => $slot->id,
            'user_id' => $user->id,
            'position' => $position,
            'status' => WaitlistStatus::Waiting,
            'requested_at' => now()->subMinutes($position),
        ]);
    }

    /**
     * この処理で走ったクエリの本数。
     */
    protected function countQueries(callable $callback): int
    {
        $count = 0;

        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $count;
    }
}
