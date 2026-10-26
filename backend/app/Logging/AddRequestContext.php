<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * « tap » Laravel : ajoute le contexte de requête aux canaux fichiers (daily…).
 */
class AddRequestContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new RequestContextProcessor);
    }
}
