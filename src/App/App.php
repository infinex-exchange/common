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
        
        $this -> loop = \React\EventLoop\Factory::create();
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
        $this -> amqp -> on('connect', function() use($th) {
            $th -> log -> start();
        });
        $this -> amqp -> on('disconnect', function() use($th) {
            $th -> log -> stop();
        });
    }
    
    public function run() {
        $this -> loop -> run();
    }
    
    public function start() {
        $this -> log -> info('Starting app');
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