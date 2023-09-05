<?php

namespace Infinex;

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
    
    private $level;
    private $dirty;
    
    function __construct() {
        if(isset($argv) && in_array('-d', $argv))
            $this -> level = Logger::LL_DEBUG;
        
        else if(defined('LOG_LEVEL'))
            $this -> level = LOG_LEVEL;
        
        else
            $this -> level = Logger::LL_WARN;
        
        $this -> dirty = array();
    }
    
    public function setupRemote($loop, $amqp, $module) {
        $th = $this;
        $hostname = gethostname();
        $loop -> addPeriodicTimer(1, function () use ($th, $amqp, $module, $hostname) {
            while(count($th -> dirty) > 0) {
                $entry = $th -> dirty[0];
                $entry['module'] = $module;
                $entry['hostname'] = $hostname;
                try {
                    $amqp -> pub('log', $entry);
                    array_pop($th -> dirty);
                }
                catch(Exception $e) {
                    break;
                }
            }
        });
    }
    
    public function log($level, $message) {
        if($level > $this -> level)
            return;
        
        $levelStr = Logger::DESC_MAP[$level];
        $now = time();
        
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
        
        $this -> dirty[] = [
            'time' => $now,
            'level' => $level,
            'msg' => $message
        ];
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
}

?>