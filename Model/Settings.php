<?php

namespace Kanboard\Plugin\Foreman\Model;

use Closure;
use Pimple\Container;

class Settings
{
    private Closure $clock;

    public function __construct(private Container $c, callable $clock)
    {
        $this->clock = Closure::fromCallable($clock);
    }

    public function host(): ?array
    {
        $listeners = $this->c['hook']->getListeners('foreman:host');
        if ($listeners === []) {
            return null;
        }

        $listener = $listeners[0];
        $adapter = is_callable($listener) ? $listener() : $listener;
        if (!is_array($adapter)) {
            $this->c['logger']->warning('Foreman: ignored foreman:host adapter of type '.gettype($adapter));
            return null;
        }

        return $adapter === [] ? null : $adapter;
    }

    public function visitMode(): string
    {
        $mode = $this->host()['visit_fallback'] ?? $this->c['configModel']->getOption('foreman_visit_mode');

        return in_array($mode, ['auto', 'always', 'never'], true) ? $mode : 'auto';
    }

    public function visitAllowed(): bool
    {
        $mode = $this->visitMode();
        if ($mode !== 'auto') {
            return $mode === 'always';
        }

        $lastSeen = $this->cliLastSeen();

        return $lastSeen === null || $lastSeen < ($this->clock)() - 600;
    }

    public function coreCronjobEnabled(): bool
    {
        $saved = $this->c['configModel']->getOption('foreman_core_cronjob', null);

        return $saved === null ? (bool) ($this->host()['core_cronjob_default'] ?? false) : (string) $saved === '1';
    }

    public function markCliSeen(): void
    {
        $this->c['configModel']->save(['foreman_cli_last_seen' => (string) ($this->clock)()]);
    }

    public function cliLastSeen(): ?int
    {
        $saved = $this->c['configModel']->getOption('foreman_cli_last_seen', null);

        return $saved === null ? null : (int) $saved;
    }

    public function setJobEnabled(string $id, bool $on): void
    {
        $this->c['db']->getConnection()->prepare('UPDATE foreman_state SET enabled = ? WHERE job_id = ?')
            ->execute([(int) $on, $id]);
    }

    public function isForced(string $key): bool
    {
        return $key === 'visit_fallback' && isset($this->host()['visit_fallback']);
    }
}
