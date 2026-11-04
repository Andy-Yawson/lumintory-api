<?php

namespace App\Console\Commands;

use App\Services\Dispatch\DispatchService;
use Illuminate\Console\Command;

class DispatchTick extends Command
{
    protected $signature = 'dispatch:tick';

    protected $description = 'Let unanswered rider offers lapse, ask again, and retry failed-trip fees';

    public function handle(DispatchService $dispatch): int
    {
        $dispatch->tick();
        $dispatch->retryTripFees();

        return self::SUCCESS;
    }
}
