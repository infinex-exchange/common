<?php

namespace Infinex\AMQP;

use Evenement\EventEmitter;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire;
use React\Promise\Promise;
use React\Promise\Deferred;

class AMQP extends EventEmitter {
    private $loop;
    private $log;
    private $rmq;
    private $channel;
    private $callerId;
    private $requests;
    private $waitTimer;
    
    function __construct($loop, $log) {
        $this -> loop = $loop;
        $this -> log = $log;
        $this -> callerId = bin2hex(random_bytes(16));
        
        $th = $this;
        $this -> on('disconnect', function() use($th) {
            $th -> start();
        });
        
        $this -> log -> debug('Initialized AMQP stack');
    }
    
    public function start() {
        $th = $this;
        $loop -> futureTick(function() use($th) {
            $th -> connect();
        });
    }
    
    private function connect() {
        try {
            $this -> log -> debug('Trying to establish AMQP connection');
            
            $this -> rmq = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS);
            $this -> channel = $this -> rmq -> channel();
            $this -> channel -> exchange_declare('infinex', AMQPExchangeType::HEADERS, false, true); // durable
            $this -> channel -> basic_qos(null, 1, null);
            $this -> log -> info('Connected to AMQP');
            
            $th = $this;
            $this -> waitTimer = $this -> loop -> addPeriodicTimer(0.0001, function () use ($th) {
                try {
                    $th -> channel -> wait(null, true);
                }
                catch(Exception $e) {
                    $th -> loop -> cancelTimer($th -> waitTimer);
                    $th -> emit('disconnect');
                }
            });
            
            $this -> sub(
                'rpc_response',
                function($body, $headers) use($th) {
                    $th -> handleRpcResponse($body, $headers);
                },
                'rpc_resp_'.$this -> callerId,
                [ 'callerId' => $this -> callerId ]
            );
            $this -> log -> info('Subscribed to RPC response queue');
            
            $this -> emit('connect');
        }
        catch(Exception $e) {
            $this -> log -> error($e -> getMessage());
            
            $loop -> addTimer(
                function() use($th) {
                    $th -> connect();
                },
                1
            );
        }
    }
    
    public function pub($event, $body = [], $headers = []) {
        $headers['event'] = $event;
        
        $msg = new AMQPMessage(json_encode($body, JSON_PRETTY_PRINT));
        $msg -> set('application_headers', new \Wire\AMQPTable($headers));
        
        $this -> channel -> basic_publish($msg, 'infinex');
    }
    
    public function sub($event, $callback, $queue, $headers = []) {
        $headers['event'] = $event;
        
        $this -> channel -> queue_declare($queue, false, false, false, true); // auto delete
        $this -> channel -> queue_bind($queue, 'infinex', '', false, new \Wire\AMQPTable($headers));
        $th = $this;
        $this -> channel -> basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function($msg) use($th, $callback) {
                $th -> handleMessage($msg, $callback);
            }
        );
    }
    
    public function call($method, $params, $timeout = 3) {
        $requestId = bin2hex(random_bytes(8));
        $deferred = new Deferred();
        $th = $this;
        $timeout = $this -> loop -> addTimer(
            function() use($th, $requestId, $deferred) {
                unset($th -> requests[$requestId]);
                
                $deferred -> reject(
                    new RPCException('TIMEOUT', "The callee did not respond within $timeout seconds")
                );
                
                $th -> log -> error('Timeout for RPC request '.$requestId);
            },
            $timeout
        );
        $this -> requests[$requestId] = [
            'deferred' => $deferred,
            'timeout' => $timeout
        ];
        
        $headers = [
            'callerId' => $this -> callerId,
            'requestId' => $requestId
        ];
        $this -> pub($method, $params, $headers);
        
        return $deferred -> promise();
    }
    
    public function method($method, $callback) {
        $th = $this;
        $this -> sub(
            $method,
            function($body, $headers) use($th) {
                $th -> handleRpcRequest($body, $headers, $callback);
            },
            $method
        );
        $this -> log -> info('Registered RPC method '.$method);
    }
    
    public function modifier($method, $callback) {
        $th = $this;
        $this -> sub(
            $method,
            function($body, $headers) use($th) {
                $th -> handleRpcRequest($body, $headers, $callback, true);
            },
            $method
        );
        $this -> log -> info('Registered RPC modifier '.$method);
    }
    
    public function handleMsg($msg, $callback) {
        $body = json_decode($msg -> body);
        $headers = $msg -> get('application_headers') -> getNativeData();
                
        $promise = new Promise(
            function($resolve, $reject) use($callback, $body, $headers) {
                $resolve($callback($body, $headers));
            }
        );
        
        $th = $this;
        $promise -> then(
            function() use($msg) {
                $msg -> ack();
            }
        ) -> catch(
            function(Exception $e) use($msg, $th) {
                $msg -> reject(true);
                $th -> log -> error('Rejected AMQP message: '.((string) $e));
            }
        );
    }
    
    public function handleRpcResponse($body, $headers) {
        if(!isset($headers['requestId'])) {
            $this -> log -> error('Received RPC response without requestId');
            return;
        }
        
        if(!isset($this -> requests[$headers['requestId']])) {
            $this -> log -> warn('Received RPC response for unknown requestId');
            return;
        }
        
        $this -> loop -> cancelTimer($this -> requests[$headers['requestId']]['timeout']);
        
        if(isset($body -> response)) {
            $this -> requests[$headers['requestId']]['deferred'] -> resolve($body -> response);
        } else if(isset($body -> error) && isset($body -> error -> code) && isset($body -> error -> msg)) {
            $this -> requests[$headers['requestId']]['deferred'] -> reject(
                new RPCException($body -> error -> code, $body -> error -> message)
            );
        } else {
            $this -> requests[$headers['requestId']]['deferred'] -> reject(
                new RPCException('INVALID_RESPONSE', 'Invalid response')
            );
            
            $this -> log -> error('Received RPC response with invalid response/error structure for requestId '.$headers['requestId']);
        }
        
        unset($this -> requests[$headers['requestId']]);
    }
    
    public function handleRpcRequest($body, $headers, $callback, $modifier = false) {
        if(!isset($headers['callerId']) || !isset($headers['requestId'])) {
            $this -> log -> error('Received RPC request without valid headers');
            return;
        }
        
        $promise = new Promise(
            function($resolve, $reject) use($callback, $body) {
                $resolve($callback($body));
            }
        );
        
        $th = $this;
        return $promise -> then(
            function($resp) use($th, $modifier) {
                if(!$modifier) {
                    $th -> pub(
                        'rpc_response',
                        [
                            'response' => $resp
                        ],
                        $headers
                    );
                }
                else {
                    $th -> pub(
                        $resp['method'],
                        $resp['body'],
                        $headers
                    );
                }
            }
        ) -> catch(
            function(RPCException $e) use($th) {
                $th -> pub(
                    'rpc_response',
                    [
                        'error' => [
                            'code' => $e -> getStrCode(),
                            'msg' => $e -> getMessage()
                        ]
                    ],
                    $headers
                );
            }
        ) -> catch(
            function(Exception $e) use($th) {
                $th -> log -> error('Failed to handle RPC request: '.( (string) $e ));
                throw $e;
            }
        );
    }
}
?>