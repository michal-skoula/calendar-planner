<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The built web app is served for any path nothing else claims, and nothing
 * the backend owns is shadowed by it.
 */
class SpaFallbackTest extends TestCase
{
    private string $index;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = public_path('index.html');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->index) && str_contains(File::get($this->index), 'test-build')) {
            File::delete($this->index);
        }

        parent::tearDown();
    }

    public function test_app_paths_get_the_built_page(): void
    {
        if (File::exists($this->index)) {
            $this->markTestSkipped('A real build is in public/; not overwriting it.');
        }

        File::put($this->index, '<!doctype html><title>test-build</title>');

        foreach (['/', '/login', '/sync', '/calendars'] as $path) {
            $this->get($path)->assertOk()->assertSee('test-build', escape: false);
        }
    }

    public function test_without_a_build_the_server_says_where_the_app_is(): void
    {
        if (File::exists($this->index)) {
            $this->markTestSkipped('A real build is in public/.');
        }

        $this->get('/')->assertNotFound()->assertSee('npm run dev');
    }

    public function test_an_unknown_api_path_is_a_json_404_rather_than_the_page(): void
    {
        $this->get('/api/nope')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_caldav_discovery_is_not_shadowed(): void
    {
        $this->get('/.well-known/caldav')->assertRedirect('/dav');
        $this->get('/up')->assertOk();
    }
}
