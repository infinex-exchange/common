<?php

namespace Infinex\App;

use React\Promise;
use function React\Async\async;
use function React\Async\await;

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
    private $level;
    private $amqp;
    
    private $hostname;
    private $instance;
    private $dirty;
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
        
        return Promise\resolve(null);
    }
    
    public function stop() {
        $th = $this;
        
        $this -> loop -> cancelTimer($this -> timerSync);
        
        return $this -> sync() -> then(
            function() use($th) {
                $th -> info('Stopped remote logging');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> error('Failed stopping remote logging: '.((string) $e));
            }
        );
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
        $th = $this;
        
        return async(
            function() use($th) {
                while(count($this -> dirty) > 0) {
                    $entry = $th -> dirty[0];
                    $entry['service'] = $th -> service;
                    $entry['hostname'] = $th -> hostname;
                    $entry['instance'] = $th -> instance;
                    try {
                        await($th -> amqp -> pub('log', $entry));
                        array_shift($th -> dirty);
                    }
                    catch(\Exception $e) {
                        $th -> error('Failed to push remote logs: '.((string) $e));
                        break;
                    }
                }
            }
        )();
    }
}

?>