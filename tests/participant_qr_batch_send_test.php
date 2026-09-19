<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

/**
 * Testy wysylki paczkowej kodow QR jada przez prawdziwy HTTP do lokalnego stubu
 * central-mailera (`php -S`). Dzieki temu sprawdzamy to, co faktycznie poszlo na produkcje:
 * podzial na paczki, naglowek Idempotency-Key i zachowanie przy awarii pojedynczej paczki.
 */
final class CentralMailerStub
{
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    public readonly string $logPath;
    private readonly string $failPath;
    private readonly string $serverLogPath;
    public readonly int $port;
    private static ?self $shared = null;

    /** Jeden serwer na caly plik testowy - start `php -S` kosztuje wiecej niz same testy. */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public static function stopShared(): void
    {
        self::$shared?->stop();
        self::$shared = null;
    }

    private function __construct()
    {
        $suffix = bin2hex(random_bytes(6));
        $this->logPath = sys_get_temp_dir() . '/central-mailer-stub-' . $suffix . '.jsonl';
        $this->failPath = sys_get_temp_dir() . '/central-mailer-stub-' . $suffix . '.fail';
        // php -S loguje kazde zadanie na stderr. Nieczytany pipe zapelnia sie i blokuje
        // serwer w polowie testu, wiec oba strumienie ida do pliku.
        $this->serverLogPath = sys_get_temp_dir() . '/central-mailer-stub-' . $suffix . '.log';
        file_put_contents($this->logPath, '');
        file_put_contents($this->failPath, '');
        $this->port = self::freePort();

        $router = __DIR__ . '/support/central_mailer_stub_server.php';
        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $this->port,
            escapeshellarg($router)
        );

        $this->process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->serverLogPath, 'a'],
                2 => ['file', $this->serverLogPath, 'a'],
            ],
            $this->pipes,
            __DIR__,
            [
                'STUB_LOG' => $this->logPath,
                'STUB_FAIL_FILE' => $this->failPath,
                'PATH' => (string)getenv('PATH'),
                'SystemRoot' => (string)getenv('SystemRoot'),
            ],
            // Na Windows bez tego proc_open startuje cmd.exe, a proc_terminate zabija tylko jego:
            // osierocony `php -S` trzyma potem stdout i `composer test` wisi w nieskonczonosc.
            ['bypass_shell' => true]
        );

        if (!is_resource($this->process)) {
            fail('Nie udalo sie uruchomic stubu central-mailera');
        }

        $this->waitUntilReady();
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** Czysci log zadan i ustawia, ktore paczki maja zostac odrzucone bledem 500. */
    public function reset(string $failChunks = ''): void
    {
        file_put_contents($this->logPath, '');
        file_put_contents($this->failPath, $failChunks);
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string)file_get_contents($this->logPath)), static fn (string $l): bool => trim($l) !== '');
        $requests = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $requests[] = $decoded;
            }
        }

        // php -S obsluguje zadania sekwencyjnie, a wpisy dopisujemy z LOCK_EX, wiec
        // kolejnosc w pliku to faktyczna kolejnosc zadan.
        return $requests;
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->logPath);
        @unlink($this->failPath);
        @unlink($this->serverLogPath);
    }

    private function waitUntilReady(): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }

        $this->stop();
        fail('Stub central-mailera nie wystartowal na porcie ' . $this->port);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            fail('Nie udalo sie zarezerwowac portu dla stubu: ' . $errstr);
        }
        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr($name, strrpos($name, ':') + 1);
    }
}

register_shutdown_function(static function (): void {
    CentralMailerStub::stopShared();
});

function qrBatchMessage(int $participantId, string $email): array
{
    return [
        'participantId' => $participantId,
        'recipientEmail' => $email,
        'recipientName' => 'Uczestnik ' . $participantId,
        'eventName' => 'Bieg Testowy',
        'officeLocations' => [],
        'eventLocation' => 'Warszawa',
        'bibNumber' => (string)$participantId,
        'qrToken' => 'QR-TOKEN-' . $participantId,
        'participantCustomFields' => [],
        'participantImportantFieldAliases' => [],
    ];
}

/** @return list<array<string, mixed>> */
function qrBatchMessages(int $count): array
{
    $messages = [];
    for ($i = 1; $i <= $count; $i++) {
        $messages[] = qrBatchMessage($i, sprintf('uczestnik%d@deliverable.test', $i));
    }

    return $messages;
}

function withCentralMailerStub(string $failChunks, int $batchSize, callable $callback): void
{
    $previousUrl = getenv('CENTRAL_MAILER_URL');
    $previousKey = getenv('CENTRAL_MAILER_API_KEY');
    $previousBatch = getenv('CENTRAL_MAILER_BATCH_SIZE');
    $previousTimeout = getenv('CENTRAL_MAILER_TIMEOUT_SECONDS');

    $stub = CentralMailerStub::shared();
    $stub->reset($failChunks);
    putenv('CENTRAL_MAILER_URL=' . $stub->baseUrl());
    putenv('CENTRAL_MAILER_API_KEY=test-key');
    putenv('CENTRAL_MAILER_BATCH_SIZE=' . $batchSize);
    putenv('CENTRAL_MAILER_TIMEOUT_SECONDS=15');

    try {
        $callback($stub);
    } finally {
        putenv($previousUrl === false ? 'CENTRAL_MAILER_URL' : 'CENTRAL_MAILER_URL=' . $previousUrl);
        putenv($previousKey === false ? 'CENTRAL_MAILER_API_KEY' : 'CENTRAL_MAILER_API_KEY=' . $previousKey);
        putenv($previousBatch === false ? 'CENTRAL_MAILER_BATCH_SIZE' : 'CENTRAL_MAILER_BATCH_SIZE=' . $previousBatch);
        putenv($previousTimeout === false ? 'CENTRAL_MAILER_TIMEOUT_SECONDS' : 'CENTRAL_MAILER_TIMEOUT_SECONDS=' . $previousTimeout);
    }
}

test('splits participants into chunks of CENTRAL_MAILER_BATCH_SIZE', function (): void {
    withCentralMailerStub('', 4, function (CentralMailerStub $stub): void {
        $result = MailService::sendParticipantQrEmailBatch(qrBatchMessages(10), 'evt-1');

        $requests = $stub->requests();
        assertSame(3, count($requests), 'Oczekiwano 3 paczek dla 10 uczestnikow po 4');
        assertSame([4, 4, 2], array_column($requests, 'recipient_count'));
        assertSame([0, 1, 2], array_column($requests, 'chunk_index'));
        assertSame(range(1, 10), $result['sentParticipantIds']);
        assertSame([], $result['failures']);
    });
});

test('keeps sending remaining chunks when one chunk fails', function (): void {
    // Sedno poprawki: wczesniej pierwszy blad przerywal cala wysylke i nikt nie byl
    // oznaczony jako wyslany, mimo ze wczesniejsze paczki juz poszly do kolejki.
    withCentralMailerStub('1', 4, function (CentralMailerStub $stub): void {
        $result = MailService::sendParticipantQrEmailBatch(qrBatchMessages(10), 'evt-1');

        assertSame(3, count($stub->requests()), 'Awaria paczki nie moze zatrzymac kolejnych');
        assertSame([1, 2, 3, 4, 9, 10], $result['sentParticipantIds']);
        assertSame(1, count($result['failures']));
        assertSame(1, $result['failures'][0]['chunkIndex']);
        assertSame([5, 6, 7, 8], $result['failures'][0]['participantIds']);
        assertStringContains('HTTP 500', $result['failures'][0]['error']);
    });
});

test('does not mark recipients the mailer rejected as sent', function (): void {
    // Literowka w adresie (gorkag@wp.ol) przechodzila jako "wyslany" i zbiorcze "wyslij"
    // pomijalo ja juz na zawsze, choc mail nigdy nie wyszedl.
    withCentralMailerStub('', 4, function (CentralMailerStub $stub): void {
        $messages = qrBatchMessages(6);
        $messages[1] = qrBatchMessage(2, 'literowka@rejected.test');
        $messages[4] = qrBatchMessage(5, 'inna@rejected.test');

        $result = MailService::sendParticipantQrEmailBatch($messages, 'evt-1');

        assertSame([1, 3, 4, 6], $result['sentParticipantIds']);
        assertSame([[2], [5]], array_column($result['failures'], 'participantIds'));
        assertSame([0, 1], array_column($result['failures'], 'chunkIndex'));
        assertSame(
            'Central-mailer odrzucil adres odbiorcy: nieprawidlowy adres e-mail.',
            $result['failures'][0]['error']
        );
    });
});

test('reports every chunk as failed when the mailer is completely down', function (): void {
    withCentralMailerStub('0,1', 5, function (CentralMailerStub $stub): void {
        $result = MailService::sendParticipantQrEmailBatch(qrBatchMessages(10), 'evt-1');

        assertSame([], $result['sentParticipantIds']);
        assertSame(2, count($result['failures']));
        assertSame([1, 2, 3, 4, 5], $result['failures'][0]['participantIds']);
        assertSame([6, 7, 8, 9, 10], $result['failures'][1]['participantIds']);
    });
});

test('uses a deterministic idempotency key so a retry after a timeout is a replay', function (): void {
    // Klucz z bin2hex(random_bytes()) powodowal, ze ponowna proba po timeoucie byla
    // druga wysylka, a nie replayem - czyli duplikatem u uczestnika.
    withCentralMailerStub('', 5, function (CentralMailerStub $stub): void {
        MailService::sendParticipantQrEmailBatch(qrBatchMessages(5), 'evt-1');
        $first = $stub->requests()[0]['idempotency_key'];

        MailService::sendParticipantQrEmailBatch(qrBatchMessages(5), 'evt-1');
        $second = $stub->requests()[1]['idempotency_key'];

        assertSame($first, $second, 'Ta sama paczka musi dac ten sam klucz idempotencji');
        assertMatches('#^biuro-zawodow:participant-qr-batch:[0-9a-f]{64}$#', $first);
    });
});

test('gives different idempotency keys to different recipients and different events', function (): void {
    withCentralMailerStub('', 5, function (CentralMailerStub $stub): void {
        MailService::sendParticipantQrEmailBatch(qrBatchMessages(5), 'evt-1');
        MailService::sendParticipantQrEmailBatch(array_slice(qrBatchMessages(10), 5), 'evt-1');
        MailService::sendParticipantQrEmailBatch(qrBatchMessages(5), 'evt-2');

        $keys = array_column($stub->requests(), 'idempotency_key');
        assertSame(3, count(array_unique($keys)), 'Rozna tresc lub rozne wydarzenie = rozny klucz');
    });
});

test('keys a chunk by its contents, not by its position in the send', function (): void {
    // Dosylka reszty uczestnikow dzieli ich inaczej na paczki. Gdyby klucz zalezal od
    // indeksu, paczka nr 0 dosylki udawalaby paczke nr 0 pierwszej wysylki i zostalaby
    // odrzucona jako replay - uczestnicy nigdy nie dostaliby maila.
    withCentralMailerStub('', 5, function (CentralMailerStub $stub): void {
        MailService::sendParticipantQrEmailBatch(qrBatchMessages(5), 'evt-1');
        MailService::sendParticipantQrEmailBatch(array_slice(qrBatchMessages(10), 5), 'evt-1');

        $requests = $stub->requests();
        assertSame(0, $requests[0]['chunk_index']);
        assertSame(0, $requests[1]['chunk_index']);
        assertFalse($requests[0]['idempotency_key'] === $requests[1]['idempotency_key']);
    });
});

test('passes eventId as contextId and participantId in recipient metadata', function (): void {
    // Bez tych dwoch pol nie da sie odtworzyc z mailera, kto juz dostal maila -
    // a to jedyna ochrona przed duplikatami po nieudanej wysylce.
    withCentralMailerStub('', 10, function (CentralMailerStub $stub): void {
        MailService::sendParticipantQrEmailBatch(qrBatchMessages(3), 'evt-42');

        $request = $stub->requests()[0];
        assertSame('evt-42', $request['context_id']);
        assertSame([1, 2, 3], $request['participant_ids']);
        assertSame(
            ['uczestnik1@deliverable.test', 'uczestnik2@deliverable.test', 'uczestnik3@deliverable.test'],
            $request['recipient_emails']
        );
        assertSame('/emails/batch', $request['path']);
    });
});

test('keeps a chunk well under the mailer request body limit', function (): void {
    // APP_MAX_REQUEST_BODY_BYTES w central-mailerze to 12 MB, a kazdy uczestnik to ~12 kB
    // (HTML + kod QR w base64). Domyslne 500 na paczke dawalo ~6 MB na zadanie i timeouty.
    withCentralMailerStub('', 50, function (CentralMailerStub $stub): void {
        MailService::sendParticipantQrEmailBatch(qrBatchMessages(50), 'evt-1');

        $bodyBytes = $stub->requests()[0]['body_bytes'];
        assertTrue($bodyBytes < 2_000_000, 'Paczka 50 odbiorcow powinna miescic sie grubo ponizej 2 MB, ma ' . $bodyBytes);
    });
});

test('defaults to 50 recipients per chunk instead of the old 500', function (): void {
    withCentralMailerStub('', 0, function (CentralMailerStub $stub): void {
        putenv('CENTRAL_MAILER_BATCH_SIZE');
        MailService::sendParticipantQrEmailBatch(qrBatchMessages(60), 'evt-1');

        assertSame([50, 10], array_column($stub->requests(), 'recipient_count'));
    });
});

test('sends nothing and reports nothing for an empty participant list', function (): void {
    withCentralMailerStub('', 10, function (CentralMailerStub $stub): void {
        $result = MailService::sendParticipantQrEmailBatch([], 'evt-1');

        assertSame([], $stub->requests());
        assertSame([], $result['sentParticipantIds']);
        assertSame([], $result['failures']);
    });
});
