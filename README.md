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
- `fireEvent()` and `callService()`, do not have `$timeout` and `$silent` parameters. They will use default values.

```php
use SharkyDog\HASS;

$url = 'http://192.168.1.123:8123/api';
$token = 'xxxxxxx';
$hassWS = new HASS\ClientWS($url, $token);
// reconnects are disabled by default
// this will tell the client to reconnect 10 seconds after remote connection close
$hassWS->reconnect(10);

// client event
$hassWS->on('open', function() use($hassWS) {
    print_r(['open',$hassWS->hassVer]);
    // send commands, fire events, call services
});

// setup subscriptions
// will also work after connect() call
// as subscriptions will be send after connect or reconnect
// and client will not transition to connected
// until execution is passed to the event loop
// and connection is established and auth succeeds

$hassWS->connect();
```

### Events
Events are emitted using [Evenement\EventEmitter](https://github.com/igorw/evenement/tree/v3.0.2)

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
Besides `subscribe_events` and `subscribe_trigger`, there are other undocumented types, this method allows subscribing for such messages.
Find out types and message structure by peeking into communication between Home Assistant frontend and core using your browser's developer console.

Monitor entity state
```php
$entity = 'input_boolean.test_toggle';
$sid = $hassWS->subscribe(function(\stdClass $data) use($entity) {
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
Calls `subscribe()` for type `subscribe_events` and supplied `event_type`.

Callback receives `$data->event->data` and `$data->event->time_fired`.

```php
$sid1 = $hassWS->subscribeEvent(function(\stdClass $event_data, string $time_fired) {
    print_r(['event test', $event_data, $time_fired]);
}, 'test');
```

### subscribeTrigger()
Calls `subscribe()` for type `subscribe_trigger` and supplied triggers.

Triggers are `\stdClass` object with the same structure as automation triggers.
Callback receives `$data->event->variables`.

```php
$sid2 = $hassWS->subscribeTrigger(
    function($vars) {
        print_r([
            $vars->trigger->id,
            $vars->trigger->entity_id,
            $vars->trigger->from_state->state,
            $vars->trigger->to_state->state
        ]);
    },
    (object)[
        'id' => 'tg1',
        'platform' => 'state',
        'entity_id' => 'input_boolean.test_toggle',
        'from' => 'off',
        'to' => 'on'
    ],
    (object)[
        'id' => 'tg2',
        'platform' => 'state',
        'entity_id' => ['input_boolean.test_toggle','input_boolean.test_toggle_2'],
        'to' => ['on','off'],
        'for' => '00:00:02'
    ]
);
```

### unsubscribe()
Remove a subscription
```php
// this returns a promise
// use either setDefaultSilent() or attach rejection handlers
// to avoid unhandled rejections if unsubscribe fails
$hassWS->unsubscribe($sid1);
// silence this one
$hassWS->unsubscribe($sid2)->catch(fn()=>null);
```

### sendCommand()
Send a command, overwrite default timeout and silence option.
```php
$promise = $hassWS->sendCommand((object)[
    'type' => 'auth/current_user'
], 5, true);
$promise->then(function(?\stdClass $result) {
    print_r($result);
})->catch(function(\Exception $e) {
    print_r(['user err', $e->getMessage(), $e->getCode()]);
});
```

### fireEvent()
Fire event `test`.
```php
$promise = $hassWS->fireEvent('test', (object)[
    'a' => 'b',
    'c' => [
        (object)['d'=>'e'],
        (object)['f'=>'g', 'h'=>'i']
    ]
]);
```
In Home Assistant developer tools, it would look like this in yaml
```yaml
event_type: test
data:
  a: b
  c:
    - d: e
    - f: g
      h: i
```

### callService()
Call a service with response.
For services that do not return response, the 4th parameter must be `false` (default).
Home Assistant will return and error when calling a service and expecting response, but the service do not return one.
```php
$hassWS->callService(
    'media_player.browse_media',
    (object)[
        'entity_id' => 'media_player.player_that_can_browse_media'
    ],
    (object)[
        'media_content_id' => 'media-source://media_source'
    ],
    true
)->then(function(?\stdClass $response) {
    print_r($response);
})->catch(function(\Exception $e) {
    print_r(['svc err', $e->getMessage(), $e->getCode()]);
});
```
