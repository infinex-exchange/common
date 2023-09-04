<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;

class AMQP {
    private $loop;
    private $logger;
    private $rmq;
    private $channel;
    private $timer;
    
    function __construct($loop, $logger) {
        $this -> logger = $logger;
        $this -> connect();
    }
    
    public function bind($service, $callback) {
        $channel -> queue_declare($service, false, true); // durable
        $channel -> basic_consume($service, '', false, false, false, false, $callback); // no auto ack
    }
    
    public function call($service, $)
    
    private function connect() {
        $this -> logger -> info('Initializing AMQP connection');
        
        try {
            if($this -> rmq !== null)
                $this -> rmq -> close();
        }
        catch(Exception $e) {
        }
        
        $this -> rmq = null;
        $this -> channel = null;
        
        while(true) {
            try {
                $this -> rmq = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS);
                $this -> channel = $this -> rmq -> channel();
            }
            catch(Exception $e) {
                $this -> logger -> error($e -> getMessage());
                sleep(1);
            }
        }
        
        $this -> logger -> info('Connected to AMQP');
        
        $th = $this;
        $this -> timer = $this -> loop -> addPeriodicTimer(0.0001, function () use ($th) {
            try {
                $th -> channel -> wait(null, true);
            }
            catch(Exception $e) {
                $th -> loop -> cancelTimer($th -> timer);
                $th -> logger -> error($e -> getMessage());
                $th -> connect();
            }
        });
    }
}
?>