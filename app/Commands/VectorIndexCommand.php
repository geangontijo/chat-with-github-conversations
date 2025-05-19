<?php

namespace App\Commands;

use App\Entity\PlaceEntity;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use LLPhant\Chat\AnthropicChat;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\VectorStores\Doctrine\DoctrineVectorStore;

use function Termwind\render;

class VectorIndexCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'index-vector {repository}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Index a vector';

    /**
     * Execute the console command.
     */
    public function handle(ExceptionHandler $exceptionHandler): void
    {
        $httpClient = Http::withHeader('Authorization', 'Bearer ' . env('GITHUB_TOKEN'))->baseUrl(
            "https://api.github.com/repos/{$this->argument('repository')}"
        );
        $chat = new AnthropicChat();
        $ormConfig = ORMSetup::createAttributeMetadataConfiguration([App::path('Entity')], true);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'pgvector',
            'dbname' => 'postgres',
            'user' => 'postgres',
            'password' => 'postgres',
            'port' => 5432,
        ]);
        $entityManager = new EntityManager($connection, $ormConfig);
        $vectorStore = new DoctrineVectorStore($entityManager, PlaceEntity::class);
        $embeddingGenerator = new OpenAI3SmallEmbeddingGenerator();

        $page = 1;
        while (true) {
            $response = $httpClient
                ->get('pulls/comments', [
                    'direction' => 'desc',
                    'sort' => 'created_at',
                    'per_page' => 100,
                    'page' => $page,
                ])
                ->throw()
                ->json();
            if (empty($response)) {
                break;
            }
            $page++;

            foreach ($response as $comment) {
                $result = $entityManager->getRepository(PlaceEntity::class)->findOneBy([
                    'sourceName' => $comment['html_url'],
                ]);

                if (!empty($result)) {
                    continue;
                }
                // identify if the comment is already indexed

                $this->info(
                    'Requesting comment: ' .
                        $comment['path'] .
                        ' ref: ' .
                        $comment['original_commit_id'] .
                        ' url: ' .
                        $comment['html_url']
                );
                try {
                    $codeResponse = $httpClient
                        ->get("contents/{$comment['path']}", [
                            'ref' => $comment['original_commit_id'],
                        ])
                        ->throw()
                        ->json();

                    $fileContents = base64_decode($codeResponse['content']);
                    $conversationCodeLinesArray = explode("\n", $fileContents);
                    $startLine = $comment['start_line'] ?? $comment['original_line'];
                    $endLine = $comment['original_line'];
                    $conversationCodeFilteredLinesArray = [];

                    for ($i = 0; $i <= $endLine - $startLine; $i++) {
                        $currentLineIndex = $startLine + $i - 1;

                        $conversationCodeFilteredLinesArray[] = $conversationCodeLinesArray[$currentLineIndex];
                    }
                    $code = implode("\n", $conversationCodeFilteredLinesArray);

                    $chat->setSystemMessage(
                        <<<'EOF'
You are a senior programmer with the ability to understand the technical context of code and conversation. Your responsibility is to objectively identify what changes are requested in the submitted conversation, along with the reason for the change.

IMPORTANT:
- It is FORBIDDEN to return a requested change with text that contains any ambiguity regarding the technical context for the reader.
- Whenever referring to any code object (methods, functions, properties, etc...), also display the name of the class.
- Give as much information as possible about each requested change.
- Each requested change MUST be readable without needing to understand the context of the conversation and the code.
- Use technical language in Portuguese.
EOF
                    );

                    $classification = $chat->generateText(
                        <<<EOF
                ### Code without changes:

```
$code
```

### Changes requested:

{$comment['body']}
EOF
                    );

                    $comment['classification'] = $classification;

                    $document = new PlaceEntity();
                    $document->content = $comment['classification'];
                    $document->formattedContent = $comment['classification'];
                    $document->embedding = $embeddingGenerator->embedText($comment['classification']);
                    $document->sourceType = 'md';
                    $document->sourceName = $comment['html_url'];
                    $document->hash = md5($comment['classification']);
                    $document->chunkNumber = 0;

                    $vectorStore->addDocument($document);
                } catch (\Throwable $e) {
                    $this->error(
                        'Error requesting file: ' . $comment['path'] . ' ref: ' . $comment['original_commit_id']
                    );
                    $exceptionHandler->renderForConsole(
                        $this->output,
                        $e
                    );
                    continue;
                }
            }
        }

        $this->info('Finished indexing vector');
    }
}
