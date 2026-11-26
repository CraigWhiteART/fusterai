<?php

namespace App\Domains\Agent\Tools;

use App\Domains\Agent\Pipeline\WriterSession;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Base for the writer's tools. Every tool only reads, or records into the
 * session; none performs a write anywhere else. Results are wrapped as
 * untrusted data, and a tool error becomes a result string (never an
 * exception that would abort the loop).
 */
abstract class WriterTool implements Tool
{
    public function __construct(protected readonly WriterSession $session) {}

    abstract public function name(): string;

    /** @param  array<string, mixed>  $arguments */
    abstract protected function run(array $arguments): string;

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->toArray();

        try {
            $result = $this->run($arguments);
        } catch (Throwable $e) {
            report($e);
            $result = 'Tool error: '.class_basename($e).'. Continue without this information.';
        }

        $this->session->log($this->name(), $arguments, $result);

        return $this->session->untrusted->block('tool_result_'.$this->name(), $result);
    }
}
