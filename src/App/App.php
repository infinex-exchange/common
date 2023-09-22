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
        $this -> amqp -> on('connect', function() use($th) {
            $th -> log -> start();
        });
        $this -> amqp -> on('disconnect', function() use($th) {
            $th -> log -> stop();
        });
        $this -> amqp -> start();
    }
    
    public function run() {
        $this -> loop -> run();
    }
}

?>