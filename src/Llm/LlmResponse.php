<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Types;

use function implode;

/**
 * Response from LLM API
 *
 * @psalm-import-type ContentBlock from Types
 */
final readonly class LlmResponse
{
    /**
     * Non-text blocks (`reasoning` etc.) are carried through to the next
     * request unchanged; `getText()` ignores them.
     *
     * @param list<ContentBlock> $content   Response content blocks
     * @param list<ToolCall>     $toolCalls Tool calls from LLM
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
