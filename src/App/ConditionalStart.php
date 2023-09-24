<?php

namespace Infinex\App;

use React\Promise;

class ConditionalStart {
    private $loop;
    private $log;
    private $act;
    private $states;
    private $actState;
    private $started;
    
    function __construct($loop, $log, $deps, $act) {
        $this -> loop = $loop;
        $this -> log = $log;
        $this -> act = $act;
        $this -> states = [];
        $this -> actState = false;
        $this -> started = false;
        
        if(!is_array($deps))
            $deps = [ $deps ];
        if(!is_array($act))
            $act = [ $act ];
        
        $th = $this;
        
        for($i = 0; $i < count($deps); $i++) {
            $this -> states[$i] = false;
            
            $deps[$i] -> on('connect', function() use($th, $i) {
                $th -> states[$i] = true;
                $th -> stateUpdated();
            });
            
            $deps[$i] -> on('disconnect', function() use($th, $i) {
                $th -> states[$i] = false;
                $th -> stateUpdated();
            });
        }
        
        $this -> log -> debug('Initialized conditional start: '.count($deps).' dependencies, '.count($act).' actuator');
    }
    
    public function start() {
        $this -> started = true;
        $this -> stateUpdated();
    }
    
    public function stop() {
        $this -> started = false;
        return $this -> stateUpdated();
    }
    
    private function stateUpdated() {
        if($this -> actState && (!$this -> started || in_array(false, $this -> states))) {
            $this -> actState = false;
            $this -> log -> info('Stopping actuators');
            $promises = [];
            foreach($this -> act as $act)
                $promises[] = $act -> stop();
            return Promise\all($promises);
        }
        
        if($this -> started && !$this -> actState && !in_array(false, $this -> states)) {
            $this -> actState = true;
            $this -> log -> info('Starting actuators');
            foreach($this -> act as $act)
                $act -> start();
            return null;
        }
    }
}

?>