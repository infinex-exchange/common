<?php

namespace Infinex\API;

use Infinex\Exceptions\Error;
use React\Promise\Promise;

class REST {
    private $log;
    private $amqp;
    private $dispatcher;
    private $routeCollector;
    
    function __construct($log, $amqp) {
        $th = $this;
        
        $this -> log = $log;
        $this -> amqp = $amqp;
        
        $this -> dispatcher = \FastRoute\simpleDispatcher(
            function($routeCollector) use($th) {
                $th -> routeCollector = $routeCollector;
            }
        );
        
        $this -> log -> debug('Initialized REST API');
    }
    
    public function start() {
        $th = $this;
        
        $this -> amqp -> method(
            'rest',
            function($body) use($th) {
                return $th -> request($body);
            }
        );
        
        $this -> log -> info('Started REST API');
    }
    
    public function stop() {
        $promise = $this -> amqp -> unreg('rest');
        $this -> log -> info('Stopped REST API');
        return $promise;
    }
    
    private function request($body) {
        $th = $this;
        $promise = new Promise(
            function($resolve, $reject) use($th, $body) {
                $routeInfo = $this -> dispatcher -> dispatch($body['method'], $body['path']);
                
                switch($routeInfo[0]) {
                    case \FastRoute\Dispatcher::NOT_FOUND:
                        throw new APIException(404, 'INVALID_ENDPOINT', 'Invalid endpoint');
                    case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                        throw new APIException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
                    case \FastRoute\Dispatcher::FOUND:
                        foreach($routeInfo[2] as $k => $v) {
                            $intVal = filter_var($v, FILTER_VALIDATE_INT);
                            if($intVal !== false)
                                $routeInfo[2][$k] = $intVal;
                        }
                        $resolve($routeInfo[1]($routeInfo[2], $body['query'], $body['body'], $body['auth']));
                }
            }
        );
        
        return $promise -> then(
            function($response) {
                if(isset($response['status']) && isset($response['body']))
                    return [
                        'status' => $response['status'],
                        'body' => $response['body'] !== null ? $response['body'] : []
                    ];
                
                return [
                    'status' => 200,
                    'body' => $response !== null ? $response : []
                ];
            }
        ) -> catch(
            function(Error $e) {
                return [
                    'status' => $e -> getCode(),
                    'body' => [
                        'error' => [
                            'code' => $e -> getStrCode(),
                            'msg' => $e -> getMessage()
                        ]
                    ]
                ];
            }
        );
    }
}

?>