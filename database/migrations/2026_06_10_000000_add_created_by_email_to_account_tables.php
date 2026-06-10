<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'created_by_email')) {
                $table->string('created_by_email')->nullable()->after('email')->index();
            }
        });

        Schema::table('game_users', function (Blueprint $table) {
            if (! Schema::hasColumn('game_users', 'created_by_email')) {
                $table->string('created_by_email')->nullable()->after('email')->index();
            }
        });

        $this->backfillGameUserCreatedByEmailFromMetadata();
        $this->backfillCreatedByEmailFromCreationAudit('users', 'custno', false);
        $this->backfillCreatedByEmailFromCreationAudit('game_users', 'id', true);
    }

    public function down(): void
    {
        Schema::table('game_users', function (Blueprint $table) {
            if (Schema::hasColumn('game_users', 'created_by_email')) {
                $table->dropIndex(['created_by_email']);
                $table->dropColumn('created_by_email');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'created_by_email')) {
                $table->dropIndex(['created_by_email']);
                $table->dropColumn('created_by_email');
            }
        });
    }

    private function backfillGameUserCreatedByEmailFromMetadata(): void
    {
        DB::table('game_users')
            ->whereNull('created_by_email')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(100, function ($gameUsers) {
                foreach ($gameUsers as $gameUser) {
                    $metadata = json_decode((string) $gameUser->metadata, true);
                    if (! is_array($metadata)) {
                        continue;
                    }

                    $createdByEmail = $metadata['created_by_email']
                        ?? $metadata['created_by_student_email']
                        ?? null;

                    if (! $this->isEmailLike($createdByEmail)) {
                        continue;
                    }

                    DB::table('game_users')
                        ->where('id', $gameUser->id)
                        ->whereNull('created_by_email')
                        ->update([
                            'created_by_email' => $createdByEmail,
                        ]);
                }
            });
    }

    private function backfillCreatedByEmailFromCreationAudit(string $table, string $keyColumn, bool $isGameUser): void
    {
        DB::table($table)
            ->whereNull('created_by_email')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($table, $keyColumn, $isGameUser) {
                foreach ($accounts as $account) {
                    $custno = $isGameUser
                        ? (string) (900000 + (int) $account->id)
                        : (string) $account->{$keyColumn};

                    if ($custno === '') {
                        continue;
                    }

                    $audit = DB::table('user_audit_history')
                        ->where('custno', $custno)
                        ->whereNotNull('created_by_email')
                        ->where(function ($query) {
                            $query->where('comments', 'like', '%created%')
                                ->orWhere('comments', 'like', '%added%');
                        })
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->first();

                    if (! $audit || ! $this->isEmailLike($audit->created_by_email)) {
                        continue;
                    }

                    if (! $this->auditHappenedNearAccountCreation($account->created_at, $audit->created_at)) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $account->id)
                        ->whereNull('created_by_email')
                        ->update([
                            'created_by_email' => $audit->created_by_email,
                        ]);
                }
            });
    }

    private function auditHappenedNearAccountCreation($accountCreatedAt, $auditCreatedAt): bool
    {
        if (! $accountCreatedAt || ! $auditCreatedAt) {
            return false;
        }

        $accountTime = Carbon::parse($accountCreatedAt);
        $auditTime = Carbon::parse($auditCreatedAt);

        return $accountTime->diffInMinutes($auditTime) <= 10;
    }

    private function isEmailLike($value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL);
    }
};
