<?php

namespace App\Services\Eventos\Ocr;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;

// One page of a sign-in sheet read by Claude through the official PHP SDK (anthropic-ai/sdk): the photo (or the PDF,
// cached so the next pages of the same PDF cost little) plus the instruction, answered as JSON that follows
// PlanillaPrompt::schema() (structured outputs). Server-side fallback "default": if the model declines for policy
// reasons the API retries on its default fallback model inside the same call (the answering model is recorded).
// Never logs the answer nor the image: only the failure class and the HTTP status.
class AnthropicOcrClient implements OcrClient
{
    // $transporter: a PSR-18 client for tests (a mocked HTTP answer); null = the SDK's default HTTP client.
    public function __construct(private ?ClientInterface $transporter = null) {}

    public function read(string $content, string $mime, int $page, string $model): OcrResult
    {
        if (! OcrSettings::hasKey()) {
            throw new OcrException(OcrSettings::MISSING_KEY);
        }

        $pdf = $mime === 'application/pdf';
        $source = $pdf
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($content)], 'cacheControl' => ['type' => 'ephemeral']]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($content)]];

        $client = new Client(
            apiKey: (string) config('eventos.ocr.api_key'),
            // No automatic retries: each call is paid and the screen offers "Intentar de nuevo"; the per-page lock lasts
            // one timeout (OcrImportService::lockSeconds()).
            requestOptions: array_filter(['timeout' => (float) config('eventos.ocr.timeout'), 'maxRetries' => 0, 'transporter' => $this->transporter], fn ($v) => $v !== null),
        );

        try {
            $message = $client->beta->messages->create(
                maxTokens: (int) config('eventos.ocr.max_tokens'),
                model: $model,
                system: PlanillaPrompt::SYSTEM,
                messages: [['role' => 'user', 'content' => [$source, ['type' => 'text', 'text' => PlanillaPrompt::instruction($page, $pdf)]]]],
                outputConfig: [
                    'effort' => (string) config('eventos.ocr.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => PlanillaPrompt::schema()],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw $this->fail('La clave del proveedor de IA no es válida o no tiene permiso. Avise al administrador de la plataforma.', false, $e);
        } catch (NotFoundException|UnprocessableEntityException $e) {
            throw $this->fail('La configuración del modelo de IA no es válida. Avise al administrador de la plataforma.', false, $e);
        } catch (RateLimitException $e) {
            throw $this->fail('El proveedor de IA está ocupado. Espere un minuto y vuelva a intentar con esta página.', true, $e);
        } catch (BadRequestException $e) {
            throw $this->fail('El proveedor de IA no aceptó este archivo. Tome la foto de nuevo (JPG o PNG) e intente otra vez.', false, $e);
        } catch (APITimeoutException $e) {
            throw $this->fail('La IA tardó demasiado en leer esta página. Vuelva a intentar con esta página.', true, $e);
        } catch (APIConnectionException $e) {
            throw $this->fail('No hubo conexión con el proveedor de IA. Vuelva a intentar en un momento.', true, $e);
        } catch (APIStatusException $e) {
            throw $this->fail('El proveedor de IA tuvo un problema al leer esta página. Vuelva a intentar en un momento.', true, $e);
        }

        $usage = $message->usage;
        // A refused, cut or unreadable answer was still charged: its tokens travel with the exception.
        $billed = new OcrResult(
            [],
            $message->model,
            (int) $usage->inputTokens,
            (int) $usage->outputTokens,
            (int) ($usage->cacheCreationInputTokens ?? 0),
            (int) ($usage->cacheReadInputTokens ?? 0),
        );
        if ($message->stopReason === 'refusal') {
            throw new OcrException('La IA no leyó esta página. Regístrela a mano en el modo operador.', false, null, $billed);
        }
        if ($message->stopReason === 'max_tokens') {
            throw new OcrException('La respuesta de la IA quedó incompleta para esta página. Vuelva a intentar.', true, null, $billed);
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new OcrException('La respuesta de la IA no se pudo leer. Vuelva a intentar con esta página.', true, null, $billed);
        }

        return new OcrResult($data, $billed->model, $billed->inputTokens, $billed->outputTokens, $billed->cacheWriteTokens, $billed->cacheReadTokens);
    }

    private function fail(string $message, bool $retryable, \Throwable $e): OcrException
    {
        Log::warning('Eventos OCR: fallo al llamar al proveedor de IA', [
            'error' => $e::class,
            'status' => $e instanceof APIStatusException ? $e->status : null,
        ]);

        return new OcrException($message, $retryable, $e);
    }
}
