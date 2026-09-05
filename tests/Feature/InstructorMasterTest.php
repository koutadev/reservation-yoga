<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Instructor;
use App\Models\LessonSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * STEP2.5: 講師（インストラクター）マスタの検証。
 *
 * 共通マスタ基盤（一覧 + 行クリックのモーダル編集）に載せているので、
 * ここで見るのはこのマスタ固有の 3 点。
 *   1. 一覧・詳細・編集が行クリックのモーダルで完結すること
 *   2. 担当ユーザー（instructors.user_id）の紐付けが保存できること（1 対 1・未紐付け可）
 *   3. 参照も更新も管理者だけができること
 *
 * 紐付けた結果、その講師が自分のレッスン枠を編集できるようになるところまで確かめる。
 */
class InstructorMasterTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }

    /**
     * 講師の更新フォームに送る値。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Instructor $instructor, array $overrides = []): array
    {
        return array_merge([
            'name' => $instructor->name,
            'profile' => $instructor->profile,
            'user_id' => $instructor->user_id,
            'is_active' => '1',
        ], $overrides);
    }

    // --- 一覧・行クリック編集 --------------------------------------------

    #[Test]
    public function the_list_shows_each_instructor_with_its_linked_user(): void
    {
        $teacher = User::factory()->create(['name' => '佐倉 みなと', 'email' => 'minato@example.com']);

        $linked = Instructor::factory()->create(['name' => '佐倉 みなと', 'user_id' => $teacher->id]);
        $unlinked = Instructor::factory()->create(['name' => '如月 あかり', 'user_id' => null]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('masters.instructors.index'))
            ->assertOk()
            ->assertSee($linked->code)
            ->assertSee($unlinked->code)
            ->assertSee('如月 あかり')
            // 担当ユーザー列は、紐付いていれば名前とメール、いなければ「未紐付け」
            ->assertSee('minato@example.com')
            ->assertSee('未紐付け')
            // 行クリックで詳細モーダルが開く
            ->assertSee(route('masters.instructors.detail', $linked->id), false)
            ->assertSee('open-detail', false);
    }

    #[Test]
    public function the_detail_fragment_shows_the_instructor_and_its_edit_form(): void
    {
        $teacher = User::factory()->create(['name' => '佐倉 みなと', 'email' => 'minato@example.com']);
        $instructor = Instructor::factory()->create([
            'name' => '佐倉 みなと',
            'profile' => 'RYT200 取得。',
            'user_id' => $teacher->id,
        ]);

        LessonSlot::factory()->count(2)->create(['instructor_id' => $instructor->id]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('masters.instructors.detail', $instructor->id))
            ->assertOk()
            ->assertSee('講師 — 佐倉 みなと')
            ->assertSee($instructor->code)
            ->assertSee('RYT200 取得。')
            ->assertSee('佐倉 みなと（minato@example.com）')
            ->assertSee('2 件')
            // その場で編集できる（入力項目はフルページのフォームと共有）
            ->assertSee(route('masters.instructors.update', $instructor->id), false)
            ->assertSee('name="_modal_record"', false)
            ->assertSee('name="user_id"', false);
    }

    #[Test]
    public function an_instructor_is_registered_with_an_auto_generated_code(): void
    {
        $admin = $this->userWithRole(RoleName::Admin);

        $this->actingAs($admin)
            ->post(route('masters.instructors.store'), [
                'name' => '白石 かえで',
                'profile' => 'ピラティス出身。',
                'user_id' => null,
                'is_active' => '1',
            ])
            ->assertRedirect(route('masters.instructors.index'));

        $instructor = Instructor::query()->sole();

        $this->assertSame('INS-0001', $instructor->code);
        $this->assertSame('白石 かえで', $instructor->name);
        $this->assertNull($instructor->user_id, '未紐付けのままでも登録できる。');
        $this->assertTrue($instructor->is_active);
        // 共通仕様（作成者の自動記録）も効いている
        $this->assertSame($admin->id, $instructor->created_by);
    }

    // --- ユーザー紐付け ---------------------------------------------------

    #[Test]
    public function linking_a_user_lets_that_teacher_edit_their_own_slots(): void
    {
        $instructor = Instructor::factory()->create(['user_id' => null]);
        $teacher = $this->userWithRole(RoleName::Staff);
        $slot = LessonSlot::factory()->create(['instructor_id' => $instructor->id]);

        // 紐付ける前は、自分の枠かどうか判別できないので編集できない
        $this->actingAs($teacher)
            ->put(route('lesson-slots.update', $slot->id), [
                'instructor_id' => $slot->instructor_id,
                'title' => '紐付け前',
                'lesson_type' => $slot->lesson_type->value,
                'starts_at' => $slot->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $slot->ends_at->format('Y-m-d\TH:i'),
                'capacity' => $slot->capacity,
                'status' => $slot->status->value,
            ])
            ->assertForbidden();

        // 管理画面から担当ユーザーを紐付ける
        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('masters.instructors.update', $instructor->id), $this->payload($instructor, [
                'user_id' => $teacher->id,
            ]))
            ->assertRedirect(route('masters.instructors.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame($teacher->id, $instructor->fresh()->user_id);

        // 紐付いたので、その講師は自分の枠を編集できるようになる
        $this->actingAs($teacher)
            ->put(route('lesson-slots.update', $slot->id), [
                'instructor_id' => $slot->instructor_id,
                'title' => '紐付け後',
                'lesson_type' => $slot->lesson_type->value,
                'starts_at' => $slot->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $slot->ends_at->format('Y-m-d\TH:i'),
                'capacity' => $slot->capacity,
                'status' => $slot->status->value,
            ])
            ->assertRedirect(route('lesson-slots.index'));

        $this->assertSame('紐付け後', $slot->fresh()->title);
    }

    #[Test]
    public function the_link_can_be_removed_again(): void
    {
        $teacher = $this->userWithRole(RoleName::Staff);
        $instructor = Instructor::factory()->create(['user_id' => $teacher->id]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->put(route('masters.instructors.update', $instructor->id), $this->payload($instructor, [
                'user_id' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($instructor->fresh()->user_id);
    }

    #[Test]
    public function one_user_cannot_be_linked_to_two_instructors(): void
    {
        $teacher = $this->userWithRole(RoleName::Staff);
        Instructor::factory()->create(['user_id' => $teacher->id]);

        $other = Instructor::factory()->create(['user_id' => null]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->from(route('masters.instructors.index'))
            ->put(route('masters.instructors.update', $other->id), $this->payload($other, [
                'user_id' => $teacher->id,
            ]))
            ->assertSessionHasErrors('user_id');

        $this->assertNull($other->fresh()->user_id);
    }

    #[Test]
    public function the_user_options_leave_out_people_already_linked(): void
    {
        $taken = User::factory()->create(['name' => 'すでに講師', 'email' => 'taken@example.com']);
        $free = User::factory()->create(['name' => 'まだ未割当', 'email' => 'free@example.com']);

        Instructor::factory()->create(['user_id' => $taken->id]);
        $editing = Instructor::factory()->create(['user_id' => null]);

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('masters.instructors.edit', $editing->id))
            ->assertOk()
            ->assertSee('free@example.com')
            ->assertDontSee('taken@example.com');
    }

    // --- 権限（管理者のみ） ----------------------------------------------

    #[Test]
    public function only_administrators_can_open_the_instructor_master(): void
    {
        $instructor = Instructor::factory()->create();

        foreach ([RoleName::Staff, RoleName::Viewer, RoleName::Member] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->get(route('masters.instructors.index'))->assertForbidden();
            $this->actingAs($user)->get(route('masters.instructors.detail', $instructor->id))->assertForbidden();
            $this->actingAs($user)->get(route('masters.instructors.create'))->assertForbidden();
        }

        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('masters.instructors.index'))
            ->assertOk();
    }

    #[Test]
    public function a_staff_member_cannot_change_an_instructor(): void
    {
        $instructor = Instructor::factory()->create(['name' => '変えさせない']);

        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->put(route('masters.instructors.update', $instructor->id), $this->payload($instructor, [
                'name' => '書き換え',
            ]))
            ->assertForbidden();

        $this->assertSame('変えさせない', $instructor->fresh()->name);
    }

    #[Test]
    public function the_hub_shows_the_instructor_card_to_administrators_only(): void
    {
        $this->actingAs($this->userWithRole(RoleName::Admin))
            ->get(route('masters.index'))
            ->assertOk()
            ->assertSee('講師')
            ->assertSee(route('masters.instructors.index'));

        // 担当者はほかのマスタは開けるが、講師マスタの入口は出さない
        $this->actingAs($this->userWithRole(RoleName::Staff))
            ->get(route('masters.index'))
            ->assertOk()
            ->assertSee(route('masters.employees.index'))
            ->assertDontSee(route('masters.instructors.index'));
    }

    // --- 論理削除・復元（共通マスタと同じ） ------------------------------

    #[Test]
    public function an_instructor_can_be_soft_deleted_and_restored(): void
    {
        $admin = $this->userWithRole(RoleName::Admin);
        $instructor = Instructor::factory()->create();

        $this->actingAs($admin)
            ->delete(route('masters.instructors.destroy', $instructor->id))
            ->assertRedirect(route('masters.instructors.index'));

        $this->assertSoftDeleted($instructor);

        $this->actingAs($admin)
            ->post(route('masters.instructors.restore', $instructor->id))
            ->assertRedirect(route('masters.instructors.index'));

        $this->assertNotSoftDeleted($instructor);
    }
}
