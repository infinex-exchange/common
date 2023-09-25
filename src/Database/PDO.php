<?php

namespace Infinex\Database;

use React\Promise;

class PDO {
    private $loop;
    private $log;
    
    private $host;
    private $user;
    private $pass;
    private $name;
    
    private $startDeferred;
    private $pdo;
    private $timerPing;
    private $timerRetryConn;
    
    function __construct($loop, $log, $host, $user, $pass, $name) {
        $this -> loop = $loop;
        $this -> log = $log;
        
        $this -> host = $host;
        $this -> user = $user;
        $this -> pass = $pass;
        $this -> name = $name;
        
        $this -> log -> debug('Initialized PDO client');
    }
    
    public function start() {
        if($this -> startDeferred !== null)
            return $this -> startDeferred -> promise();
        
        $this -> startDeferred = new Promise\Deferred();
        $this -> connect();
        return $this -> startDeferred -> promise();
    }
    
    public function stop() {
        $th = $this;
        
        if($this -> timerRetryConn !== null) {
            $this -> loop -> cancelTimer($this -> timerRetryConn);
            return Promise\resolve(null);
        }
        
        $this -> loop -> cancelTimer($this -> timerPing);
        $this -> pdo = null;
        
        $this -> log -> info('Stopped PDO client');
        
        return Promise\resolve(null);
    }
    
    public function __call($method, $args) {
        try {
            return call_user_func_array([$this -> pdo, $method], $args);
        }
        catch(\Exception $e) {
            $this -> log -> error('PDO error: '.((string) $e));
            throw $e;
        }
    }
    
    private function connect() {
        $th = $this;
        
        $this -> log -> debug('Trying connect to database');
        
        $this -> timerRetryConn = null;
        
        try {
            $this -> pdo = new \PDO(
                'pgsql:host='.$this -> host.';dbname='.$this -> name,
                $this -> user,
                $this -> pass
            );
            $this -> pdo -> setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this -> pdo -> setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            $this -> pdo -> setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            
            $this -> timerPing = $this -> loop -> addPeriodicTimer(
                30,
                function() use($th) {
                    $th -> ping();
                }
            );
            
            $this -> log -> info('Connected to database');
            
            $this -> startDeferred -> resolve(null);
        }
        catch(\Exception $e) {
            $this -> log -> error('PDO connection failed: '.((string) $e));
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
            $this -> log -> debug('Database ping OK');
        }
        catch(\Exception $e) {
            $this -> log -> error('Database ping failed');
        }
    }
}
?>