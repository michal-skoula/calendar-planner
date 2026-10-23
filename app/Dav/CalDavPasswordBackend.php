<?php

namespace App\Dav;

use App\Models\User;
use App\Services\PlannerIdentity;
use App\Services\SyncLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Throwable;

/**
 * HTTP Basic authentication for Apple Calendar, against the one planner account.
 *
 * laravel-sabre ships an AuthBackend that issues a *Bearer* challenge and resolves
 * the user from a Laravel guard. iOS has neither a session cookie nor a bearer
 * token, so it can never authenticate against it. This backend speaks Basic and
 * checks the same password the web login uses, which is why there is only one
 * credential for both surfaces.
 */
final class CalDavPasswordBackend extends AbstractBasic
{
    private ?User $authenticated = null;

    public function __construct()
    {
        $this->setRealm((string) config('caldav.realm', 'Tasks'));
    }

    /**
     * AbstractBasic ends with `[true, $this->principalPrefix.$userpass[0]]` — the
     * principal is built from the username *as the client typed it*. A device that
     * capitalises the address would authenticate fine and then go on to address a
     * principal that owns no calendar, which on a phone is indistinguishable from
     * a wrong password. So the principal comes from the stored row instead.
     */
    public function check(RequestInterface $request, ResponseInterface $response): array
    {
        [$ok, $principal] = parent::check($request, $response);

        if (! $ok || $this->authenticated === null) {
            return [$ok, $principal];
        }

        return [true, $this->principalPrefix.$this->authenticated->email];
    }

    protected function validateUserPass($username, $password): bool
    {
        $this->authenticated = null;

        $user = PlannerIdentity::resolve((string) $username);

        if ($user === null) {
            $this->deny($username, 'no such account');

            return false;
        }

        if (! $this->passwordMatches((string) $password, $user)) {
            $this->deny($username, 'wrong password');

            return false;
        }

        $this->authenticated = $user;

        return true;
    }

    /**
     * A stored hash that is empty or not bcrypt makes Hash::check throw, which
     * sabre turns into a 500 on every authenticated request while unauthenticated
     * ones still answer 401 — a confusing pair of symptoms that has cost real
     * time here. Answer 401 and say so in the log instead.
     */
    private function passwordMatches(string $password, User $user): bool
    {
        $hash = (string) $user->password;

        if ($hash === '' || $password === '') {
            return false;
        }

        try {
            return Hash::check($password, $hash);
        } catch (Throwable $e) {
            Log::channel('dav')->error(
                'CalDAV auth: the stored password hash for {email} is unusable ({reason}). Re-run php artisan planner:user.',
                ['email' => $user->email, 'reason' => $e->getMessage()],
            );

            return false;
        }
    }

    /** The address attempted and why it failed. Never the password, never the hash. */
    private function deny(mixed $username, string $reason): void
    {
        $attempted = (string) $username === '' ? '-' : (string) $username;

        Log::channel('dav')->warning('CalDAV auth refused for {username}: {reason}', [
            'username' => $attempted,
            'reason' => $reason,
        ]);

        app(SyncLog::class)->record('auth_refused', detail: "{$attempted} — {$reason}");
    }
}
