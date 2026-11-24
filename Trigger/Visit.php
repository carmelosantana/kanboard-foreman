<?php

namespace Kanboard\Plugin\Foreman\Trigger;

use Closure;
use Pimple\Container;
use Throwable;

class Visit
{
    private Closure $defer;
    private ?Closure $finish;
    private Closure $clock;

    public function __construct(
        private Container $c,
        ?callable $defer = null,
        ?callable $finish = null,
        ?callable $clock = null
    ) {
        $this->defer = Closure::fromCallable($defer ?? 'register_shutdown_function');
        $finish ??= function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : null;
        $this->finish = $finish === null ? null : Closure::fromCallable($finish);
        $this->clock = Closure::fromCallable($clock ?? 'time');
    }

    public function arm(): bool
    {
        if ($this->finish === null || !$this->c['foremanSettings']->visitAllowed()) {
            return false;
        }

        // Create state for new jobs before the due check, including on installs without CLI runs.
        $this->c['foremanRegistry']->sync();
        $runner = $this->c['foremanRunner'];
        if (!$runner->hasDueJob()) {
            return false;
        }

        ($this->defer)(function () use ($runner): void {
            try {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                ignore_user_abort(true);
                if (($this->finish)() === false) {
                    return;
                }

                $runner->tick('web', ($this->clock)() + 10);
            } catch (Throwable $e) {
                $this->c['logger']->error('Foreman: deferred visit failed ('.get_class($e).')');
            }
        });

        return true;
    }
}
