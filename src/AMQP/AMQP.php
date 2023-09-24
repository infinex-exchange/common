<?php

namespace Infinex\AMQP;

use Infinex\Exceptions\Error;
use Evenement\EventEmitter;
use Bunny\Async\Client;
use function React\Async\await;
use React\Promise\Promise;
use React\Promise\Deferred;

class AMQP extends EventEmitter {
    private $service;
    private $loop;
    private $log;
    private $host;
    private $port;
    private $user;
    private $pass;
    private $vhost;
    private $client;
    private $channel;
    private $callerId;
    private $requests;
    private $timerRetryConn;
    private $connected;
    private $mapQueueToCt;
    
    function __construct(
        $service,
        $loop,
        $log,
        $host,
        $port,
        $user,
        $pass,
        $vhost
    ) {
        $this -> service = $service;
        $this -> loop = $loop;
        $this -> log = $log;
        $this -> host = $host;
        $this -> port = $port;
        $this -> user = $user;
        $this -> pass = $pass;
        $this -> vhost = $vhost;
        $this -> callerId = bin2hex(random_bytes(16));
        $this -> requests = [];
        $this -> connected = false;
        
        $this -> log -> debug('Initialized AMQP stack');
    }
    
    public function start() {
        $th = $this;
        
        $this -> loop -> futureTick(function() use($th) {
            $th -> connect();
        });
        
        $this -> log -> info('Started AMQP');
    }
    
    public function stop() {
        if($this -> timerRetryConn) {
            $this -> loop -> cancelTimer($this -> timerRetryConn);
            $this -> timerRetryConn = null;
        }
        
        if($this -> connected) {
            $this -> connected = false;
            $this -> emit('disconnect');
            await($this -> client -> disconnect());
        }
            
        $this -> log -> info('Stopped AMQP');
    }
    
    public function pub($event, $body = [], $headers = [], $persistent = true) {
        $th = $this;
        
        $headers['event'] = $event;
        $headers['delivery_mode'] = $persistent ? 2 : 1;
        
        $promise = $this -> channel -> publish(
            json_encode($body, JSON_UNESCAPED_SLASHES),
            $headers,
            'infinex'
        ) -> then(
            function($x) use($th) {
                $th -> log -> warn($x);
                return $x;
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Exception in AMQP publish: '.((string) $e));
                $th -> disconnected();
                throw $e;
            }
        );
    }
    
    public function sub($event, $callback, $queue = null, $persistent = null, $headers = []) {
        $th = $this;
        
        if($queue === null)
            $queue = $event;
        
        if($persistent === null)
            $persistent = ($queue == $event);
        
        $headers['event'] = $event;
        
        $promise = $this -> channel -> queueDeclare(
            $queue,
            false,
            true, // durable
            false,
            !$persistent, // autoDelete
        ) -> then(
            function() use($th, $queue, $headers) {
                return $th -> channel -> queueBind(
                    $queue,
                    'infinex',
                    '',
                    false,
                    $headers
                );
            }
        ) -> then(
            function() use($th, $queue, $callback) {
                return $th -> channel -> consume(
                    function($msg) use($th, $callback) {
                        $th -> handleMsg($msg, $callback);
                    },
                    $queue
                );
            }
        ) -> then(
            function($response) use($th, $queue) {
                $th -> mapQueueToCt[$queue] = $response -> consumerTag;
                var_dump($th -> mapQueueToCt);
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Exception in AMQP consume: '.((string) $e));
                $th -> disconnected();
            }
        );
        
        await($promise);
    }
    
    public function unsub($queue) {
        $promise = $this -> channel -> cancel($this -> mapQueueToCt[$queue]);
        await($promise);
        unset($this -> mapQueueToCt[$event]);
    }
    
    public function call($service, $method, $params, $timeout = 3) {
        $requestId = bin2hex(random_bytes(8));
        $deferred = new Deferred();
        $th = $this;
        $timeout = $this -> loop -> addTimer(
            $timeout,
            function() use($th, $requestId, $deferred, $timeout) {
                unset($th -> requests[$requestId]);
                
                $deferred -> reject(
                    new Error('TIMEOUT', 'Request timeout', 500)
                );
                
                $th -> log -> error('Timeout for RPC request '.$requestId);
            }
        );
        $this -> requests[$requestId] = [
            'deferred' => $deferred,
            'timeout' => $timeout
        ];
        
        $headers = [
            'service' => $service,
            'method' => $method,
            'callerId' => $this -> callerId,
            'requestId' => $requestId
        ];
        $this -> pub('rpcRequest', $params, $headers, false);
        
        return $deferred -> promise();
    }
    
    public function method($method, $callback, $modifier = false) {
        $th = $this;
        $this -> sub(
            'rpcRequest',
            function($body, $headers) use($th, $callback, $modifier) {
                $th -> handleRpcRequest($body, $headers, $callback, $modifier);
            },
            'rpc_'.$this -> service.'_'.$method,
            true,
            [
                'service' => $this -> service,
                'method' => $method
            ]
        );
        $this -> log -> info('Registered RPC '.($modifier ? 'modifier' : 'method').' '.$method);
    }
    
    public function modifier($method, $callback) {
        $this -> method($method, $callback, true);
    }
    
    public function unreg($method) {
        $this -> unsub('rpc_'.$this -> service.'_'.$method);
    }
    
    private function connect() {
        $th = $this;
        
        $this -> log -> debug('Trying to establish AMQP connection');
        
        $this -> client = new Client(
            $this -> loop,
            [
                'host' => $this -> host,
                'port' => $this -> port,
                'vhost' => $this -> vhost,
                'user' => $this -> user,
                'password' => $this -> pass
            ]
        );
        
        $this -> client -> connect() -> then(
            function($client) {
                return $client -> channel();
            }
        ) -> then(
            function($channel) use($th) {
                $th -> channel = $channel;
                return $channel -> exchangeDeclare('infinex', 'headers', false, true);
            }
        ) -> then(
            function() use($th) {
                $th -> log -> info('Connected to AMQP');
                
                $th -> sub(
                    'rpcResponse',
                    function($body, $headers) use($th) {
                        $th -> handleRpcResponse($body, $headers);
                    },
                    'rpc_resp_'.$th -> callerId,
                    false,
                    [ 'callerId' => $th -> callerId ]
                );
                $th -> log -> debug('Subscribed to RPC response queue');
                
                $th -> connected = false;
                $th -> mapQueueToCt = [];
                $th -> emit('connect');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('AMQP connection failed: '.((string) $e));
                $th -> timerRetryConn = $th -> loop -> addTimer(
                    1,
                    function() use($th) {
                        $th -> connect();
                    }
                );
            }
        );
    }
    
    private function disconnected() {
        if(! $this -> connected)
            return;
        
        $th = $this;
        
        $this -> connected = false;
        $this -> emit('disconnect');
        $this -> loop -> futureTick(function() use($th) {
            $th -> connect();
        });
        $this -> log -> error('AMQP disconnected');
    }
    
    private function handleMsg($msg, $callback) {
        $body = json_decode($msg -> content, true);
        $headers = $msg -> headers;
                
        $promise = new Promise(
            function($resolve, $reject) use($callback, $body, $headers) {
                $resolve($callback($body, $headers));
            }
        );
        
        $th = $this;
        $promise -> catch(
            function(\Exception $e) use($th, $msg) {
                $th -> log -> error('Rejecting AMQP message: '.((string) $e));
                return $th -> channel -> reject($msg);
            }
        ) -> then(
            function() use($th, $msg) {
                return $th -> channel -> ack($msg);
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Exception in AMQP ack/reject: '.((string) $e));
                $th -> disconnected();
            }
        );
    }
    
    private function handleRpcResponse($body, $headers) {
        if(!isset($headers['requestId'])) {
            $this -> log -> error('Received RPC response without requestId');
            return;
        }
        
        if(!isset($this -> requests[$headers['requestId']])) {
            $this -> log -> warn('Received RPC response for unknown requestId');
            return;
        }
        
        $this -> loop -> cancelTimer($this -> requests[$headers['requestId']]['timeout']);
        
        if(array_key_exists('response', $body)) {
            $this -> requests[$headers['requestId']]['deferred'] -> resolve($body['response']);
        } else if(
            isset($body['error']) &&
            isset($body['error']['status']) &&
            isset($body['error']['code']) &&
            isset($body['error']['msg'])
        ) {
            $this -> requests[$headers['requestId']]['deferred'] -> reject(
                new Error($body['error']['code'], $body['error']['message'], $body['error']['status'])
            );
        } else {
            $this -> requests[$headers['requestId']]['deferred'] -> reject(
                new Error('RESPONSE_CORRUPTED', 'RPC response corrupted')
            );
            
            $this -> log -> error('Received RPC response with invalid response/error structure for requestId '.$headers['requestId']);
        }
        
        unset($this -> requests[$headers['requestId']]);
    }
    
    private function handleRpcRequest($body, $headers, $callback, $modifier = false) {
        if(!isset($headers['callerId']) || !isset($headers['requestId'])) {
            $this -> log -> error('Received RPC request without valid headers');
            return;
        }
        $respHeaders = [
            'callerId' => $header['callerId'],
            'requestId' => $headers['requestId']
        ];
        
        $promise = new Promise(
            function($resolve, $reject) use($callback, $body) {
                $resolve($callback($body));
            }
        );
        
        $th = $this;
        return $promise -> then(
            function($resp) use($th, $modifier, $respHeaders) {
                if(!$modifier) {
                    $th -> pub(
                        'rpcResponse',
                        [
                            'response' => $resp
                        ],
                        $respHeaders,
                        false
                    );
                }
                else if(isset($resp['response'])) {
                    $th -> pub(
                        'rpcResponse',
                        [
                            'response' => $resp['response']
                        ],
                        $respHeaders,
                        false
                    );
                }
                else if(isset($resp['service']) && isset($resp['method']) && isset($resp['body'])) {
                    $respHeaders['service'] = $resp['service'];
                    $respHeaders['method'] = $resp['method'];
                    
                    $th -> pub(
                        'rpcRequest',
                        $resp['body'],
                        $respHeaders,
                        false
                    );
                }
                else {
                    throw new \Exception('Invalid structure returned from modifier callback');
                }
            }
        ) -> catch(
            function(Error $e) use($th, $respHeaders) {
                $th -> pub(
                    'rpcResponse',
                    [
                        'error' => [
                            'code' => $e -> getStrCode(),
                            'msg' => $e -> getMessage(),
                            'status' => $e -> getCode()
                        ]
                    ],
                    $respHeaders,
                    false
                );
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to handle RPC request: '.( (string) $e ));
                throw $e;
            }
        );
    }
}
?>