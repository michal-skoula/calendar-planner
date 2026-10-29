<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlannerIdentity;
use Database\Seeders\DavSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlannerUser extends Command
{
    protected $signature = 'planner:user
        {--email= : Defaults to PLANNER_USER_EMAIL}
        {--password= : Prompted for when omitted; input is hidden}
        {--replace : Delete any other user row, leaving only this account}';

    protected $description = 'Set the single account that signs in to both the planner and CalDAV';

    public function handle(): int
    {
        $email = $this->resolveEmail();
        if ($email === null) {
            return self::FAILURE;
        }

        $password = $this->resolvePassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $rows = User::query()->orderBy('id')->get();

        // The row this account refers to: the one already holding the address,
        // or — when there is exactly one user — that row renamed. Anything else
        // is a stray, and a stray cannot authenticate anywhere, because
        // PlannerIdentity picks exactly one account. So check before writing.
        $target = $rows->first(fn (User $u): bool => mb_strtolower((string) $u->email) === $email)
            ?? ($rows->count() === 1 ? $rows->first() : null);

        $strays = $rows->reject(fn (User $u): bool => $target !== null && $u->is($target));

        if ($strays->isNotEmpty() && ! $this->option('replace')) {
            $this->reportStrays($strays);

            return self::FAILURE;
        }

        $renamedFrom = $target !== null && mb_strtolower((string) $target->email) !== $email
            ? (string) $target->email
            : null;

        $user = $this->store($target ?? new User, $email, $password);

        if ($strays->isNotEmpty()) {
            $this->remove($strays);
        }

        if ($renamedFrom !== null) {
            $this->warn("Renamed the account {$renamedFrom} -> {$user->email}.");
        }

        // Prove the credential works before claiming it does. Without this, a
        // silently bad write looks identical to a successful one.
        if (! Auth::validate(['id' => $user->getKey(), 'password' => $password])) {
            $this->error('The account was written but the password does not authenticate. Nothing has been left usable.');

            return self::FAILURE;
        }

        // The DAV principal is derived from the email, so changing it moves the
        // principal. Re-seeding carries the calendar across in the same breath.
        $this->call('db:seed', ['--class' => DavSeeder::class, '--force' => true]);

        $this->report($user);

        return self::SUCCESS;
    }

    private function store(User $user, string $email, string $password): User
    {
        // The `hashed` cast on User::$casts does the hashing, and it passes an
        // already-hashed value through untouched, so handing it the plaintext is
        // correct and cannot double-hash.
        $user->fill([
            'name' => $user->name ?: str($email)->before('@')->headline()->toString(),
            'email' => $email,
            'password' => $password,
        ])->save();

        return $user->refresh();
    }

    /** @param  Collection<int, User>  $strays */
    private function reportStrays(Collection $strays): void
    {
        $this->newLine();
        $this->error('There is more than one user row, and only one account can sign in.');
        $this->newLine();
        $this->line('  These would be left unable to log in anywhere:');
        foreach ($strays as $stray) {
            $this->line("    #{$stray->getKey()}  {$stray->email}");
        }
        $this->newLine();
        $this->line('  Re-run with --replace to delete them and keep only the account you named.');
        $this->newLine();
    }

    /** @param  Collection<int, User>  $strays */
    private function remove(Collection $strays): void
    {
        foreach ($strays as $stray) {
            // Sanctum rows are keyed on the user, so they would otherwise outlive it.
            $stray->tokens()->delete();
            $stray->delete();

            $this->warn("Deleted user #{$stray->getKey()} {$stray->email}.");
        }
    }

    private function report(User $user): void
    {
        $this->newLine();
        $this->info("One account, one password: {$user->email}");
        $this->newLine();
        $this->line('  web login:   '.rtrim((string) config('app.url'), '/').'/login');
        $this->line('  CalDAV:      '.rtrim((string) config('app.url'), '/').'/dav  (same email, same password)');
        $this->line('  principal:   '.PlannerIdentity::principalUri());

        // Which database. Creating an account locally and then trying it against
        // the deployed site is the easiest mistake to make here, and it looks
        // exactly like a broken password.
        $this->line('  database:    '.$this->databaseLabel());
        $this->newLine();
    }

    private function resolveEmail(): ?string
    {
        $email = $this->option('email') ?? env('PLANNER_USER_EMAIL');

        if (blank($email) && $this->input->isInteractive()) {
            $email = $this->ask('Email');
        }

        $email = is_string($email) ? trim($email) : '';

        if ($email === '') {
            $this->error('An email is required. Pass --email, or set PLANNER_USER_EMAIL.');

            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("[{$email}] is not a valid email address.");

            return null;
        }

        // Stored lowercase so the address is one value everywhere. Both logins
        // compare case-insensitively, but only if what is stored is predictable.
        return mb_strtolower($email);
    }

    private function resolvePassword(): ?string
    {
        $password = $this->option('password');

        // Deliberately not `?:` — an explicitly empty --password must fail loudly
        // rather than fall through to a prompt that may have nothing to read.
        if ($password === null && $this->input->isInteractive()) {
            $this->comment('  Typing is hidden, so you will see nothing as you type.');
            $this->comment('  This becomes the password for the web UI and for CalDAV alike.');
            $password = $this->secret('Password');
        }

        $password = is_string($password) ? $password : '';

        if (trim($password) === '') {
            $this->error('A password is required. Pass --password, or run this interactively.');

            return null;
        }

        if (mb_strlen($password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return null;
        }

        return $password;
    }

    private function databaseLabel(): string
    {
        $connection = DB::connection();
        $name = $connection->getDatabaseName();

        return $connection->getDriverName() === 'sqlite'
            ? $name.' ('.(is_file($name) ? 'this machine' : 'in memory').')'
            : $connection->getDriverName().':'.$name;
    }
}
