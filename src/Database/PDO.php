<?php

namespace Infinex\Database;

class PDO {
    private $loop;
    private $log;
    private $pdo;
    private $ping;
    
    function __construct($loop, $log) {
        $this -> loop = $loop;
        $this -> log = $log;
        
        $this -> log -> debug('Initialized PDO connection');
    }
    
    public function start() {
        $th = $this;
        $this -> loop -> futureTick(function() use($th) {
            $th -> connect();
        });
    }
    
    private function connect() {
        $th = $this;
        
        $this -> log -> debug('Trying connect to database');
        
        try {
            $this -> pdo = new \PDO('pgsql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
            $this -> pdo -> setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this -> pdo -> setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            
            if($this -> ping === null) {
                $this -> loop -> addPeriodicTimer(
                    30,
                    function() use($th) {
                        try {
                            $th -> log -> debug('Database ping');
                            $th -> query('SELECT 1');
                        }
                        catch(Exception $e) {
                            $this -> loop -> cancelTimer($this -> ping);
                            $this -> ping = null;
                        }
                    }
                );
            }
            
            $this -> log -> info('Connected to database');
        }
        catch(\Exception $e) {
            $this -> log -> error('PDO connection failed: '.$e -> getMessage());
            $this -> loop -> addTimer(
                1,
                function() use($th) {
                    $th -> connect();
                }
            );
        }
    }
    
    public function __call($method, $args) {
        try {
            return call_user_func_array([$this -> pdo, $method], $args);
        }
        catch(\Exception $e) {
            $this -> log -> error((string) $e);
            $this -> start();
            throw $e;
        }
    }
}
?>