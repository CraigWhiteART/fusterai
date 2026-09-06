<?php

namespace Modules\CommerceAssist\Services;

use Modules\CommerceAssist\Models\CommerceIntent;
use Modules\CommerceAssist\Models\CommerceSetting;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Models\TrackingSnapshot;

class ConfidenceScorer
{
    /**
     * @param  list<string>  $unsupportedClaims
     * @param  list<mixed>  $examples
     * @param  list<mixed>  $kbDocs
     * @return array{confidence: float, safe_to_send: bool, requires_human: bool}
     */
    public function score(
        CommerceSetting $settings,
        CommerceIntent $intent,
        ?CommerceIntent $subtype,
        ShopifySnapshot $snapshot,
        array $unsupportedClaims,
        array $examples,
        array $kbDocs,
        ?TrackingSnapshot $tracking = null,
    ): array {
        $score = 0.9;
        $requiresHuman = $intent->always_human || ($subtype?->always_human ?? false);
        $requirements = array_merge(
            $intent->data_requirements ?? [],
            $subtype?->data_requirements ?? [],
        );

        if (in_array('shopify_order', $requirements, true) && ! $snapshot->found()) {
            $score -= 0.25;
            $requiresHuman = true;
        }

        if (in_array('tracking', $requirements, true) && empty($snapshot->payload['tracking_number'] ?? null)) {
            $score -= 0.1;
        }

        if (in_array('tracking_live', $requirements, true) && ! ($tracking?->found() ?? false)) {
            // Answering "where is it" without carrier state is guesswork.
            $score -= 0.1;
        }

        if ($tracking?->found()) {
            // A carrier exception or failed attempt needs a person, not a
            // reassuring paragraph — the next step is usually a claim.
            if ($tracking->needsAttention()) {
                $score -= 0.2;
                $requiresHuman = true;
            }

            if ($tracking->isStalled($settings->tracking_stale_days)) {
                $score -= 0.15;
                $requiresHuman = true;
            }

            // Delivered but nobody recorded taking it: the classic
            // delivered-not-received dispute. Never auto-send into that.
            if ($tracking->isDelivered() && ! $tracking->proofIsAttributable()) {
                $requiresHuman = true;
            }
        }

        if ($unsupportedClaims !== []) {
            $score -= 0.4;
            $requiresHuman = true;
        }

        if ($examples === []) {
            $score -= 0.08;
        }

        $policyIntents = ['product_question', 'duties_tariffs', 'technical_support', 'preorder_status'];
        if (in_array($intent->slug, $policyIntents, true) && $kbDocs === []) {
            $score -= 0.12;
        }

        if (in_array($intent->slug, ['refund_request', 'wholesale', 'influencer', 'spam'], true)) {
            $requiresHuman = true;
        }

        $score = round(max(0.05, min(0.99, $score)), 3);

        $autoSendAllowed = $intent->auto_send_allowed && ! ($subtype?->always_human ?? false);
        $inherentlySafe = $autoSendAllowed
            && ! $requiresHuman
            && $unsupportedClaims === []
            && $score >= 0.85;

        return [
            'confidence' => $score,
            'safe_to_send' => $inherentlySafe && ! $settings->draft_only,
            'requires_human' => $requiresHuman,
        ];
    }
}
