<?php

namespace IdeiaCv\BugCatcher\Console;

use IdeiaCv\BugCatcher\Client;
use IdeiaCv\BugCatcher\Transport\HttpTransport;
use Illuminate\Console\Command;
use RuntimeException;

class TestCommand extends Command
{
    protected $signature = 'ideiabugcatcher:test';

    protected $description = 'Envia um erro de teste para o IdeiaBugCatcher e mostra a resposta';

    public function handle(Client $client): int
    {
        if (empty(config('ideiabugcatcher.api_key'))) {
            $this->error('Falta IDEIABUGCATCHER_API_KEY no .env (crie a chave em Projetos → Chaves).');

            return self::FAILURE;
        }

        if (! $client->shouldNotify()) {
            $this->error('A fase "'.$client->getReleaseStage().'" não está em IDEIABUGCATCHER_NOTIFY_RELEASE_STAGES.');

            return self::FAILURE;
        }

        $transport = $client->transport();
        $this->line('A enviar para '.($transport instanceof HttpTransport ? $transport->url() : get_class($transport)).' …');

        $client->sendImmediately();
        $client->notifyException(
            new RuntimeException('Erro de teste do IdeiaBugCatcher (php artisan ideiabugcatcher:test)'),
            fn ($report) => $report->setSeverity('info')->setMetaData(['teste' => ['enviado_por' => 'ideiabugcatcher:test']]),
        );

        $status = $client->lastStatus();
        if ($status === 202) {
            $this->info('Recebido (202). O erro aparece no projeto dentro de segundos.');

            return self::SUCCESS;
        }

        $reason = match ($status) {
            0 => 'Servidor inacessível',
            401 => 'Chave inválida, revogada ou projeto inativo (401)',
            413 => 'Pedido demasiado grande (413)',
            429 => 'Limite de pedidos por minuto atingido (429)',
            default => 'Resposta inesperada ('.$status.')',
        };
        $detail = $transport instanceof HttpTransport && $transport->lastError ? ': '.$transport->lastError : '';
        $this->error($reason.$detail);

        return self::FAILURE;
    }
}
