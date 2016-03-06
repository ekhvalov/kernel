<?php

declare (strict_types = 1);

namespace Recoil\Kernel;

use Recoil\Exception\CompositeException;
use Recoil\Exception\TerminatedException;
use Throwable;

/**
 * Implementation of Api::any().
 */
final class StrandWaitAny implements Awaitable, StrandObserver
{
    public function __construct(Strand ...$substrands)
    {
        $this->substrands = $substrands;
    }

    /**
     * Perform the work.
     *
     * @param Strand $strand The strand to resume on completion.
     * @param Api    $api    The kernel API.
     */
    public function await(Strand $strand, Api $api)
    {
        $this->strand = $strand;
        $this->strand->setTerminator([$this, 'cancel']);

        foreach ($this->substrands as $substrand) {
            $substrand->attachObserver($this);
        }
    }

    /**
     * A strand completed successfully.
     *
     * @param Strand $strand The strand.
     * @param mixed  $value  The result of the strand's entry point coroutine.
     */
    public function success(Strand $strand, $value)
    {
        foreach ($this->substrands as $s) {
            if ($s !== $strand) {
                $s->detachObserver($this);
                $s->terminate();
            }
        }

        $this->strand->resume($value);
    }

    /**
     * A strand failed due to an uncaught exception.
     *
     * @param Strand    $strand    The strand.
     * @param Throwable $exception The exception.
     */
    public function failure(Strand $strand, Throwable $exception)
    {
        $index = \array_search($strand, $this->substrands, true);
        assert($index !== false);
        unset($this->substrands[$index]);

        $this->exceptions[$index] = $exception;

        if (!$this->substrands) {
            $this->strand->throw(
                new CompositeException($this->exceptions)
            );
        }
    }

    /**
     * A strand was terminated.
     *
     * @param Strand $strand The strand.
     */
    public function terminated(Strand $strand)
    {
        $this->failure($strand, new TerminatedException($strand));
    }

    /**
     * Terminate all pending strands.
     */
    public function cancel()
    {
        foreach ($this->substrands as $strand) {
            $strand->detachObserver($this);
            $strand->terminate();
        }
    }

    /**
     * @var Strand|null The strand to resume.
     */
    private $strand;

    /**
     * @var array<Strand> The strands to wait for.
     */
    private $substrands;

    /**
     * @var array<integer, Exception> The exceptions thrown by failed strands.
     *                     Ordered by completion order, indexed by strand order.
     */
    private $exceptions = [];
}