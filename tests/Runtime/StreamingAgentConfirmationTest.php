<?php

declare(strict_types=1);

namespace BEAR\ToolUse\Runtime;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\ToolUse\Dispatch\Dispatcher;
use BEAR\ToolUse\Dispatch\NullToolCallObserver;
use BEAR\ToolUse\Dispatch\ToolRegistry;
use BEAR\ToolUse\Fake\FakeStreamingLlmClient;
use BEAR\ToolUse\Llm\StreamEvent;
use BEAR\ToolUse\Schema\Tool;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_map;
use function iterator_to_array;
use function json_encode;

#[CoversClass(StreamingAgent::class)]
#[CoversClass(AgentEvent::class)]
final class StreamingAgentConfirmationTest extends TestCase
{
    private FakeStreamingLlmClient $llmClient;
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->llmClient = new FakeStreamingLlmClient();

        $injector = new Injector(new ResourceModule('BEAR\ToolUse\Fake'));
        $resource = $injector->getInstance(ResourceInterface::class);
        $registry = new ToolRegistry();
        $registry->register('article_get', 'app://self/article', 'get');
        $registry->register('article_delete', 'app://self/article', 'delete');
        $this->dispatcher = new Dispatcher($resource, $registry, new NullToolCallObserver());
    }

    public function testConfirmationApproved(): void
    {
        $agent = $this->createAgentWithConfirmableTool();
        $toolInput = json_encode(['id' => 123]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'I will delete article 123.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_delete']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Article 123 has been deleted.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        $events = $this->consumeWithConfirmation($agent->runStream('Delete article 123'), true);

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertContains(AgentEvent::CONFIRMATION_REQUIRED, $types);
        self::assertContains(AgentEvent::TOOL_RESULT, $types);
        self::assertContains(AgentEvent::COMPLETED, $types);
    }

    public function testConfirmationDenied(): void
    {
        $agent = $this->createAgentWithConfirmableTool();
        $toolInput = json_encode(['id' => 123]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'I will delete article 123.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_delete']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Understood, I will not delete the article.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        $events = $this->consumeWithConfirmation($agent->runStream('Delete article 123'), false);

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertContains(AgentEvent::CONFIRMATION_REQUIRED, $types);

        // Verify the LLM received the cancellation error
        $secondCallMessages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage  = $secondCallMessages[2];
        self::assertTrue($toolResultMessage->content[0]['is_error']);
        self::assertSame('User cancelled this operation.', $toolResultMessage->content[0]['content']);
    }

    public function testNonConfirmableToolExecutedWithoutConfirmation(): void
    {
        $tools = [
            new Tool('article_get', 'Get an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
        ];

        $agent = new StreamingAgent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );

        $toolInput = json_encode(['id' => 1]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_get']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Here is the article.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($agent->runStream('Get article 1'));

        $types = array_map(static fn (AgentEvent $e): string => $e->type, $events);
        self::assertNotContains(AgentEvent::CONFIRMATION_REQUIRED, $types);
        self::assertContains(AgentEvent::TOOL_RESULT, $types);
    }

    public function testConfirmationRequiredEventData(): void
    {
        $agent = $this->createAgentWithConfirmableTool();
        $toolInput = json_encode(['id' => 42]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Deleting article 42.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_42', 'name' => 'article_delete']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'OK.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        $events = $this->consumeWithConfirmation($agent->runStream('Delete article 42'), true);

        $confirmEvents = [];
        foreach ($events as $event) {
            if ($event->type !== AgentEvent::CONFIRMATION_REQUIRED) {
                continue;
            }

            $confirmEvents[] = $event;
        }

        self::assertCount(1, $confirmEvents);
        self::assertSame('article_delete', $confirmEvents[0]->data['toolName']);
        self::assertSame('call_42', $confirmEvents[0]->data['toolId']);
        self::assertSame(['id' => 42], $confirmEvents[0]->data['input']);
        self::assertSame('Deleting article 42.', $confirmEvents[0]->data['message']);
    }

    public function testConfirmationPromptKeepsTheWholeTurnText(): void
    {
        // The prompt shows what the model said this turn. A reasoning block
        // between two text blocks must not truncate it to the last block.
        $agent = $this->createAgentWithConfirmableTool();

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Let me check. ']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::REASONING_DELTA, ['text' => 'The user asked to delete it.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Deleting now.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_42', 'name' => 'article_delete']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => json_encode(['id' => 42])]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Cancelled.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        $events = $this->consumeWithConfirmation($agent->runStream('Delete article 42'), false);

        $prompt = null;
        foreach ($events as $event) {
            if ($event->type !== AgentEvent::CONFIRMATION_REQUIRED) {
                continue;
            }

            $prompt = $event->data['message'];
        }

        self::assertSame('Let me check. Deleting now.', $prompt);
    }

    public function testConfirmationRequiredJsonSerialize(): void
    {
        $event = AgentEvent::confirmationRequired('delete_user', 'call_1', ['id' => 1], 'Delete user?');

        $json = $event->jsonSerialize();

        self::assertSame('confirmation_required', $json['type']);
        self::assertSame('delete_user', $json['toolName']);
        self::assertSame('call_1', $json['toolId']);
        self::assertSame(['id' => 1], $json['input']);
        self::assertSame('Delete user?', $json['message']);
    }

    public function testDefaultDenialWithoutSend(): void
    {
        $agent = $this->createAgentWithConfirmableTool();
        $toolInput = json_encode(['id' => 1]);

        $this->llmClient->setEventSequences([
            [
                new StreamEvent(StreamEvent::TOOL_USE_START, ['id' => 'call_1', 'name' => 'article_delete']),
                new StreamEvent(StreamEvent::TOOL_USE_DELTA, ['input' => $toolInput]),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'tool_use']),
            ],
            [
                new StreamEvent(StreamEvent::TEXT_DELTA, ['text' => 'Cancelled.']),
                new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP),
                new StreamEvent(StreamEvent::MESSAGE_STOP, ['stopReason' => 'end_turn']),
            ],
        ]);

        // iterator_to_array sends null, which should deny by default
        /** @var list<AgentEvent> $events */
        $events = iterator_to_array($agent->runStream('Delete article 1'));

        // Verify cancellation was sent to LLM
        $secondCallMessages = $this->llmClient->calls[1]['messages'];
        $toolResultMessage  = $secondCallMessages[2];
        self::assertTrue($toolResultMessage->content[0]['is_error']);
        self::assertSame('User cancelled this operation.', $toolResultMessage->content[0]['content']);
    }

    private function createAgentWithConfirmableTool(): StreamingAgent
    {
        $tools = [
            new Tool('article_delete', 'Delete an article', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ], confirm: true),
        ];

        return new StreamingAgent(
            client: $this->llmClient,
            dispatcher: $this->dispatcher,
            tools: $tools,
            systemPrompt: 'You are a helpful assistant.',
            maxIterations: 5,
        );
    }

    /**
     * Consume a streaming generator, sending confirmation responses
     *
     * @param Generator<int, AgentEvent, bool, void> $gen
     *
     * @return list<AgentEvent>
     */
    private function consumeWithConfirmation(Generator $gen, bool $approve): array
    {
        $events = [];
        while ($gen->valid()) {
            $event = $gen->current();
            $events[] = $event;
            if ($event->type === AgentEvent::CONFIRMATION_REQUIRED) {
                $gen->send($approve);
            } else {
                $gen->next();
            }
        }

        return $events;
    }
}
