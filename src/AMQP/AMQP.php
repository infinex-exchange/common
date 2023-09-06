<?php

namespace Infinex\AMQP;

use Evenement\EventEmitter;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire;
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
            $this -> channel -> basic_qos(null, 1, null);
            $this -> logger -> info('Connected to AMQP');
            
            $this -> channel -> exchange_declare('infinex', AMQPExchangeType::HEADERS, false, true); // durable
            // sub to rpc callback
            $this -> logger -> info('Subscribed to RPC response queue');
            
            $th = $this;
            $this -> loop -> addPeriodicTimer(0.0001, function () use ($th) {
                $th -> channel -> wait(null, true);
            });
        }
        catch(Exception $e) {
            $this -> logger -> error($e -> getMessage());
            sleep(1);
        }
    }
    
    public function pub($event, $body = [], $headers = []) {
        $headers['event'] = $event;
        
        $msg = new AMQPMessage(json_encode($body, JSON_PRETTY_PRINT));
        $msg -> set('application_headers', new Wire\AMQPTable($headers));
        $this -> channel -> basic_publish($msg, 'infinex');
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
    
    public function sub($event, $callback) {
        $this -> channel -> queue_declare($service, false, true); // durable
        $this -> channel -> basic_consume($service, '', false, false, false, false, $callback); // no auto ack
    }
    
    public function method() {
    }
}
?>