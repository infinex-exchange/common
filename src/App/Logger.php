<?php

namespace Infinex\App;

class Logger {
    const LL_ERROR = 0;
    const LL_WARN = 1;
    const LL_INFO = 2;
    const LL_DEBUG = 3;
    
    const C_RESET = "\033[0m";
    const C_RED = "\033[91m";
    const C_GREEN = "\033[92m";
    const C_YELLOW = "\033[93m";
    const C_BLUE = "\033[94m";
    
    const COLOR_MAP = array(
        Logger::LL_ERROR => Logger::C_RED,
        Logger::LL_WARN => Logger::C_YELLOW,
        Logger::LL_INFO => Logger::C_GREEN,
        Logger::LL_DEBUG => Logger::C_BLUE
    );
    
    const DESC_MAP = array(
        Logger::LL_ERROR => 'Error',
        Logger::LL_WARN => 'Warning',
        Logger::LL_INFO => 'Info',
        Logger::LL_DEBUG => 'Debug'
    );
    
    const LEVEL_COL_SIZE = 8;
    
    private $service;
    private $loop;
    private $hostname;
    private $instance;
    private $level;
    private $dirty;
    private $amqp;
    private $timerSync;
    
    function __construct($service, $loop, $level) {
        global $argv;
        
        $this -> service = $service;
        $this -> loop = $loop;
        $this -> level = $level;
        $this -> hostname = gethostname();
        $this -> instance = getmypid();
        $this -> dirty = array();
        
        $this -> debug('Initialized logger');
    }
    
    public function setAmqp($amqp) {
        $this -> amqp = $amqp;
    }
    
    public function start() {
        $th = $this;
        
        $this -> timerSync = $this -> loop -> addPeriodicTimer(
            5,
            function() use($th) {
                $th -> sync();
            }
        );
        
        $this -> info('Started remote logging');
    }
    
    public function stop() {
        $this -> loop -> cancelTimer($this -> timerSync);
        $this -> sync();
        $this -> info('Stopped remote logging');
    }
    
    public function log($level, $message) {
        $now = microtime(true);
        
        $this -> dirty[] = [
            'time' => $now,
            'level' => $level,
            'msg' => $message
        ];
        
        if($level > $this -> level)
            return;
        
        $levelStr = Logger::DESC_MAP[$level];
        
        echo date('r', $now).
             Logger::COLOR_MAP[$level].
             ' ['.
             $levelStr.
             ']';
        
        for($i = 0; $i < Logger::LEVEL_COL_SIZE - strlen($levelStr); $i++)
            echo ' ';
        
        echo Logger::C_RESET.
             $message.
             PHP_EOL;
    }
    
    public function error($message) {
        $this -> log(Logger::LL_ERROR, $message);
    }
    
    public function warn($message) {
        $this -> log(Logger::LL_WARN, $message);
    }
    
    public function info($message) {
        $this -> log(Logger::LL_INFO, $message);
    }
    
    public function debug($message) {
        $this -> log(Logger::LL_DEBUG, $message);
    }
    
    private function sync() {
        while(count($this -> dirty) > 0) {
            $entry = $this -> dirty[0];
            $entry['service'] = $this -> service;
            $entry['hostname'] = $this -> hostname;
            $entry['instance'] = $this -> instance;
            try {
                $this -> amqp -> pub('log', $entry);
                array_shift($this -> dirty);
            }
            catch(\Exception $e) {
                $this -> error('Failed to push remote logs: '.((string) $e));
                break;
            }
        }
    }
}

?>