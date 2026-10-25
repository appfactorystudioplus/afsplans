# 09 — Move the Brain-only scheduled commands out of www's `routes/console.php`

Status: DONE 2026-10-15 in code (steps 3 and 4 of section 5 as one change); nothing is installed on any server, so none of the nine commands runs yet (steps 1, 2, 5, 6).
See "Done 2026-10-15" at the end. Code blocks above that section show the design; the code is the source of truth.

Files: `/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/w/www/routes/console.php` (130 lines) and
`/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/w/members/routes/console.php` (52 lines).

## 1. Why

www's `routes/console.php` is evaluated on every tenant clone, so every Brain-only schedule is registered on servers
where it can only print "Not the Brain (IS_BRAIN) — nothing to do." The `members` app runs once, on appfactory only,
and already schedules appfactory's own tasks. Brain-only schedules belong in its `routes/console.php`.

## 2. Audit of `w/www/routes/console.php`

| Line | Schedule | Frequency | Guards on `IS_BRAIN`? (command file:line) | Verdict |
|---|---|---|---|---|
| 6-8 | `inspire` (an `Artisan::command` closure, not scheduled) | n/a | n/a | stays |
| 21 | `direct_payments:subscription-notify` | every 15 min | no. Its header says it runs on the Brain and every tenant clone | **stays** |
| 34 | `ecommerce:expire-holds` | every 5 min | no | **stays** |
| 46 | `brain:storage-prune` | daily | yes, `BrainStoragePrune.php:28` | **Brain only: move** |
| 57 | `brain:storage-mark-stale` | every minute | yes, `BrainStorageMarkStale.php:30` | **Brain only: move** |
| 69 | `domains:reverify` | every 5 min | yes, `DomainsReverify.php:24` | **Brain only: move** |
| 81 | `domains:publish` | every minute | yes, `DomainsPublish.php:25` | **Brain only: move** |
| 86 | `domains:dns-reconcile` | every 10 min | yes, `DomainsDnsReconcile.php:23` | **Brain only: move** |
| 96 | `domains:purchases` | every 5 min | yes, `DomainsPurchases.php:24` | **Brain only: move** |
| 106 | `domains:servers-sweep` | every 5 min | yes, `DomainsServersSweep.php:23` | **Brain only: move** |
| 117 | `domains:deploys` | every minute, `runInBackground` | yes, `DomainsDeploys.php:25` | **Brain only: move** |
| 127 | `domains:health --log` | every 15 min | yes, `DomainsHealth.php:23` | **Brain only: move** |

Nine entries move. Two stay because tenants use them. The `queue:work` line from [08-queue-worker.md](08-queue-worker.md)
is not Brain-only (a tenant's delayed mail needs it too), so it stays in www.

## 3. The catch: the commands cannot move, only their schedule

Those nine commands are classes in `w/www/app/Console/Commands/` and use www's Brain code (domain claims, the DNS
publisher, the storage registry, the domain claims and so on). `members` is a separate Laravel app: it
shares the database with www but not the code, and it has none of those classes. Adding `Schedule::command('domains:publish')`
to `members/routes/console.php` as it stands would fail with "command not found".

The repo already solves this for one command. `billing:enforce-membership` is scheduled in `members/routes/console.php`
(lines 42-52), but the members command only asks www to run it:

* members: `App\Console\Commands\BillingEnforceMembership` calls `BrainSurfaceClient::post('billing/enforce')`, a signed
  server-to-server call (timeout `WWW_BRAIN_SURFACE_TIMEOUT_SECONDS`, 120 s default).
* www: `POST /api/internal/brain/billing/enforce` (`routes/api.php:33`, `brain.only` + `brain.surface:MEMBERS`) in
  `BrainBillingEnforcementController` takes a cache lock (409 when already running), calls
  `Artisan::call('billing:enforce-membership')` and returns the output and the exit code.

The nine commands move the same way.

## 4. Design

### 4.1 www: one endpoint with an allowlist

`routes/api.php`, inside the existing `Route::prefix('internal/brain')->middleware(['brain.only'])` group:

```php
Route::post('/commands/{name}', [BrainCommandsController::class, 'run'])->middleware('brain.surface:MEMBERS');
```

`app/Http/Controllers/Internal/BrainCommandsController.php`, modelled on `BrainBillingEnforcementController`:

```php
// imports: Symfony\Component\Process\Process (not the Process facade; fromShellCommandline is Symfony's) and
// Symfony\Component\Process\PhpExecutableFinder
class BrainCommandsController extends Controller
{
    /**
     * The commands members may trigger, with their arguments. Nothing else can be run through here.
     * `detached` commands are started in the background and answered at once (202); the rest run inside the request.
     */
    private const COMMANDS = [
        'brain:storage-prune'      => ['args' => [],               'detached' => false],
        'brain:storage-mark-stale' => ['args' => [],               'detached' => false],
        'domains:reverify'         => ['args' => [],               'detached' => false],
        'domains:publish'          => ['args' => [],               'detached' => false],
        'domains:dns-reconcile'    => ['args' => [],               'detached' => false],
        'domains:purchases'        => ['args' => [],               'detached' => false],
        'domains:servers-sweep'    => ['args' => [],               'detached' => false],
        'domains:deploys'          => ['args' => [],               'detached' => true],
        'domains:health'           => ['args' => ['--log' => true], 'detached' => false],
    ];

    public function run(string $name): JsonResponse
    {
        if (!array_key_exists($name, self::COMMANDS)) {
            return response()->json(['success' => false, 'message' => 'Unknown command.'], 404);
        }
        $spec = self::COMMANDS[$name];

        if ($spec['detached']) {
            return $this->startDetached($name);
        }

        $lock = Cache::lock('brain-command:' . $name, 600);
        if (!$lock->get()) {
            return response()->json(['success' => false, 'code' => 'ALREADY_RUNNING'], 409);
        }

        try {
            @set_time_limit(600);
            $started = microtime(true);
            $output = new BufferedOutput();
            $exit = Artisan::call($name, $spec['args'], $output);

            return response()->json([
                'success'     => $exit === 0,
                'exit_code'   => $exit,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'output'      => mb_substr(trim($output->fetch()), 0, 8000),
            ], $exit === 0 ? 200 : 500);
        } finally {
            $lock->release();
        }
    }

    /** Starts `php artisan <name>` as its own process and returns at once; the command logs for itself. */
    private function startDetached(string $name): JsonResponse
    {
        $php = (new PhpExecutableFinder())->find(false);   // the CLI php, not the php-fpm binary this request runs in
        if ($php === false) {
            return response()->json(['success' => false, 'message' => 'The php CLI binary was not found.'], 500);
        }

        $command = escapeshellarg($php) . ' ' . escapeshellarg(base_path('artisan')) . ' ' . escapeshellarg($name)
            . ' > /dev/null 2>&1 &';
        Process::fromShellCommandline($command, base_path())->setTimeout(10)->run();   // the shell returns at once

        return response()->json(['success' => true, 'started' => true], 202);
    }
}
```

`domains:deploys` takes no lock: its header says a deployment's own conditional claim keeps two processes from running the same
deployment, so an overlapping start is safe, which is also why the old schedule could run it in the background.

The lock replaces the old `withoutOverlapping()` for the eight in-request commands. The commands keep their `IS_BRAIN`
guards, so a manual run elsewhere still does nothing.

### 4.2 members: one command and nine schedule lines

`app/Console/Commands/BrainRun.php`, modelled on `BillingEnforceMembership`:

```php
protected $signature = 'brain:run {name : the Brain command to run, e.g. domains:publish}';

public function handle(BrainSurfaceClient $client): int
{
    $result = $client->post('commands/' . $this->argument('name'));

    if ($result['status'] === 409) {
        return self::SUCCESS;                        // the previous run is still going; the next tick tries again
    }
    $output = trim((string) ($result['body']['output'] ?? ''));
    if ($output !== '') {
        $this->line($output);
    }
    if (!$result['ok'] || ($result['body']['success'] ?? false) !== true) {
        $this->error('www: ' . ($result['error'] ?? $result['body']['message'] ?? 'the command failed'));
        return self::FAILURE;
    }

    return self::SUCCESS;
}
```

`members/routes/console.php` gains the nine lines, same frequencies as today:

```php
Schedule::command('brain:run brain:storage-prune')->daily()->withoutOverlapping(60)->runInBackground();
Schedule::command('brain:run brain:storage-mark-stale')->everyMinute()->withoutOverlapping(4)->runInBackground();
Schedule::command('brain:run domains:reverify')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
Schedule::command('brain:run domains:publish')->everyMinute()->withoutOverlapping(10)->runInBackground();
Schedule::command('brain:run domains:dns-reconcile')->everyTenMinutes()->withoutOverlapping(10)->runInBackground();
Schedule::command('brain:run domains:purchases')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
Schedule::command('brain:run domains:servers-sweep')->everyFiveMinutes()->withoutOverlapping(10)->runInBackground();
Schedule::command('brain:run domains:deploys')->everyMinute()->runInBackground();   // answered at once: the Brain starts it detached
Schedule::command('brain:run domains:health')->everyFifteenMinutes()->withoutOverlapping(10)->runInBackground();
```

`runInBackground()` keeps the members scheduler from waiting on a slow call.

### 4.3 www: the nine entries and their docblocks are deleted

Lines 40-130 of `routes/console.php`, leaving `inspire`, `direct_payments:subscription-notify`,
`ecommerce:expire-holds` (and the `queue:work` line from plan 08).

## 5. Order of rollout

**Owner confirmed: the Brain's own scheduler is not running, so none of these nine commands runs today.** The move
therefore does not replace a working schedule; it starts them for the first time, once the members scheduler is
installed. Nothing can run twice, but each command begins acting on whatever data is in its tables.

1. Confirm members' `.env` has `BRAIN_API_URL` and the MEMBERS app key (the enforcement trigger already needs them).
2. Check which user runs the web process on the Brain and which user the old schedule would have run as. The nine
   commands now run in the web process (eight inside the request, `domains:deploys` as a process the web user starts),
   so any key, file or permission the cron user had must also belong to the web user (see section 8).
3. Deploy www with the endpoint, and members with `BrainRun`, with no new schedule lines yet. Run
   `php artisan brain:run domains:health` on members once and confirm the www output comes back.
4. Delete the nine entries from www in the same release that adds the nine lines to members.
5. Install the members scheduler entry in apache's crontab (`core/scripts/laravel-cron.sh --install --user apache --group apache --path <members folder>`, as root; plan 10). From here the commands run.
6. Optional staging, since they start for the first time: first add only `domains:health`, then the rest one at a time,
   starting with the read-mostly ones and leaving `domains:purchases` (the only code that refunds) and `domains:deploys`
   for last. `domains:publish` already does nothing while `DOMAINS_PUBLISH_ENABLED` is off.

## 6. A lighter alternative (not the request)

Wrap the nine www entries in `if (config('brain.is_brain')) { ... }`. Tenants stop registering them with a single edit
and no new code, but the schedule stays in www. This is only mentioned because the effect on tenants is the same.

## 7. Tests

* The endpoint answers 404 for a name not in the allowlist, 409 when locked, 401/403 without the MEMBERS key, and runs
  an allowed command through `Artisan::call`.
* `BrainRun` with a faked `BrainSurfaceClient`: success, 409 skipped, failure exit code.
* A test that every name in `COMMANDS` is a registered command whose source contains the `IS_BRAIN` guard, so a
  non-Brain command cannot be added to the allowlist by mistake.

## 8. Decisions and open points

* DECIDED: `domains:deploys` is started detached by the endpoint, which answers 202 at once (section 4.1).
* DECIDED: one signed call per command per tick, no batching. About 225 calls an hour in total; three land on each minute
  boundary (`brain:storage-mark-stale`, `domains:publish`, `domains:deploys`). Each is a small request, `members` runs them
  in the background so they go in parallel, and batching would mean a second protocol or a scheduler inside www.
* DECIDED (owner): the Brain's scheduler is not running; nothing runs today (section 5).
* DECIDED (owner): everything runs as apache, web and scheduler, Brain and tenants ([10-run-as-apache.md](10-run-as-apache.md)). Still
  to check: any SSH key or file the nine commands use must be readable by apache (plan 10 section 4).
* OPEN: `php` for the detached command is found with `PhpExecutableFinder` (the web process runs under php-fpm, whose own
  binary cannot run `artisan`); confirm the CLI binary is on the Brain.
* OPEN (owner): which of the seven `domains:*` entries are still wanted at all. The owner chose the old website-deploy flow
  (`DomainController::createWebsite` and the shell scripts), and the old flow does not call these commands. Moving them to members
  only makes sense for the ones the old flow or the dev environment actually uses; the two `brain:storage-*` entries are unrelated
  to domains. Until the owner decides, the plan still lists all nine.

## Done 2026-10-15

Committed in `w/www` and `w/members`, nothing pushed, nothing installed or started on any server.

* **www:** `BrainCommandsController` (`app/Http/Controllers/Internal/`) with the nine-name allowlist (`COMMANDS`), the in-request lock (`brain-command:{name}`, 409 `ALREADY_RUNNING`), the detached start of `domains:deploys` (202,
  no lock) and route `POST /api/internal/brain/commands/{name}` (`brain.only` + `brain.surface:MEMBERS`) in `routes/api.php`. The nine schedule entries and their docblocks are removed from `routes/console.php`; it keeps
  `queue:work` (plan 08), `direct_payments:subscription-notify` and `ecommerce:expire-holds`, and a comment saying where the others went.
* **members:** `app/Console/Commands/BrainRun.php` (`brain:run {name}`) and the nine `Schedule::command('brain:run ...')` lines in `routes/console.php`, with the frequencies and `runInBackground()` of section 4.2.
  (The old www entries also had `onOneServer()`; the new lines follow the plan text and do not, since members runs once.)
* **Addition to the plan text:** `spawn()` is a protected method of the controller that starts the detached process; the test replaces it. Without that seam a test of `domains:deploys` would start a real
  `php artisan` that reads this machine's `.env` (`IS_BRAIN=true` and the lab database).
* **Tests:** www `BrainCommandsEndpointTest` (9: signature refused, name outside the allowlist 404 and not run, allowed command with its arguments and output, failing command 500, 409 while locked and the lock
  released after a run, detached start answered 202, every allowlisted name is a registered command that guards on `IS_BRAIN`, www no longer schedules the nine); `BrainStorageHealthTest` now asserts the stale job is
  triggered by members and not scheduled in www; members `BrainRunTest` (6: one signed POST to the right path, 409 skipped, 202 success, a Brain failure fails here with its output, an unknown command or an
  unconfigured Brain fails without throwing, the nine schedule lines with their cron expressions). The members tests were run with www's phpunit (members has none in its own vendor folder).
* **Still to do, needs you (section 5):** confirm members' `.env` has `WWW_BRAIN_SURFACE_URL` / `WWW_BRAIN_SURFACE_KEY` (the names `BrainSurfaceClient` reads); check that apache can read what the nine commands use (plan 10);
  confirm the CLI php binary on the Brain; install the members scheduler (`laravel-cron.sh --install --user apache --group apache --path <members folder>`, as root); optionally stage the first run starting with `domains:health` and leaving
  `domains:purchases` and `domains:deploys` for last.
* **Still open for the owner (section 8):** which of the seven `domains:*` entries are wanted at all, given the old website-deploy flow; the plan still moves all nine.
