# Changelog

## 1.0.0

- Runs every registered suite job from one crontab line: `* * * * * cd /var/www/app && php cli foreman:run`.
- Plugins register jobs with the `foreman:jobs` hook, as plain descriptors that return `done`, `more` or `failed`.
- One lease per job, so cron, a page visit and Run now never run the same job twice at once.
- Page-visit fallback after the response (Auto / Always / Never).
- Run log with 30-day retention; exception class only, never messages.
- Settings → Jobs page: run now, enable or disable, run history, crontab status and a stale warning.
- Admin alert bar for failing or stale jobs; `foreman.job.failed` and `foreman.job.recovered` events.
- Built-in `core.cronjob` (off by default) and `foreman.prune` jobs.
- `foreman:host` adapter for Floorboard.
