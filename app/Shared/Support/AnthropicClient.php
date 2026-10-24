<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Modules\Auth\Models\GlobalSetting;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the Anthropic Messages API.
 * Uses Laravel's HTTP client (Guzzle under the hood).
 */
final class AnthropicClient
{
    private const BASE_URL        = 'https://api.anthropic.com/v1';
    private const API_VERSION     = '2023-06-01';
    private const DEFAULT_TIMEOUT = 90;
    private const MAX_TOKENS      = 1024;

    /** @var array{input_tokens: int, output_tokens: int}|null Uso de la última llamada real a la API. */
    private ?array $lastUsage = null;

    public static function invalidateKeyCache(): void
    {
        Cache::forget('global:anthropic_api_key');
    }

    /**
     * Tokens de la última llamada completada — null si todavía no se hizo ninguna llamada en
     * esta instancia. Usado por BaseAiJob para persistir consumo/costo en ai_analyses.
     *
     * @return array{input_tokens: int, output_tokens: int}|null
     */
    public function getLastUsage(): ?array
    {
        return $this->lastUsage;
    }

    /** @param array<string, mixed> $body */
    private function captureUsage(array $body): void
    {
        $usage = $body['usage'] ?? null;

        if (! is_array($usage)) {
            return;
        }

        $this->lastUsage = [
            'input_tokens'  => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
        ];
    }

    private function resolveApiKey(): string
    {
        return Cache::remember('global:anthropic_api_key', 300, function (): string {
            try {
                $setting = GlobalSetting::find('anthropic_api_key');
                if ($setting !== null && $setting->value !== '') {
                    return $setting->value;
                }
            } catch (\Throwable) {
                // Table may not exist yet during migrations — fall through to env
            }
            return (string) config('services.anthropic.api_key', '');
        });
    }

    public function complete(
        string $model,
        string $systemPrompt,
        string $userMessage,
        int    $maxTokens = self::MAX_TOKENS,
        int    $timeout = self::DEFAULT_TIMEOUT,
    ): string {
        $apiKey = $this->resolveApiKey();

        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $response = Http::timeout($timeout)
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ])
            ->post(self::BASE_URL . '/messages', [
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new AnthropicApiException($response->status(), "Anthropic API error ({$response->status()}): {$error}");
        }

        /** @var array{content: list<array{type: string, text: string}>} $body */
        $body = $response->json();
        $this->captureUsage($body);

        return $body['content'][0]['text'] ?? '';
    }

    /**
     * Como complete(), pero adjunta un documento (PDF o imagen) como content block y fuerza
     * tool_choice a una tool específica — usado para extracción estructurada desde binarios
     * (ver ADR-SCRUM211-confirmacion-proveedor-ocr-ia.md). Devuelve el `input` ya parseado del
     * bloque tool_use; nunca texto libre, porque tool_choice forzado no le deja otra salida al modelo.
     *
     * @param array<string, mixed> $tool Definición completa de la tool (name, description, input_schema)
     * @return array<string, mixed>
     */
    public function completeWithDocumentTool(
        string $model,
        string $systemPrompt,
        string $taskInstruction,
        string $documentBase64,
        string $documentMediaType,
        array  $tool,
        int    $maxTokens = self::MAX_TOKENS,
    ): array {
        $apiKey = $this->resolveApiKey();

        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $toolName = $tool['name'] ?? null;
        if (! is_string($toolName) || $toolName === '') {
            throw new \InvalidArgumentException('Tool definition must include a name.');
        }

        // La API distingue el tipo de content block por familia de media_type: "document" es
        // específico de PDF, las imágenes (png/jpeg/webp/gif) van con "image" — mandar un PNG
        // como "document" lo rechaza la API. SUPPORTED_MEDIA_TYPES en el caller incluye ambas
        // familias, así que esto no es un caso hipotético.
        $blockType = str_starts_with($documentMediaType, 'image/') ? 'image' : 'document';

        $response = Http::timeout(self::DEFAULT_TIMEOUT)
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ])
            ->post(self::BASE_URL . '/messages', [
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'system'     => $systemPrompt,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => $blockType,
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $documentMediaType,
                                'data'       => $documentBase64,
                            ],
                        ],
                        ['type' => 'text', 'text' => $taskInstruction],
                    ],
                ]],
                'tools'       => [$tool],
                'tool_choice' => ['type' => 'tool', 'name' => $toolName],
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new AnthropicApiException($response->status(), "Anthropic API error ({$response->status()}): {$error}");
        }

        /** @var array{content: list<array<string, mixed>>} $body */
        $body = $response->json();
        $this->captureUsage($body);

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                /** @var array<string, mixed> $input */
                $input = $block['input'] ?? [];

                return $input;
            }
        }

        throw new \RuntimeException('Anthropic response did not include the expected tool_use block.');
    }

    /**
     * Como completeWithDocumentTool(), pero sin content block binario — para cuando el contenido
     * ya se extrajo a texto plano (ver ADR-SCRUM224: facturas Excel/CSV, donde no existe un
     * content block de documento válido para la API, a diferencia de PDF/imagen). Mismo mecanismo
     * de tool_choice forzado, mismo parseo de la respuesta — es completeWithDocumentTool() sin el
     * primer content block.
     *
     * @param array<string, mixed> $tool Definición completa de la tool (name, description, input_schema)
     * @return array<string, mixed>
     */
    public function completeWithTool(
        string $model,
        string $systemPrompt,
        string $taskInstruction,
        array  $tool,
        int    $maxTokens = self::MAX_TOKENS,
    ): array {
        $apiKey = $this->resolveApiKey();

        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $toolName = $tool['name'] ?? null;
        if (! is_string($toolName) || $toolName === '') {
            throw new \InvalidArgumentException('Tool definition must include a name.');
        }

        $response = Http::timeout(self::DEFAULT_TIMEOUT)
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ])
            ->post(self::BASE_URL . '/messages', [
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'system'     => $systemPrompt,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => $taskInstruction,
                ]],
                'tools'       => [$tool],
                'tool_choice' => ['type' => 'tool', 'name' => $toolName],
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new AnthropicApiException($response->status(), "Anthropic API error ({$response->status()}): {$error}");
        }

        /** @var array{content: list<array<string, mixed>>} $body */
        $body = $response->json();
        $this->captureUsage($body);

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                /** @var array<string, mixed> $input */
                $input = $block['input'] ?? [];

                return $input;
            }
        }

        throw new \RuntimeException('Anthropic response did not include the expected tool_use block.');
    }

    /**
     * Como completeWithDocumentTool(), pero para varios documentos/imágenes en una sola llamada
     * (ver ADR-SCRUM245: comparación de una foto/ficha contra hasta 25 candidatos del catálogo).
     * Cada documento va precedido de un content block de texto con su label (ej. "Candidato #14
     * (catalog_product_id=203):"), para que el modelo pueda referenciar el id correcto en el
     * ranking devuelto — nunca se infiere el id por posición.
     *
     * @param list<array{base64: string, mediaType: string, label: string}> $documents
     * @param array<string, mixed> $tool Definición completa de la tool (name, description, input_schema)
     * @return array<string, mixed>
     */
    public function completeWithDocumentsTool(
        string $model,
        string $systemPrompt,
        string $taskInstruction,
        array  $documents,
        array  $tool,
        int    $maxTokens = self::MAX_TOKENS,
    ): array {
        $apiKey = $this->resolveApiKey();

        if ($apiKey === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        if ($documents === []) {
            throw new \InvalidArgumentException('completeWithDocumentsTool() requires at least one document.');
        }

        $toolName = $tool['name'] ?? null;
        if (! is_string($toolName) || $toolName === '') {
            throw new \InvalidArgumentException('Tool definition must include a name.');
        }

        $content = [];
        foreach ($documents as $document) {
            $blockType = str_starts_with($document['mediaType'], 'image/') ? 'image' : 'document';

            $content[] = ['type' => 'text', 'text' => $document['label']];
            $content[] = [
                'type'   => $blockType,
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $document['mediaType'],
                    'data'       => $document['base64'],
                ],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $taskInstruction];

        $response = Http::timeout(self::DEFAULT_TIMEOUT)
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ])
            ->post(self::BASE_URL . '/messages', [
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'system'     => $systemPrompt,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => $content,
                ]],
                'tools'       => [$tool],
                'tool_choice' => ['type' => 'tool', 'name' => $toolName],
            ]);

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new AnthropicApiException($response->status(), "Anthropic API error ({$response->status()}): {$error}");
        }

        /** @var array{content: list<array<string, mixed>>} $body */
        $body = $response->json();
        $this->captureUsage($body);

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                /** @var array<string, mixed> $input */
                $input = $block['input'] ?? [];

                return $input;
            }
        }

        throw new \RuntimeException('Anthropic response did not include the expected tool_use block.');
    }
}
