# BEAR.ToolUse

BEAR.SundayアプリケーションをAIエージェント対応にするライブラリ。

リソースクラスからTool Use定義を自動生成し、LLMとのエージェントループを管理します。

## 特徴

- リソースクラスからJSON Schemaベースのツール定義を自動生成
- JSON Schema、ALPSプロファイル、PHPDocによるパラメータ説明の補強
- `#[Tool]`と`#[Exclude]`アトリビュートによる公開制御
- URIベースのリソース指定（`app://self/user`、`page://self/article`）
- LLM実装に依存しない設計（インターフェイスのみ提供）

## 要件

- PHP 8.2以上
- BEAR.Sunday

## インストール

```bash
composer require bear/tool-use
```

## 使用方法

### 1. リソースクラスの定義

```php
<?php

namespace MyApp\Resource\App;

use BEAR\Resource\ResourceObject;
use BEAR\ToolUse\Attribute\Tool;

#[Tool(description: 'ユーザー情報を管理')]
class User extends ResourceObject
{
    /**
     * ユーザーを取得
     *
     * @param int $id ユーザーID
     */
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id, 'name' => 'John'];
        return $this;
    }

    /**
     * ユーザーを作成
     *
     * @param string $name ユーザー名
     * @param string $email メールアドレス
     */
    public function onPost(string $name, string $email): static
    {
        $this->body = ['id' => 1, 'name' => $name, 'email' => $email];
        return $this;
    }
}
```

### 2. LLMクライアントの実装

```php
<?php

namespace MyApp\Llm;

use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Llm\LlmResponse;
use BEAR\ToolUse\Runtime\Message;
use BEAR\ToolUse\Schema\Tool;

final class MyLlmClient implements LlmClientInterface
{
    /**
     * @param list<Message> $messages
     * @param list<Tool> $tools
     */
    public function chat(string $system, array $messages, array $tools): LlmResponse
    {
        // LLM APIを呼び出してレスポンスを返す
    }
}
```

### 3. DIモジュールの設定

```php
<?php

namespace MyApp\Module;

use BEAR\ToolUse\Llm\LlmClientInterface;
use BEAR\ToolUse\Module\ToolUseModule;
use MyApp\Llm\MyLlmClient;
use Ray\Di\AbstractModule;

final class AppModule extends AbstractModule
{
    protected function configure(): void
    {
        $this->install(new ToolUseModule());
        $this->bind(LlmClientInterface::class)->to(MyLlmClient::class);
    }
}
```

### 4. エージェントの実行

```php
<?php

use BEAR\ToolUse\Runtime\AgentFactory;

// ファクトリーでエージェントを作成（URIベース）
$agent = $factory
    ->addResources([
        'app://self/user',
        'app://self/article',
        'page://self/search',
    ])
    ->create('あなたは親切なアシスタントです。');

// エージェントを実行
$response = $agent->run('ID 123のユーザー情報を教えてください');

if ($response->completed) {
    echo $response->getText();
}
```

### 5. 会話履歴

エージェントは複数の `run()` 呼び出しにわたって会話履歴を保持します。

```php
// 会話を継続
$response = $agent->run('そのユーザーのメールアドレスは？');

// メッセージ履歴にアクセス
$messages = $agent->messages;

// 後で使用するために保存（例：DBやセッションに）
$savedHistory = $agent->messages;

// 会話を復元して継続
$agent->messages = $savedHistory;
$response = $agent->run('このユーザーについてもっと教えて');

// 履歴をクリアして新しい会話を開始
$agent->reset();
```

### 6. ストリーミングエージェント

リアルタイム出力（SSE、WebSocket）には、ストリーミングエージェントを使用します。LLMの出力に応じてイベントをyieldします。

```php
use BEAR\ToolUse\Llm\StreamingLlmClientInterface;

// DIモジュールでストリーミングクライアントをバインド
$this->bind(StreamingLlmClientInterface::class)->to(MyStreamingLlmClient::class);
```

```php
// ストリーミングエージェントを作成
$agent = $factory
    ->addResources(['app://self/user', 'app://self/article'])
    ->createStreaming('あなたは親切なアシスタントです。');

// イベントを処理
$gen = $agent->runStream('ユーザー123を取得して');
while ($gen->valid()) {
    $event = $gen->current();
    match ($event->type) {
        'text_delta'            => sendSseEvent('text', $event->data['text']),
        'tool_start'            => sendSseEvent('status', "{$event->data['toolName']}を呼び出し中..."),
        'tool_result'           => sendSseEvent('status', "{$event->data['toolName']}完了"),
        'confirmation_required' => sendSseEvent('confirm', json_encode($event)),
        'completed'             => sendSseEvent('done', $event->data['fullText']),
        'error'                 => sendSseEvent('error', $event->data['message']),
    };
    // 確認イベントの場合、Generator::send()でユーザーの応答を送信
    if ($event->type === 'confirmation_required') {
        $approved = waitForUserConfirmation(); // アプリケーション固有のロジック
        $gen->send($approved);
    } else {
        $gen->next();
    }
}
```

`AgentEvent` は `JsonSerializable` を実装しており、SSEレスポンスで直接利用できます：

```php
echo "data: " . json_encode($event) . "\n\n";
```

### 推論ブロック（thinking 対応モデル）

拡張思考を行うモデルは、テキストやツール呼び出しと並んで推論ブロックを返し、
同じターンの次のリクエストでそれをそのまま返すことを要求します。ストリーミング
クライアントから次のイベントを流せば、エージェントが assistant メッセージに
保持します。

```php
// StreamingLlmClientInterface の実装内で
yield new StreamEvent(StreamEvent::REASONING_DELTA, ['text' => $delta]);
yield new StreamEvent(StreamEvent::REASONING_SIGNATURE, ['signature' => $signature]);
yield new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP);

// 安全上の理由で秘匿された推論は、テキストではなく暗号化データを持つ
yield new StreamEvent(StreamEvent::REASONING_REDACTED, ['data' => $data]);
yield new StreamEvent(StreamEvent::CONTENT_BLOCK_STOP);
```

`['type' => 'reasoning', 'text' => ..., 'signature' => ...]` と
`['type' => 'redacted_reasoning', 'data' => ...]` という content block として
保持されます。プロバイダによっては推論テキストを返さず署名だけを返しますが、
モデルが必要とするのは署名なので、その場合もブロックは保持されます。

秘匿された推論を別のブロック種別にしているのは意図的です。そのまま返す必要があり、
通常の推論ブロックに畳み込むと、モデルが依存している区別が失われます。

推論は `AgentEvent` として**流しません**。SSE の購読者に意図せず届くことはありません。
これらのイベントを流さないクライアントに影響はありません。

非ストリーミングのクライアントでは、`LlmResponse::$content` にブロックを含めれば
そのまま引き継がれます。`LlmResponse::getText()` は無視します。

## ツール公開の制御

### メソッド単位での除外

```php
use BEAR\ToolUse\Attribute\Exclude;

class User extends ResourceObject
{
    public function onGet(int $id): static { /* 公開される */ }

    #[Exclude]
    public function onDelete(int $id): static { /* 除外 */ }
}
```

### クラス全体を除外

```php
use BEAR\ToolUse\Attribute\Exclude;

#[Exclude]
class InternalResource extends ResourceObject
{
    // このリソースの全メソッドが除外
}
```

### カスタムツール名と説明

```php
use BEAR\ToolUse\Attribute\Tool;

#[Tool(name: 'search_users', description: 'ユーザーを検索')]
public function onGet(string $query): static { /* ... */ }
```

## Human-in-the-Loop 確認

`confirm: true` を指定すると、破壊的なツール呼び出しの実行前にユーザーの確認を求めます。

### 確認が必要なツールの指定

```php
use BEAR\ToolUse\Attribute\Tool;

// クラスレベル - 全メソッドで確認が必要
#[Tool(confirm: true)]
class User extends ResourceObject
{
    public function onGet(int $id): static { /* ... */ }
    public function onDelete(int $id): static { /* ... */ }
}

// メソッドレベル - 特定のメソッドのみ確認が必要
class Article extends ResourceObject
{
    public function onGet(int $id): static { /* ... */ }

    #[Tool(confirm: true)]
    public function onDelete(int $id): static { /* ... */ }
}
```

### 確認ハンドラーの実装

```php
use BEAR\ToolUse\Runtime\ConfirmationHandlerInterface;
use BEAR\ToolUse\Dispatch\ToolCall;

final class CliConfirmationHandler implements ConfirmationHandlerInterface
{
    public function confirm(ToolCall $toolCall, string $llmText): bool
    {
        echo $llmText . "\n実行しますか？ [Y/n]: ";

        $line = fgets(STDIN);

        return $line !== false && trim($line) !== 'n';
    }
}
```

### DIモジュールでのバインド

```php
$this->bind(ConfirmationHandlerInterface::class)->to(CliConfirmationHandler::class);
```

### 動作の仕組み

LLMのテキストレスポンスが確認メッセージとして使われます。テンプレートは不要です。

```text
ユーザー: 「記事123を削除して」
  ↓
LLM: 「記事ID 123「BEAR.Sundayの紹介」を削除します。」
     tool_use: article_delete({id: 123})
  ↓
ConfirmationHandler: 「記事ID 123「BEAR.Sundayの紹介」を削除します。」
                     実行しますか？ [Y/n]:
  ↓
Y → ツール実行
N → "User cancelled this operation." → LLM: 「承知しました。」
```

`ConfirmationHandlerInterface` がバインドされていない場合、確認対象ツールも通常通り実行されます（ブロックなし）。

### ストリーミングエージェントでの確認

`StreamingAgent` は `ConfirmationHandlerInterface` の代わりに yield-based アプローチを使用します。確認が必要なツールに遭遇すると `confirmation_required` イベントを yield し、`Generator::send(bool)` でユーザーの応答を受け取ります。

```text
StreamingAgent が yield: confirmation_required (toolName, input, message)
  ↓
SSE で確認イベントをクライアントに送信 → クライアントがUIを表示
  ↓
クライアントが別のHTTPリクエストで応答
  ↓
サーバーが呼び出し: $generator->send(true)  // false でキャンセル
  ↓
StreamingAgent が再開: ツール実行またはキャンセル
```

`send()` が呼ばれない場合（例: `iterator_to_array()`）、ツールは**デフォルトで拒否**されます（安全なデフォルト）。

## クライアントツール

リソースにディスパッチせず、クライアント側（ブラウザUI・CLI・エッジアプリ等）で実行するツールを公開できます。LLMがクライアントツールを呼ぶと、ランは保留中の呼び出しを消費者に引き渡して終了します。クライアント側で実行し、結果を渡して会話を再開します。

これによりフロントエンドツール呼び出しが実現できます。LLMがUI更新（フォームフィールドへの入力等）を提案し、クライアントがユーザーを介在させて適用し、結果をLLMに報告する形です。

### クライアントツールの登録

クライアントツールはリソースではなく、素の `Tool` 定義です。`addClientTools()` で登録します:

```php
use BEAR\ToolUse\Schema\Tool;

$agent = $agentFactory
    ->addResources(['app://self/article'])
    ->addClientTools([
        new Tool('update_editor_field', 'エディタUIのフィールドを更新する', [
            'type' => 'object',
            'properties' => [
                'field' => ['type' => 'string', 'enum' => ['title', 'description']],
                'value' => ['type' => 'string'],
            ],
            'required' => ['field', 'value'],
        ]),
    ])
    ->create('あなたは編集アシスタントです。');
```

`client: true` フラグは自動的に付与されます。クライアントツールに `confirm: true` は指定できません — ライブラリはクライアントツールの確認を強制しないため、承認が必要な操作はクライアント側で明示的に承認UIを実装してください（「セキュリティ上の考慮事項」参照）。また、ツール名はリソース・クライアントツールを通して一意である必要があります。違反は登録時に例外になります。

### 同期エージェント

```php
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\AgentResponse;

$response = $agent->run('descriptionを改善して');

if ($response->stopReason === AgentResponse::STOP_CLIENT_TOOL_USE) {
    $results = [];
    foreach ($response->clientToolCalls as $call) {
        // $call->id, $call->name, $call->input を使いクライアント側で実行
        $results[] = ToolResult::success($call->id, ['applied' => true]);
    }

    // 保留中の呼び出しごとに1件の結果を渡してループを継続
    $final = $agent->resume($results);
}
```

### ストリーミングエージェント

```php
use BEAR\ToolUse\Dispatch\ToolResult;
use BEAR\ToolUse\Runtime\AgentEvent;

$results = [];
foreach ($agent->runStream($userMessage) as $event) {
    if ($event->type === AgentEvent::CLIENT_TOOL_CALL) {
        // toolName / toolId / input をクライアントに転送（例: SSE）
        $results[] = ToolResult::success($event->data['toolId'], ['applied' => true]);
    }
}

// ストリームは CLIENT_TOOL_CALL イベントの後に終了する。
// 受け取った呼び出しごとに1件の結果を渡して継続:
foreach ($agent->resumeStream($results) as $event) {
    // ...
}
```

### 動作の流れ

```text
LLM: tool_use update_editor_field({field: "description", value: "..."})
  ↓
同一ターンのサーバー側ツールは通常どおりディスパッチ
  ↓
ラン終了: CLIENT_TOOL_CALL イベント（ストリーミング）/ STOP_CLIENT_TOOL_USE（同期）
  ↓
クライアントがツールを実行（UI更新、ユーザーの採用/却下 等）
  ↓
resume() / resumeStream() に ToolResult を渡す
  ↓
LLMがそのターンの全ツール結果を受けて継続
```

### 再開時の検証

`resume()` / `resumeStream()` は、末尾の assistant メッセージに結果待ちのクライアントツール呼び出しがあり、渡された結果IDが **クライアント呼び出し** と各1回ずつ完全一致する場合のみ受理します。不足・余剰・重複したID、`reset()` 後や完了済みランからの再開は `InvalidResumeException` で拒否されます。サーバーツールの結果は再開入力として決して受け付けません — サーバー結果はサーバー側で計算されインスタンス内に保持されるものであり、外部からの供給を要する再開は拒否されます。期待IDは会話そのものから導出するため、HTTPリクエストをまたいで新しいインスタンスに `$agent->messages` を再構築するステートレスな再開はそのまま機能します。サーバー・クライアント混在ターンの場合は、末尾の assistant メッセージをクライアントの `tool_use` ブロックのみで再構築してください。

### セキュリティ上の考慮事項

クライアントツールは「LLMが生成した未信頼の入力」をクライアント側で実行する機能です。公開する際は以下を徹底してください。

- 入力スキーマはLLMへのツール説明であり、検証ではありません。適用前にクライアント側で型・値域・対象IDを必ず再検証してください。
- 汎用的な能力（任意URLへの遷移、任意の `fetch`、HTML挿入、JS実行、クリップボードやDOM全体の読み取り）は公開しないでください。テキストは `textContent` で挿入し、URLはスキーム・ホストの許可リストで扱います。
- HTTPリクエストをまたいで再開する場合、再開エンドポイントを認証済みユーザーと会話セッションに結び付けてください（例: サーバー側に状態を保持した一回限りの不透明な continuation token）。上記の再開時検証は fail-fast の正当性チェックであり、信頼境界ではありません。クライアントから受け取った会話履歴を信頼済みとして扱ってはいけません。ツール呼び出しIDは会話に含まれLLMにもクライアントにも見える公開値です。それ自体を認可トークンとして扱わないでください。
- ツール結果もLLMにとっては未信頼データであり、後続のサーバーツール呼び出しを誘導し得ます。最小の構造化結果に限定し、秘密情報・Cookie・トークン・画面全体の内容は返さないでください。
- 状態変更を伴うUI操作は、変更内容を表示してクライアント側でユーザーの承認を取ってください。クライアントツールで `confirm: true` を拒否しているのはこのためです。確認はサーバーではなくクライアントの責務です。

- クライアントツールはサーバー側で決してディスパッチされません（`Dispatcher` を完全にバイパス）。
- サーバーツールとクライアントツールが同一ターンに混在した場合、サーバー側の結果はエージェントインスタンスに保持され、`resume()` / `resumeStream()` 時に自動でマージされます。
- ステートレスなHTTP構成では、再開前に永続化した会話から `Agent::$messages` を再構築してください。保持中のサーバー結果はインスタンス内にあり再開時に再供給できないため、混在ターンでは末尾の assistant メッセージをクライアントの `tool_use` ブロックのみで再構築します。

## レスポンスフィルタリング

`filter` を使用して、LLMに送信する前にレスポンスボディを削減できます。大量のデータを返すリソースでトークン効率を改善します。

### フィルタの定義

```php
use BEAR\ToolUse\Dispatch\ToolResultFilterInterface;
use Override;

final readonly class SummaryFilter implements ToolResultFilterInterface
{
    #[Override]
    public function __invoke(mixed $body): mixed
    {
        // LLMに必要なフィールドだけを抽出
        return array_map(fn (array $item) => [
            'id' => $item['id'],
            'title' => $item['title'],
        ], $body);
    }
}
```

### リソースへの適用

```php
use BEAR\ToolUse\Attribute\Tool;

// クラスレベル - 全メソッドでフィルタを使用
#[Tool(filter: SummaryFilter::class)]
class Search extends ResourceObject
{
    public function onGet(string $query): static { /* ... */ }
}

// メソッドレベル - 特定のメソッドのみフィルタを使用
class Article extends ResourceObject
{
    #[Tool(filter: SummaryFilter::class)]
    public function onGet(string $query): static { /* ... */ }

    public function onPost(string $title, string $body): static { /* ... */ }
}
```

フィルタは成功レスポンスにのみ適用されます。エラーレスポンス（ステータスコード >= 400）はフィルタされずにそのまま送信されます。

## ツール呼び出しの観測

すべてのツール呼び出しをフックして、監査ログ・メトリクス・レイテンシ計測などを行えます。Observer はディスパッチごとに 1 回だけ呼び出され、成功・ステータスコードエラー・例外・未知ツールのどの経路でも `ToolCall`、`ToolResult`、経過時間（ミリ秒）を受け取ります。

### Observer の実装

```php
use BEAR\ToolUse\Dispatch\ToolCall;
use BEAR\ToolUse\Dispatch\ToolCallObserverInterface;
use BEAR\ToolUse\Dispatch\ToolResult;
use Override;

final readonly class AuditLogObserver implements ToolCallObserverInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function observe(ToolCall $toolCall, ToolResult $result, float $durationMs): void
    {
        $this->logger->info('tool_call', [
            'name' => $toolCall->name,
            'input' => $toolCall->input,
            'isError' => $result->isError,
            'durationMs' => $durationMs,
        ]);
    }
}
```

> **Note:** `$toolCall->input` や `$result->content` には、利用するリソース次第で機密情報（個人情報、認証情報、API トークンなど）が含まれる可能性があります。永続ログ・トレース・外部システムへ送る前に、該当フィールドのサニタイズ／マスキングを行ってください。

### DI モジュールでバインド

```php
$this->bind(ToolCallObserverInterface::class)->to(AuditLogObserver::class);
```

Observer がバインドされていない場合、デフォルトで `NullToolCallObserver`（no-op）が使用されます。

### 設計上の補足

- Observer に渡される `ToolResult` はレスポンスフィルタ適用**後**のもの、つまり LLM が実際に受け取る形です。
- インターフェイスは意図的に最小限です。スレッドID／会話ID／ユーザーIDなどアプリケーション固有のコンテキストは、利用者側のステートフルな Observer 実装で扱うべきです（インターフェイス引数には含めません）。
- `Dispatcher` は分岐に関わらず、ディスパッチごとに必ず 1 回 Observer を呼び出します。
- **キャンセルされたツール呼び出しは Dispatcher を経由しません。** 確認拒否は `Agent` / `StreamingAgent` 層（`ConfirmationHandlerInterface` または `Generator::send(false)`）で処理され、`Dispatcher::dispatch()` を呼ばずに `ToolResult::cancelled()` を返します。そのため Observer はキャンセル時には**呼び出されません**。
- **`observe()` から throw された例外は `Dispatcher::dispatch()` の外へ伝播します。** 観測の失敗でツール呼び出しを止めたくない場合、Observer 実装側で I/O を try/catch するなどして自身でエラーハンドリングする責任があります。

## JSON Schemaの統合

BEAR.ResourceのJSON Schemaを使用してパラメータ定義を強化できます。

### 1. JsonSchemaModuleと共にインストール

```php
use BEAR\Resource\Module\JsonSchemaModule;
use BEAR\ToolUse\Module\ToolUseModule;

$this->install(
    new JsonSchemaModule(
        $this->appMeta->appDir . '/var/json_schema',
        $this->appMeta->appDir . '/var/json_validate',
    ),
);
$this->install(new ToolUseModule());
```

### 2. JSON Schemaの定義

```json
// /path/to/validate/user.json
{
    "type": "object",
    "properties": {
        "id": {
            "type": "integer",
            "description": "ユーザーID",
            "minimum": 1
        },
        "status": {
            "type": "string",
            "description": "ユーザーステータス",
            "enum": ["active", "inactive", "pending"]
        }
    }
}
```

### 3. リソースへの適用

```php
use BEAR\Resource\Annotation\JsonSchema;

class User extends ResourceObject
{
    #[JsonSchema(params: 'user.json')]
    public function onGet(int $id, string $status = 'active'): static
    {
        // JSON Schemaによるランタイムバリデーションとツール定義の両方に使用
    }
}
```

JSON Schemaから以下のプロパティが抽出されます：
- `description` - パラメータの説明
- `enum` - 許可される値
- `format` - 値のフォーマット（email、uri、date等）
- `minimum` / `maximum` - 数値の範囲
- `minLength` / `maxLength` - 文字列の長さ
- `pattern` - 正規表現パターン

## ALPSによるセマンティック記述

ALPSプロファイルを使用してパラメータの説明を補強できます。

```php
use BEAR\ToolUse\Schema\AlpsSemanticDictionary;
use BEAR\ToolUse\Schema\SchemaConverter;

$dictionary = new AlpsSemanticDictionary('/path/to/profile.json');
$converter = new SchemaConverter($dictionary);
```

**JSON**と**XML**の両形式の ALPS プロファイルをサポートしています（ファイル拡張子で自動判別）。`semantic`記述子の`title`または`doc`がパラメータの説明として使用されます。同一プロファイル内の`href="#id"`参照は自動解決され、`safe` / `unsafe` / `idempotent`（トランジション）記述子は除外されます。

## パラメータ説明の優先順位

複数のソースが説明を提供する場合、以下の順序で解決されます：

1. **JSON Schema** - スキーマファイルの`description`プロパティ（+ `enum`、`format`、`min/max`等の制約）
2. **PHPDoc** - `@param`タグの説明（メソッド固有）
3. **ALPS** - セマンティック記述子の`title`または`doc.value`（アプリケーション全体のフォールバック）

## アーキテクチャ

```
┌─────────────────────────────────────────────────────────────┐
│                        Agent                                │
│  ┌─────────────┐    ┌──────────────┐    ┌───────────────┐   │
│  │ LlmClient   │───▶│   Message    │───▶│  Dispatcher   │   │
│  │ (Interface) │    │   Loop       │    │               │   │
│  └─────────────┘    └──────────────┘    └───────┬───────┘   │
│                                                 │           │
│  ┌─────────────────────────────────────────────┐│           │
│  │              ToolRegistry                   ││           │
│  │  tool_name → {resourceUri, method}          ││           │
│  └─────────────────────────────────────────────┘│           │
│                                                 ▼           │
│                                         ┌───────────────┐   │
│                                         │ BEAR.Resource │   │
│                                         └───────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## エラーフィードバックループ

ツール実行が失敗した場合、エラーは自動的にLLMにフィードバックされ、LLMはパラメータを修正して再試行したり、別のアクションを取ることができます。例外ベースのエラーと非2xxステータスコードの両方に対応しています。

```
ユーザー: 「ユーザー999を削除して」
  ↓
LLM: tool_use → user_delete(id: 999)
  ↓
Dispatcher: 404 Not Found → ToolResult(isError: true)
  ↓
LLMがエラーを受信し、次のアクションを決定
  ↓
LLM: 「ユーザー999は見つかりませんでした。」
```

Dispatcherが検出するエラー:

| エラー種別 | 例 | エラーメッセージ形式 |
|-----------|---|-------------------|
| 例外 | `ResourceNotFoundException` | `BEAR\Resource\Exception\ResourceNotFoundException: /user?id=999` |
| ステータスコード | `$this->code = 400` | `400: {"error":"Validation failed"}` |
| 未知のツール | 未登録のツール | `Unknown tool: foo_bar` |

## API

### インターフェイス

| インターフェイス | 説明 |
|-----------------|------|
| `LlmClientInterface` | LLM APIクライアント（ユーザー実装） |
| `StreamingLlmClientInterface` | ストリーミングLLM APIクライアント（ユーザー実装） |
| `DispatcherInterface` | ツール呼び出しのディスパッチ |
| `ToolRegistryInterface` | ツール名とリソースのマッピング |
| `SchemaConverterInterface` | リソースからツール定義への変換 |
| `ToolCollectorInterface` | ツールの収集と登録 |
| `AgentInterface` | エージェントランタイム |
| `StreamingAgentInterface` | ストリーミングエージェントランタイム |
| `ToolResultFilterInterface` | LLM送信前のレスポンスフィルタ |
| `ConfirmationHandlerInterface` | 破壊的ツールのユーザー確認 |
| `ToolCallObserverInterface` | ディスパッチごとに 1 回呼ばれるフック（監査・メトリクス・レイテンシ計測） |

### 主要クラス

| クラス | 説明 |
|-------|------|
| `Agent` | LLMとの会話ループを管理 |
| `StreamingAgent` | `AgentEvent`をyieldするストリーミング会話ループ |
| `AgentFactory` | エージェントのビルダー（同期・ストリーミング） |
| `AgentResponse` | エージェント実行結果（同期） |
| `AgentEvent` | ストリーミングイベント（`JsonSerializable`） |
| `StreamEvent` | 低レベルLLMストリームイベント |
| `Tool` | ツール定義（JSON Schema） |
| `ToolCall` | LLMからのツール呼び出し |
| `ToolResult` | ツール実行結果 |
| `Message` | 会話メッセージ |
| `LlmResponse` | LLMからのレスポンス |

## 開発

```bash
# 開発ツールのセットアップ
composer setup

# テスト実行
composer test

# コーディング規約チェック
composer cs

# 静的解析
composer sa

# 全チェック実行
composer tests
```

## ライセンス

MIT License
