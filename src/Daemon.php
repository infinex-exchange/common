<?php

namespace Infinex;

class Daemon {
    private $log;
    private $loop;
    private $amqp;
    
    function __construct($module) {
        $this -> log = new Logger();
        
        $this -> loop = React\EventLoop\Factory::create();
        $this -> log -> debug('Event loop created');
        
        $this -> amqp = new AMQP();
        $th = $this;
        $this -> amqp -> once('connect', function() use($th, $module) {
            $th -> log -> setupRemote($th -> loop, $th -> amqp, $module);
        });
    }
    
    public function run() {
        $this -> loop -> run();
    }
}

?>