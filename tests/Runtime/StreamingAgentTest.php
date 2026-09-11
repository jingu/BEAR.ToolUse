<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeStreamingLlmClient;
use BEAR\ToolUse\Fake\FakeThrowingDispatcher;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;
use function end;
use function iterator_to_array;
use function json_encode;

#[CoversClass(StreamingAgent::class)]
#[CoversClass(StreamContentAccumulator::class)]
#[CoversClass(StreamIterationState::class)]
#[CoversClass(AgentEvent::class)]
#[CoversClass(StreamEvent::class)]
final class StreamingAgentTest extends TestCase
{
    private StreamingAgent $agent;
    private FakeStreamingLlmClient $llmClient;

    protected function setUp(): void
    {
        $this->llmClient = new FakeStreamingLlmClient();

        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('article_get', 'article', 'get');
        $dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());

        $tools = [
            new Tool('article_get', 'Get an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
        ];

        $this->agent = new StreamingAgent(
            client: $this->llmClient,
            dispatcher: $dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    public function testSimpleTextStreaming(): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Hello']),
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => ' world']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Hi'));

        self::assertCount(3, $events);
        self::assertSame(AgentEvent::TEXT_DELTA, $events[0]->type);
        self::assertSame('Hello', $events[0]->data['text']);
        self::assertSame(AgentEvent::TEXT_DELTA, $events[1]->type);
        self::assertSame(' world', $events[1]->data['text']);
        self::assertSame(AgentEvent::COMPLETED, $events[2]->type);
        self::assertSame('Hello world', $events[2]->data['fullText']);
    }

    public function testToolUseStreaming(): void
    {
        $toolInput = json_encode(['id' => 123]);

        $this->llmClient->setEventSequences([
            // First call: LLM requests tool use
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Looking up...']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            // Second call: LLM responds after tool result
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Found it!']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Get article 123'));

        // Expect: text_delta("Looking up..."), tool_start, tool_result, text_delta("\n"), text_delta("Found it!"), completed
        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertSame([
            AgentEvent::TEXT_DELTA,
            AgentEvent::TOOL_START,
            AgentEvent::TOOL_RESULT,
            AgentEvent::TEXT_DELTA,  // newline separator
            AgentEvent::TEXT_DELTA,
            AgentEvent::COMPLETED,
        ], $types);

        self::assertSame("\n", $events[3]->data['text']);
        self::assertSame('article_get', $events[1]->data['toolName']);
        self::assertSame('article_get', $events[2]->data['toolName']);
        self::assertSame("Looking up...\nFound it!", $events[5]->data['fullText']);
    }

    public function testReasoningIsKeptInHistoryButNotEmitted(): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::REASONING_DELTA, ['text' => 'The user wants article 123.']),
                new StreamEvent(StreamEvent::REASONING_SIGNATURE, ['signature' => 'sig-abc']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Looking up...']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Get article 123'));

        // Reasoning must not reach the caller: only the visible text and completion
        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertSame([AgentEvent::TEXT_DELTA, AgentEvent::COMPLETED], $types);
        self::assertSame('Looking up...', $events[1]->data['fullText']);

        // ...but it must survive in the history so the next request can replay it
        $assistant = $this->agent->messages[1];
        self::assertSame('assistant', $assistant->role);
        self::assertSame([
            ['type' => 'reasoning', 'text' => 'The user wants article 123.', 'signature' => 'sig-abc'],
            ['type' => 'text', 'text' => 'Looking up...'],
        ], $assistant->content);
    }

    public function testReasoningWithheldTextStillKeepsSignature(): void
    {
        // Providers may withhold the reasoning text and return only a signature
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::REASONING_SIGNATURE, ['signature' => 'sig-only']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Done']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        iterator_to_array($this->agent->runStream('Hi'));

        self::assertSame([
            ['type' => 'reasoning', 'text' => '', 'signature' => 'sig-only'],
            ['type' => 'text', 'text' => 'Done'],
        ], $this->agent->messages[1]->content);
    }

    public function testRedactedReasoningKeepsItsOwnBlockType(): void
    {
        // Safety-redacted reasoning is a distinct block carrying opaque data,
        // not a regular reasoning block with an empty text
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::REASONING_REDACTED, ['data' => 'EncRypTedBlob==']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Done']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Hi'));

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertSame([AgentEvent::TEXT_DELTA, AgentEvent::COMPLETED], $types);
        self::assertSame([
            ['type' => 'redacted_reasoning', 'data' => 'EncRypTedBlob=='],
            ['type' => 'text', 'text' => 'Done'],
        ], $this->agent->messages[1]->content);
    }

    public function testReasoningSurvivesToolResultRoundTrip(): void
    {
        $toolInput = json_encode(['id' => 123]);

        $this->llmClient->setEventSequences([
            // First call: reasoning, redacted reasoning, then a tool call
            [
                new StreamEvent(StreamEvent::REASONING_DELTA, ['text' => 'Need to look it up.']),
                new StreamEvent(StreamEvent::REASONING_SIGNATURE, ['signature' => 'sig-1']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::REASONING_REDACTED, ['data' => 'Blob==']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            // Second call: after the tool result
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Found it!']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        iterator_to_array($this->agent->runStream('Get article 123'));

        // The assistant turn that requested the tool must keep both reasoning
        // blocks ahead of the tool_use, in the order the model produced them
        self::assertSame([
            ['type' => 'reasoning', 'text' => 'Need to look it up.', 'signature' => 'sig-1'],
            ['type' => 'redacted_reasoning', 'data' => 'Blob=='],
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'article_get', 'input' => ['id' => 123]],
        ], $this->agent->messages[1]->content);

        // ...and the tool result follows it, so the next request replays both
        self::assertSame('user', $this->agent->messages[2]->role);
    }

    /**
     * @param list<StreamEvent> $middle
     */
    #[DataProvider('blockBetweenTextProvider')]
    public function testTextBlockDoesNotCarryOverPrecedingText(string $middleType, array $middle): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'A']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                ...$middle,
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'B']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        iterator_to_array($this->agent->runStream('Hi'));

        $content = $this->agent->messages[1]->content;
        self::assertCount(3, $content);
        self::assertSame(['type' => 'text', 'text' => 'A'], $content[0]);
        self::assertSame($middleType, $content[1]['type']);
        // The trailing block must be 'B', not 'AB': each block owns only its own text
        self::assertSame(['type' => 'text', 'text' => 'B'], $content[2]);
    }

    /** @return array<string, array{0: string, 1: list<StreamEvent>}> */
    public static function blockBetweenTextProvider(): array
    {
        return [
            'reasoning' => ['reasoning', [
                new StreamEvent(StreamEvent::REASONING_DELTA, ['text' => 'R']),
                new StreamEvent(StreamEvent::REASONING_SIGNATURE, ['signature' => 'sig']),
            ]],
            'redacted reasoning' => ['redacted_reasoning', [
                new StreamEvent(StreamEvent::REASONING_REDACTED, ['data' => 'Blob==']),
            ]],
            'tool use' => ['tool_use', [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => '{"id":1}']),
            ]],
        ];
    }

    public function testMaxIterationsReached(): void
    {
        $toolInput = json_encode(['id' => 1]);

        $sequences = [];
        for ($i = 0; $i < 6; $i++) {
            $sequences[] = [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_' . $i, 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ];
        }

        $this->llmClient->setEventSequences($sequences);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Keep calling tools'));

        $lastEvent = end($events);
        self::assertSame(AgentEvent::ERROR, $lastEvent->type);
        self::assertSame('Max iterations reached', $lastEvent->data['message']);
    }

    public function testReset(): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Hello']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        iterator_to_array($this->agent->runStream('Hi'));
        self::assertNotEmpty($this->agent->messages);

        $this->agent->reset();
        self::assertEmpty($this->agent->messages);
    }

    public function testAgentEventJsonSerialize(): void
    {
        $event = AgentEvent::textDelta('hello');
        $json = json_encode($event);

        self::assertSame('{"type":"text_delta","text":"hello"}', $json);
    }

    public function testAgentEventCompleted(): void
    {
        $event = AgentEvent::completed('full text');
        $json = json_encode($event);

        self::assertSame('{"type":"completed","fullText":"full text"}', $json);
    }

    public function testAgentEventError(): void
    {
        $event = AgentEvent::error('something failed');
        self::assertSame(AgentEvent::ERROR, $event->type);
        self::assertSame('something failed', $event->data['message']);
    }

    public function testOtherStopReason(): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Partial']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'max_tokens']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Hi'));

        $lastEvent = end($events);
        self::assertSame(AgentEvent::COMPLETED, $lastEvent->type);
        self::assertSame('Partial', $lastEvent->data['fullText']);
    }

    public function testToolDispatchException(): void
    {
        $llmClient = new FakeStreamingLlmClient();
        $tools = [
            new Tool('article_get', 'Get an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
        ];
        $agent = new StreamingAgent(
            client: $llmClient,
            dispatcher: new FakeThrowingDispatcher(),
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );

        $toolInput = json_encode(['id' => 1]);
        $llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Error handled']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($agent->runStream('Call tool'));

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertContains(AgentEvent::TOOL_RESULT, $types);
        $lastEvent = end($events);
        self::assertSame(AgentEvent::COMPLETED, $lastEvent->type);
    }

    public function testNonStringEventData(): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 42]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Hi'));

        self::assertSame(AgentEvent::TEXT_DELTA, $events[0]->type);
        self::assertSame('', $events[0]->data['text']);
    }

    public function testEmptyContentBlockStop(): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Hi'));

        self::assertCount(1, $events);
        self::assertSame(AgentEvent::COMPLETED, $events[0]->type);
    }

    public function testToolUseWithEmptyPendingToolCalls(): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Done']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($this->agent->runStream('Hi'));

        $lastEvent = end($events);
        self::assertSame(AgentEvent::COMPLETED, $lastEvent->type);
    }

    public function testConversationHistoryPreserved(): void
    {
        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'First response']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        iterator_to_array($this->agent->runStream('First message'));

        // 1 user message + 1 assistant message from content_block_stop
        self::assertCount(2, $this->agent->messages);
        self::assertSame('user', $this->agent->messages[0]->role);
        self::assertSame('assistant', $this->agent->messages[1]->role);
    }
}
