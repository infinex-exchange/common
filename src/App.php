<?php

namespace Infinex;

class Daemon {
    private $log;
    private $loop;
    private $amqp;
    
    function __construct() {
        $this -> log = new Logger();
        
        $this -> loop = React\EventLoop\Factory::create();
        $this -> log -> debug('Event loop created');
        
        $this -> amqp = new AMQP();
    }
    
    public function run() {
        $this -> loop -> run();
    }
}

?>