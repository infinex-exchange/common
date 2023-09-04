<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;

class AMQP {
    private $loop;
    private $logger;
    private $rmq;
    private $channel;
    
    function __construct($loop, $logger) {
        $this -> loop = $loop;
        $this -> logger = $logger;
    }
    
    public function connect() {
        $this -> logger -> debug('Initializing AMQP connection');
        
        while(true) {
            try {
                $this -> rmq = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS);
                $this -> channel = $this -> rmq -> channel();
                $this -> channel -> basic_qos(null, 1, null);
                
                $this -> channel -> exchange_declare('infinex', AMQPExchangeType::HEADERS, false, true); // durable
                
            }
            catch(Exception $e) {
                $this -> logger -> error($e -> getMessage());
                sleep(1);
            }
        }
        
        $th = $this;
        $this -> loop -> addPeriodicTimer(0.0001, function () use ($th) {
            $th -> channel -> wait(null, true);
        });
        
        $this -> logger -> info('Connected to AMQP');
        
        $this -> logger -> initRemote($loop, $this);
        $this -> logger -> info('Remote logging initialized');
    }
    
    public function pub() {
    }
    
    public function sub($service, $callback) {
        $this -> channel -> queue_declare($service, false, true); // durable
        $this -> channel -> basic_consume($service, '', false, false, false, false, $callback); // no auto ack
    }
    
    public function call() {
    }
    
    public function reg() {
    }
}
?>