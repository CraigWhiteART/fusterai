<?php

namespace Modules\CommerceAssist\Services;

use App\Services\AiSettingsService;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\CommerceAssist\Agents\ValidateFactsAgent;
use Throwable;

class FactValidator
{
    /**
     * @return list<string>
     */
    public function unsupportedClaims(int $workspaceId, string $draft, string $verifiedInformation): array
    {
        if (trim(strip_tags($draft)) === '') {
            return [];
        }

        $prompt = <<<PROMPT
PROPOSED RESPONSE:
{$draft}

VERIFIED INFORMATION:
{$verifiedInformation}
PROMPT;

        try {
            /** @var StructuredAgentResponse $response */
            $response = app(AiSettingsService::class)->withWorkspaceCredentials(
                $workspaceId,
                fn ($lab, $model, $providerOptions = []) => (new ValidateFactsAgent)
                    ->withProviderOptions($providerOptions)
                    ->prompt($prompt, provider: $lab, model: $model),
                task: 'reply_suggestions',
            );

            $data = $response->toArray();
            $claims = $data['unsupported_claims'] ?? [];

            return array_values(array_filter(array_map(
                fn ($claim) => trim((string) $claim),
                is_array($claims) ? $claims : [],
            )));
        } catch (Throwable $e) {
            Log::warning('Commerce Assist fact validator failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
