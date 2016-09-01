<?php declare(strict_types = 1);

namespace Amp\Parallel\Worker;

use Amp\Parallel\Forking\Fork;
use Amp\Parallel\Worker\Internal\TaskRunner;
use Interop\Async\Awaitable;

/**
 * A worker thread that executes task objects.
 */
class WorkerFork extends AbstractWorker {
    public function __construct() {
        parent::__construct(new Fork(function (): Awaitable {
            $runner = new TaskRunner($this, new BasicEnvironment);
            return $runner->run();
        }));
    }
}