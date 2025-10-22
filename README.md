# hass-connector
Home Assistant REST and WebSocket api wrappers

These are simple "barebones" clients, they do not provide high level abstraction for Home Assistant, so you must have some knowledge about the APIs, automation triggers and configuring Home Assistant in general.

- REST API - [https://developers.home-assistant.io/docs/api/rest](https://developers.home-assistant.io/docs/api/rest)
- WebSocket API - [https://developers.home-assistant.io/docs/api/websocket](https://developers.home-assistant.io/docs/api/websocket)

Responses are awaited using [ReactPHP Promises](https://reactphp.org/promise/).
Data comes in and out mostly as `\stdClass` objects and has the same structure as seen in various Home Assistant documentation and examples.

An example state object from `print_r()`
```
stdClass Object
(
    [entity_id] => input_boolean.test_toggle
    [state] => off
    [attributes] => stdClass Object
        (
            [editable] => 1
            [friendly_name] => Test Toggle
        )

    [last_changed] => 2025-10-21T13:09:18.481787+00:00
    [last_reported] => 2025-10-21T13:09:18.481787+00:00
    [last_updated] => 2025-10-21T13:09:18.481787+00:00
    [context] => stdClass Object
        (
            [id] => XXXXXXXXXXXXXXXXXXXXXXXXXX
            [parent_id] =>
            [user_id] => xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
        )

)
```

## REST Client
Class `SharkyDog\HASS\ClientREST`
```php
public function __construct(string $url, string $token);
public function setDefaultTimeout(int $timeout);
public function setDefaultSilent(bool $silent);

public function GET(
    string $endpoint,
    ?int $timeout=null,
    ?bool $silent=null
): Promise\PromiseInterface;

public function POST(
    string $endpoint,
    ?\stdClass $data=null,
    ?int $timeout=null,
    ?bool $silent=null
): Promise\PromiseInterface;
```
- `$url` is the base rest api url - `http://192.168.1.123:8123/api`
- `$endpoint` is path after `/api/` in docs above - `states/<entity_id>`
- `$timeout` is in seconds, can be 0 to wait forever, default 60s
- `$data` is a `\stdClass` with the same structure as required for the endpoint, shown as json in docs
- `$silent` will silence errors, `catch(fn()=>null)` will be used on the promise if `$silent==true`,
  so the returned promise will be resolved with `null` on error, default `false`

Example
```php
use SharkyDog\HASS;

$url = 'http://192.168.1.123:8123/api';
$token = 'xxxxxxx';
$hass = new HASS\ClientREST($url, $token);

// === GET ===
$promise = $hass->GET('states/input_boolean.test_toggle');
$promise = $promise->then(function($result) {
    print_r($result);
});
$promise = $promise->catch(function(\Exception $e) {
    print_r(['GET error', $e->getMessage(), $e->getCode()]);
});
// cancelling the promise will abort the request
//$promise->cancel();

// === POST ===
$hass->POST(
    'template',
    (object)['template' => 'Toggle is {{ states("input_boolean.test_toggle") }}']
)->then(function($result) {
  print_r($result);
})->catch(function(\Exception $e) {
  print_r(['POST error', $e->getMessage(), $e->getCode()]);
});
```
First (GET) will print the state object like shown above. Second (POST) renders a template and will print plain text.

## WebSocket Client
Extends `SharkyDog\HTTP\Helpers\WsClientDecorator`, which decorates `SharkyDog\HTTP\WebSocket\Client` from [sharkydog/http](https://github.com/sharkydog/http) package.
Some methods, events and reconnect logic are inherited from these classes.
See the [websocket client example](https://github.com/sharkydog/http/blob/main/examples/08-websocket-client.php)

Class `SharkyDog\HASS\ClientWS`
```php
public function __construct(string $url, string $token);
public function setDefaultTimeout(int $timeout);
public function setDefaultSilent(bool $silent);
public function connected(): bool;

public function subscribe(callable $callback, \stdClass $data): int;
public function subscribeEvent(callable $callback, string $event): int;
public function subscribeTrigger(callable $callback, \stdClass ...$triggers): int;
public function unsubscribe(int $sid): Promise\PromiseInterface;

public function sendCommand(
    \stdClass $data,
    ?int $timeout=null,
    ?bool $silent=null
): Promise\PromiseInterface;

public function fireEvent(
    string $event,
    ?\stdClass $data=null
): Promise\PromiseInterface;

public function callService(
    string $service,
    ?\stdClass $target=null,
    ?\stdClass $data=null,
    bool $resp=false
): Promise\PromiseInterface;
```
- Timeout and silencing errors work the same as in the REST client
- Subscribe methods return an id to be used in `unsubscribe()`, these can also be used when client is not connected.
  Subscriptions will be reestablished on reconnect.
- `connected()` will return `true` only after auth phase passed
  - There is also `open` event, more on that bellow
- Sending commands is possible only when connected, they will reject otherwise.
  Except `unsubscribe()`, when offline it will remove the subscription and resolve with `null`

### Events
Events are emitted using [Evenement\EventEmitter](https://github.com/igorw/evenement/tree/v3.0.2)
```php
$emitter->on('event', function(...$args) {
    // stuff
});
```
`event` [`parameter1`, `parameter2`, ...]
- `open`
  - Client connected and auth phase passed
- `close` [`bool $reconnect`]
  - Connection close, will try to reconnect if `$reconnect==true`, all pending commands will be rejected
- `subscribed` [`int $sid`]
  - Subscription with id `$sid` registered
- `error-auth` [`string $message`]
  - Error in auth phase, connection will be closed
- `error-subscribe` [`\Exception $e`, `int $sid`]
  - Subscription with id `$sid` failed

### subscribe()
Create a general subscription.
Besides `subscribe_events` and `subscribe_trigger`, there are other undocumented types, this method allows subscribing for such messages. Find out types and message structure by peeking into communication between Home Assistant frontend and core using your browser and a developer console.

Monitor entity state
```php
$entity = 'input_boolean.test_toggle';
$sid = $hassWS->subscribe(function($data) use($entity) {
    $states = $data->event->states->$entity;
    print_r([
        date('Y-m-d\TH:i:s', (int)$states[0]->lu),
        $states[0]->s
    ]);
}, (object)[
    'type' => 'history/stream',
    'entity_ids' => [$entity],
    'start_time' => gmdate('Y-m-d\TH:i:s\Z'),
    'minimal_response' => true,
    'significant_changes_only' => false,
    'no_attributes' => true
]);
```

### subscribeEvent()

TBC...
