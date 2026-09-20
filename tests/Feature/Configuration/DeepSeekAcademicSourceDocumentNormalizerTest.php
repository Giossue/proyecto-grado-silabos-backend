<?php

namespace Tests\Feature\Configuration;

use App\Modules\Configuration\Domain\Data\AcademicSourceOcrPage;
use App\Modules\Configuration\Domain\Data\AcademicSourceOcrResult;
use App\Modules\Configuration\Infrastructure\Gateways\DeepSeekAcademicSourceDocumentNormalizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DeepSeekAcademicSourceDocumentNormalizerTest extends TestCase
{
    public function test_normalizes_text_without_altering_protected_tables_and_images(): void
    {
        config([
            'ai.source_normalization.base_url' => 'https://api.deepseek.com',
            'ai.source_normalization.model' => 'deepseek-flash',
            'ai.source_normalization.api_key' => 'secret-test-key',
            'ai.source_normalization.batch_input_characters' => 100000,
        ]);
        $normalizedResponse = [
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'pages' => [[
                            'index' => 0,
                            'markdown' => "# Título ordenado\n\nTexto unido.\n\n[[[SOURCE_BLOCK_0]]]\n\n[[[SOURCE_BLOCK_1]]]\n",
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]],
        ];
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::response($normalizedResponse, 200)]);

        $result = (new DeepSeekAcademicSourceDocumentNormalizer)->normalize(new AcademicSourceOcrResult(
            model: 'paddleocr-vl-1.6',
            pages: [new AcademicSourceOcrPage(
                index: 0,
                markdown: "# Título\nTexto\n<table><tr><td rowspan=\"2\">A</td></tr></table>\n![Figura](figure.png)",
                images: ['figure.png' => 'data:image/png;base64,iVBORw0KGgo='],
            )],
            usage: ['provider' => 'openparser'],
            requestId: 'opj_1',
        ));

        $this->assertSame('# Título ordenado', strtok($result->pages[0]->markdown, "\n"));
        $this->assertStringContainsString('<table><tr><td rowspan="2">A</td></tr></table>', $result->pages[0]->markdown);
        $this->assertStringContainsString('![Figura](figure.png)', $result->pages[0]->markdown);
        $this->assertSame('deepseek-flash', $result->usage['normalization']['model']);
        $this->assertSame(1, $result->usage['normalization']['batch_count']);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $content = data_get($data, 'messages.1.content');

            return $request->url() === 'https://api.deepseek.com/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer secret-test-key')
                && $data['model'] === 'deepseek-flash'
                && data_get($data, 'response_format.type') === 'json_object'
                && data_get($data, 'thinking.type') === 'disabled'
                && is_array($content)
                && collect($content)->contains(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'image_url');
        });
    }

    public function test_processes_pages_in_batches(): void
    {
        config([
            'ai.source_normalization.base_url' => 'https://api.deepseek.com',
            'ai.source_normalization.model' => 'deepseek-flash',
            'ai.source_normalization.api_key' => 'secret-test-key',
            'ai.source_normalization.batch_input_characters' => 1000,
        ]);
        Http::fake(function (Request $request) {
            $text = (string) data_get($request->data(), 'messages.1.content.0.text');
            preg_match('/"index":(\d+)/', $text, $match);
            $index = (int) ($match[1] ?? 0);

            $normalizedResponse = [
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'pages' => [['index' => $index, 'markdown' => "Página {$index} normalizada"]],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ];

            return Http::response($normalizedResponse, 200);
        });

        $result = (new DeepSeekAcademicSourceDocumentNormalizer)->normalize(new AcademicSourceOcrResult(
            model: 'paddleocr-vl-1.6',
            pages: [
                new AcademicSourceOcrPage(index: 0, markdown: str_repeat('Primera página. ', 100)),
                new AcademicSourceOcrPage(index: 1, markdown: str_repeat('Segunda página. ', 100)),
            ],
            usage: [],
            requestId: 'opj_2',
        ));

        $this->assertSame(['Página 0 normalizada', 'Página 1 normalizada'], array_map(
            static fn (AcademicSourceOcrPage $page): string => $page->markdown,
            $result->pages,
        ));
        $this->assertSame(2, $result->usage['normalization']['batch_count']);
        Http::assertSentCount(2);
    }
}
