<?php

namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Handler Monolog qui écrit dans la table app_logs.
 *
 * Il utilise une connexion dédiée (« logs ») pour que les entrées survivent au
 * rollback d'une transaction métier, et ne doit jamais faire échouer la requête :
 * en cas d'erreur d'écriture, il se rabat sur error_log().
 */
class DatabaseHandler extends AbstractProcessingHandler
{
    /** Empêche la récursion si l'écriture elle-même provoque un log. */
    private static bool $writing = false;

    public function __construct(
        private readonly string $connection = 'logs',
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (self::$writing) {
            return;
        }

        self::$writing = true;

        try {
            DB::connection($this->connection)->table('app_logs')->insert(
                self::rowFromRecord($record)
            );
        } catch (Throwable $e) {
            error_log('[app_logs] écriture impossible : '.$e->getMessage().' | log initial : '.$record->message);
        } finally {
            self::$writing = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function rowFromRecord(LogRecord $record): array
    {
        $context = $record->context;
        $extra = $record->extra;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $row = [
            'source' => $context['source'] ?? 'backend',
            'level' => strtolower($record->level->getName()),
            'level_value' => $record->level->value,
            'channel' => $record->channel,
            'message' => LogSanitizer::truncate($record->message, 10000),
            'url' => LogSanitizer::truncate($extra['url'] ?? null, 2048),
            'method' => $extra['method'] ?? null,
            'status_code' => $context['status_code'] ?? null,
            'ip' => $extra['ip'] ?? null,
            'user_agent' => LogSanitizer::truncate($extra['user_agent'] ?? null, 1000),
            'user_id' => $extra['user_id'] ?? null,
            'request_id' => $extra['request_id'] ?? null,
            'created_at' => $record->datetime->format('Y-m-d H:i:s.uP'),
        ];
        unset($context['source'], $context['status_code']);

        if ($exception instanceof Throwable) {
            $row['exception_class'] = $exception::class;
            $row['file'] = LogSanitizer::truncate($exception->getFile(), 1024);
            $row['line'] = $exception->getLine();
            $row['trace'] = LogSanitizer::truncate($exception->getTraceAsString(), 20000);
            if ($previous = $exception->getPrevious()) {
                $context['previous'] = ['class' => $previous::class, 'message' => $previous->getMessage()];
            }
        }

        $context = LogSanitizer::sanitize($context);
        $row['context'] = $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;

        return $row;
    }
}
