<?php

namespace Infinex\API;

use Infinex\Exceptions\Error;
use React\Promise;

class REST {
    private $log;
    private $amqp;
    
    private $dispatcher;
    
    function __construct($log, $amqp, $apis) {
        $th = $this;
        
        $this -> log = $log;
        $this -> amqp = $amqp;
        
        if(!is_array($apis))
            $apis = [ $apis ];
        
        $this -> dispatcher = \FastRoute\simpleDispatcher(
            function($routeCollector) use($th, $apis) {
                foreach($apis as $api)
                    $api -> initRoutes($routeCollector);
            }
        );
        
        $this -> log -> debug('Initialized REST API');
    }
    
    public function start() {
        $th = $this;
        
        return $this -> amqp -> method(
            'rest',
            function($body) use($th) {
                return $th -> request($body);
            }
        ) -> then(
            function() use($th) {
                $th -> log -> info('Started REST API');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start REST API: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        return $this -> amqp -> unreg('rest') -> then(
            function() use($th) {
                $th -> log -> info('Stopped REST API');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop REST API: '.((string) $e));
            }
        );
    }
    
    private function request($body) {
        $th = $this;
        $promise = new Promise\Promise(
            function($resolve, $reject) use($th, $body) {
                $routeInfo = $this -> dispatcher -> dispatch($body['method'], $body['path']);
                
                switch($routeInfo[0]) {
                    case \FastRoute\Dispatcher::NOT_FOUND:
                        throw new Error('INVALID_ENDPOINT', 'Invalid endpoint', 404);
                    case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
                        throw new Error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
                    case \FastRoute\Dispatcher::FOUND:
                        foreach($routeInfo[2] as $k => $v) {
                            $intVal = filter_var($v, FILTER_VALIDATE_INT);
                            if($intVal !== false)
                                $routeInfo[2][$k] = $intVal;
                        }
                        $resolve($routeInfo[1](
                            $routeInfo[2],
                            $body['query'],
                            $body['body'],
                            $body['auth'],
                            $body['userAgent'],
                            $body['ip']
                        ));
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
        );
    }
}

?>