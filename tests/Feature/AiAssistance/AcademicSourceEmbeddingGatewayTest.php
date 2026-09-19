<?php

namespace Tests\Feature\AiAssistance;

use App\Modules\AiAssistance\Domain\Exceptions\AiContractException;
use App\Modules\AiAssistance\Infrastructure\Gateways\OpenAiAcademicSourceEmbeddingGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AcademicSourceEmbeddingGatewayTest extends TestCase
{
    public function test_openai_embeddings_are_sent_in_batches_and_returned_in_input_order(): void
    {
        config([
            'ai.embeddings.base_url' => 'https://api.openai.com/v1',
            'ai.embeddings.model' => 'text-embedding-3-small',
            'ai.embeddings.api_key' => 'secret-test-key',
            'ai.embeddings.dimensions' => 1536,
        ]);
        Http::fake(function (Request $request) {
            return Http::response([
                'data' => [
                    ['index' => 1, 'embedding' => array_fill(0, 1536, 0.25)],
                    ['index' => 0, 'embedding' => array_fill(0, 1536, 0.125)],
                ],
            ]);
        });

        $vectors = (new OpenAiAcademicSourceEmbeddingGateway)->embed(['primero', 'segundo']);

        $this->assertCount(2, $vectors);
        $this->assertSame(0.125, $vectors[0][0]);
        $this->assertSame(0.25, $vectors[1][0]);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.openai.com/v1/embeddings'
                && $request->hasHeader('Authorization', 'Bearer secret-test-key')
                && $data['model'] === 'text-embedding-3-small'
                && $data['input'] === ['primero', 'segundo']
                && $data['dimensions'] === 1536
                && $data['encoding_format'] === 'float';
        });
    }

    public function test_embedding_contract_rejects_an_unexpected_dimension(): void
    {
        config([
            'ai.embeddings.base_url' => 'https://api.openai.com/v1',
            'ai.embeddings.model' => 'text-embedding-3-small',
            'ai.embeddings.api_key' => 'secret-test-key',
            'ai.embeddings.dimensions' => 1536,
        ]);
        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]],
            ]),
        ]);

        $this->expectException(AiContractException::class);

        (new OpenAiAcademicSourceEmbeddingGateway)->embed(['texto']);
    }
}
