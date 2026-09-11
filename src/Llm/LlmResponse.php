<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

use BEAR\ToolUse\Dispatch\ToolCall;

use function implode;

/**
 * Response from LLM API
 */
final readonly class LlmResponse
{
    /**
     * @param list<array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>, signature?: string}> $content   Response content blocks. Non-text blocks (`reasoning` etc.) are passed through to the next request unchanged
     * @param list<ToolCall>                                                                                     $toolCalls Tool calls from LLM
     */
    public function __construct(
        public string $stopReason,
        public array $content,
        public array $toolCalls,
    ) {
    }

    public function getText(): string
    {
        $texts = [];
        foreach ($this->content as $block) {
            if ($block['type'] !== 'text' || ! isset($block['text'])) {
                continue;
            }

            $texts[] = $block['text'];
        }

        return implode("\n", $texts);
    }
}
