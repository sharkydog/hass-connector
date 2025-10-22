<?php
namespace SharkyDog\HASS;
use SharkyDog\HTTP;
use React\Promise;

class ClientREST {
  private $_client = null;
  private $_reqTmo = 60;
  private $_reqSilent = false;

  public function __construct(string $url, string $token) {
    $this->_client = new HTTP\Client($url, ['Authorization' => 'Bearer '.$token]);
    $this->_client->path(rtrim($this->_client->path(),'/').'/');
  }

  public function setDefaultTimeout(int $timeout) {
    $this->_reqTmo = max(0, $timeout);
  }

  public function setDefaultSilent(bool $silent) {
    $this->_reqSilent = $silent;
  }

  private function _request($request, $timeout, $silent) {
    $timer = HTTP\Timer::noop();
    $timeout = $timeout!==null ? max(0, $timeout) : $this->_reqTmo;
    $aborted = false;

    $deferred = new Promise\Deferred(function() use($request,&$timer,&$aborted) {
      $aborted = true;
      $timer->cancel();
      $request->abort();
    });

    if($timeout) {
      $timer = new HTTP\Timer($timeout, function() use($request,$deferred,&$aborted) {
        $aborted = true;
        $request->abort();
        $deferred->reject(new \Exception('Timeout'));
      });
    }

    $request->on('response', function($response) use($deferred) {
      if(($status=$response->getStatus()) != 200 && $status != 201) {
        $msg = HTTP\Response::$statusMsg[$status] ?? '';
        $msg = 'HTTP '.$status.($msg ? ' '.$msg : '');
        $deferred->reject(new \Exception($msg, $status));
        return;
      }

      $body = $response->getBody();
      $json = preg_match('/^application\/json/i', $response->getHeader('Content-Type')??'');

      $deferred->resolve($json ? json_decode($body) : $body);
    });

    $request->on('error', function($message) use($deferred) {
      $deferred->reject(new \Exception($message));
    });

    $request->on('close', function() use($deferred,&$aborted) {
      if($aborted) return;
      $deferred->reject(new \Exception('Connection refused'));
    });

    $pr = $deferred->promise();

    if($silent ?? $this->_reqSilent) {
      $pr = $pr->catch(fn()=>null);
    }

    return $pr->finally(function() use($timer) {
      $timer->cancel();
    });
  }

  public function GET(string $endpoint, ?int $timeout=null, ?bool $silent=null): Promise\PromiseInterface {
    return $this->_request($this->_client->GET($this->_client->path().$endpoint), $timeout, $silent);
  }

  public function POST(string $endpoint, ?\stdClass $data=null, ?int $timeout=null, ?bool $silent=null): Promise\PromiseInterface {
    return $this->_request($this->_client->POST(
      $data ? json_encode($data) : '',
      $this->_client->path().$endpoint,
      $data ? ['Content-Type' => 'application/json'] : []
    ), $timeout, $silent);
  }
}
