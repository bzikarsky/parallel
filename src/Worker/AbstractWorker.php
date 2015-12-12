<?php
namespace Icicle\Concurrent\Worker;

use Icicle\Awaitable\Delayed;
use Icicle\Concurrent\Context;
use Icicle\Concurrent\Exception\StatusError;
use Icicle\Concurrent\Worker\Internal\TaskFailure;

/**
 * Base class for most common types of task workers.
 */
abstract class AbstractWorker implements Worker
{
    /**
     * @var \Icicle\Concurrent\Context
     */
    private $context;

    /**
     * @var bool
     */
    private $idle = true;

    /**
     * @var bool
     */
    private $shutdown = false;

    /**
     * @var \SplQueue
     */
    private $busyQueue;

    /**
     * @param \Icicle\Concurrent\Context $context
     */
    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->busyQueue = new \SplQueue();
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning()
    {
        return $this->context->isRunning();
    }

    /**
     * {@inheritdoc}
     */
    public function isIdle()
    {
        return $this->idle;
    }

    /**
     * {@inheritdoc}
     */
    public function start()
    {
        $this->context->start();
    }

    /**
     * {@inheritdoc}
     */
    public function enqueue(Task $task)
    {
        if (!$this->context->isRunning()) {
            throw new StatusError('The worker has not been started.');
        }

        if ($this->shutdown) {
            throw new StatusError('The worker has been shut down.');
        }

        // If the worker is currently busy, store the task in a busy queue.
        if (!$this->idle) {
            $delayed = new Delayed();
            $this->busyQueue->enqueue($delayed);
            yield $delayed;
        }

        $this->idle = false;

        yield $this->context->send($task);

        $result = (yield $this->context->receive());

        $this->idle = true;

        // We're no longer busy at the moment, so dequeue a waiting task.
        if (!$this->busyQueue->isEmpty()) {
            $this->busyQueue->dequeue()->resolve();
        }

        if ($result instanceof TaskFailure) {
            throw $result->getException();
        }

        yield $result;
    }

    /**
     * {@inheritdoc}
     */
    public function shutdown()
    {
        if (!$this->context->isRunning() || $this->shutdown) {
            throw new StatusError('The worker is not running.');
        }

        $this->shutdown = true;

        yield $this->context->send(0);

        yield $this->context->join();

        // Cancel any waiting tasks.
        while (!$this->busyQueue->isEmpty()) {
            $this->busyQueue->dequeue()->reject();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function kill()
    {
        $this->context->kill();

        // Cancel any waiting tasks.
        while (!$this->busyQueue->isEmpty()) {
            $this->busyQueue->dequeue()->reject();
        }
    }
}