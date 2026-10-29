<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\WallClock;
use Illuminate\Console\Command;

/**
 * The command-line twin of the web app's Access tokens page, for when there is
 * no browser to hand (first setup on the box, say).
 */
class PlannerToken extends Command
{
    protected $signature = 'planner:token
        {name=shortcut : A label for the token}
        {--expires= : Last day the token works, YYYY-MM-DD. Omit for no expiry}';

    protected $description = 'Mint a bearer token for the iOS Shortcut, MCP or another client';

    public function handle(): int
    {
        $user = User::query()->oldest('id')->first();

        if ($user === null) {
            $this->error('No user exists yet. Run: php artisan planner:user');

            return self::FAILURE;
        }

        $expires = $this->option('expires');
        $expiresAt = null;

        if ($expires !== null) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || WallClock::parse($expires)->endOfDay()->isPast()) {
                $this->error('--expires must be a date in YYYY-MM-DD form, today or later.');

                return self::FAILURE;
            }

            $expiresAt = WallClock::parse($expires)->endOfDay()->utc();
        }

        $token = $user->createToken((string) $this->argument('name'), ['*'], $expiresAt);

        $this->newLine();
        $this->line('  Bearer token for '.$user->email.($expires ? ", valid through {$expires}" : '').':');
        $this->newLine();
        $this->line('  '.$token->plainTextToken);
        $this->newLine();
        $this->warn('  Shown once only. Revoke it on the web app\'s Access tokens page.');
        $this->newLine();

        return self::SUCCESS;
    }
}
