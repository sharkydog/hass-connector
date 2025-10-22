<?php
namespace SharkyDog\HASS;
use SharkyDog\HTTP\Timer;
use SharkyDog\HTTP\Helpers\WsClientDecorator;
use React\Promise;

class ClientWS extends WsClientDecorator {
  public ?string $hassVer = null;
  private $_authToken = '';
  private $_authOk = false;
  private $_cmdId = 0;
  private $_cmdTmo = 60;
  private $_cmdSent = [];
  private $_cmdSilent = false;
  private $_listen = [];
  private $_listenId = [];

  public function __construct(string $url, string $token) {
    parent::__construct($url);
    $this->_authToken = $token;
  }

  protected function _event_open() {
    if(!$this->_authOk) {
      $this->_send((object)[
  			'type' => 'auth',
  			'access_token' => $this->_authToken
  		]);
      return;
    }

    foreach($this->_listen as $id => $listen) {
      $this->_subscribeSend($listen['d'], $id);
    }

    $this->_emit('open');
  }

  protected function _event_message(string $msg) {
    if(!($msg = json_decode($msg))) {
      return;
    }

    if(!$this->_authOk) {
      $type = $msg->type ?? null;

      if($type == 'auth_ok') {
        $this->_authOk = true;
        $this->hassVer = $msg->ha_version;

        $this->_sendCmd((object)[
          'type' => 'supported_features',
          'features' => (object)[
            'coalesce_messages' => 1
          ]
        ], 0, true);

        $this->_emit('open');
      } else if($type == 'auth_invalid') {
        $this->_emit('error-auth', [$msg->message]);
        $this->close();
      } else if(!$type) {
        $this->_emit('error-auth', ['Invalid auth message, missing "type"']);
        $this->close();
      }

      return;
    }

    foreach((is_array($msg) ? $msg : [$msg]) as $_msg) {
      $this->_recv($_msg);
    }
  }

  protected function _event_close(bool $reconnect) {
    $this->_authOk = false;
    $this->_cmdId = 0;
    $this->_listenId = [];

    $e = new \Exception('Connection closed');
    foreach(array_keys($this->_cmdSent) as $id) {
      $this->_rejectCmd($id, $e);
    }

    $this->_emit('close', [$reconnect]);
  }

  public function send() {
  }

  private function _recv($msg) {
    if(!($id = $msg->id??null)) {
      return;
    }

    if(isset($this->_listenId[$id])) {
      ($this->_listen[$this->_listenId[$id]]['c'])($msg);
      return;
    }

    if(!isset($this->_cmdSent[$id])) {
      return;
    }

    if($error = $msg->error??null) {
      $this->_rejectCmd($id, new \Exception('('.$error->code.') '.$error->message));
      return;
    }

    $def = $this->_cmdSent[$id]['def'];
    $this->_cmdSent[$id]['tmr']->cancel();
    unset($this->_cmdSent[$id]);

    $def->resolve($msg);
  }

  private function _send($msg) {
    $this->ws->send(json_encode($msg));
  }

  private function _sendCmd($data, $timeout=null, $silent=null) {
    if(!$this->connected()) {
      return Promise\reject(new \Exception('Not connected'));
    }

    $id = $data->id = ++$this->_cmdId;
    $timeout = $timeout!==null ? max(0, $timeout) : $this->_cmdTmo;

    $this->_cmdSent[$id] = [
      'def' => new Promise\Deferred(function() use($id) {
        if(!isset($this->_cmdSent[$id])) return;
        $this->_cmdSent[$id]['tmr']->cancel();
        unset($this->_cmdSent[$id]);
      }),
      'tmr' => !$timeout ? Timer::noop() : new Timer($timeout, function() use($id) {
        $this->_rejectCmd($id, new \Exception('Timeout'));
      })
    ];

    $this->_send($data);

    $pr = $this->_cmdSent[$id]['def']->promise();

    if($silent ?? $this->_cmdSilent) {
      $pr = $pr->catch(fn()=>null);
    }

    return $pr;
  }

  private function _rejectCmd($id, $e) {
    if(!isset($this->_cmdSent[$id])) {
      return;
    }

    $def = $this->_cmdSent[$id]['def'];
    $this->_cmdSent[$id]['tmr']->cancel();
    unset($this->_cmdSent[$id]);

    $def->reject($e);
  }

  private function _subscribeSend($data, $sid) {
    $pr = $this->_sendCmd($data);

    $pr = $pr->then(function($msg) use($sid) {
      $this->_listenId[$msg->id] = $sid;
      $this->_emit('subscribed', [$sid]);
    });

    $pr = $pr->catch(function(\Exception $e) use($sid) {
      $this->_emit('error-subscribe', [$e,$sid]);
    });
  }

  public function setDefaultTimeout(int $timeout) {
    $this->_cmdTmo = max(0, $timeout);
  }

  public function setDefaultSilent(bool $silent) {
    $this->_cmdSilent = $silent;
  }

  public function connected(): bool {
    return $this->_authOk;
  }

  public function subscribe(callable $callback, \stdClass $data): int {
    $sid = (int)array_key_last($this->_listen) + 1;

    $this->_listen[$sid] = [
      'd' => $data,
      'c' => $callback
    ];

    if($this->connected()) {
      $this->_subscribeSend($this->_listen[$sid]['d'], $sid);
    }

    return $sid;
  }

  public function sendCommand(\stdClass $data, ?int $timeout=null, ?bool $silent=null): Promise\PromiseInterface {
    return $this->_sendCmd($data, $timeout, $silent)->then(fn($msg) => $msg->result??null);
  }

  public function subscribeEvent(callable $callback, string $event): int {
    return $this->subscribe(
      function($msg) use($callback) {
        $callback($msg->event->data, $msg->event->time_fired);
      },
      (object)[
        'type' => 'subscribe_events',
        'event_type' => $event
      ]
    );
  }

  public function subscribeTrigger(callable $callback, \stdClass ...$triggers): int {
    return $this->subscribe(
      function($msg) use($callback) {
        $callback($msg->event->variables);
      },
      (object)[
        'type' => 'subscribe_trigger',
        'trigger' => count($triggers)>1 ? $triggers : $triggers[0]
      ]
    );
  }

  public function unsubscribe(int $sid): Promise\PromiseInterface {
    if(!isset($this->_listen[$sid])) {
      return Promise\reject(new \Exception('Subscription '.$sid.' not found'));
    }

    if($this->connected() && ($cid = array_search($sid, $this->_listenId))) {
      return $this->sendCommand((object)[
        'type' => 'unsubscribe_events',
        'subscription' => $cid
      ])->finally(function() use($cid,$sid) {
        unset($this->_listenId[$cid]);
        unset($this->_listen[$sid]);
      });
    }

    unset($this->_listen[$sid]);
    return Promise\resolve(null);
  }

  public function fireEvent(string $event, ?\stdClass $data=null): Promise\PromiseInterface {
    $edata = (object)[
      'type' => 'fire_event',
      'event_type' => $event
    ];

    if($data) {
      $edata->event_data = $data;
    }

    return $this->sendCommand($edata);
  }

  public function callService(string $service, ?\stdClass $target=null, ?\stdClass $data=null, bool $resp=false): Promise\PromiseInterface {
    $service = explode('.', $service);

    if(!isset($service[0], $service[1])) {
      return Promise\reject(new \Exception('No domain and/or service found in '.$service));
    }

    $sdata = (object)[
      'type' => 'call_service',
      'domain' => $service[0],
      'service' => $service[1]
    ];

    if($target) {
      $sdata->target = $target;
    }
    if($data) {
      $sdata->service_data = $data;
    }
    if($resp) {
      $sdata->return_response = true;
    }

    return $this->sendCommand($sdata)->then(function($result) {
      return $result->response ?? null;
    });
  }
}
