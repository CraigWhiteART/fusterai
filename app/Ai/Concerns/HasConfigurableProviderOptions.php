<?php

namespace App\Ai\Concerns;

use Laravel\Ai\Enums\Lab;

trait HasConfigurableProviderOptions
{
    /** @var array<string, mixed> */
    private array $runtimeProviderOptions = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function withProviderOptions(array $options): static
    {
        $this->runtimeProviderOptions = $options;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->runtimeProviderOptions;
    }
}
