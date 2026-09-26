<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single call site every instrumented action goes through to write an
 * audit_logs row. Wraps its own DB::transaction() so every call site is
 * concurrency-safe on its own — a caller doesn't need to remember it's
 * writing into a hash chain (see HasHashChain, which otherwise expects the
 * write to already be inside one).
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $changes  before/after values, e.g. ['status' => ['old' => 'active', 'new' => 'inactive']]
     */
    public static function log(string $action, string $description, ?Model $target = null, array $changes = [], ?User $actor = null): AuditLog
    {
        $actor ??= auth()->user();

        return DB::transaction(fn () => AuditLog::create([
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->displayName(),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'target_label' => $target ? self::labelFor($target) : null,
            'description' => $description,
            'changes' => $changes ?: null,
            'ip_address' => app()->bound('request') ? request()->ip() : null,
        ]));
    }

    private static function labelFor(Model $target): ?string
    {
        return match (true) {
            method_exists($target, 'displayName') => $target->displayName(),
            isset($target->name) => (string) $target->name,
            isset($target->username) => (string) $target->username,
            // Fight has none of the above — its own "name" is its number.
            isset($target->fight_number) => __('Fight #:number', ['number' => $target->fight_number]),
            isset($target->code) => (string) $target->code,
            isset($target->ticket_code) => (string) $target->ticket_code,
            isset($target->device_id) => (string) $target->device_id,
            default => (string) $target->getKey(),
        };
    }
}
