<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\Validator;
use Osteel\OpenApi\Testing\ValidatorBuilder;

/**
 * Every JSON request a test makes to /api is checked against openapi.yaml.
 *
 * This is what keeps the spec honest. Add a field to a Resource without adding
 * it to the spec and the schema's `additionalProperties: false` fails the test;
 * drop one and `required` does. A status code the spec does not list for that
 * operation fails too.
 *
 * It hooks `json()`, which getJson/postJson/patchJson/deleteJson all go through,
 * so tests read like ordinary Laravel tests.
 */
trait MatchesOpenApi
{
    private static ?Validator $openApi = null;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        $response = parent::json($method, $uri, $data, $headers, $options);

        $path = (string) parse_url($uri, PHP_URL_PATH);

        if (str_starts_with($path, '/api/')) {
            self::openApi()->validate($response->baseResponse, $path, strtolower($method));
        }

        return $response;
    }

    protected static function openApi(): Validator
    {
        return self::$openApi ??= ValidatorBuilder::fromYamlFile(dirname(__DIR__, 2).'/openapi.yaml')->getValidator();
    }
}
