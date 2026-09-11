<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Llm;

/**
 * Low-level stream event from LLM
 */
final readonly class StreamEvent
{
    public const TEXT_DELTA = 'text_delta';
    public const REASONING_DELTA = 'reasoning_delta';
    public const REASONING_SIGNATURE = 'reasoning_signature';
    public const REASONING_REDACTED = 'reasoning_redacted';
    public const TOOL_USE_START = 'tool_use_start';
    public const TOOL_USE_DELTA = 'tool_use_delta';
    public const CONTENT_BLOCK_STOP = 'content_block_stop';
    public const MESSAGE_STOP = 'message_stop';

    /** @param array<string, mixed> $data */
    public function __construct(
        public string $type,
        public array $data = [],
    ) {
    }
}
