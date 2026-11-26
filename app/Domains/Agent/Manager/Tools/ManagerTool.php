<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Manager\ManagerSession;
use App\Domains\Agent\Models\Playbook;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Base for the manager's tools. Read tools only read this workspace's rows;
 * the step cap, token budget and deadline are enforced here (a refused call
 * returns a message telling the model to answer). Results are wrapped as
 * untrusted data: they can contain customer mail. Errors become results.
 */
abstract class ManagerTool implements Tool
{
    public function __construct(protected readonly ManagerSession $session) {}

    abstract public function name(): string;

    /** @param  array<string, mixed>  $arguments */
    abstract protected function run(array $arguments): string;

    /** Short label for the progress stream ("Reading run #12"). */
    abstract protected function label(array $arguments): string;

    /** stage_proposal / add_restriction are not limited by the step / token caps. */
    protected function isStage(): bool
    {
        return false;
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->toArray();
        $this->session->calls++;
        $refused = false;
        $ok = true;

        if (($reason = $this->session->blockedReason($this->isStage())) !== null) {
            $refused = true;
            $result = "Not run: {$reason}. Do not call more tools; answer the user now with what you have and say what you could not check.";
        } else {
            try {
                $result = $this->run($arguments);
            } catch (Throwable $e) {
                report($e);
                $ok = false;
                $result = 'Tool error: '.class_basename($e).'. Continue without this information.';
            }
        }

        $result = mb_substr($result, 0, $this->session->resultChars);
        $this->session->charge($arguments, $result);
        $this->session->step([
            'tool' => $this->name(),
            'arguments' => self::compact($arguments),
            'summary' => mb_substr($this->label($arguments), 0, 200),
            'ok' => $ok,
            'refused' => $refused,
            'step' => $this->session->calls,
        ]);

        return $this->session->untrusted->block('tool_result_'.$this->name(), $result);
    }

    /** @param  array<string, mixed>  $data */
    protected static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    protected static function cut(?string $text, int $chars): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);

        return mb_strlen($text) > $chars ? mb_substr($text, 0, $chars).'…' : $text;
    }

    protected static function date(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $date = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return strlen(trim($value)) <= 10 ? ($endOfDay ? $date->endOfDay() : $date->startOfDay()) : $date;
    }

    protected static function limit(mixed $value, int $default, int $max): int
    {
        $n = is_numeric($value) ? (int) $value : $default;

        return max(1, min($max, $n > 0 ? $n : $default));
    }

    /** A playbook of this workspace by id or slug. */
    protected function playbook(mixed $ref): ?Playbook
    {
        if ($ref === null || $ref === '' || $ref === 0) {
            return null;
        }
        $query = Playbook::with('liveVersion')->where('workspace_id', $this->session->workspaceId);

        return is_numeric($ref) ? $query->find((int) $ref) : $query->where('slug', trim((string) $ref))->first();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function compact(array $arguments): array
    {
        $json = (string) json_encode($arguments, JSON_UNESCAPED_UNICODE);

        return mb_strlen($json) <= 2000 ? $arguments : ['truncated' => mb_substr($json, 0, 2000)];
    }
}
