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
    private $logger;
    private $rmq;
    private $channel;
    private $callerId;
    private $requests;
    
    function __construct($loop, $logger) {
        $this -> loop = $loop;
        $this -> logger = $logger;
        $this -> callerId = bin2hex(random_bytes(16));
        
        $th = $this;
        $loop -> futureTick(function() use($th) {
            $th -> connect();
        });
        
        $this -> logger -> debug('Initialized AMQP stack');
    }
    
    public function connect() {
        try {
            $this -> logger -> debug('Trying to establish AMQP connection');
            
            $this -> rmq = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS);
            $this -> channel = $this -> rmq -> channel();
            $this -> channel -> exchange_declare('infinex', AMQPExchangeType::HEADERS, false, true); // durable
            $this -> channel -> basic_qos(null, 1, null);
            $this -> logger -> info('Connected to AMQP');
            
            $th = $this;
            $this -> sub(
                'rpc_response',
                function($body, $headers) use($th) {
                    $th -> handleRpcResponse($body, $headers);
                },
                'rpc_resp_'.$this -> callerId,
                [ 'callerId' => $this -> callerId ]
            );
            $this -> logger -> info('Subscribed to RPC response queue');
            
            $this -> loop -> addPeriodicTimer(0.0001, function () use ($th) {
                $th -> channel -> wait(null, true);
            });
        }
        catch(Exception $e) {
            $this -> logger -> error($e -> getMessage());
        }
    }
    
    public function pub($event, $body = [], $headers = []) {
        $headers['event'] = $event;
        
        $msg = new AMQPMessage(json_encode($body, JSON_PRETTY_PRINT));
        $msg -> set('application_headers', new Wire\AMQPTable($headers));
        $this -> channel -> basic_publish($msg, 'infinex');
    }
    
    public function sub($event, $callback, $queue, $headers = []) {
        $headers['event'] = $event;
        $this -> channel -> queue_declare($queue, false, false, false, true); // auto delete
        $this -> channel -> queue_bind($queue, 'infinex', '', false, new Wire\AMQPTable($headers));
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
    
    public function method() {
    }
    
    public function modifier() {
    }
    
    private function handleMsg($msg, $callback) {
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
                $th -> logger -> error('Rejected AMQP message: '.((string) $e));
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
        
        if(isset($body -> response)) {
            $this -> requests[$headers['requestId']]['deferred'] -> resolve($body -> response);
        } else if(isset($body -> error) && isset($body -> error -> code) && isset($body -> error -> msg)) {
            $this -> requests[$headers['requestId']]['deferred'] -> reject(
                new RPCException($body -> error -> code, $body -> error -> message)
            );
        } else {
            $this -> requests[$headers['requestId']]['deferred'] -> reject(
                new RPCException('INVALID_RESPONSE', 'Received RPC response with invalid response/error structure')
            );
        }
        
        unset($this -> requests[$headers['requestId']]);
    }
}
?>