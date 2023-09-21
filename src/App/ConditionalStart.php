<?php

namespace Infinex;

class ConditionalStart {
    private $loop;
    private $log;
    private $act;
    private $states;
    private $actState;
    
    function __construct($loop, $log, $deps, $act) {
        $this -> loop = $loop;
        $this -> log = $log;
        $this -> act = $act;
        $this -> states = [];
        $this -> actState = false;
        
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
    
    private function stateUpdated() {
        if($this -> actState && in_array(false, $this -> states)) {
            $this -> actState = false;
            $this -> log -> info('One of the dependencies is broken. Stopping actuators.');
            $this -> act -> stop();
            return;
        }
        
        if(!$this -> actState && !in_array(false, $this -> states)) {
            $this -> actState = true;
            $this -> log -> info('All dependencies are healthy. Starting actuators.');
            $this -> act -> start();
            return;
        }
    }
}

?>