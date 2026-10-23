<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PlannerToken extends Command
{
    protected $signature = 'planner:token {name=shortcut : A label for the token}';

    protected $description = 'Mint a Sanctum bearer token for the iOS Shortcut endpoint and the MCP server';

    public function handle(): int
    {
        $user = User::query()->oldest('id')->first();

        if ($user === null) {
            $this->error('No user exists yet. Run: php artisan planner:user');

            return self::FAILURE;
        }

        $token = $user->createToken((string) $this->argument('name'));

        $this->newLine();
        $this->line('  Bearer token for '.$user->email.':');
        $this->newLine();
        $this->line('  '.$token->plainTextToken);
        $this->newLine();
        $this->warn('  Shown once only.');
        $this->newLine();

        return self::SUCCESS;
    }
}
