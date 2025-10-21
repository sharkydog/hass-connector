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
public function GET(string $endpoint, ?int $timeout=null): React\Promise\PromiseInterface;
public function POST(string $endpoint, ?\stdClass $data=null, ?int $timeout=null): React\Promise\PromiseInterface;
```
- $url is the base rest api url - `http://192.168.1.123:8123/api`
- $endpoint is path after `/api/` in docs above - `states/<entity_id>`
- $timeout is in seconds, can be 0 to wait forwever
- $data is a `\stdClass` with the same structure as required for the endpoint, shown as json in docs

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
    'states/input_boolean.test_toggle',
    (object)['state' => 'on']
)->then(function($result) {
  print_r($result);
})->catch(function(\Exception $e) {
  print_r(['POST error', $e->getMessage(), $e->getCode()]);
});
```
Both will print the state object like shown above.

## WebSocket Client
Class `SharkyDog\HASS\ClientWS`


TBC...
