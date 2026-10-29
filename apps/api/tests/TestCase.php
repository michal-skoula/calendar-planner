<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sign in the way the web app does. The Referer is what makes Sanctum treat
     * the request as coming from a browser and start a session for it.
     */
    protected function signIn(string $email, string $password): TestResponse
    {
        return $this->withHeader('Referer', 'http://localhost')
            ->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }
}
