# survos/civicrm-bundle

CiviCRM APIv4 over Symfony HttpClient, with background work on Symfony Messenger.

Two packages, following the quickbase and grist pattern:

- **`survos/civicrm-php`** (`lib/civicrm-php`) — the APIv4 client. Framework-agnostic: it takes
  an `HttpClientInterface` and needs nothing else, so it works from Symfony, from WordPress, or
  from plain PHP. That last one matters here, because VillageDesk's CiviCRM runs inside
  WordPress.
- **`survos/civicrm-bundle`** (`bu/civicrm-bundle`) — the Symfony wrapper: configuration,
  service wiring, the Messenger messages and handlers, and the console commands.

Two claims, and the second is the interesting one:

1. Talk to CiviCRM through its API, not its tables.
2. CiviCRM's model for background work is a workaround for shared hosting, and Messenger is a
   better answer everywhere else — including on FrankenPHP, where CiviCRM's model does not run
   at all.

## Why the API and not the database

CiviCRM's schema looks more readable than it is. Custom fields live in generated tables named by
id, option values resolve through option groups, a contact's display name is assembled by rules,
and `civicrm_activity` needs `civicrm_activity_contact` plus a record-type option value before it
means anything. Every one of those is somewhere a `SELECT` returns an answer that is quietly
wrong.

The API also enforces what a village depends on: ACLs, dedupe rules, the hooks extensions
register, and the change log. A direct `INSERT` skips all of it and says nothing.

```php
$contacts = $civi->call('Contact', 'get', [
    'select' => ['display_name', 'email_primary.email'],
    'where'  => [['contact_type', '=', 'Individual']],
    'limit'  => 25,
]);

foreach ($civi->all('Contribution') as $row) {   // pages, yields, constant memory
    // ...
}
```

Two details the wire format gets wrong if you guess. Parameters go as a **single JSON string in a
`params` field**, not a JSON body — get it wrong and you get an empty result rather than an
error. And the API reports failures *inside* an HTTP 200, so the body is authoritative:
`{"error_message": "Api FakeEntity fakeAction version 4 does not exist."}` arrives as a success
to anything checking status codes.

Authentication is `X-Civi-Auth: Bearer`, not the documented `?_authx=` query form. A key in a URL
ends up in access logs, proxy logs and browser history.

## Why Messenger, and what CiviCRM does today

CiviCRM has three ways to run long work, and all three tie it to an HTTP request.

**In the browser.** `CRM_Queue_Runner::runAllViaWeb()` drives an AJAX progress bar. The manual is
candid about the failure: *"There is no way to safely re-connect with the queue"* if the browser
closes — leaving tasks that may run twice or not at all.

**After the response, in the same process.** `Civi\Queue\ShutdownWorker`:

```php
public static function register(string $queueName, int $seconds = 30) {
    if (\Civi::settings()->get('queue_paused')) { return; }
    if (!\function_exists('fastcgi_finish_request')) { return; }   // ← the FrankenPHP blocker
    ...
    register_shutdown_function([self::class, 'workQueues']);
}

public static function workQueues() {
    \session_write_close();
    \fastcgi_finish_request();          // flush to the client, keep burning CPU
    foreach (self::$queues as $queueName => $seconds) { ... }
}
```

**On cron**, every few minutes, for scheduled jobs.

The shutdown worker is genuinely clever, and it is the right answer to a real constraint: on
shared hosting with no daemon, no supervisor and no CLI, this is how you get asynchronous work.
It is the same reasoning behind WordPress's `wp-cron`.

But it buys that with properties nobody would choose otherwise:

| | ShutdownWorker | Messenger |
|---|---|---|
| Work happens when | somebody visits a page | always, a worker is running |
| Duration | hard 30s, then cut off | as long as it takes |
| Retries | none | configurable, with backoff |
| Failures | vanish into a shutdown handler | land in a failure transport |
| Visibility | none | `messenger:stats`, `messenger:failed:show` |
| Scaling | one process per request | N workers, any host |
| Requires | `fastcgi_finish_request` | nothing in particular |

That last row is the FrankenPHP problem, and it is not a detail. `fastcgi_finish_request()` is an
FPM function; the guard means the shutdown worker **silently does nothing** anywhere else — CLI,
and FrankenPHP. Not an error, not a warning: queued work simply never runs. And in worker mode
the premise is gone anyway, because the process does not end after a request, which is the whole
point of worker mode.

So "CiviCRM background work" and "FrankenPHP" are not merely awkward together. The former is
built on the assumption the latter exists to remove.

## Mail

`CRM_Utils_Mail::send()` delivers **synchronously, through PEAR Mail, inside the request**:

```php
$mailer = \Civi::service('pear_mail');
```

A slow SMTP server is a slow page. An SMTP server that is down is an error in front of whoever
pressed the button. There is no retry.

```php
// instead
$bus->dispatch(new SendCiviEmail(
    toEmail: 'member@example.org',
    subject: 'Your ride on Thursday',
    body: $body,
    contactId: 1234,          // also logs an Email activity, through the API
));
```

The request returns immediately. A worker sends it, retries on failure, and records the send back
into CiviCRM as an activity — because a message the CRM does not know about is one no coordinator
can find when somebody asks "did anyone tell her it was cancelled?".

## Exports

The example that motivated this bundle: ask for an export, get an email with a link when it is
done.

```php
$bus->dispatch(new ExportRequested(
    entity: 'Contact',
    params: ['where' => [['contact_type', '=', 'Individual']]],
    notifyEmail: 'coordinator@example.org',
));
```

```bash
bin/console civicrm:export Contact coordinator@example.org
```

No spinner, no tab that has to stay open, no thirty-second budget. `ExportRequested` streams the
API a page at a time and writes a zip; `ExportReady` emails the link. They are **two messages on
purpose** — building a file and telling somebody about it fail differently and deserve their own
retries. A transient SMTP outage should not rebuild a 40MB zip, and a failed export should not
send a link to a file that is not there.

## Configuration

```yaml
survos_civicrm:
    base_url: '%env(CIVICRM_BASE_URL)%'      # site root, not the API path
    api_key:  '%env(CIVICRM_API_KEY)%'
    from_address: 'no-reply@villagedesk.org'
    export_dir: '%kernel.project_dir%/var/exports'
    download_base_url: '/exports'
    max_attempts: 3          # reads only -- see below
    base_delay_ms: 200
```

```yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
            failed: 'doctrine://default?queue_name=failed'
        failure_transport: failed
        routing:
            'Survos\CivicrmBundle\Message\ExportRequested': async
            'Survos\CivicrmBundle\Message\ExportReady': async
            'Survos\CivicrmBundle\Message\SendCiviEmail': async
```

```bash
bin/console messenger:consume async -vv
```

## Commands

```bash
bin/console civicrm:count Contact
bin/console civicrm:peek Contribution --limit=5
bin/console civicrm:export Contact somebody@example.org
```

## Retry, and why it is not an HTTP decorator

Reads are retried on 429, 5xx and transport failures, with exponential backoff and full jitter.
**Writes never are.**

That asymmetry is the reason retry lives in the client instead of in a `RetryableHttpClient`
decorator. APIv4 is POST-only, so at the HTTP layer `Contact.get` and `Contact.create` are
indistinguishable — and a decorator that retried a timed-out `create` would create the contact
twice. Only this layer knows the action name, so only this layer can tell the difference. The
retryable set is an allowlist, so a write action added upstream defaults to not retrying.

The same reasoning cuts against the obvious `survos/fetch-bundle` integration, which is worth
writing down because it looks like it should work. `CachingHttpClientFactory` is RFC 9111, and
RFC 9111 does not cache POST — so for a POST-only API it would add a cache that never hits.
fetch-bundle's `ExponentialBackoffRetry` is a good strategy but implements fetch-bundle's own
interface for its own fetcher, not a Symfony HttpClient decorator, and it retries on status alone
— which is exactly the method-blind decision that is unsafe here.

Where fetch-bundle *would* earn its place is bounded concurrency on large exports:
`SymfonyConcurrentFetcher` over the page requests in `all()`, instead of the strictly sequential
loop there now. That is a genuine improvement and is on the list below, not done.

## Mailer is optional

The API client works with no mailer at all. If `framework.mailer` is not configured the two mail
handlers are removed from the container rather than failing to autowire — you can use the client
and `civicrm:count`/`civicrm:peek` without ever touching SMTP. `ExportRequested` will still build
its zip; only the `ExportReady` notification needs somewhere to send from.

## Testing

```bash
cd lib/civicrm-php && ../../vendor/bin/phpunit
cd bu/civicrm-bundle && ../../vendor/bin/phpunit
```

20 tests: 13 on the client, 7 on the bundle. The ones worth knowing about:

- **The wire format.** That params arrive as a JSON *string* in a `params` field, and that the
  key stays in a header and out of the URL. Both are silent when wrong.
- **Errors inside HTTP 200.** A `MockResponse` with status 200 and an `error_message` body must
  raise, not return an empty result.
- **Paging.** `all()` stops on a short page, and on an exactly-full page followed by an empty one.
- **`count()`** strips `limit`, which would otherwise cap the count at the limit.
- **Retry.** A read recovers from a 503; a `create` is attempted exactly once; a 401 is not
  retried; attempts are bounded.
- **The export split.** One `ExportReady` dispatched, a zip that exists at the URL named in it,
  and nested APIv4 values flattened to JSON rather than the string `Array`.
- **The container boots**, in a real kernel, with and without a configured mailer.

## Status, honestly

The unit and container tests pass, but the client has **not yet been run against a live
CiviCRM**. Everything above is written from the APIv4 REST contract and CiviCRM 6.17 source; a
mock proves the client sends what I believe CiviCRM wants, not that CiviCRM agrees. The API key
path is the least certain part: `X-Civi-Auth` needs the `authx` extension enabled *and*
configured to accept that flow, which is not the default everywhere.

Not yet done, in rough order of how much they matter:

- An integration test against a real site. Until then treat the first run as the real test.
- Bounded concurrency on `all()`, via fetch-bundle's `SymfonyConcurrentFetcher`. Paging is
  sequential today, which is fine for a village and slow for a federation. (Retry is done; see
  above for why it is not fetch-bundle's.)
- Honouring a `Retry-After` header when CiviCRM sends one, rather than always using the
  computed backoff.
- A `CiviQueueDrain` message so CiviCRM's own `Civi::queue()` items can be worked by a Messenger
  worker — the piece that would actually *replace* `ShutdownWorker` rather than sit beside it.
  Everything here so far is new work done properly; that one is the migration path for work
  CiviCRM already queues itself.
- Typed DTOs for common entities, instead of associative arrays.
