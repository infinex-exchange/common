<?php

namespace Infinex\App;

use Infinex\AMQP\AMQP;

class App {
    protected $service;
    protected $log;
    protected $loop;
    protected $amqp;
    
    function __construct($service) {
        global $argv;
        $th = $this;
        
        $this -> service = $service;
        
        $this -> loop = \React\EventLoop\Loop::get();
        $this -> loop -> addSignal(SIGINT, function() use($th) {
            $th -> log -> warn('Received SIGINT');
            $th -> stop();
        });
        $this -> loop -> addSignal(SIGTERM, function() use($th) {
            $th -> log -> warn('Received SIGTERM');
            $th -> stop();
        });
        
        $level = Logger::LL_ERROR;
        if(in_array('-d', $argv))
            $level = Logger::LL_DEBUG;
        else if(defined('LOG_LEVEL'))
            $level = LOG_LEVEL;
        $this -> log = new Logger($this -> service, $this -> loop, $level);
        
        $this -> amqp = new AMQP(
            $this -> service,
            $this -> loop,
            $this -> log,
            AMQP_HOST,
            AMQP_PORT,
            AMQP_USER,
            AMQP_PASS,
            AMQP_VHOST
        );
        $this -> log -> setAmqp($this -> amqp);
    }
    
    public function run() {
        $this -> loop -> run();
    }
    
    public function start() {
        $th = $this;
        
        return $this -> amqp -> start() -> then(
            function() use($th) {
                return $th -> log -> start();
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $this -> log -> stop() -> then(
            function() use($th) {
                return $th -> amqp -> stop();
            }
        ) -> then(
            function() use($th) {
                $this -> loop -> stop();
            }
        );
    }
}

?>