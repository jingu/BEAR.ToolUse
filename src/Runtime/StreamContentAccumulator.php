<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Types;

use function is_string;
use function json_decode;

/**
 * Accumulates stream events into content blocks and iteration state
 *
 * @psalm-import-type ContentBlock from Types
 */
final class StreamContentAccumulator
{
    private string $currentText = '';
    private bool $needsSeparator;
    private string $stopReason = 'end_turn';
    private string $currentToolId = '';
    private string $currentToolName = '';
    private string $currentToolInputJson = '';
    private string $currentReasoning = '';
    private string $currentReasoningSignature = '';

    /** @var list<PendingToolCall> */
    private array $pendingToolCalls = [];

    /** @var list<ContentBlock> */
    private array $contentBlocks = [];

    public function __construct(bool $hadPreviousText, private string $fullText)
    {
        $this->needsSeparator = $hadPreviousText;
    }

    /** @return list<AgentEvent> */
    public function handleEvent(StreamEvent $event): array
    {
        return match ($event->type) {
            StreamEvent::TEXT_DELTA => $this->handleTextDelta($event),
            StreamEvent::REASONING_DELTA => $this->handleReasoningDelta($event),
            StreamEvent::REASONING_SIGNATURE => $this->handleReasoningSignature($event),
            StreamEvent::TOOL_USE_START => $this->handleToolUseStart($event),
            StreamEvent::TOOL_USE_DELTA => $this->handleToolUseDelta($event),
            StreamEvent::CONTENT_BLOCK_STOP => $this->handleContentBlockStop(),
            StreamEvent::MESSAGE_STOP => $this->handleMessageStop($event),
            default => [],
        };
    }

    public function toState(): StreamIterationState
    {
        return new StreamIterationState(
            stopReason: $this->stopReason,
            currentText: $this->currentText,
            pendingToolCalls: $this->pendingToolCalls,
            contentBlocks: $this->contentBlocks,
            fullText: $this->fullText,
        );
    }

    /** @return list<AgentEvent> */
    private function handleTextDelta(StreamEvent $event): array
    {
        $text = $this->eventString($event, 'text');
        $events = [];

        if ($this->needsSeparator) {
            $this->fullText .= "\n";
            $events[] = AgentEvent::textDelta("\n");
            $this->needsSeparator = false;
        }

        $this->currentText .= $text;
        $this->fullText .= $text;
        $events[] = AgentEvent::textDelta($text);

        return $events;
    }

    /**
     * Reasoning text is not surfaced as an AgentEvent: callers that display
     * agent output must not leak the model's internal reasoning by default.
     * It is kept only to be replayed to the model on the next request.
     *
     * @return list<AgentEvent>
     */
    private function handleReasoningDelta(StreamEvent $event): array
    {
        $this->currentReasoning .= $this->eventString($event, 'text');

        return [];
    }

    /** @return list<AgentEvent> */
    private function handleReasoningSignature(StreamEvent $event): array
    {
        $this->currentReasoningSignature = $this->eventString($event, 'signature');

        return [];
    }

    /** @return list<AgentEvent> */
    private function handleToolUseStart(StreamEvent $event): array
    {
        $this->currentToolId = $this->eventString($event, 'id');
        $this->currentToolName = $this->eventString($event, 'name');
        $this->currentToolInputJson = '';

        return [AgentEvent::toolStart($this->currentToolName)];
    }

    /** @return list<AgentEvent> */
    private function handleToolUseDelta(StreamEvent $event): array
    {
        $this->currentToolInputJson .= $this->eventString($event, 'input');

        return [];
    }

    /** @return list<AgentEvent> */
    private function handleContentBlockStop(): array
    {
        $this->finalizeContentBlock();
        $this->currentToolId = '';
        $this->currentToolName = '';
        $this->currentToolInputJson = '';
        $this->currentReasoning = '';
        $this->currentReasoningSignature = '';

        return [];
    }

    /** @return list<AgentEvent> */
    private function handleMessageStop(StreamEvent $event): array
    {
        $this->stopReason = $this->eventString($event, 'stopReason', 'end_turn');

        return [];
    }

    private function finalizeContentBlock(): void
    {
        // A reasoning block carries a signature even when its text is withheld
        // by the provider, and the model needs both back to continue the turn
        if ($this->currentReasoning !== '' || $this->currentReasoningSignature !== '') {
            $this->contentBlocks[] = [
                'type' => 'reasoning',
                'text' => $this->currentReasoning,
                'signature' => $this->currentReasoningSignature,
            ];

            return;
        }

        if ($this->currentToolName !== '') {
            $this->pendingToolCalls[] = new PendingToolCall(
                $this->currentToolId,
                $this->currentToolName,
                $this->currentToolInputJson,
            );
            /** @var array<string, mixed> $input */
            $input = (array) json_decode($this->currentToolInputJson, true);
            $this->contentBlocks[] = [
                'type' => 'tool_use',
                'id' => $this->currentToolId,
                'name' => $this->currentToolName,
                'input' => $input,
            ];

            return;
        }

        if ($this->currentText === '') {
            return;
        }

        $this->contentBlocks[] = ['type' => 'text', 'text' => $this->currentText];
    }

    private function eventString(StreamEvent $event, string $key, string $default = ''): string
    {
        $value = $event->data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
