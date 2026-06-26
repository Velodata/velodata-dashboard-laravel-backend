<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VelodataRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Protector', 'Trainer', 'Creator', 'Member', 'Spy'] as $roleName) {
            $this->roleId($roleName);
        }
    }

    public function test_student_admin_cannot_create_roles_but_staff_admin_can(): void
    {
        $staffAdmin = $this->createStaffUser('staff-admin@example.test', 'Admin');
        $studentAdmin = $this->createGameUser('student-admin@example.test', 'Admin');

        $this->postJson('/api/v2/roles', [
            'name' => 'Student Created Role',
            'vmd_user_email' => $studentAdmin->email,
            'vmd_user_identity_type' => 'student',
            'vmd_user_is_game_user' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', [
            'name' => 'Student Created Role',
        ]);

        $this->postJson('/api/v2/roles', [
            'name' => 'Staff Created Role',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_identity_type' => 'staff',
            'vmd_user_is_game_user' => false,
        ])->assertCreated();

        $this->assertDatabaseHas('roles', [
            'name' => 'Staff Created Role',
            'guard_name' => 'api',
        ]);
    }

    public function test_student_created_accounts_are_created_as_game_users_in_the_creators_intake(): void
    {
        $intakeId = $this->createIntake('TEST-FAKE-ACCOUNT');
        $creator = $this->createGameUser('creator@example.test', 'Creator', 'active', $intakeId);
        $adminRoleId = $this->roleId('Admin');

        $this->postJson('/api/v2/users', [
            'data' => [
                'type' => 'users',
                'attributes' => [
                    'name' => 'Fake Admin',
                    'email' => 'fake-admin@example.test',
                    'password' => 'secret-password',
                    'vmd_user_email' => $creator->email,
                    'vmd_user_name' => $creator->display_name,
                ],
                'relationships' => [
                    'roles' => [
                        'data' => [
                            ['type' => 'roles', 'id' => (string) $adminRoleId],
                        ],
                    ],
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseMissing('users', [
            'email' => 'fake-admin@example.test',
        ]);

        $this->assertDatabaseHas('game_users', [
            'email' => 'fake-admin@example.test',
            'intake_id' => $intakeId,
            'game_role' => 'Admin',
            'created_by_email' => $creator->email,
        ]);

        $metadata = DB::table('game_users')
            ->where('email', 'fake-admin@example.test')
            ->value('metadata');

        $this->assertSame('student_fake_account', json_decode($metadata, true)['source'] ?? null);
    }

    public function test_students_cannot_nominate_admin_or_member_roles(): void
    {
        $intakeId = $this->createIntake('TEST-STUDENT-ROLE-NOMINATION');
        $studentProtector = $this->createGameUser('student-role-protector@example.test', 'Protector', 'active', $intakeId);
        $this->createSystemAdminAccount();
        $staffTarget = $this->createStaffUser('student-role-staff-target@example.test', 'Trainer');
        $studentTarget = $this->createGameUser('student-role-student-target@example.test', 'Creator', 'active', $intakeId);
        $adminRoleId = $this->roleId('Admin');
        $memberRoleId = $this->roleId('Member');

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $staffTarget->id,
            'custno' => $staffTarget->custno,
            'email' => $staffTarget->email,
            'name' => $staffTarget->name,
            'role_id' => $adminRoleId,
            'role_name' => 'Admin',
            'updated_by' => $studentProtector->email,
            'vmd_audit_reason' => 'Regression Student attempted Staff Admin assignment',
            'vmd_user_name' => $studentProtector->display_name,
            'vmd_user_email' => $studentProtector->email,
        ])
            ->assertForbidden()
            ->assertJson([
                'outcome' => 'FAIL',
                'message' => 'Only Staff Admins can assign the Admin role.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $staffTarget->id,
            'role_name' => 'Trainer',
        ]);

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $staffTarget->id,
            'custno' => $staffTarget->custno,
            'email' => $staffTarget->email,
            'name' => $staffTarget->name,
            'role_id' => $memberRoleId,
            'role_name' => 'Member',
            'updated_by' => $studentProtector->email,
            'vmd_audit_reason' => 'Regression Student attempted Staff Member assignment',
            'vmd_user_name' => $studentProtector->display_name,
            'vmd_user_email' => $studentProtector->email,
        ])
            ->assertForbidden()
            ->assertJson([
                'outcome' => 'FAIL',
                'message' => 'Students cannot assign the Member role.',
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $staffTarget->id,
            'role_name' => 'Trainer',
        ]);

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $studentTarget->id,
            'email' => $studentTarget->email,
            'name' => $studentTarget->display_name,
            'role_name' => 'Member',
            'vmd_audit_reason' => 'Regression Student attempted Student Member assignment',
            'vmd_user_email' => $studentProtector->email,
            'vmd_user_name' => $studentProtector->display_name,
        ])
            ->assertForbidden()
            ->assertJson([
                'outcome' => 'FAIL',
                'message' => 'Students cannot assign the Member role.',
            ]);

        $this->assertDatabaseHas('game_users', [
            'id' => $studentTarget->id,
            'game_role' => 'Creator',
        ]);

        $this->postJson('/api/v2/users', $this->studentCreateUserPayload(
            $studentProtector,
            'student-created-member@example.test',
            'Student Created Member',
            $memberRoleId
        ))
            ->assertForbidden()
            ->assertJson([
                'outcome' => 'FAIL',
                'message' => 'Students cannot create Member accounts.',
            ]);

        $this->assertDatabaseMissing('game_users', [
            'email' => 'student-created-member@example.test',
        ]);
    }

    public function test_staff_created_accounts_store_immediate_creator_email(): void
    {
        $staffAdmin = $this->createStaffUser('staff-account-creator@example.test', 'Admin');
        $memberRoleId = $this->roleId('Member');

        $this->postJson('/api/v2/users', [
            'data' => [
                'type' => 'users',
                'attributes' => [
                    'name' => 'Staff Created Member',
                    'email' => 'staff-created-member@example.test',
                    'password' => 'secret-password',
                    'vmd_user_email' => $staffAdmin->email,
                    'vmd_user_name' => $staffAdmin->name,
                ],
                'relationships' => [
                    'roles' => [
                        'data' => [
                            ['type' => 'roles', 'id' => (string) $memberRoleId],
                        ],
                    ],
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'staff-created-member@example.test',
            'created_by_email' => $staffAdmin->email,
        ]);
    }

    public function test_account_drill_down_follows_nested_fake_account_ownership(): void
    {
        $intakeId = $this->createIntake('TEST-ACCOUNT-DRILL-DOWN');
        $staffAdmin = $this->createStaffUser('account-drill-down-admin@example.test', 'Admin');
        $creator = $this->createGameUser('account-drill-down-creator@example.test', 'Creator', 'active', $intakeId);
        $adminRoleId = $this->roleId('Admin');
        $this->setIntakeGameSetting($intakeId, 'game_account_drill_down_enabled', '1');

        $this->postJson(
            '/api/v2/users',
            $this->studentCreateUserPayload($creator, 'account-drill-down-fake-one@example.test', 'Fake One', $adminRoleId)
        )->assertCreated();

        $fakeOne = DB::table('game_users')->where('email', 'account-drill-down-fake-one@example.test')->first();

        $this->postJson(
            '/api/v2/users',
            $this->studentCreateUserPayload($fakeOne, 'account-drill-down-fake-two@example.test', 'Fake Two', $adminRoleId)
        )->assertCreated();

        $fakeTwo = DB::table('game_users')->where('email', 'account-drill-down-fake-two@example.test')->first();

        $response = $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $staffAdmin->email,
            'target_identity_type' => 'student',
            'target_id' => $fakeTwo->id,
            'target_email' => $fakeTwo->email,
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN',
        ])->assertOk();

        $this->assertSame([
            'account-drill-down-fake-two@example.test',
            'account-drill-down-fake-one@example.test',
            'account-drill-down-creator@example.test',
        ], collect($response->json('data.chain'))->pluck('email')->all());
        $this->assertSame(2, $response->json('data.chain_depth'));
        $this->assertSame('root_account', $response->json('data.stop_reason'));
        $this->assertSame($creator->email, $response->json('data.root.email'));
    }

    public function test_account_drill_down_admins_can_see_spy_chain_nodes(): void
    {
        $intakeId = $this->createIntake('TEST-ACCOUNT-DRILL-DOWN-SPY');
        $staffAdmin = $this->createStaffUser('account-drill-down-spy-admin@example.test', 'Admin');
        $spy = $this->createGameUser('account-drill-down-spy@example.test', 'Spy', 'active', $intakeId);
        $target = $this->createGameUser('account-drill-down-spy-made@example.test', 'Admin', 'active', $intakeId);
        $this->setIntakeGameSetting($intakeId, 'game_account_drill_down_enabled', '1');

        DB::table('game_users')
            ->where('id', $target->id)
            ->update(['created_by_email' => $spy->email]);

        $response = $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $staffAdmin->email,
            'target_identity_type' => 'student',
            'target_id' => $target->id,
            'target_email' => $target->email,
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN-SPY',
        ])->assertOk();

        $this->assertSame('root_account', $response->json('data.stop_reason'));
        $this->assertSame($target->email, $response->json('data.chain.0.email'));
        $this->assertSame($spy->email, $response->json('data.chain.1.email'));
        $this->assertSame('Spy', $response->json('data.chain.1.role_name'));
        $this->assertFalse((bool) $response->json('data.chain.1.redacted'));
    }

    public function test_staff_protector_can_use_account_drill_down_when_enabled(): void
    {
        $intakeId = $this->createIntake('TEST-ACCOUNT-DRILL-DOWN-STAFF-PROTECTOR');
        $staffProtector = $this->createStaffUser('account-drill-down-staff-protector@example.test', 'Protector');
        $creator = $this->createGameUser('account-drill-down-staff-protector-creator@example.test', 'Creator', 'active', $intakeId);
        $fakeAccount = $this->createGameUser('account-drill-down-staff-protector-fake@example.test', 'Admin', 'active', $intakeId);
        $this->setIntakeGameSetting($intakeId, 'game_account_drill_down_enabled', '1');

        DB::table('game_users')
            ->where('id', $fakeAccount->id)
            ->update(['created_by_email' => $creator->email]);

        $response = $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $staffProtector->email,
            'target_identity_type' => 'student',
            'target_id' => $fakeAccount->id,
            'target_email' => $fakeAccount->email,
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN-STAFF-PROTECTOR',
        ])->assertOk();

        $this->assertSame($fakeAccount->email, $response->json('data.chain.0.email'));
        $this->assertSame($creator->email, $response->json('data.chain.1.email'));
    }

    public function test_student_protector_can_use_account_drill_down_when_enabled(): void
    {
        $intakeId = $this->createIntake('TEST-ACCOUNT-DRILL-DOWN-STUDENT-PROTECTOR');
        $studentProtector = $this->createGameUser('account-drill-down-student-protector@example.test', 'Protector', 'active', $intakeId);
        $creator = $this->createGameUser('account-drill-down-student-protector-creator@example.test', 'Creator', 'active', $intakeId);
        $fakeAccount = $this->createGameUser('account-drill-down-student-protector-fake@example.test', 'Admin', 'active', $intakeId);
        $this->setIntakeGameSetting($intakeId, 'game_account_drill_down_enabled', '1');

        DB::table('game_users')
            ->where('id', $fakeAccount->id)
            ->update(['created_by_email' => $creator->email]);

        $response = $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $studentProtector->email,
            'target_identity_type' => 'student',
            'target_id' => $fakeAccount->id,
            'target_email' => $fakeAccount->email,
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN-STUDENT-PROTECTOR',
        ])->assertOk();

        $this->assertSame($fakeAccount->email, $response->json('data.chain.0.email'));
        $this->assertSame($creator->email, $response->json('data.chain.1.email'));
    }

    public function test_admin_notification_recipient_can_drill_down_matching_actor_when_enabled(): void
    {
        $intakeId = $this->createIntake('TEST-ACCOUNT-DRILL-DOWN-NOTIFICATION');
        $adminRecipient = $this->createGameUser('account-drill-down-admin-recipient@example.test', 'Admin', 'active', $intakeId);
        $studentActor = $this->createGameUser('account-drill-down-notification-actor@example.test', 'Admin', 'active', $intakeId);
        $this->setIntakeGameSetting($intakeId, 'game_account_drill_down_enabled', '1');

        DB::table('user_notifications')->insert([
            'recipient_email' => $adminRecipient->email,
            'actor_email' => $studentActor->email,
            'type' => 'info',
            'title' => 'Role changed',
            'message' => 'Your role was changed by a Student actor.',
            'source' => 'user-management',
            'metadata' => json_encode([
                'actorEmail' => $studentActor->email,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $adminRecipient->email,
            'target_email' => $studentActor->email,
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN-NOTIFICATION',
            'context' => 'user_notifications',
        ])->assertOk();

        $this->assertSame($studentActor->email, $response->json('data.chain.0.email'));
        $this->assertSame('student', $response->json('data.chain.0.identity_type'));

        $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $adminRecipient->email,
            'target_email' => 'unrelated-actor@example.test',
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN-NOTIFICATION',
            'context' => 'user_notifications',
        ])->assertNotFound();
    }

    public function test_notification_drill_down_reveals_the_matching_actor_even_when_actor_is_spy(): void
    {
        $intakeId = $this->createIntake('TEST-ACCOUNT-DRILL-DOWN-NOTIFICATION-SPY');
        $adminRecipient = $this->createGameUser('account-drill-down-spy-recipient@example.test', 'Admin', 'active', $intakeId);
        $spyActor = $this->createGameUser('account-drill-down-visible-spy-actor@example.test', 'Spy', 'active', $intakeId);
        $this->setIntakeGameSetting($intakeId, 'game_account_drill_down_enabled', '1');

        DB::table('user_notifications')->insert([
            'recipient_email' => $adminRecipient->email,
            'actor_email' => $spyActor->email,
            'type' => 'info',
            'title' => 'Role changed',
            'message' => 'Your role was changed by Ariel.',
            'source' => 'user-management',
            'metadata' => json_encode([
                'actorEmail' => $spyActor->email,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $adminRecipient->email,
            'target_email' => $spyActor->email,
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN-NOTIFICATION-SPY',
            'context' => 'user_notifications',
        ])->assertOk();

        $this->assertSame($spyActor->email, $response->json('data.chain.0.email'));
        $this->assertSame('Spy', $response->json('data.chain.0.role_name'));
        $this->assertFalse((bool) $response->json('data.chain.0.redacted'));
    }

    public function test_account_drill_down_requires_enabled_intake_setting(): void
    {
        $intakeId = $this->createIntake('TEST-ACCOUNT-DRILL-DOWN-OFF');
        $studentAdmin = $this->createGameUser('account-drill-down-disabled-admin@example.test', 'Admin', 'active', $intakeId);
        $target = $this->createGameUser('account-drill-down-disabled-target@example.test', 'Member', 'active', $intakeId);

        $this->setIntakeGameSetting($intakeId, 'game_account_drill_down_enabled', '0');

        $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $studentAdmin->email,
            'target_identity_type' => 'student',
            'target_id' => $target->id,
            'target_email' => $target->email,
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN-OFF',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission Denied: account drill down is not enabled for this Class Intake.');
    }

    public function test_matching_notification_does_not_grant_non_admin_account_drill_down(): void
    {
        $intakeId = $this->createIntake('TEST-ACCOUNT-DRILL-DOWN-NON-ADMIN');
        $memberRecipient = $this->createGameUser('account-drill-down-member-recipient@example.test', 'Member', 'active', $intakeId);
        $studentActor = $this->createGameUser('account-drill-down-non-admin-actor@example.test', 'Admin', 'active', $intakeId);

        $this->setIntakeGameSetting($intakeId, 'game_account_drill_down_enabled', '1');

        DB::table('user_notifications')->insert([
            'recipient_email' => $memberRecipient->email,
            'actor_email' => $studentActor->email,
            'type' => 'info',
            'title' => 'Role changed',
            'message' => 'Your role was changed by a Student actor.',
            'source' => 'user-management',
            'metadata' => json_encode([
                'actorEmail' => $studentActor->email,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v2/VMD-get-account-drill-down', [
            'vmd_user_email' => $memberRecipient->email,
            'target_email' => $studentActor->email,
            'game_intake_code' => 'TEST-ACCOUNT-DRILL-DOWN-NON-ADMIN',
            'context' => 'user_notifications',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Permission Denied: account drill down requires Admin or Protector access.');
    }

    public function test_student_cannot_create_fake_account_for_existing_staff_user(): void
    {
        $intakeId = $this->createIntake('TEST-FAKE-STAFF-COLLISION');
        $creator = $this->createGameUser('staff-collision-creator@example.test', 'Creator', 'active', $intakeId);
        $staffUser = $this->createStaffUser('existing-staff@example.test', 'Member');
        $adminRoleId = $this->roleId('Admin');

        $this->postJson('/api/v2/users', [
            'data' => [
                'type' => 'users',
                'attributes' => [
                    'name' => 'Fake Staff',
                    'email' => $staffUser->email,
                    'password' => 'secret-password',
                    'vmd_user_email' => $creator->email,
                    'vmd_user_name' => $creator->display_name,
                ],
                'relationships' => [
                    'roles' => [
                        'data' => [
                            ['type' => 'roles', 'id' => (string) $adminRoleId],
                        ],
                    ],
                ],
            ],
        ])
            ->assertStatus(409)
            ->assertJsonPath('email_exists', 'true');

        $this->assertDatabaseMissing('game_users', [
            'email' => $staffUser->email,
            'intake_id' => $intakeId,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => $staffUser->email,
            'is_game_user' => false,
        ]);
    }

    public function test_seeded_student_accounts_have_usable_default_passwords(): void
    {
        $seededStudents = DB::table('game_users')
            ->join('game_intakes', 'game_intakes.id', '=', 'game_users.intake_id')
            ->where('game_intakes.code', 'EQ-CYBER-26-04')
            ->select('game_users.email', 'game_users.password', 'game_users.must_change_password')
            ->get();

        $this->assertGreaterThan(0, $seededStudents->count());

        foreach ($seededStudents as $student) {
            $this->assertNotNull($student->password, $student->email);
            $this->assertTrue(Hash::check('equinim01', $student->password), $student->email);
            $this->assertEquals(1, (int) $student->must_change_password, $student->email);
        }
    }

    public function test_student_baseline_restore_matches_students_by_intake_code_and_email(): void
    {
        $intakeCode = 'TEST-BASELINE-RESTORE-EMAIL';
        $intakeId = $this->createIntake($intakeCode);
        $staffAdmin = $this->createStaffUser('baseline-restore-admin@example.test', 'Admin');
        $alpha = $this->createGameUser('baseline-alpha@example.test', 'Creator', 'active', $intakeId);
        $beta = $this->createGameUser('baseline-beta@example.test', 'Member', 'active', $intakeId);

        $captureResponse = $this->postJson('/api/v2/VMD-capture-game-user-baseline', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
            'name' => 'Email identity restore baseline',
        ])->assertOk();

        $baselineId = (int) $captureResponse->json('baseline_id');

        DB::table('game_baseline_users')
            ->where('baseline_id', $baselineId)
            ->update([
                'game_user_id' => DB::raw('game_user_id + 10000'),
            ]);

        DB::table('game_users')
            ->where('id', $alpha->id)
            ->update([
                'game_role' => 'Spy',
                'display_name' => 'Changed Alpha',
                'updated_at' => now(),
            ]);

        $this->createGameUser('baseline-extra-one@example.test', 'Member', 'active', $intakeId);
        $this->createGameUser('baseline-extra-two@example.test', 'Member', 'active', $intakeId);

        $restoreResponse = $this->postJson('/api/v2/VMD-restore-game-user-baseline', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
            'baseline_id' => $baselineId,
        ])->assertOk();

        $restoreResponse
            ->assertJsonPath('restored_rows', 2)
            ->assertJsonPath('updated_rows', 2)
            ->assertJsonPath('created_rows', 0)
            ->assertJsonPath('deleted_extra_rows', 2);

        $remainingStudents = DB::table('game_users')
            ->where('intake_id', $intakeId)
            ->orderBy('email')
            ->get();

        $this->assertSame([
            'baseline-alpha@example.test',
            'baseline-beta@example.test',
        ], $remainingStudents->pluck('email')->all());

        $this->assertDatabaseHas('game_users', [
            'id' => $alpha->id,
            'intake_id' => $intakeId,
            'email' => $alpha->email,
            'display_name' => $alpha->display_name,
            'game_role' => 'Creator',
        ]);

        $this->assertDatabaseHas('game_users', [
            'id' => $beta->id,
            'intake_id' => $intakeId,
            'email' => $beta->email,
        ]);
    }

    public function test_student_role_change_creates_persisted_notification_for_that_game_user(): void
    {
        $staffAdmin = $this->createStaffUser('role-admin@example.test', 'Admin');
        $student = $this->createGameUser('role-target@example.test', 'Admin');

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $student->id,
            'email' => $student->email,
            'name' => $student->display_name,
            'gender' => 'Other',
            'location' => 'Brisbane',
            'phone_no' => '0400000000',
            'languages' => ['English'],
            'role_name' => 'Member',
            'vmd_audit_reason' => 'Regression role change',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
        ])->assertOk();

        $this->assertDatabaseHas('game_users', [
            'id' => $student->id,
            'game_role' => 'Member',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $student->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Role changed',
            'source' => 'user-management',
        ]);
    }

    public function test_staff_and_student_users_receive_notifications_when_their_role_is_changed(): void
    {
        $staffAdmin = $this->createStaffUser('role-change-admin@example.test', 'Admin');
        $staffTarget = $this->createStaffUser('role-change-staff-target@example.test', 'Member');
        $studentTarget = $this->createGameUser('role-change-student-target@example.test', 'Creator');
        $protectorRoleId = $this->roleId('Protector');

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $staffTarget->id,
            'custno' => $staffTarget->custno,
            'email' => $staffTarget->email,
            'name' => $staffTarget->name,
            'role_id' => $protectorRoleId,
            'role_name' => 'Protector',
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression Staff role change',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $staffTarget->id,
            'role_name' => 'Protector',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $staffTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Role changed',
            'source' => 'user-management',
        ]);

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $studentTarget->id,
            'email' => $studentTarget->email,
            'name' => $studentTarget->display_name,
            'gender' => 'Other',
            'location' => 'Brisbane',
            'phone_no' => '0400555666',
            'languages' => ['English'],
            'role_name' => 'Member',
            'vmd_audit_reason' => 'Regression Student role change',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
        ])->assertOk();

        $this->assertDatabaseHas('game_users', [
            'id' => $studentTarget->id,
            'game_role' => 'Member',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $studentTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Role changed',
            'source' => 'user-management',
        ]);
    }

    public function test_staff_and_student_users_receive_notifications_when_their_password_is_changed(): void
    {
        $staffAdmin = $this->createStaffUser('password-admin@example.test', 'Admin');
        $staffTarget = $this->createStaffUser('password-staff-target@example.test', 'Member');
        $studentTarget = $this->createGameUser('password-student-target@example.test', 'Member');

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $staffTarget->id,
            'custno' => $staffTarget->custno,
            'email' => $staffTarget->email,
            'name' => $staffTarget->name,
            'role_id' => $staffTarget->role_id,
            'role_name' => $staffTarget->role_name,
            'password' => 'new-staff-password',
            'password_confirmation' => 'new-staff-password',
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression Staff password change',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $staffTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Password changed',
            'source' => 'user-management',
        ]);

        $this->postJson('/api/v2/VMD-update-game-user-password', [
            'id' => $studentTarget->id,
            'password' => 'new-student-password',
            'password_confirmation' => 'new-student-password',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Regression Student password change',
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $studentTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Password changed',
            'source' => 'user-management',
        ]);
    }

    public function test_staff_and_student_users_receive_notifications_when_their_basic_info_is_changed(): void
    {
        $staffAdmin = $this->createStaffUser('basic-info-admin@example.test', 'Admin');
        $staffTarget = $this->createStaffUser('basic-info-staff-target@example.test', 'Member');
        $studentTarget = $this->createGameUser('basic-info-student-target@example.test', 'Member');

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $staffTarget->id,
            'custno' => $staffTarget->custno,
            'email' => $staffTarget->email,
            'name' => 'Updated Staff Target',
            'role_id' => $staffTarget->role_id,
            'role_name' => $staffTarget->role_name,
            'phone_no' => '0400111222',
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression Staff Basic Info change',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $staffTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Basic Info changed',
            'source' => 'user-management',
        ]);

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $studentTarget->id,
            'email' => $studentTarget->email,
            'name' => 'Updated Student Target',
            'gender' => 'Other',
            'location' => 'Brisbane',
            'phone_no' => '0400333444',
            'languages' => ['English'],
            'role_name' => $studentTarget->game_role,
            'vmd_audit_reason' => 'Regression Student Basic Info change',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $studentTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Basic Info changed',
            'source' => 'user-management',
        ]);
    }

    public function test_notification_refresh_backfills_matching_audit_history_for_student(): void
    {
        $intakeId = $this->createIntake('TEST-NOTIFICATION-BACKFILL');
        $staffAdmin = $this->createStaffUser('notification-backfill-admin@example.test', 'Admin');
        $student = $this->createGameUser('notification-backfill-student@example.test', 'Member', 'active', $intakeId);

        $auditHistoryId = DB::table('user_audit_history')->insertGetId([
            'custno' => 900000 + intval($student->id),
            'dteprfmd' => now()->subHour(),
            'comments' => 'Regression historical password change',
            'clerk_id' => $staffAdmin->name,
            'created_by_email' => $staffAdmin->email,
            'created_by_ip_address' => '127.0.0.1',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->assertDatabaseMissing('user_notifications', [
            'recipient_email' => $student->email,
            'related_audit_history_id' => $auditHistoryId,
        ]);

        $this->postJson('/api/v2/VMD-get-notifications', [
            'email' => $student->email,
        ])
            ->assertOk()
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.title', 'Password changed')
            ->assertJsonPath('data.0.source', 'audit-history');

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $student->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Password changed',
            'source' => 'audit-history',
            'related_audit_history_id' => $auditHistoryId,
        ]);

        $this->postJson('/api/v2/VMD-get-notifications', [
            'email' => $student->email,
        ])->assertOk();

        $this->assertSame(1, DB::table('user_notifications')
            ->where('recipient_email', $student->email)
            ->where('related_audit_history_id', $auditHistoryId)
            ->count());
    }

    public function test_staff_and_student_users_receive_notifications_when_deleted_or_undeleted(): void
    {
        $staffAdmin = $this->createStaffUser('delete-restore-admin@example.test', 'Admin');
        $staffTarget = $this->createStaffUser('delete-restore-staff-target@example.test', 'Member');
        $studentTarget = $this->createGameUser('delete-restore-student-target@example.test', 'Member');

        $this->postJson('/api/v2/VMD-delete-user', [
            'id' => $staffTarget->id,
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression Staff deleted',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $staffTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Account deleted',
            'source' => 'user-management',
        ]);

        $this->postJson('/api/v2/VMD-unbanUser', [
            'id' => $staffTarget->id,
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression Staff undeleted',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $staffTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Account undeleted',
            'source' => 'user-management',
        ]);

        $this->postJson('/api/v2/VMD-delete-game-user', [
            'id' => $studentTarget->id,
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $studentTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Account deleted',
            'source' => 'user-management',
        ]);

        $this->postJson('/api/v2/VMD-unban-game-user', [
            'id' => $studentTarget->id,
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $studentTarget->email,
            'actor_email' => $staffAdmin->email,
            'title' => 'Account undeleted',
            'source' => 'user-management',
        ]);
    }

    public function test_game_user_spy_has_admin_like_power_to_edit_other_students(): void
    {
        $intakeId = $this->createIntake('TEST-SPY-ADMIN-POWERS');
        $spy = $this->createGameUser('spy-actor@example.test', 'Spy', 'active', $intakeId);
        $studentTarget = $this->createGameUser('spy-edit-target@example.test', 'Creator', 'active', $intakeId);

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $studentTarget->id,
            'email' => $studentTarget->email,
            'name' => 'Spy Edited Student',
            'gender' => 'Other',
            'location' => 'Brisbane',
            'phone_no' => '0400999888',
            'role_name' => 'Member',
            'vmd_audit_reason' => 'Regression Spy edits another Student',
            'vmd_user_email' => $spy->email,
            'vmd_user_name' => $spy->display_name,
        ])->assertOk();

        $this->assertDatabaseHas('game_users', [
            'id' => $studentTarget->id,
            'display_name' => 'Spy Edited Student',
            'game_role' => 'Member',
            'updated_by' => $spy->email,
        ]);
    }

    public function test_game_user_spy_flag_can_manage_student_status_even_with_staff_email_collision(): void
    {
        $intakeId = $this->createIntake('TEST-SPY-FLAG-STATUS');
        $spy = $this->createGameUser('spy-flag-status@example.test', 'Member', 'active', $intakeId);
        $target = $this->createGameUser('spy-flag-status-target@example.test', 'Member', 'active', $intakeId);
        $this->createStaffUser($spy->email, 'Member');

        DB::table('game_users')
            ->where('id', $spy->id)
            ->update(['is_spy' => true]);

        $this->postJson('/api/v2/VMD-ban-game-user', [
            'id' => $target->id,
            'vmd_user_email' => $spy->email,
            'vmd_user_name' => $spy->display_name,
            'vmd_audit_reason' => 'Regression Spy flag banned another Student',
        ])->assertOk();

        $this->assertDatabaseHas('game_users', [
            'id' => $target->id,
            'game_status' => 'BANNED',
            'updated_by' => $spy->email,
        ]);
    }

    public function test_game_user_spy_can_ban_non_admin_staff_but_not_staff_admin(): void
    {
        $spy = $this->createGameUser('spy-staff-ban-actor@example.test', 'Spy');
        $staffProtector = $this->createStaffUser('spy-ban-staff-protector@example.test', 'Protector');
        $staffAdmin = $this->createStaffUser('spy-ban-staff-admin@example.test', 'Admin');

        $this->postJson('/api/v2/VMD-ban-user', [
            'id' => $staffProtector->id,
            'updated_by' => $spy->email,
            'vmd_audit_reason' => 'Regression Spy banned Staff Protector',
            'vmd_user_name' => $spy->display_name,
            'vmd_user_email' => $spy->email,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $staffProtector->id,
            'status' => 'BANNED',
            'updated_by' => $spy->email,
        ]);

        $this->postJson('/api/v2/VMD-ban-user', [
            'id' => $staffAdmin->id,
            'updated_by' => $spy->email,
            'vmd_audit_reason' => 'Regression Spy attempted Staff Admin ban',
            'vmd_user_name' => $spy->display_name,
            'vmd_user_email' => $spy->email,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $staffAdmin->id,
            'status' => 'Active',
        ]);
    }

    public function test_only_staff_admin_or_staff_protector_can_restore_deleted_game_users(): void
    {
        $deletedStudent = $this->createGameUser('deleted-student@example.test', 'Member', 'DELETED');
        $studentAdmin = $this->createGameUser('restore-student-admin@example.test', 'Admin');
        $staffProtector = $this->createStaffUser('staff-protector@example.test', 'Protector');

        $this->postJson('/api/v2/VMD-unban-game-user', [
            'id' => $deletedStudent->id,
            'vmd_user_email' => $studentAdmin->email,
            'vmd_user_name' => $studentAdmin->display_name,
            'vmd_audit_reason' => 'Student Admin attempted restore',
        ])->assertForbidden();

        $this->assertDatabaseHas('game_users', [
            'id' => $deletedStudent->id,
            'game_status' => 'DELETED',
        ]);

        $this->postJson('/api/v2/VMD-unban-game-user', [
            'id' => $deletedStudent->id,
            'vmd_user_email' => $staffProtector->email,
            'vmd_user_name' => $staffProtector->name,
            'vmd_audit_reason' => 'Staff Protector restored deleted user',
        ])->assertOk();

        $this->assertDatabaseHas('game_users', [
            'id' => $deletedStudent->id,
            'game_status' => 'ACTIVE',
        ]);
    }

    public function test_only_staff_admin_or_staff_protector_can_permanently_delete_already_deleted_users(): void
    {
        $systemAdmin = $this->createSystemAdminAccount();
        $staffAdmin = $this->createStaffUser('permanent-delete-admin@example.test', 'Admin');
        $staffProtector = $this->createStaffUser('permanent-delete-protector@example.test', 'Protector');
        $studentAdmin = $this->createGameUser('permanent-delete-student-admin@example.test', 'Admin');
        $studentProtector = $this->createGameUser('permanent-delete-student-protector@example.test', 'Protector');
        $deletedStaff = $this->createStaffUser('permanent-delete-staff-target@example.test', 'Member');
        $deletedStudentByProtector = $this->createGameUser('permanent-delete-student-target@example.test', 'Member', 'DELETED');
        $studentAdminTarget = $this->createGameUser('permanent-delete-student-admin-target@example.test', 'Member', 'DELETED');
        $studentProtectorTarget = $this->createGameUser('permanent-delete-student-protector-target@example.test', 'Member', 'DELETED');
        $activeStudentTarget = $this->createGameUser('permanent-delete-active-student-target@example.test', 'Member', 'ACTIVE');

        DB::table('users')->where('id', $deletedStaff->id)->update(['status' => 'DELETED']);
        DB::table('users')->where('id', $systemAdmin->id)->update(['status' => 'DELETED']);

        $this->postJson('/api/v2/VMD-permanently-delete-game-user', [
            'id' => $studentAdminTarget->id,
            'vmd_user_email' => $studentAdmin->email,
            'vmd_user_name' => $studentAdmin->display_name,
            'vmd_audit_reason' => 'Student Admin attempted permanent delete',
        ])->assertForbidden();

        $this->assertDatabaseHas('game_users', [
            'id' => $studentAdminTarget->id,
            'game_status' => 'DELETED',
        ]);

        $this->postJson('/api/v2/VMD-permanently-delete-game-user', [
            'id' => $studentProtectorTarget->id,
            'vmd_user_email' => $studentProtector->email,
            'vmd_user_name' => $studentProtector->display_name,
            'vmd_audit_reason' => 'Student Protector attempted permanent delete',
        ])->assertForbidden();

        $this->assertDatabaseHas('game_users', [
            'id' => $studentProtectorTarget->id,
            'game_status' => 'DELETED',
        ]);

        $this->postJson('/api/v2/VMD-permanently-delete-game-user', [
            'id' => $activeStudentTarget->id,
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Staff Admin attempted permanent delete of active student',
        ])->assertStatus(409);

        $this->assertDatabaseHas('game_users', [
            'id' => $activeStudentTarget->id,
            'game_status' => 'ACTIVE',
        ]);

        $this->postJson('/api/v2/VMD-permanently-delete-user', [
            'id' => $systemAdmin->id,
            'updated_by' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Staff Admin attempted permanent delete of system Admin',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $systemAdmin->id,
            'email' => 'admin@velodata.org',
        ]);

        $this->postJson('/api/v2/VMD-permanently-delete-user', [
            'id' => $deletedStaff->id,
            'updated_by' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Staff Admin permanently deleted Staff user',
        ])->assertOk();

        $this->assertDatabaseMissing('users', [
            'id' => $deletedStaff->id,
        ]);

        $staffAuditResponse = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
        ])->assertOk();

        $staffPermanentDeleteAudit = collect($staffAuditResponse->json('data'))
            ->firstWhere('attributes.target_email', $deletedStaff->email);

        $this->assertNotNull($staffPermanentDeleteAudit);
        $this->assertSame($deletedStaff->name, $staffPermanentDeleteAudit['attributes']['target_name']);
        $this->assertStringContainsString('target_email=' . $deletedStaff->email, $staffPermanentDeleteAudit['attributes']['comments']);

        $this->postJson('/api/v2/VMD-permanently-delete-game-user', [
            'id' => $deletedStudentByProtector->id,
            'vmd_user_email' => $staffProtector->email,
            'vmd_user_name' => $staffProtector->name,
            'vmd_audit_reason' => 'Staff Protector permanently deleted Student user',
        ])->assertOk();

        $this->assertDatabaseMissing('game_users', [
            'id' => $deletedStudentByProtector->id,
        ]);

        $studentAuditResponse = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
        ])->assertOk();

        $studentPermanentDeleteAudit = collect($studentAuditResponse->json('data'))
            ->firstWhere('attributes.target_email', $deletedStudentByProtector->email);

        $this->assertNotNull($studentPermanentDeleteAudit);
        $this->assertSame($deletedStudentByProtector->display_name, $studentPermanentDeleteAudit['attributes']['target_name']);
        $this->assertStringContainsString('target_email=' . $deletedStudentByProtector->email, $studentPermanentDeleteAudit['attributes']['comments']);
    }

    public function test_student_admin_cannot_delete_staff_users_or_ban_any_admin(): void
    {
        $studentAdmin = $this->createGameUser('student-admin-actions@example.test', 'Admin');
        $staffMember = $this->createStaffUser('staff-member@example.test', 'Member');
        $staffAdmin = $this->createStaffUser('protected-staff-admin@example.test', 'Admin');
        $targetStudentAdmin = $this->createGameUser('target-student-admin@example.test', 'Admin');

        $this->postJson('/api/v2/VMD-delete-user', [
            'id' => $staffMember->id,
            'updated_by' => $studentAdmin->email,
            'vmd_audit_reason' => 'Student Admin attempted Staff delete',
            'vmd_user_name' => $studentAdmin->display_name,
            'vmd_user_email' => $studentAdmin->email,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $staffMember->id,
            'status' => 'Active',
        ]);

        $this->postJson('/api/v2/VMD-ban-user', [
            'id' => $staffAdmin->id,
            'updated_by' => $studentAdmin->email,
            'vmd_audit_reason' => 'Student Admin attempted Staff Admin ban',
            'vmd_user_name' => $studentAdmin->display_name,
            'vmd_user_email' => $studentAdmin->email,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $staffAdmin->id,
            'status' => 'Active',
        ]);

        $this->postJson('/api/v2/VMD-ban-game-user', [
            'id' => $targetStudentAdmin->id,
            'vmd_user_email' => $studentAdmin->email,
            'vmd_user_name' => $studentAdmin->display_name,
            'vmd_audit_reason' => 'Student Admin attempted Student Admin ban',
        ])->assertForbidden();

        $this->assertDatabaseHas('game_users', [
            'id' => $targetStudentAdmin->id,
            'game_status' => 'active',
        ]);
    }

    public function test_students_cannot_be_assigned_the_trainer_role(): void
    {
        $staffAdmin = $this->createStaffUser('trainer-role-admin@example.test', 'Admin');
        $student = $this->createGameUser('trainer-target@example.test', 'Creator');

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $student->id,
            'email' => $student->email,
            'name' => $student->display_name,
            'role_name' => 'Trainer',
            'vmd_audit_reason' => 'Regression Trainer assignment attempt',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
        ])->assertStatus(422);

        $this->assertDatabaseHas('game_users', [
            'id' => $student->id,
            'game_role' => 'Creator',
        ]);
    }

    public function test_student_user_management_requests_are_scoped_to_their_own_intake(): void
    {
        $studentIntakeId = $this->createIntake('TEST-STUDENT-OWN');
        $otherIntakeId = $this->createIntake('TEST-STUDENT-OTHER');
        $viewer = $this->createGameUser('student-viewer@example.test', 'Creator', 'active', $studentIntakeId);
        $ownClassmate = $this->createGameUser('own-classmate@example.test', 'Member', 'active', $studentIntakeId);
        $otherStudent = $this->createGameUser('other-classmate@example.test', 'Member', 'active', $otherIntakeId);

        $response = $this->getJson('/api/v2/users?include=roles&game_intake_id=' . $otherIntakeId . '&vmd_user_email=' . urlencode($viewer->email))
            ->assertOk();

        $emails = collect($response->json('data'))
            ->pluck('attributes.email')
            ->all();

        $this->assertContains($viewer->email, $emails);
        $this->assertContains($ownClassmate->email, $emails);
        $this->assertNotContains($otherStudent->email, $emails);
    }

    public function test_user_management_prefers_intake_code_when_intake_id_disagrees(): void
    {
        $codeIntakeId = $this->createIntake('TEST-CODE-WINS');
        $staleIdIntakeId = $this->createIntake('TEST-STALE-ID');
        $staffAdmin = $this->createStaffUser('code-wins-admin@example.test', 'Admin');
        $codeStudent = $this->createGameUser('code-wins-student@example.test', 'Creator', 'active', $codeIntakeId);
        $staleIdStudent = $this->createGameUser('stale-id-student@example.test', 'Creator', 'active', $staleIdIntakeId);

        $response = $this->getJson('/api/v2/users?include=roles&game_intake_id=' . $staleIdIntakeId . '&game_intake_code=TEST-CODE-WINS&vmd_user_email=' . urlencode($staffAdmin->email))
            ->assertOk();

        $emails = collect($response->json('data'))
            ->pluck('attributes.email')
            ->all();

        $this->assertContains($codeStudent->email, $emails);
        $this->assertNotContains($staleIdStudent->email, $emails);
    }

    public function test_staff_protectors_only_see_students_from_linked_intakes(): void
    {
        $linkedIntakeId = $this->createIntake('TEST-LINKED');
        $unlinkedIntakeId = $this->createIntake('TEST-UNLINKED');
        $protector = $this->createStaffUser('linked-protector@example.test', 'Protector');
        $linkedStudent = $this->createGameUser('linked-student@example.test', 'Member', 'active', $linkedIntakeId);
        $unlinkedStudent = $this->createGameUser('unlinked-student@example.test', 'Member', 'active', $unlinkedIntakeId);

        $this->assignStaffToIntake((int) $protector->id, $linkedIntakeId, 'protector');

        $linkedResponse = $this->getJson('/api/v2/users?include=roles&game_intake_id=' . $linkedIntakeId . '&vmd_user_email=' . urlencode($protector->email))
            ->assertOk();
        $linkedEmails = collect($linkedResponse->json('data'))->pluck('attributes.email')->all();

        $this->assertContains($linkedStudent->email, $linkedEmails);
        $this->assertNotContains($unlinkedStudent->email, $linkedEmails);

        $unlinkedResponse = $this->getJson('/api/v2/users?include=roles&game_intake_id=' . $unlinkedIntakeId . '&vmd_user_email=' . urlencode($protector->email))
            ->assertOk();
        $unlinkedEmails = collect($unlinkedResponse->json('data'))->pluck('attributes.email')->all();

        $this->assertNotContains($unlinkedStudent->email, $unlinkedEmails);
    }

    public function test_class_intake_management_loads_roster_with_second_code_based_request(): void
    {
        $staffAdmin = $this->createStaffUser('class-roster-admin@example.test', 'Admin');
        $cyberIntakeId = $this->createIntake('TEST-CYBER-ROSTER');
        $webIntakeId = $this->createIntake('TEST-WEB-ROSTER');
        $cyberStudent = $this->createGameUser('cyber-roster-student@example.test', 'Creator', 'active', $cyberIntakeId);
        $webStudent = $this->createGameUser('web-roster-student@example.test', 'Creator', 'active', $webIntakeId);

        $managementResponse = $this->postJson('/api/v2/VMD-get-class-intake-management-data', [
            'vmd_user_email' => $staffAdmin->email,
        ])->assertOk();

        $this->assertNull($managementResponse->json('data.rosters'));
        $this->assertContains('TEST-CYBER-ROSTER', collect($managementResponse->json('data.intakes'))->pluck('code')->all());

        $rosterResponse = $this->postJson('/api/v2/VMD-get-class-intake-roster', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => 'TEST-CYBER-ROSTER',
        ])->assertOk();

        $cyberEmails = collect($rosterResponse->json('data.roster'))
            ->pluck('email')
            ->all();

        $webRosterResponse = $this->postJson('/api/v2/VMD-get-class-intake-roster', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => 'TEST-WEB-ROSTER',
        ])->assertOk();

        $webEmails = collect($webRosterResponse->json('data.roster'))
            ->pluck('email')
            ->all();

        $this->assertContains($cyberStudent->email, $cyberEmails);
        $this->assertNotContains($webStudent->email, $cyberEmails);
        $this->assertContains($webStudent->email, $webEmails);
        $this->assertNotContains($cyberStudent->email, $webEmails);
    }

    public function test_class_intake_management_adds_student_by_intake_code(): void
    {
        $staffAdmin = $this->createStaffUser('class-add-student-admin@example.test', 'Admin');
        $this->createIntake('TEST-ADD-STUDENT-TARGET');
        $this->createIntake('TEST-ADD-STUDENT-OTHER');

        $this->postJson('/api/v2/VMD-add-class-intake-student', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => 'TEST-ADD-STUDENT-TARGET',
            'first_name' => 'New',
            'email' => 'new-class-student@example.test',
            'password' => 'student-start-password',
            'company_name' => 'Equinim College',
            'gender' => 'Female',
            'location' => 'Sydney, Aus',
            'phone_no' => '+61 400 000 000',
            'languages' => ['javascript', 'react'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.intake.code', 'TEST-ADD-STUDENT-TARGET');

        $targetStudents = DB::table('game_users')
            ->join('game_intakes', 'game_intakes.id', '=', 'game_users.intake_id')
            ->where('game_intakes.code', 'TEST-ADD-STUDENT-TARGET')
            ->where('game_users.email', 'new-class-student@example.test')
            ->where('game_users.display_name', 'New')
            ->where('game_users.created_by_email', $staffAdmin->email)
            ->where('game_users.game_role', 'Creator')
            ->where('game_users.game_status', 'active')
            ->where('game_users.must_change_password', true)
            ->where('game_users.company_name', 'Equinim College')
            ->where('game_users.gender', 'Female')
            ->where('game_users.location', 'Sydney, Aus')
            ->where('game_users.phone_no', '+61 400 000 000')
            ->count();

        $otherIntakeStudents = DB::table('game_users')
            ->join('game_intakes', 'game_intakes.id', '=', 'game_users.intake_id')
            ->where('game_intakes.code', 'TEST-ADD-STUDENT-OTHER')
            ->where('game_users.email', 'new-class-student@example.test')
            ->count();

        $this->assertSame(1, $targetStudents);
        $this->assertSame(0, $otherIntakeStudents);
        $this->assertNull(DB::table('game_users')->where('email', 'new-class-student@example.test')->value('surname'));
        $this->assertNull(DB::table('game_users')->where('email', 'new-class-student@example.test')->value('preferred_name'));

        $studentPassword = DB::table('game_users')
            ->where('email', 'new-class-student@example.test')
            ->value('password');

        $this->assertTrue(Hash::check('student-start-password', $studentPassword));
        $this->assertSame(
            ['javascript', 'react'],
            json_decode(DB::table('game_users')->where('email', 'new-class-student@example.test')->value('languages'), true)
        );
    }

    public function test_class_intake_management_cannot_add_student_with_existing_staff_email(): void
    {
        $staffAdmin = $this->createStaffUser('class-add-staff-collision-admin@example.test', 'Admin');
        $staffUser = $this->createStaffUser('class-add-existing-staff@example.test', 'Member');
        $this->createIntake('TEST-ADD-STUDENT-STAFF-COLLISION');

        $this->postJson('/api/v2/VMD-add-class-intake-student', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => 'TEST-ADD-STUDENT-STAFF-COLLISION',
            'first_name' => 'Fake',
            'email' => $staffUser->email,
            'password' => 'student-start-password',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A Staff user with this email already exists.');

        $this->assertDatabaseMissing('game_users', [
            'email' => $staffUser->email,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => $staffUser->email,
            'is_game_user' => false,
        ]);
    }

    public function test_gmui_can_block_student_add_user_and_restrict_student_role_selection(): void
    {
        $intakeId = $this->createIntake('TEST-GMUI');
        $creator = $this->createGameUser('gmui-creator@example.test', 'Creator', 'active', $intakeId);
        $adminRoleId = $this->roleId('Admin');

        $this->setIntakeGameSetting($intakeId, 'game_restrict_student_role_selection', '1');

        $this->postJson('/api/v2/users', $this->studentCreateUserPayload(
            $creator,
            'restricted-role@example.test',
            'Restricted Role',
            $adminRoleId
        ))->assertCreated();

        $this->assertDatabaseHas('game_users', [
            'email' => 'restricted-role@example.test',
            'intake_id' => $intakeId,
            'game_role' => 'Creator',
        ]);

        $this->setIntakeGameSetting($intakeId, 'game_block_student_add_users', '1');

        $this->postJson('/api/v2/users', $this->studentCreateUserPayload(
            $creator,
            'blocked-add@example.test',
            'Blocked Add',
            $adminRoleId
        ))->assertForbidden();

        $this->assertDatabaseMissing('game_users', [
            'email' => 'blocked-add@example.test',
        ]);
    }

    public function test_gmui_pane_02_option_0201_blocks_students_from_adding_users_in_laravel(): void
    {
        $intakeId = $this->createIntake('TEST-GMUI-0201');
        $creator = $this->createGameUser('gmui-0201-creator@example.test', 'Creator', 'active', $intakeId);
        $adminRoleId = $this->roleId('Admin');

        $this->setIntakeGameSetting($intakeId, 'game_block_student_add_users', '1');

        $this->postJson('/api/v2/users', $this->studentCreateUserPayload(
            $creator,
            'gmui-0201-blocked@example.test',
            'GMUI 0201 Blocked',
            $adminRoleId
        ))
            ->assertForbidden()
            ->assertJson([
                'outcome' => 'FAIL',
                'message' => 'Students cannot add users for this Class Intake right now.',
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'gmui-0201-blocked@example.test',
        ]);

        $this->assertDatabaseMissing('game_users', [
            'email' => 'gmui-0201-blocked@example.test',
        ]);
    }

    public function test_gmui_pane_02_option_0202_forces_student_added_users_to_creator_in_laravel(): void
    {
        $intakeId = $this->createIntake('TEST-GMUI-0202');
        $studentAdmin = $this->createGameUser('gmui-0202-admin@example.test', 'Admin', 'active', $intakeId);
        $adminRoleId = $this->roleId('Admin');

        $this->setIntakeGameSetting($intakeId, 'game_restrict_student_role_selection', '1');

        $this->postJson('/api/v2/users', $this->studentCreateUserPayload(
            $studentAdmin,
            'gmui-0202-forced-creator@example.test',
            'GMUI 0202 Forced Creator',
            $adminRoleId
        ))->assertCreated();

        $this->assertDatabaseHas('game_users', [
            'email' => 'gmui-0202-forced-creator@example.test',
            'intake_id' => $intakeId,
            'game_role' => 'Creator',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'gmui-0202-forced-creator@example.test',
        ]);
    }

    public function test_gmui_pane_02_option_0204_locks_only_students_out_of_user_management_after_ban_or_delete(): void
    {
        $intakeId = $this->createIntake('TEST-GMUI-0204');
        $studentAdmin = $this->createGameUser('gmui-0204-student-admin@example.test', 'Admin', 'active', $intakeId);
        $studentTarget = $this->createGameUser('gmui-0204-student-target@example.test', 'Member', 'active', $intakeId);
        $studentEditTarget = $this->createGameUser('gmui-0204-student-edit@example.test', 'Member', 'active', $intakeId);
        $staffAdmin = $this->createStaffUser('gmui-0204-staff-admin@example.test', 'Admin');
        $staffTarget = $this->createGameUser('gmui-0204-staff-target@example.test', 'Member', 'active', $intakeId);
        $staffEditTarget = $this->createGameUser('gmui-0204-staff-edit@example.test', 'Member', 'active', $intakeId);

        $this->setIntakeGameSetting($intakeId, 'game_delete_cooldown_enabled', '1', 'elimination-recovery');
        $this->setIntakeGameSetting($intakeId, 'game_delete_cooldown_minutes', '5', 'elimination-recovery', 'integer');

        $studentBanResponse = $this->postJson('/api/v2/VMD-ban-game-user', [
            'id' => $studentTarget->id,
            'vmd_user_email' => $studentAdmin->email,
            'vmd_user_name' => $studentAdmin->display_name,
            'vmd_audit_reason' => 'Regression Student ban triggers timeout',
        ])->assertOk();

        $this->assertNotNull($studentBanResponse->json('actor_action_locked_until'));
        $this->assertNotNull(DB::table('game_users')->where('id', $studentAdmin->id)->value('action_locked_until'));
        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $studentAdmin->email,
            'type' => 'warning',
            'title' => 'User Management timeout',
            'source' => 'user-management',
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'recipient_email' => $studentAdmin->email,
            'message' => 'You are in timeout for 5 minutes, until ' . $studentBanResponse->json('actor_action_locked_until') . ', because you banned or deleted another user.',
        ]);

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $studentEditTarget->id,
            'email' => $studentEditTarget->email,
            'name' => 'Blocked Student Edit',
            'role_name' => 'Member',
            'vmd_audit_reason' => 'Regression blocked after Student timeout',
            'vmd_user_email' => $studentAdmin->email,
            'vmd_user_name' => $studentAdmin->display_name,
        ])
            ->assertStatus(423)
            ->assertJson([
                'outcome' => 'FAIL',
                'message' => 'You are currently in a timeout period because you banned or deleted another user.',
            ]);

        $staffBanResponse = $this->postJson('/api/v2/VMD-ban-game-user', [
            'id' => $staffTarget->id,
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Regression Staff ban does not trigger timeout',
        ])->assertOk();

        $this->assertNull($staffBanResponse->json('actor_action_locked_until'));

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $staffEditTarget->id,
            'email' => $staffEditTarget->email,
            'name' => 'Allowed Staff Edit',
            'role_name' => 'Member',
            'vmd_audit_reason' => 'Regression Staff remains unlocked',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
        ])->assertOk();

        $this->assertDatabaseHas('game_users', [
            'id' => $staffEditTarget->id,
            'display_name' => 'Allowed Staff Edit',
        ]);
    }

    public function test_students_can_read_their_own_gmui_0201_setting_for_user_management(): void
    {
        $intakeCode = 'TEST-GMUI-0201-READ';
        $otherIntakeCode = 'TEST-GMUI-0201-OTHER';
        $intakeId = $this->createIntake($intakeCode);
        $otherIntakeId = $this->createIntake($otherIntakeCode);
        $creator = $this->createGameUser('gmui-0201-reader@example.test', 'Creator', 'active', $intakeId);

        $this->setIntakeGameSetting($intakeId, 'game_block_student_add_users', '1');
        $this->setIntakeGameSetting($otherIntakeId, 'game_block_student_add_users', '0');

        $response = $this->postJson('/api/v2/VMD-get-intake-game-settings', [
            'vmd_user_email' => $creator->email,
            'game_intake_code' => $intakeCode,
        ])
            ->assertOk()
            ->assertJsonPath('selected_intake.code', $intakeCode);

        $blockAddUsersSetting = collect($response->json('settings'))
            ->firstWhere('key', 'game_block_student_add_users');

        $this->assertSame(true, $blockAddUsersSetting['value'] ?? null);

        $this->postJson('/api/v2/VMD-get-intake-game-settings', [
            'vmd_user_email' => $creator->email,
            'game_intake_code' => $otherIntakeCode,
        ])->assertForbidden();
    }

    public function test_assigned_staff_protector_can_read_intake_game_settings(): void
    {
        $intakeCode = 'TEST-GMUI-PROTECTOR-READ';
        $otherIntakeCode = 'TEST-GMUI-PROTECTOR-OTHER';
        $intakeId = $this->createIntake($intakeCode);
        $otherIntakeId = $this->createIntake($otherIntakeCode);
        $staffProtector = $this->createStaffUser('gmui-protector-reader@example.test', 'Protector');

        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_allow_undelete', '1');
        $this->setIntakeGameSetting($otherIntakeId, 'game_allow_undelete', '0');

        $response = $this->postJson('/api/v2/VMD-get-intake-game-settings', [
            'vmd_user_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
        ])
            ->assertOk()
            ->assertJsonPath('selected_intake.code', $intakeCode);

        $allowUndeleteSetting = collect($response->json('settings'))
            ->firstWhere('key', 'game_allow_undelete');

        $this->assertSame(true, $allowUndeleteSetting['value'] ?? null);

        $this->postJson('/api/v2/VMD-get-intake-game-settings', [
            'vmd_user_email' => $staffProtector->email,
            'game_intake_code' => $otherIntakeCode,
        ])->assertForbidden();
    }

    public function test_gmui_pane_01_option_0102_blocks_banned_students_from_logging_in(): void
    {
        $intakeId = $this->createIntake('TEST-GMUI-0102');
        $bannedStudent = $this->createGameUser('banned-login@example.test', 'Member', 'BANNED', $intakeId);

        $this->setIntakeGameSetting($intakeId, 'security_block_banned_login', '1');

        $this->postJson('/api/v2/VMD-login-user', [
            'email' => $bannedStudent->email,
            'password' => 'password',
            'login_context' => 'student',
            'vmd_ip_address_v4' => '127.0.0.1',
        ])
            ->assertForbidden()
            ->assertJson([
                'outcome' => 'STUDENT_LOGIN_DENIED',
                'errors' => 'Your class intake account has been banned.',
            ]);
    }

    public function test_gmui_week_defaults_keep_0102_on_from_week_one(): void
    {
        $staffAdmin = $this->createStaffUser('week-default-admin@example.test', 'Admin');
        $intakeCode = 'TEST-WEEK-DEFAULT-0102';
        $intakeId = $this->createIntake($intakeCode);

        $this->postJson('/api/v2/VMD-get-intake-game-settings', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
        ])->assertOk();

        $this->assertDatabaseHas('game_intake_settings', [
            'game_intake_id' => $intakeId,
            'key' => 'security_block_banned_login',
            'value' => '1',
        ]);
    }

    public function test_gmui_0303_protector_actor_impersonation_setting_can_be_saved(): void
    {
        $staffAdmin = $this->createStaffUser('gmui-0303-admin@example.test', 'Admin');
        $intakeCode = 'TEST-GMUI-0303';
        $intakeId = $this->createIntake($intakeCode);

        $response = $this->postJson('/api/v2/VMD-save-intake-game-settings', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
            'active_week' => 'week_3',
            'settings' => [
                'game_protector_actor_impersonation' => true,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('selected_intake.activeWeek', 'week_3');

        $setting = collect($response->json('settings'))
            ->firstWhere('key', 'game_protector_actor_impersonation');

        $this->assertSame(true, $setting['value'] ?? null);
        $this->assertDatabaseHas('game_intake_settings', [
            'game_intake_id' => $intakeId,
            'key' => 'game_protector_actor_impersonation',
            'value' => '1',
        ]);

        $this->postJson('/api/v2/VMD-save-intake-game-settings', [
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
            'active_week' => 'week_3',
            'settings' => [
                'game_protector_actor_impersonation' => false,
            ],
        ])->assertOk();

        $this->assertDatabaseHas('game_intake_settings', [
            'game_intake_id' => $intakeId,
            'key' => 'game_protector_actor_impersonation',
            'value' => '0',
        ]);
    }

    public function test_protector_actor_mask_is_email_based_and_requires_gmui_0303(): void
    {
        $intakeCode = 'TEST-PROTECTOR-MASK';
        $otherIntakeCode = 'TEST-PROTECTOR-MASK-OTHER';
        $intakeId = $this->createIntake($intakeCode);
        $otherIntakeId = $this->createIntake($otherIntakeCode);
        $staffProtector = $this->createStaffUser('mask-protector@example.test', 'Protector');
        $student = $this->createGameUser('mask-student@example.test', 'Member', 'active', $intakeId);
        $otherStudent = $this->createGameUser('mask-other-student@example.test', 'Member', 'active', $otherIntakeId);
        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_protector_actor_impersonation', '0', 'roles-spies');

        $this->postJson('/api/v2/VMD-get-protector-actor-mask', [
            'vmd_user_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
        ])->assertForbidden();

        $this->setIntakeGameSetting($intakeId, 'game_protector_actor_impersonation', '1', 'roles-spies');

        $loadResponse = $this->postJson('/api/v2/VMD-get-protector-actor-mask', [
            'vmd_user_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
        ])->assertOk();

        $studentEmails = collect($loadResponse->json('students'))->pluck('email')->all();
        $this->assertContains($student->email, $studentEmails);
        $this->assertNotContains($otherStudent->email, $studentEmails);

        $this->postJson('/api/v2/VMD-save-protector-actor-mask', [
            'vmd_user_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
            'masked_as_email' => $otherStudent->email,
        ])->assertStatus(422);

        $this->postJson('/api/v2/VMD-save-protector-actor-mask', [
            'vmd_user_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
            'masked_as_email' => $student->email,
        ])
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('masked_as_email', $student->email);

        $this->assertDatabaseHas('protector_actor_masks', [
            'protector_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
            'masked_as_email' => $student->email,
            'enabled' => 1,
        ]);

        $this->postJson('/api/v2/VMD-save-protector-actor-mask', [
            'vmd_user_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
            'masked_as_email' => '',
        ])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertDatabaseHas('protector_actor_masks', [
            'protector_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
            'masked_as_email' => null,
            'enabled' => 0,
        ]);
    }

    public function test_audit_history_displays_enabled_protector_actor_mask_without_rewriting_audit_row(): void
    {
        $intakeCode = 'TEST-PROTECTOR-AUDIT-MASK';
        $intakeId = $this->createIntake($intakeCode);
        $staffAdmin = $this->createStaffUser('audit-mask-admin@example.test', 'Admin');
        $staffProtector = $this->createStaffUser('audit-mask-protector@example.test', 'Protector');
        $target = $this->createGameUser('audit-mask-target@example.test', 'Member', 'active', $intakeId);
        $maskedStudent = $this->createGameUser('audit-mask-student@example.test', 'Member', 'active', $intakeId);
        $protectorAvatar = 'https://dashboard.velodata.org/storage/protector-real-avatar.png';

        DB::table('users')
            ->where('id', $staffProtector->id)
            ->update(['profile_image' => $protectorAvatar]);

        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_protector_actor_impersonation', '1', 'roles-spies');

        DB::table('protector_actor_masks')->insert([
            'protector_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
            'masked_as_email' => $maskedStudent->email,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auditHistoryId = DB::table('user_audit_history')->insertGetId([
            'custno' => 900000 + (int) $target->id,
            'comments' => 'Protector edited target while masked',
            'clerk_id' => $staffProtector->name,
            'created_by_email' => $staffProtector->email,
            'created_by_ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
        ])->assertOk();

        $row = collect($response->json('data'))
            ->firstWhere('id', $auditHistoryId);

        $this->assertSame($maskedStudent->email, $row['attributes']['created_by_email'] ?? null);
        $this->assertSame($maskedStudent->display_name, $row['attributes']['clerk_id'] ?? null);
        $this->assertSame($staffProtector->email, $row['attributes']['actual_created_by_email'] ?? null);
        $this->assertSame($staffProtector->name, $row['attributes']['actual_clerk_id'] ?? null);
        $this->assertTrue($row['attributes']['actor_appearance_applied'] ?? false);
        $this->assertEmpty($row['attributes']['actor_profile_image'] ?? null);
        $this->assertNotSame($protectorAvatar, $row['attributes']['actor_profile_image'] ?? null);

        $this->assertDatabaseHas('user_audit_history', [
            'id' => $auditHistoryId,
            'created_by_email' => $staffProtector->email,
            'clerk_id' => $staffProtector->name,
        ]);

        $this->setIntakeGameSetting($intakeId, 'game_protector_actor_impersonation', '0', 'roles-spies');

        $unmaskedResponse = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
        ])->assertOk();

        $unmaskedRow = collect($unmaskedResponse->json('data'))
            ->firstWhere('id', $auditHistoryId);

        $this->assertSame($staffProtector->email, $unmaskedRow['attributes']['created_by_email'] ?? null);
        $this->assertSame($staffProtector->name, $unmaskedRow['attributes']['clerk_id'] ?? null);
        $this->assertFalse($unmaskedRow['attributes']['actor_appearance_applied'] ?? true);
        $this->assertSame($protectorAvatar, $unmaskedRow['attributes']['actor_profile_image'] ?? null);
    }

    public function test_audit_history_uses_server_side_pagination_metadata(): void
    {
        $staffAdmin = $this->createStaffUser('audit-pagination-admin@example.test', 'Admin');
        $target = $this->createStaffUser('audit-pagination-target@example.test', 'Member');

        for ($index = 1; $index <= 130; $index++) {
            DB::table('user_audit_history')->insert([
                'custno' => 100000 + (int) $target->id,
                'comments' => 'Audit pagination row ' . $index,
                'clerk_id' => $staffAdmin->name,
                'created_by_email' => $staffAdmin->email,
                'created_by_ip_address' => '127.0.0.1',
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $response = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
            'page' => 2,
            'per_page' => 25,
        ])->assertOk();

        $response->assertJsonPath('page', 2);
        $response->assertJsonPath('per_page', 25);
        $response->assertJsonPath('recordsTotal', 130);
        $response->assertJsonPath('recordsFiltered', 130);
        $this->assertCount(25, $response->json('data'));
        $this->assertSame('Audit pagination row 26', $response->json('data.0.attributes.comments'));
    }

    public function test_audit_history_date_performed_uses_dteprfmd_before_row_timestamp(): void
    {
        $staffAdmin = $this->createStaffUser('audit-date-admin@example.test', 'Admin');
        $target = $this->createStaffUser('audit-date-target@example.test', 'Member');

        DB::table('user_audit_history')->insert([
            'custno' => 100000 + (int) $target->id,
            'comments' => 'Audit date performed regression row',
            'clerk_id' => $staffAdmin->name,
            'created_by_email' => $staffAdmin->email,
            'created_by_ip_address' => '95.173.193.8',
            'dteprfmd' => '2026-06-24 10:58:34',
            'created_at' => '2026-06-24 20:58:34',
            'updated_at' => '2026-06-24 20:58:34',
        ]);

        $response = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
            'search' => '95.173.193.8',
        ])->assertOk();

        $this->assertSame('Audit date performed regression row', $response->json('data.0.attributes.comments'));
        $this->assertSame('2026-06-24 10:58:34', $response->json('data.0.attributes.created_at'));
    }

    public function test_audit_history_resolves_game_user_target_and_creator_from_joins(): void
    {
        $staffAdmin = $this->createStaffUser('audit-target-admin@example.test', 'Admin');
        $intakeId = $this->createIntake('TEST-AUDIT-TARGET-JOIN');
        $creator = $this->createGameUser('audit-target-creator@example.test', 'Creator', 'active', $intakeId);
        $target = $this->createGameUser('audit-target-created@example.test', 'Member', 'active', $intakeId);

        DB::table('game_users')
            ->where('id', $target->id)
            ->update([
                'display_name' => 'Joined Target',
                'created_by_email' => $creator->email,
            ]);

        DB::table('user_audit_history')->insert([
            'custno' => 900000 + (int) $target->id,
            'comments' => 'Fake account created by Student via New User function',
            'clerk_id' => $creator->display_name,
            'created_by_email' => $creator->email,
            'created_by_ip_address' => '95.173.193.8',
            'dteprfmd' => '2026-06-24 10:58:34',
            'created_at' => '2026-06-24 20:58:34',
            'updated_at' => '2026-06-24 20:58:34',
        ]);

        $response = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
            'search' => '95.173.193.8',
        ])->assertOk();

        $this->assertSame('Joined Target', $response->json('data.0.attributes.target_name'));
        $this->assertSame($target->email, $response->json('data.0.attributes.target_email'));
        $this->assertSame('Member', $response->json('data.0.attributes.target_role_name'));
        $this->assertSame('student', $response->json('data.0.attributes.target_identity_type'));
        $this->assertSame($creator->email, $response->json('data.0.attributes.target_created_by_email'));
        $this->assertSame($creator->display_name, $response->json('data.0.attributes.target_created_by_name'));
    }

    public function test_audit_history_resolves_deleted_game_user_target_from_baseline_snapshot(): void
    {
        $staffAdmin = $this->createStaffUser('audit-snapshot-admin@example.test', 'Admin');
        $intakeId = $this->createIntake('TEST-AUDIT-SNAPSHOT-JOIN');
        $creator = $this->createGameUser('audit-snapshot-creator@example.test', 'Creator', 'active', $intakeId);
        $deletedGameUserId = 7777;
        $deletedCustno = 900000 + $deletedGameUserId;
        $baselineId = DB::table('game_baselines')->insertGetId([
            'intake_id' => $intakeId,
            'name' => 'Deleted student audit lookup baseline',
            'description' => 'Baseline row used to resolve an audit target after game_users deletion.',
            'is_active' => true,
            'created_by_user_id' => $staffAdmin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('game_baseline_users')->insert([
            'baseline_id' => $baselineId,
            'game_user_id' => $deletedGameUserId,
            'first_name' => 'Deleted',
            'surname' => 'Student',
            'preferred_name' => 'Deleted Student',
            'display_name' => 'Deleted Snapshot Student',
            'email' => 'deleted-snapshot-student@example.test',
            'game_role' => 'Member',
            'game_status' => 'DELETED',
            'is_spy' => false,
            'is_protector' => false,
            'metadata' => json_encode(['created_by_email' => $creator->email]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_audit_history')->insert([
            'custno' => $deletedCustno,
            'comments' => 'User password changed',
            'clerk_id' => $creator->display_name,
            'created_by_email' => $creator->email,
            'created_by_ip_address' => '95.173.193.8',
            'dteprfmd' => '2026-06-24 10:58:34',
            'created_at' => '2026-06-24 20:58:34',
            'updated_at' => '2026-06-24 20:58:34',
        ]);

        $response = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
            'search' => (string) $deletedCustno,
        ])->assertOk();

        $this->assertSame('Deleted Snapshot Student', $response->json('data.0.attributes.target_name'));
        $this->assertSame('deleted-snapshot-student@example.test', $response->json('data.0.attributes.target_email'));
        $this->assertSame('Member', $response->json('data.0.attributes.target_role_name'));
        $this->assertSame('student', $response->json('data.0.attributes.target_identity_type'));
        $this->assertSame($creator->email, $response->json('data.0.attributes.target_created_by_email'));
        $this->assertSame($creator->display_name, $response->json('data.0.attributes.target_created_by_name'));
    }

    public function test_audit_history_search_filters_before_server_side_pagination(): void
    {
        $staffAdmin = $this->createStaffUser('audit-search-admin@example.test', 'Admin');
        $target = $this->createStaffUser('audit-search-target@example.test', 'Member');

        for ($index = 1; $index <= 130; $index++) {
            DB::table('user_audit_history')->insert([
                'custno' => 100000 + (int) $target->id,
                'comments' => $index % 2 === 0
                    ? 'Password audit row ' . $index
                    : 'Profile audit row ' . $index,
                'clerk_id' => $staffAdmin->name,
                'created_by_email' => $staffAdmin->email,
                'created_by_ip_address' => '127.0.0.1',
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $response = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
            'search' => 'password',
            'page' => 2,
            'per_page' => 25,
        ])->assertOk();

        $response->assertJsonPath('page', 2);
        $response->assertJsonPath('per_page', 25);
        $response->assertJsonPath('search', 'password');
        $response->assertJsonPath('recordsTotal', 130);
        $response->assertJsonPath('recordsFiltered', 65);
        $this->assertCount(25, $response->json('data'));
        $this->assertSame('Password audit row 52', $response->json('data.0.attributes.comments'));
    }

    public function test_online_users_response_displays_enabled_protector_actor_mask_for_requested_intake(): void
    {
        $intakeCode = 'TEST-PROTECTOR-ONLINE-MASK';
        $intakeId = $this->createIntake($intakeCode);
        $staffProtector = $this->createStaffUser('online-mask-protector@example.test', 'Protector');
        $maskedStudent = $this->createGameUser('online-mask-student@example.test', 'Member', 'active', $intakeId);

        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_protector_actor_impersonation', '1', 'roles-spies');

        DB::table('protector_actor_masks')->insert([
            'protector_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
            'masked_as_email' => $maskedStudent->email,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_presence')->insert([
            'identity_type' => 'staff',
            'user_id' => $staffProtector->id,
            'game_user_id' => null,
            'presence_key' => 'staff:' . $staffProtector->id,
            'email' => $staffProtector->email,
            'name' => $staffProtector->name,
            'ip_address' => '127.0.0.1',
            'ip_address_v4' => '127.0.0.1',
            'current_path' => '/examples-api/user-management',
            'last_seen_at' => now(),
            'heartbeat_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/VMD-get-online-users', [
            'game_intake_code' => $intakeCode,
        ])->assertOk();

        $presence = collect($response->json('data'))
            ->firstWhere('presence_key', 'staff:' . $staffProtector->id);

        $this->assertSame($staffProtector->email, $presence['email'] ?? null);
        $this->assertSame($staffProtector->name, $presence['name'] ?? null);
        $this->assertSame($maskedStudent->email, $presence['display_email'] ?? null);
        $this->assertSame($maskedStudent->display_name, $presence['display_name'] ?? null);
        $this->assertSame($staffProtector->email, $presence['actual_email'] ?? null);
        $this->assertTrue($presence['actor_appearance_applied'] ?? false);

        $this->setIntakeGameSetting($intakeId, 'game_protector_actor_impersonation', '0', 'roles-spies');

        $unmaskedResponse = $this->postJson('/api/v2/VMD-get-online-users', [
            'game_intake_code' => $intakeCode,
        ])->assertOk();

        $unmaskedPresence = collect($unmaskedResponse->json('data'))
            ->firstWhere('presence_key', 'staff:' . $staffProtector->id);

        $this->assertSame($staffProtector->email, $unmaskedPresence['display_email'] ?? null);
        $this->assertSame($staffProtector->name, $unmaskedPresence['display_name'] ?? null);
        $this->assertFalse($unmaskedPresence['actor_appearance_applied'] ?? true);
    }

    public function test_user_notifications_display_enabled_protector_actor_mask_without_rewriting_notification(): void
    {
        $intakeCode = 'TEST-PROTECTOR-NOTIFICATION-MASK';
        $intakeId = $this->createIntake($intakeCode);
        $staffProtector = $this->createStaffUser('notification-mask-protector@example.test', 'Protector');
        $recipient = $this->createGameUser('notification-mask-recipient@example.test', 'Member', 'active', $intakeId);
        $maskedStudent = $this->createGameUser('notification-mask-student@example.test', 'Spy', 'active', $intakeId);

        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_protector_actor_impersonation', '1', 'roles-spies');

        DB::table('protector_actor_masks')->insert([
            'protector_email' => $staffProtector->email,
            'game_intake_code' => $intakeCode,
            'masked_as_email' => $maskedStudent->email,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notificationId = DB::table('user_notifications')->insertGetId([
            'recipient_email' => $recipient->email,
            'actor_email' => $staffProtector->email,
            'type' => 'info',
            'title' => 'Role changed',
            'message' => "Your role was changed from Member to Creator by {$staffProtector->name}.",
            'source' => 'user-management',
            'metadata' => json_encode([
                'actorEmail' => $staffProtector->email,
                'actorName' => $staffProtector->name,
                'actorIntakeCode' => $intakeCode,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/VMD-get-notifications', [
            'email' => $recipient->email,
        ])->assertOk();

        $notification = collect($response->json('data'))
            ->firstWhere('id', (string) $notificationId);

        $this->assertSame($maskedStudent->email, $notification['metadata']['actorEmail'] ?? null);
        $this->assertSame($maskedStudent->display_name, $notification['metadata']['actorName'] ?? null);
        $this->assertSame('ACTIVE', $notification['metadata']['actorStatus'] ?? null);
        $this->assertSame('student', $notification['metadata']['actorIdentityType'] ?? null);
        $this->assertSame($staffProtector->email, $notification['metadata']['actualActorEmail'] ?? null);
        $this->assertStringContainsString("by {$maskedStudent->display_name}", $notification['message'] ?? '');
        $this->assertTrue($notification['metadata']['actor_appearance_applied'] ?? false);

        $this->assertDatabaseHas('user_notifications', [
            'id' => $notificationId,
            'actor_email' => $staffProtector->email,
            'message' => "Your role was changed from Member to Creator by {$staffProtector->name}.",
        ]);

        $this->setIntakeGameSetting($intakeId, 'game_protector_actor_impersonation', '0', 'roles-spies');

        $unmaskedResponse = $this->postJson('/api/v2/VMD-get-notifications', [
            'email' => $recipient->email,
        ])->assertOk();

        $unmaskedNotification = collect($unmaskedResponse->json('data'))
            ->firstWhere('id', (string) $notificationId);

        $this->assertSame($staffProtector->email, $unmaskedNotification['metadata']['actorEmail'] ?? null);
        $this->assertSame($staffProtector->name, $unmaskedNotification['metadata']['actorName'] ?? null);
        $this->assertSame('ACTIVE', $unmaskedNotification['metadata']['actorStatus'] ?? null);
        $this->assertStringContainsString("by {$staffProtector->name}", $unmaskedNotification['message'] ?? '');
    }

    public function test_gmui_pane_01_geo_lock_blocks_edit_user_actions_from_outside_australia(): void
    {
        $intakeCode = 'TEST-GMUI-0103';
        $intakeId = $this->createIntake($intakeCode);
        $staffAdmin = $this->createStaffUser('geo-admin@example.test', 'Admin');
        $staffTarget = $this->createStaffUser('geo-staff-target@example.test', 'Member');
        $studentTarget = $this->createGameUser('geo-student-target@example.test', 'Member', 'active', $intakeId);
        $protectorRoleId = $this->roleId('Protector');

        $this->setIntakeGameSetting($intakeId, 'security_geo_lock_user_edits', '1');

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $staffTarget->id,
            'custno' => $staffTarget->custno,
            'email' => $staffTarget->email,
            'name' => $staffTarget->name,
            'role_id' => $protectorRoleId,
            'role_name' => 'Protector',
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression outside-AU Staff role change',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
            'vmd_test_country_code' => 'US',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $staffTarget->id,
            'role_name' => 'Member',
        ]);

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $staffTarget->id,
            'custno' => $staffTarget->custno,
            'email' => $staffTarget->email,
            'name' => 'Outside AU Staff Name',
            'role_id' => $staffTarget->role_id,
            'role_name' => $staffTarget->role_name,
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression outside-AU Staff Basic Info change',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
            'vmd_test_country_code' => 'US',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $staffTarget->id,
            'name' => $staffTarget->name,
        ]);

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $staffTarget->id,
            'custno' => $staffTarget->custno,
            'email' => $staffTarget->email,
            'name' => $staffTarget->name,
            'role_id' => $staffTarget->role_id,
            'role_name' => $staffTarget->role_name,
            'password' => 'outside-au-password',
            'password_confirmation' => 'outside-au-password',
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression outside-AU Staff password change',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
            'game_intake_code' => $intakeCode,
            'vmd_test_country_code' => 'US',
        ])->assertForbidden();

        $staffPassword = DB::table('users')->where('id', $staffTarget->id)->value('password');
        $this->assertTrue(Hash::check('password', $staffPassword));

        $this->post('/api/v2/uploads/users/' . $staffTarget->id . '/profile-image', [
            'attachment' => UploadedFile::fake()->create('outside-au-staff.jpg', 1, 'image/jpeg'),
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Regression outside-AU Staff avatar change',
            'game_intake_code' => $intakeCode,
            'vmd_test_country_code' => 'US',
        ])->assertForbidden();

        $this->assertNull(DB::table('users')->where('id', $staffTarget->id)->value('profile_image'));

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $studentTarget->id,
            'email' => $studentTarget->email,
            'name' => 'Outside AU Student Name',
            'gender' => 'Other',
            'location' => 'Outside',
            'phone_no' => '0400999888',
            'languages' => ['English'],
            'role_name' => 'Protector',
            'vmd_audit_reason' => 'Regression outside-AU Student role and Basic Info change',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_test_country_code' => 'US',
        ])->assertForbidden();

        $this->assertDatabaseHas('game_users', [
            'id' => $studentTarget->id,
            'display_name' => $studentTarget->display_name,
            'game_role' => 'Member',
        ]);

        $this->postJson('/api/v2/VMD-update-game-user-password', [
            'id' => $studentTarget->id,
            'password' => 'outside-au-student-password',
            'password_confirmation' => 'outside-au-student-password',
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Regression outside-AU Student password change',
            'vmd_test_country_code' => 'US',
        ])->assertForbidden();

        $studentPassword = DB::table('game_users')->where('id', $studentTarget->id)->value('password');
        $this->assertTrue(Hash::check('password', $studentPassword));

        $this->post('/api/v2/uploads/game-users/' . $studentTarget->id . '/profile-image', [
            'attachment' => UploadedFile::fake()->create('outside-au-student.jpg', 1, 'image/jpeg'),
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Regression outside-AU Student avatar change',
            'vmd_test_country_code' => 'US',
        ])->assertForbidden();

        $this->assertNull(DB::table('game_users')->where('id', $studentTarget->id)->value('profile_image'));
    }

    public function test_spy_actors_are_hidden_from_audit_history_until_spy_visibility_is_enabled(): void
    {
        $intakeId = $this->createIntake('TEST-SPY-AUDIT-ACTOR');
        $staffAdmin = $this->createStaffUser('spy-audit-admin@example.test', 'Admin');
        $staffProtector = $this->createStaffUser('spy-audit-protector@example.test', 'Protector');
        $studentProtector = $this->createGameUser('spy-audit-student-protector@example.test', 'Protector', 'active', $intakeId);
        $spyActor = $this->createGameUser('spy-audit-actor@example.test', 'Spy', 'active', $intakeId);
        $normalActor = $this->createGameUser('normal-audit-actor@example.test', 'Creator', 'active', $intakeId);
        $target = $this->createGameUser('spy-audit-target@example.test', 'Creator', 'active', $intakeId);
        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_protector_spy_visibility', '0', 'roles-spies');

        DB::table('user_audit_history')->insert([
            [
                'custno' => 900000 + $target->id,
                'comments' => 'Spy actor edited target',
                'clerk_id' => $spyActor->display_name,
                'created_by_email' => $spyActor->email,
                'created_by_ip_address' => '127.0.0.1',
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'custno' => 900000 + $target->id,
                'comments' => 'Normal actor edited target',
                'clerk_id' => $normalActor->display_name,
                'created_by_email' => $normalActor->email,
                'created_by_ip_address' => '127.0.0.1',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $adminResponse = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffAdmin->email,
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $adminActorEmails = collect($adminResponse->json('data'))
            ->pluck('attributes.created_by_email')
            ->all();
        $this->assertNotContains($spyActor->email, $adminActorEmails);
        $this->assertContains($normalActor->email, $adminActorEmails);

        $protectorResponse = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffProtector->email,
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $protectorActorEmails = collect($protectorResponse->json('data'))
            ->pluck('attributes.created_by_email')
            ->all();
        $this->assertNotContains($spyActor->email, $protectorActorEmails);
        $this->assertContains($normalActor->email, $protectorActorEmails);

        $this->setIntakeGameSetting($intakeId, 'game_protector_spy_visibility', '1', 'roles-spies');

        $protectorVisibilityResponse = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $staffProtector->email,
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $protectorVisibleActorEmails = collect($protectorVisibilityResponse->json('data'))
            ->pluck('attributes.created_by_email')
            ->all();
        $this->assertContains($spyActor->email, $protectorVisibleActorEmails);
        $this->assertContains($normalActor->email, $protectorVisibleActorEmails);

        $studentProtectorResponse = $this->postJson('/api/v2/VMD-get-audit-history', [
            'email' => $studentProtector->email,
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $studentProtectorActorEmails = collect($studentProtectorResponse->json('data'))
            ->pluck('attributes.created_by_email')
            ->all();
        $this->assertContains($spyActor->email, $studentProtectorActorEmails);
        $this->assertContains($normalActor->email, $studentProtectorActorEmails);
    }

    public function test_spy_users_are_hidden_from_login_history_until_spy_visibility_is_enabled(): void
    {
        $intakeId = $this->createIntake('TEST-SPY-LOGIN-HISTORY');
        $staffAdmin = $this->createStaffUser('spy-login-admin@example.test', 'Admin');
        $staffProtector = $this->createStaffUser('spy-login-protector@example.test', 'Protector');
        $studentProtector = $this->createGameUser('spy-login-student-protector@example.test', 'Protector', 'active', $intakeId);
        $spyUser = $this->createGameUser('spy-login-user@example.test', 'Spy', 'active', $intakeId);
        $normalUser = $this->createGameUser('normal-login-user@example.test', 'Creator', 'active', $intakeId);
        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_protector_spy_visibility', '0', 'roles-spies');

        DB::table('user_login_history')->insert([
            [
                'custno' => 900000 + $spyUser->id,
                'email' => $spyUser->email,
                'name' => $spyUser->display_name,
                'ip_address' => '127.0.0.1',
                'ip_address_v4' => '127.0.0.1',
                'created_at' => now()->subMinute(),
                'login_identity_type' => 'student',
            ],
            [
                'custno' => 900000 + $normalUser->id,
                'email' => $normalUser->email,
                'name' => $normalUser->display_name,
                'ip_address' => '127.0.0.1',
                'ip_address_v4' => '127.0.0.1',
                'created_at' => now(),
                'login_identity_type' => 'student',
            ],
        ]);

        $adminResponse = $this->postJson('/api/v2/VMD-get-login-history', [
            'email' => $staffAdmin->email,
            'method' => 'all users',
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $adminLoginEmails = collect($adminResponse->json('data'))
            ->pluck('attributes.email')
            ->all();
        $this->assertNotContains($spyUser->email, $adminLoginEmails);
        $this->assertContains($normalUser->email, $adminLoginEmails);

        $protectorResponse = $this->postJson('/api/v2/VMD-get-login-history', [
            'email' => $staffProtector->email,
            'method' => 'all users',
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $protectorLoginEmails = collect($protectorResponse->json('data'))
            ->pluck('attributes.email')
            ->all();
        $this->assertNotContains($spyUser->email, $protectorLoginEmails);
        $this->assertContains($normalUser->email, $protectorLoginEmails);

        $this->setIntakeGameSetting($intakeId, 'game_protector_spy_visibility', '1', 'roles-spies');

        $protectorVisibilityResponse = $this->postJson('/api/v2/VMD-get-login-history', [
            'email' => $staffProtector->email,
            'method' => 'all users',
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $protectorVisibleLoginEmails = collect($protectorVisibilityResponse->json('data'))
            ->pluck('attributes.email')
            ->all();
        $this->assertContains($spyUser->email, $protectorVisibleLoginEmails);
        $this->assertContains($normalUser->email, $protectorVisibleLoginEmails);

        $studentProtectorResponse = $this->postJson('/api/v2/VMD-get-login-history', [
            'email' => $studentProtector->email,
            'method' => 'all users',
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $studentProtectorLoginEmails = collect($studentProtectorResponse->json('data'))
            ->pluck('attributes.email')
            ->all();
        $this->assertContains($spyUser->email, $studentProtectorLoginEmails);
        $this->assertContains($normalUser->email, $studentProtectorLoginEmails);
    }

    public function test_spy_users_are_hidden_from_user_management_until_spy_visibility_is_enabled(): void
    {
        $intakeId = $this->createIntake('TEST-SPY-USER-MANAGEMENT');
        $staffProtector = $this->createStaffUser('spy-user-management-protector@example.test', 'Protector');
        $staffSpy = $this->createStaffUser('spy-user-management-staff-spy@example.test', 'Spy');
        $spyUser = $this->createGameUser('spy-user-management-spy@example.test', 'Spy', 'active', $intakeId);
        $normalUser = $this->createGameUser('spy-user-management-normal@example.test', 'Creator', 'active', $intakeId);
        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_protector_spy_visibility', '0', 'roles-spies');

        $hiddenResponse = $this->getJson('/api/v2/users?include=roles&game_intake_id=' . $intakeId . '&vmd_user_email=' . urlencode($staffProtector->email))
            ->assertOk();

        $hiddenEmails = collect($hiddenResponse->json('data'))
            ->pluck('attributes.email')
            ->all();
        $this->assertNotContains($staffSpy->email, $hiddenEmails);
        $this->assertNotContains($spyUser->email, $hiddenEmails);
        $this->assertContains($normalUser->email, $hiddenEmails);

        $this->setIntakeGameSetting($intakeId, 'game_protector_spy_visibility', '1', 'roles-spies');

        $visibleResponse = $this->getJson('/api/v2/users?include=roles&game_intake_id=' . $intakeId . '&vmd_user_email=' . urlencode($staffProtector->email))
            ->assertOk();

        $visibleEmails = collect($visibleResponse->json('data'))
            ->pluck('attributes.email')
            ->all();
        $this->assertContains($staffSpy->email, $visibleEmails);
        $this->assertContains($spyUser->email, $visibleEmails);
        $this->assertContains($normalUser->email, $visibleEmails);
    }

    public function test_protectors_cannot_edit_ban_or_delete_spies_until_protector_spy_controls_are_enabled(): void
    {
        $intakeId = $this->createIntake('TEST-SPY-ACTION-CONTROLS');
        $staffProtector = $this->createStaffUser('spy-action-protector@example.test', 'Protector');
        $spyUser = $this->createGameUser('spy-action-target@example.test', 'Spy', 'active', $intakeId);
        $this->assignStaffToIntake($staffProtector->id, $intakeId, 'protector');
        $this->setIntakeGameSetting($intakeId, 'game_protector_spy_controls', '0', 'roles-spies');

        $this->postJson('/api/v2/VMD-update-game-user-basic-info', [
            'id' => $spyUser->id,
            'email' => $spyUser->email,
            'name' => 'Blocked Spy Edit',
            'gender' => 'Other',
            'location' => 'Blocked',
            'phone_no' => '0400000000',
            'languages' => ['English'],
            'role_name' => 'Spy',
            'vmd_audit_reason' => 'Regression blocked Spy edit',
            'vmd_user_email' => $staffProtector->email,
            'vmd_user_name' => $staffProtector->name,
        ])->assertForbidden();

        $this->postJson('/api/v2/VMD-ban-game-user', [
            'id' => $spyUser->id,
            'vmd_user_email' => $staffProtector->email,
            'vmd_user_name' => $staffProtector->name,
            'vmd_audit_reason' => 'Regression blocked Spy ban',
        ])->assertForbidden();

        $this->postJson('/api/v2/VMD-delete-game-user', [
            'id' => $spyUser->id,
            'vmd_user_email' => $staffProtector->email,
            'vmd_user_name' => $staffProtector->name,
            'vmd_audit_reason' => 'Regression blocked Spy delete',
        ])->assertForbidden();

        $this->assertDatabaseHas('game_users', [
            'id' => $spyUser->id,
            'display_name' => $spyUser->display_name,
            'game_status' => 'active',
        ]);

        $this->setIntakeGameSetting($intakeId, 'game_protector_spy_controls', '1', 'roles-spies');

        $this->postJson('/api/v2/VMD-ban-game-user', [
            'id' => $spyUser->id,
            'vmd_user_email' => $staffProtector->email,
            'vmd_user_name' => $staffProtector->name,
            'vmd_audit_reason' => 'Regression allowed Spy ban',
        ])->assertOk();

        $this->assertDatabaseHas('game_users', [
            'id' => $spyUser->id,
            'game_status' => 'BANNED',
            'updated_by' => $staffProtector->email,
        ]);
    }

    public function test_login_history_returns_joined_profile_images_without_frontend_user_data_lookup(): void
    {
        $intakeId = $this->createIntake('TEST-LOGIN-HISTORY-PROFILE-IMAGE');
        $staffAdmin = $this->createStaffUser('login-history-profile-admin@example.test', 'Admin');
        $student = $this->createGameUser('login-history-profile-student@example.test', 'Member', 'active', $intakeId);

        DB::table('game_users')
            ->where('id', $student->id)
            ->update(['profile_image' => 'https://dashboard.velodata.org/storage/student-avatar.png']);

        DB::table('user_login_history')->insert([
            'custno' => 900000 + $student->id,
            'email' => $student->email,
            'name' => $student->display_name,
            'ip_address' => '127.0.0.1',
            'ip_address_v4' => '127.0.0.1',
            'created_at' => now(),
            'login_identity_type' => 'student',
        ]);

        $response = $this->postJson('/api/v2/VMD-get-login-history', [
            'email' => $staffAdmin->email,
            'method' => 'all users',
            'game_intake_id' => $intakeId,
        ])->assertOk();

        $loginRow = collect($response->json('data'))
            ->firstWhere('attributes.email', $student->email);

        $this->assertNotNull($loginRow);
        $this->assertSame(
            'https://dashboard.velodata.org/storage/student-avatar.png',
            $loginRow['attributes']['profile_image'] ?? null
        );
    }

    public function test_browser_trace_report_stores_local_notification_identities(): void
    {
        $intakeId = $this->createIntake('TEST-BROWSER-TRACE');
        $student = $this->createGameUser('rafiki@king.com', 'Protector', 'active', $intakeId);

        $response = $this
            ->withHeaders([
                'User-Agent' => 'Browser Trace Test Agent',
            ])
            ->postJson('/api/v2/VMD-report-browser-trace', [
                'browser_uuid' => 'browser-trace-test-uuid',
                'current_user' => [
                    'email' => $student->email,
                    'custno' => 900000 + (int) $student->id,
                    'identity_type' => 'student',
                    'is_game_user' => true,
                ],
                'origin' => 'https://dashboard.velodata.org',
                'selected_game_intake_code' => 'TEST-BROWSER-TRACE',
                'notification_identities' => [
                    ['email' => 'creator@jsonapi.com', 'notification_count' => 2],
                    ['email' => 'ivan@equinimcollege.com', 'notification_count' => 1],
                    ['email' => 'creator@jsonapi.com', 'notification_count' => 2],
                ],
                'timezone' => 'Australia/Brisbane',
                'locale' => 'en-AU',
                'screen_size' => '1920x1080',
                'viewport_size' => '1800x900',
                'client_sent_at' => '2026-06-24T11:38:24.000Z',
                'vmd_ip_address_v4' => '45.248.76.214',
            ])
            ->assertOk();

        $response->assertJsonPath('recorded', true);
        $response->assertJsonPath('notification_identity_count', 2);

        $this->assertDatabaseHas('browser_identities', [
            'browser_uuid' => 'browser-trace-test-uuid',
            'last_current_user_email' => 'rafiki@king.com',
            'last_current_user_identity_type' => 'student',
            'last_selected_game_intake_code' => 'TEST-BROWSER-TRACE',
            'last_notification_identity_count' => 2,
        ]);

        $event = DB::table('browser_identity_events')
            ->where('browser_uuid', 'browser-trace-test-uuid')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('local_notification_identity_report', $event->event_type);
        $this->assertSame('rafiki@king.com', $event->current_user_email);
        $this->assertSame('45.248.76.214', $event->ip_address_v4);

        $reportedEmails = collect(json_decode($event->notification_identities, true))
            ->pluck('email')
            ->all();

        $this->assertSame(['creator@jsonapi.com', 'ivan@equinimcollege.com'], $reportedEmails);
    }

    public function test_system_admin_user_id_one_can_never_be_edited(): void
    {
        $systemAdmin = $this->createSystemAdminAccount();
        $staffAdmin = $this->createStaffUser('system-admin-attacker@example.test', 'Admin');
        $studentSpy = $this->createGameUser('system-admin-spy-attacker@example.test', 'Spy');
        $protectorRoleId = $this->roleId('Protector');

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $systemAdmin->id,
            'custno' => $systemAdmin->custno,
            'email' => $systemAdmin->email,
            'name' => 'Compromised System Admin',
            'role_id' => $protectorRoleId,
            'role_name' => 'Protector',
            'password' => 'changed-password',
            'password_confirmation' => 'changed-password',
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression attempted system admin edit',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertForbidden();

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $systemAdmin->id,
            'custno' => $systemAdmin->custno,
            'email' => $systemAdmin->email,
            'name' => 'Self Edited System Admin',
            'role_id' => $systemAdmin->role_id,
            'role_name' => $systemAdmin->role_name,
            'updated_by' => $systemAdmin->email,
            'vmd_audit_reason' => 'Regression attempted system admin self edit',
            'vmd_user_name' => $systemAdmin->name,
            'vmd_user_email' => $systemAdmin->email,
        ])->assertForbidden();

        $this->postJson('/api/v2/VMD-ban-user', [
            'id' => $systemAdmin->id,
            'updated_by' => $studentSpy->email,
            'vmd_audit_reason' => 'Regression attempted system admin ban',
            'vmd_user_name' => $studentSpy->display_name,
            'vmd_user_email' => $studentSpy->email,
        ])->assertForbidden();

        $this->postJson('/api/v2/VMD-delete-user', [
            'id' => $systemAdmin->id,
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression attempted system admin delete',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertForbidden();

        $this->post('/api/v2/uploads/users/' . $systemAdmin->id . '/profile-image', [
            'attachment' => UploadedFile::fake()->create('system-admin.jpg', 1, 'image/jpeg'),
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Regression attempted system admin avatar',
        ])->assertForbidden();

        $unchangedPassword = DB::table('users')->where('id', $systemAdmin->id)->value('password');
        $this->assertTrue(Hash::check('password', $unchangedPassword));

        $this->assertDatabaseHas('users', [
            'id' => 1,
            'email' => 'admin@velodata.org',
            'name' => 'System Admin',
            'role_name' => 'Admin',
            'status' => 'Active',
            'profile_image' => null,
        ]);
    }

    public function test_ivan_user_id_four_is_sacrosanct_like_system_admin(): void
    {
        $ivanAccount = $this->createIvanProtectedAccount();
        $staffAdmin = $this->createStaffUser('ivan-protected-attacker@example.test', 'Admin');
        $studentSpy = $this->createGameUser('ivan-protected-spy-attacker@example.test', 'Spy');
        $protectorRoleId = $this->roleId('Protector');

        $this->postJson('/api/v2/VMD-updateUser', [
            'id' => $ivanAccount->id,
            'custno' => $ivanAccount->custno,
            'email' => $ivanAccount->email,
            'name' => 'Compromised Ivan',
            'role_id' => $protectorRoleId,
            'role_name' => 'Protector',
            'password' => 'changed-password',
            'password_confirmation' => 'changed-password',
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression attempted Ivan account edit',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertForbidden();

        $this->postJson('/api/v2/VMD-ban-user', [
            'id' => $ivanAccount->id,
            'updated_by' => $studentSpy->email,
            'vmd_audit_reason' => 'Regression attempted Ivan account ban',
            'vmd_user_name' => $studentSpy->display_name,
            'vmd_user_email' => $studentSpy->email,
        ])->assertForbidden();

        $this->postJson('/api/v2/VMD-delete-user', [
            'id' => $ivanAccount->id,
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression attempted Ivan account delete',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertForbidden();

        DB::table('users')
            ->where('id', $ivanAccount->id)
            ->update(['status' => 'DELETED']);

        $this->postJson('/api/v2/VMD-permanently-delete-user', [
            'id' => $ivanAccount->id,
            'updated_by' => $staffAdmin->email,
            'vmd_audit_reason' => 'Regression attempted Ivan permanent delete',
            'vmd_user_name' => $staffAdmin->name,
            'vmd_user_email' => $staffAdmin->email,
        ])->assertForbidden();

        DB::table('users')
            ->where('id', $ivanAccount->id)
            ->update(['status' => 'Active']);

        $this->post('/api/v2/uploads/users/' . $ivanAccount->id . '/profile-image', [
            'attachment' => UploadedFile::fake()->create('ivan-protected.jpg', 1, 'image/jpeg'),
            'vmd_user_email' => $staffAdmin->email,
            'vmd_user_name' => $staffAdmin->name,
            'vmd_audit_reason' => 'Regression attempted Ivan avatar',
        ])->assertForbidden();

        $unchangedPassword = DB::table('users')->where('id', $ivanAccount->id)->value('password');
        $this->assertTrue(Hash::check('password', $unchangedPassword));

        $this->assertDatabaseHas('users', [
            'id' => 4,
            'email' => 'ivanvetsich@gmail.com',
            'name' => 'Ivan Vetsich',
            'role_name' => 'Admin',
            'status' => 'Active',
            'is_system_user' => true,
            'profile_image' => null,
        ]);

        $this->assertDatabaseHas('user_audit_history', [
            'custno' => 100004,
            'created_by_email' => $staffAdmin->email,
        ]);
    }

    public function test_only_system_admin_email_can_change_role_management_view_permission(): void
    {
        $staffAdmin = $this->createStaffUser('ordinary-admin@example.test', 'Admin');
        $systemAdmin = $this->createStaffUser('admin@velodata.org', 'Admin');
        $adminRoleId = $this->roleId('Admin');
        $viewRolesPermissionId = $this->permissionId('view roles');
        $viewUsersPermissionId = $this->permissionId('view users');

        $this->attachPermission($adminRoleId, $viewRolesPermissionId);

        $this->putJson('/api/v2/roles/' . $adminRoleId, [
            'data' => [
                'attributes' => [
                    'name' => 'Admin',
                    'permissions' => [
                        'roles' => ['view' => false],
                        'users' => ['view' => true],
                    ],
                    'vmd_user_email' => $staffAdmin->email,
                    'vmd_user_identity_type' => 'staff',
                    'vmd_user_is_game_user' => false,
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $adminRoleId,
            'permission_id' => $viewRolesPermissionId,
        ]);
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $adminRoleId,
            'permission_id' => $viewUsersPermissionId,
        ]);

        $this->putJson('/api/v2/roles/' . $adminRoleId, [
            'data' => [
                'attributes' => [
                    'name' => 'Admin',
                    'permissions' => [
                        'roles' => ['view' => false],
                    ],
                    'vmd_user_email' => $systemAdmin->email,
                    'vmd_user_identity_type' => 'staff',
                    'vmd_user_is_game_user' => false,
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => $adminRoleId,
            'permission_id' => $viewRolesPermissionId,
        ]);
    }

    private function roleId(string $roleName): int
    {
        DB::table('roles')->updateOrInsert(
            ['name' => $roleName, 'guard_name' => 'api'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        return (int) DB::table('roles')
            ->where('name', $roleName)
            ->where('guard_name', 'api')
            ->value('id');
    }

    private function createIntake(string $code): int
    {
        return (int) DB::table('game_intakes')->insertGetId([
            'code' => $code,
            'name' => str_replace('-', ' ', $code),
            'status' => 'active',
            'active_week' => 'week_1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignStaffToIntake(int $staffUserId, int $intakeId, string $assignmentType): void
    {
        DB::table('staff_intake_assignments')->insert([
            'staff_user_id' => $staffUserId,
            'game_intake_id' => $intakeId,
            'assignment_type' => $assignmentType,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function permissionId(string $permissionName): int
    {
        DB::table('permissions')->updateOrInsert(
            ['name' => $permissionName, 'guard_name' => 'api'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        return (int) DB::table('permissions')
            ->where('name', $permissionName)
            ->where('guard_name', 'api')
            ->value('id');
    }

    private function attachPermission(int $roleId, int $permissionId): void
    {
        DB::table('role_has_permissions')->updateOrInsert([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    private function setIntakeGameSetting(
        int $intakeId,
        string $key,
        string $value,
        string $group = 'game-vulnerabilities',
        string $type = 'boolean'
    ): void
    {
        DB::table('game_intake_settings')->updateOrInsert(
            [
                'game_intake_id' => $intakeId,
                'key' => $key,
            ],
            [
                'value' => $value,
                'type' => $type,
                'group' => $group,
                'label' => $key,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function studentCreateUserPayload(object $creator, string $email, string $name, int $roleId): array
    {
        return [
            'data' => [
                'type' => 'users',
                'attributes' => [
                    'name' => $name,
                    'email' => $email,
                    'password' => 'secret-password',
                    'vmd_user_email' => $creator->email,
                    'vmd_user_name' => $creator->display_name,
                ],
                'relationships' => [
                    'roles' => [
                        'data' => [
                            ['type' => 'roles', 'id' => (string) $roleId],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createStaffUser(string $email, string $roleName): object
    {
        $roleId = $this->roleId($roleName);
        $id = DB::table('users')->insertGetId([
            'name' => "{$roleName} Staff",
            'email' => $email,
            'password' => Hash::make('password'),
            'role_id' => $roleId,
            'role_name' => $roleName,
            'status' => 'Active',
            'is_system_user' => true,
            'is_game_user' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('users')
            ->where('id', $id)
            ->update(['custno' => 100000 + $id]);

        return DB::table('users')->where('id', $id)->first();
    }

    private function createSystemAdminAccount(): object
    {
        $roleId = $this->roleId('Admin');
        DB::table('users')->insert([
            'id' => 1,
            'custno' => 100001,
            'name' => 'System Admin',
            'email' => 'admin@velodata.org',
            'password' => Hash::make('password'),
            'role_id' => $roleId,
            'role_name' => 'Admin',
            'status' => 'Active',
            'is_system_user' => true,
            'is_game_user' => false,
            'profile_image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('users')->where('id', 1)->first();
    }

    private function createIvanProtectedAccount(): object
    {
        $roleId = $this->roleId('Admin');
        DB::table('users')->insert([
            'id' => 4,
            'custno' => 100004,
            'name' => 'Ivan Vetsich',
            'email' => 'ivanvetsich@gmail.com',
            'password' => Hash::make('password'),
            'role_id' => $roleId,
            'role_name' => 'Admin',
            'status' => 'Active',
            'is_system_user' => true,
            'is_game_user' => false,
            'profile_image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('users')->where('id', 4)->first();
    }

    private function createGameUser(
        string $email,
        string $roleName,
        string $status = 'active',
        ?int $intakeId = null
    ): object {
        $intakeId = $intakeId ?: $this->createIntake('TEST-' . strtoupper(substr(md5($email), 0, 10)));

        $id = DB::table('game_users')->insertGetId([
            'intake_id' => $intakeId,
            'first_name' => ucfirst(strtok($email, '@')),
            'surname' => 'Tester',
            'preferred_name' => ucfirst(strtok($email, '@')),
            'display_name' => ucfirst(strtok($email, '@')) . ' Tester',
            'email' => $email,
            'password' => Hash::make('password'),
            'must_change_password' => false,
            'game_role' => $roleName,
            'game_status' => $status,
            'is_spy' => false,
            'is_protector' => strcasecmp($roleName, 'Protector') === 0,
            'metadata' => json_encode(['source' => 'phpunit']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('game_users')->where('id', $id)->first();
    }
}
