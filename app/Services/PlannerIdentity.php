<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * The one account. Both surfaces — the browser session and CalDAV HTTP Basic —
 * authenticate against this single row, so there is exactly one password and the
 * DAV principal is derived from it rather than from configuration.
 *
 * Single-user is enforced by taking the lowest id rather than by trusting the
 * table to hold one row. A stray second row then cannot sign in anywhere, which
 * fails loudly instead of authenticating into a principal that owns no calendar.
 */
final class PlannerIdentity
{
    public static function user(): ?User
    {
        return User::query()->oldest('id')->first();
    }

    public static function userOrFail(): User
    {
        return self::user() ?? throw new RuntimeException(
            'No planner user exists. Run: php artisan planner:user'
        );
    }

    /**
     * Resolve a typed username to the one account, case-insensitively.
     *
     * iOS capitalises the first letter of a text field by default, so the address
     * a device sends routinely differs in case from the one that was stored.
     */
    public static function resolve(?string $username): ?User
    {
        $username = mb_strtolower(trim((string) $username));

        if ($username === '') {
            return null;
        }

        $user = self::user();

        return $user !== null && mb_strtolower((string) $user->email) === $username
            ? $user
            : null;
    }

    /** Any user row that is not the one account. */
    public static function strays(): Collection
    {
        $user = self::user();

        return User::query()
            ->when($user !== null, fn ($q) => $q->whereKeyNot($user->getKey()))
            ->orderBy('id')
            ->get();
    }

    public static function principalUri(): string
    {
        return 'principals/'.self::userOrFail()->email;
    }
}
