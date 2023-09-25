<?php

namespace Infinex\AMQP;

use Infinex\Exceptions\Error;
use Bunny\Async\Client;
use React\Promise;

class AMQP {
    private $service;
    private $loop;
    private $log;
    
    private $callerId;
    private $requests;
    private $mapQueueToCt;
    private $startDeferred;
    private $client;
    private $channel;
    private $timerRetryConn;
    
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
        
        $this -> callerId = bin2hex(random_bytes(16));
        $this -> requests = [];
        $this -> mapQueueToCt = [];
        
        $this -> client = new Client(
            $this -> loop,
            [
                'host' => $host,
                'port' => $port,
                'vhost' => $vhost,
                'user' => $user,
                'password' => $pass
            ]
        );
        
        $this -> log -> debug('Initialized AMQP client');
    }
    
    public function start() {
        if($this -> startDeferred !== null)
            return $this -> startDeferred -> promise();
        
        $this -> startDeferred = new Promise\Deferred();
        $this -> connect();
        return $this -> startDeferred -> promise();
    }
    
    public function stop() {
        $th = $this;
        
        if($this -> timerRetryConn !== null) {
            $this -> loop -> cancelTimer($this -> timerRetryConn);
            return Promise\resolve(null);
        }
        
        return $this -> client -> disconnect() -> then(
            function() use($th) {
                $th -> log -> info('Stopped AMQP client');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed stopping AMQP client: '.((string) $e));
            }
        );
    }
    
    public function pub($event, $body = [], $headers = [], $persistent = true) {
        $th = $this;
        
        $headers['event'] = $event;
        $headers['delivery_mode'] = $persistent ? 2 : 1;
        
        return $this -> channel -> publish(
            json_encode($body, JSON_UNESCAPED_SLASHES),
            $headers,
            'infinex'
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Exception in AMQP publish: '.((string) $e));
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
        
        return $this -> channel -> queueDeclare(
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
            function($response) use($th, $queue, $event) {
                $th -> mapQueueToCt[$queue] = $response -> consumerTag;
                $th -> log -> debug("Subscribed to $event -> $queue");
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Exception in AMQP subscribe: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function unsub($queue) {
        $th = $this;
        
        return $this -> channel -> cancel($this -> mapQueueToCt[$queue]) -> then(
            function() use($th, $queue) {
                unset($this -> mapQueueToCt[$queue]);
                $th -> log -> debug("Unsubscribed from $queue");
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Exception in AMQP unsubscribe: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function call($service, $method, $params, $timeout = 3) {
        $requestId = bin2hex(random_bytes(8));
        $deferred = new Promise\Deferred();
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
        return $this -> pub('rpcRequest', $params, $headers, false) -> then(
            function() use($deferred) {
                return $deferred -> promise();
            }
        );
    }
    
    public function method($method, $callback, $modifier = false) {
        $th = $this;
        
        return $this -> sub(
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
        ) -> then(
            function() use($th, $modifier, $method) {
                $th -> log -> info('Registered RPC '.($modifier ? 'modifier' : 'method').' '.$method);
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed register RPC '.($modifier ? 'modifier' : 'method').' '.$method.': '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function modifier($method, $callback) {
        return $this -> method($method, $callback, true);
    }
    
    public function unreg($method) {
        $th = $this;
        
        return $this -> unsub('rpc_'.$this -> service.'_'.$method) -> then(
            function() use($th, $method) {
                $th-> log -> info('Unregistered RPC '.$method);
            }
        ) -> catch(
            function($e) use($th, $method) {
                $th -> log -> error('Failed register RPC '.$method.': '.((string) $e));
                throw $e;
            }
        );
    }
    
    private function connect() {
        $th = $this;
        
        $this -> log -> debug('Connecting to AMQP server');
        
        $this -> timerRetryConn = null;
        
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
                $th -> log -> info('Connected to AMQP server');
                return $th -> sub(
                    'rpcResponse',
                    function($body, $headers) use($th) {
                        $th -> handleRpcResponse($body, $headers);
                    },
                    'rpc_resp_'.$th -> callerId,
                    false,
                    [ 'callerId' => $th -> callerId ]
                );
            }
        ) -> then(
            function() use($th) {
                $th -> log -> debug('Subscribed to RPC response queue');
                $th -> startDeferred -> resolve(null);
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
    
    private function handleMsg($msg, $callback) {
        $body = json_decode($msg -> content, true);
        $headers = $msg -> headers;
                
        $promise = new Promise\Promise(
            function($resolve, $reject) use($callback, $body, $headers) {
                $resolve($callback($body, $headers));
            }
        );
        
        $th = $this;
        $promise -> catch(
            function($e) use($th, $msg) {
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
            'callerId' => $headers['callerId'],
            'requestId' => $headers['requestId']
        ];
        
        $promise = new Promise\Promise(
            function($resolve, $reject) use($callback, $body) {
                $resolve($callback($body));
            }
        );
        
        $th = $this;
        return $promise -> then(
            function($resp) use($th, $modifier, $respHeaders) {
                if(!$modifier) {
                    return $th -> pub(
                        'rpcResponse',
                        [
                            'response' => $resp
                        ],
                        $respHeaders,
                        false
                    );
                }
                else if(isset($resp['response'])) {
                    return $th -> pub(
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
                    
                    return $th -> pub(
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
                return $th -> pub(
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
                $th -> log -> error('Failed to handle RPC request: '.((string) $e));
                throw $e;
            }
        );
    }
}
?>