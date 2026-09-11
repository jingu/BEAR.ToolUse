# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - Unreleased

### Added
- `StreamEvent::REASONING_DELTA` / `StreamEvent::REASONING_SIGNATURE` so streaming clients can report the reasoning blocks that thinking-capable models emit. `StreamContentAccumulator` keeps them as `{type: 'reasoning', text, signature}` content blocks in the assistant message, which is what these models require to be replayed on the following request. Reasoning is never surfaced as an `AgentEvent`, so callers do not leak it into user-facing output; clients that do not emit these events are unaffected.
- `ToolCallObserverInterface` invoked once per `Dispatcher` dispatch (success, status>=400, exception, unknown tool) with `ToolCall`, `ToolResult` (post-filter), and elapsed `durationMs`. `ToolUseModule` binds `NullToolCallObserver` (no-op) by default; applications can override the binding to plug in audit logging, metrics, or latency tracking.
- Resource classes as AI agent tools via `#[Tool]` attribute
- `#[Exclude]` attribute to exclude methods/classes from tool exposure
- `#[Tool(confirm: true)]` for human-in-the-loop confirmation on destructive operations
- `#[Tool(filter: FilterClass::class)]` for response body filtering before sending to LLM
- JSON Schema integration for parameter validation (`#[JsonSchema]`)
- ALPS semantic dictionary for parameter descriptions (JSON and XML profiles)
- Parameter description resolution (JSON Schema > PHPDoc > ALPS)
- Synchronous `Agent` with `ConfirmationHandlerInterface`
- `StreamingAgent` for SSE real-time output with yield-based confirmation
- `ToolList` class for tool collection queries (`isConfirmable()`)
- `ToolResult::cancelled()` for encapsulating cancellation results
- Error feedback loop: exceptions and 4xx/5xx status codes fed back to LLM
- `ToolUseModule` for Ray.Di binding configuration
- Client tools for frontend tool calling: `Tool(client: true)` definitions registered via `AgentFactory::addClientTools()` are exposed to the LLM but never dispatched server-side. A client tool call ends the run — `AgentResponse::STOP_CLIENT_TOOL_USE` (sync) / `AgentEvent::CLIENT_TOOL_CALL` (streaming) — and the consumer executes it (e.g. browser UI update), then continues with `Agent::resume()` / `StreamingAgent::resumeStream()`; server-side results held from the interrupted turn are merged in automatically
- Resume validation: `resume()` / `resumeStream()` throw `InvalidResumeException` unless the supplied result IDs match the awaited client tool calls exactly once each (no missing, extra, or duplicate IDs; no resumption after `reset()`). Server tool results are never accepted from resume input — they must already be held from the interrupted turn. Expected IDs are derived from the conversation itself, so stateless resumption across HTTP requests on a reconstructed agent keeps working; a mixed turn is replayed with the client `tool_use` blocks only
- Malformed client tool arguments from the LLM throw `JsonException` instead of silently degrading to an empty input
- Client tool registration guards: `ConfirmableClientToolException` rejects `confirm: true` on client tools (confirmation is the client's responsibility), `DuplicateToolNameException` rejects name collisions between client and server tools in both registration orders

### Changed
- **BREAKING (interface implementers only)**: `ToolRegistryInterface::get()` now returns `BEAR\ToolUse\Dispatch\ToolMapping|null` instead of an `array{resourceUri: string, method: string, filter?: class-string}|null` shape. Direct `ToolRegistry` users are unaffected (`register()` signature is unchanged). Internal `PendingToolCall` (used by the streaming pipeline) was likewise promoted from `@psalm-type` to `BEAR\ToolUse\Runtime\PendingToolCall` — `final readonly class`. Both promotions remove static-only Psalm enforcement in favor of runtime type safety; consumer code switches from `$mapping['filter'] ?? null` style to property access.
- **BREAKING**: `AlpsSemanticDictionary` now takes a profile file path (`string`) instead of a `Koriym\AppStateDiagram\Profile` instance.

  ```php
  // Before
  $profile    = new Profile($path, new LabelNameTitle());
  $dictionary = new AlpsSemanticDictionary($profile);

  // After
  $dictionary = new AlpsSemanticDictionary($path);
  ```
- ALPS parsing is now self-contained: JSON/XML format auto-detection, `type === 'semantic'` filter, and same-profile `href="#id"` resolution (including multi-hop chains) are handled internally.

### Removed
- Dependency on `koriym/app-state-diagram` (and its transitive deps `koriym/data-file`, `michelf/php-markdown`, `seld/jsonlint`, `symfony/polyfill-php81`). Approx 12 MB of vendor code eliminated.
