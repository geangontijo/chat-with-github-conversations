<?php

namespace App\Commands;

use App\Entity\PlaceEntity;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Illuminate\Console\Command;
use LLPhant\Chat\AnthropicChat;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\VectorStores\Doctrine\DoctrineEmbeddingEntityBase;
use LLPhant\Embeddings\VectorStores\Doctrine\DoctrineVectorStore;
use LLPhant\Query\SemanticSearch\QuestionAnswering;

class Chat extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat {question}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Chat with comments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $embeddingGenerator = new OpenAI3SmallEmbeddingGenerator();
        $ormConfig = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Entity'], true);
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
        $chat = new AnthropicChat();

        $docs = $vectorStore->similaritySearch(
            $embeddingGenerator->embedText($this->argument('question')),
        );
        dd(
            array_map(
                fn(DoctrineEmbeddingEntityBase $doc) => [
                    'id' => $doc->getId(),
                    'content' => $doc->content,
                    'sourceType' => $doc->sourceType,
                    'sourceName' => $doc->sourceName,
                ],
                $docs
            )
        );
    }
}
