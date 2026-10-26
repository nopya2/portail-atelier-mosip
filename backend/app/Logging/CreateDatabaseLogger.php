<?php

namespace App\Logging;

use Monolog\Logger;

/**
 * Fabrique du canal « database » (driver custom de config/logging.php).
 */
class CreateDatabaseLogger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        return new Logger(
            $config['name'] ?? 'database',
            [new DatabaseHandler($config['connection'] ?? 'logs', $config['level'] ?? 'warning')],
            [new RequestContextProcessor],
        );
    }
}
