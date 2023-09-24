<?php

namespace Infinex\Database;

use Evenement\EventEmitter;

class PDO extends EventEmitter {
    private $loop;
    private $log;
    private $host;
    private $user;
    private $pass;
    private $name;
    private $pdo;
    private $timerPing;
    private $timerRetryConn;
    private $connected;
    
    function __construct($loop, $log, $host, $user, $pass, $name) {
        $this -> loop = $loop;
        $this -> log = $log;
        $this -> host = $host;
        $this -> user = $user;
        $this -> pass = $pass;
        $this -> name = $name;
        $this -> connected = false;
        
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
        if($this -> timerRetryConn) {
            $this -> loop -> cancelTimer($this -> timerRetryConn);
            $this -> timerRetryConn = null;
        }
        
        if($this -> connected) {
            $this -> emit('disconnect');
            $this -> loop -> cancelTimer($this -> timerPing);
            $this -> pdo = null;
        }
        
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
            
            $this -> connected = true;
            $this -> emit('connect');
            
            $this -> log -> info('Connected to database');
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
            $this -> log -> debug('Database ping ok');
        }
        catch(\Exception $e) {
            $this -> log -> error('Database ping failed');
        }
    }
    
    private function disconnected() {
        if(! $this -> connected)
            return;
        
        $th = $this;
        
        $this -> connected = false;
        $this -> emit('disconnect');
        $this -> loop -> cancelTimer($this -> timerPing);
        $this -> timerPing = null;
        $this -> loop -> futureTick(function() use($th) {
            $th -> connect();
        });
        $this -> log -> error('PDO disconnected');
    }
}
?>