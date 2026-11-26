<?php

use App\Domains\Agent\Pipeline\UsageMeter;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

uses(TestCase::class);

test('OpenRouter model ids are priced like the vendor id, not the default', function (string $model, int $expected) {
    expect(UsageMeter::microUsd($model, new Usage(1_000_000, 1_000_000)))->toBe($expected);
})->with([
    ['anthropic/claude-haiku-5.5', 600_000],
    ['anthropic/claude-sonnet-5.5:batch', 12_000_000],
    ['claude-haiku-5-5-20260101', 600_000],
    ['openai/gpt-5.6-luna', 1_400_000],
    ['someone/unknown-model', 24_000_000],
]);
