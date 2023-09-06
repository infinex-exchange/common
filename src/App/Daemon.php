<?php

namespace Infinex\App;

use Infinex\AMQP\AMQP;

class Daemon {
    protected $log;
    protected $loop;
    protected $amqp;
    
    function __construct($module) {
        $this -> log = new Logger();
        
        $this -> loop = \React\EventLoop\Factory::create();
        $this -> log -> debug('Event loop created');
        
        $this -> amqp = new AMQP($this -> loop, $this -> logger);
        $th = $this;
        $this -> amqp -> once('connect', function() use($th, $module) {
            $th -> log -> setupRemote($th -> loop, $th -> amqp, $module);
        });
        $this -> amqp -> start();
    }
    
    public function run() {
        $this -> loop -> run();
    }
}

?>