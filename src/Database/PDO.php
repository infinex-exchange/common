<?php

namespace Infinex\Database;

use Evenement\EventEmitter;

class PDO {
    private $loop;
    private $log;
    private $pdo;
    private $timerPing;
    private $timerRetryConn;
    
    function __construct($loop, $log) {
        $this -> loop = $loop;
        $this -> log = $log;
        
        $this -> log -> debug('Initialized PDO');
    }
    
    public function start() {
        $th = $this;
        
        $this -> loop -> futureTick(function() use($th) {
            $th -> connect();
        });
        
        $this -> log -> info('Started PDO');
    }
    
    public function stop() {
        $this -> emit('disconnect');
        $this -> cancelTimer($this -> timerPing);
        $this -> cancelTimer($this -> timerRetryConn);
        $this -> pdo = null;
        $this -> log -> info('Stopped PDO');
    }
    
    public function __call($method, $args) {
        try {
            return call_user_func_array([$this -> pdo, $method], $args);
        }
        catch(\Exception $e) {
            $this -> log -> error('PDO query failed: '.((string) $e));
            $this -> disconnected();
            throw $e;
        }
    }
    
    private function connect() {
        $th = $this;
        
        $this -> log -> debug('Trying connect to database');
        
        try {
            $this -> pdo = new \PDO('pgsql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
            $this -> pdo -> setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this -> pdo -> setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            $this -> pdo -> setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            
            $this -> timerPing = $this -> loop -> addPeriodicTimer(
                30,
                function() use($th) {
                    $th -> ping();
                }
            );
            
            $this -> emit('connect');
            
            $this -> log -> info('Connected to database');
        }
        catch(\Exception $e) {
            $this -> log -> error('PDO connection failed: '.((string) $e);
            $this -> timerRetryConn = $this -> loop -> addTimer(
                1,
                function() use($th) {
                    $th -> connect();
                }
            );
        }
    }
    
    private function ping() {
        try {
            $this -> query('SELECT 1');
            $this -> log -> debug('Database ping ok');
        }
        catch(\Exception $e) {
            $this -> log -> error('Database ping failed');
        }
    }
    
    private function disconnected() {
        $th = $this;
        
        $this -> loop -> cancelTimer($this -> timerPing);
        $this -> emit('disconnect');
        $this -> loop -> futureTick(function() use($th) {
            $th -> connect();
        });
        $this -> log -> error('PDO disconnected');
    }
}
?>