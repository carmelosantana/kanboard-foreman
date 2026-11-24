# Foreman

Foreman runs every plugin's scheduled jobs from one crontab line. Plugins register their jobs and Foreman runs
them on time, one at a time per job. It keeps a run log, shows admins when something fails, and falls back to
page visits when no crontab line exists.

Requires Kanboard 1.2.47 or later and PHP 8.4 or later. Works on SQLite, MariaDB/MySQL and PostgreSQL.

## Install

Extract the release archive into Kanboard's `plugins/` directory so that `Plugin.php` is at
`plugins/Foreman/Plugin.php`. The tables are created on the next page load. Admins find the job list in
**Settings → Jobs**.

## Run it

Add one crontab line and every registered job runs on time:

```cron
* * * * * cd /var/www/app && php cli foreman:run
```

In Docker, run it as the web server's user so any file a job creates keeps the right owner:

```cron
* * * * * docker exec -u nginx kanboard php /var/www/app/cli foreman:run
```

`php cli foreman:run --job <id>` runs one job until it has no work left. The command prints one line per job
(`deed.refresh: done — …`) and exits 0 when everything succeeded, 1 when a job failed, and 2 for an unknown or
disabled `--job`.

Without the crontab line, page visits drive the jobs. After an admin or user page has been sent to the browser,
Foreman runs due jobs for up to about 10 seconds, so nobody waits for them. This needs PHP-FPM or FrankenPHP
(`fastcgi_finish_request`). On other setups, add the crontab line.

**Settings → Jobs → Page-visit fallback:**

| Setting | Behaviour |
| --- | --- |
| Auto (default) | Page visits run jobs only while the crontab line hasn't run in the last 10 minutes |
| Always | Page visits always run due jobs |
| Never | Only the crontab line (or Run now) runs jobs |

Kanboard's own daily `cronjob` can also run through Foreman as the built-in `core.cronjob` job (daily at 08:00).
It is off by default. Turn it on in Settings → Jobs **and** remove your old `./cli cronjob` crontab line,
otherwise overdue-task emails go out twice.

## For plugin authors: register a job

Attach a callable to the `foreman:jobs` hook in `initialize()`. It returns a list of plain arrays, so your plugin
needs no Foreman class and loads fine without Foreman. List Foreman under `recommends` in `plugin.json`.

```php
$this->hook->on('foreman:jobs', fn (): array => [[
    'id' => 'deed.refresh',              // ^[a-z0-9][a-z0-9:._-]{0,63}$
    'plugin' => 'Deed',
    'title' => 'Refresh due domains',
    'interval' => 86400,                 // seconds, an int ≥ 60
    'hour' => 6,                         // optional: earliest local hour (0–23) for daily jobs; null = none
    'budget' => 10,                      // optional: seconds per page-visit run, an int ≥ 1 (10 if omitted or null)
    'link' => ['controller' => 'DomainController', 'action' => 'index', 'params' => [], 'plugin' => 'Deed'], // optional Details link
    'run' => function (int $deadline, string $trigger): array {
        // Work until time() reaches $deadline, keeping your own cursor.
        // $trigger is cli, web, manual or worker.
        return ['status' => 'more', 'summary' => '12 refreshed'];   // done | more | failed
    },
]]);
```

- Return `more` while work is left. The job is due again on the next tick and continues from your cursor.
- A thrown exception, or anything other than a status array, counts as `failed`. Foreman stores only the
  exception's class name, never its message. A failed job retries after its interval, or after 15 minutes,
  whichever is sooner.
- Invalid descriptors are skipped with a log warning: `Foreman: skipped job "{id}" ({reason})`.
  The reasons are `invalid id`, `duplicate id`, `interval below 60`, `run not callable`,
  `invalid hour`, and `invalid budget`.
- To check whether Foreman is installed, test `isset($container['foreman'])` at request time, never in
  `initialize()`. Plugins load alphabetically, so Foreman may not be registered yet. When Foreman is present,
  turn your own page-visit fallback off.
- Wrap your own CLI command in Foreman's lease, so an old per-plugin crontab line can't run alongside Foreman:

  ```php
  $container['foreman']->withLease('deed.refresh', fn () => $this->sweep());   // throws LeaseHeld when busy
  ```

## Events

Foreman dispatches these through Kanboard's event dispatcher:

| Event | When |
| --- | --- |
| `foreman.job.failed` | Every failed run |
| `foreman.job.recovered` | The first `done` after one or more failures |

The payload has `job_id`, `plugin`, `run_id`, `consecutive_failures` and `error_class`. Admins also see a
`Foreman: {n} job(s) failing` bar, or a `no job has run for {m} minutes` bar, above the page header.

## Host adapter (Floorboard)

A host plugin can register `foreman:host` as an array, or a callable returning one, with these keys:

| Key | Effect |
| --- | --- |
| `name` | Identifies the host (informational) |
| `visit_fallback` | Forces `auto`, `always` or `never`; the setting is then locked |
| `core_cronjob_default` | Turns `core.cronjob` on by default |
| `run_job` | `callable(string $id): array{accepted: bool, ref?: string}`; Run now hands the job to the host instead of running it in the request |
| `schedules` | `callable(): array` of host schedule rows, shown on the Jobs page |

On Floorboard, a systemd timer runs `foreman:run` every minute. The page-visit fallback is forced to Never there,
and Joist runs individual jobs as resource-limited workers.

## Tables

- `foreman_state`: one row per job, holding its lease, next due time, last status and failure count.
- `foreman_runs`: the run log. The built-in `foreman.prune` job (daily at 03:00) deletes runs older than 30 days.

## Licence

MIT.
