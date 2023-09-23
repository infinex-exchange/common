<?php

namespace Infinex\App;

use Infinex\AMQP\AMQP;

class App {
    protected $service;
    protected $log;
    protected $loop;
    protected $amqp;
    
    function __construct($service) {
        $th = $this;
        
        $this -> service = $service;
        
        $this -> loop = \React\EventLoop\Factory::create();
        
        $this -> log = new Logger($this -> service, $this -> loop);
        
        $this -> amqp = new AMQP($this -> service, $this -> loop, $this -> log);
        $this -> log -> setAmqp($this -> amqp);
    }
    
    public function run() {
        $this -> loop -> run();
    }
    
    public function start() {
        $this -> log -> info('Starting app');
        
        $this -> loop -> addSignal(SIGINT, function() use($th) {
            $th -> log -> warn('Received SIGINT');
            $th -> stop();
        });
        $this -> loop -> addSignal(SIGTERM, function() use($th) {
            $th -> log -> warn('Received SIGTERM');
            $th -> stop();
        });
        
        $this -> amqp -> on('connect', function() use($th) {
            $th -> log -> start();
        });
        $this -> amqp -> on('disconnect', function() use($th) {
            $th -> log -> stop();
        });
        $this -> amqp -> start();
    }
    
    public function stop() {
        $this -> log -> info('Stopping app');
        
        $this -> log -> stop();
        $this -> amqp -> stop();
        $this -> loop -> stop();
    }
}

?>