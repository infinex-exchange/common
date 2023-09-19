<?php

namespace Infinex\API;

use Infinex\API\APIException;
use FastRoute\RouteCollector;
use React\Promise\Promise;

class API {
    private $log;
    private $dispatcher;
    private $rpcMethod;
    
    function __construct($log, $rpcMethod, $apis) {
        $this -> log = $log;
        $this -> rpcMethod = $rpcMethod;
        
        $this -> dispatcher = new \FastRoute\simpleDispatcher(
            function(RouteCollector $rc) use($apis) {
                if(!is_array($apis))
                    $apis = [ $apis ];
                foreach($apis as $api)
                    $api -> initRoutes($rc);
            }
        );
        
        $this -> log -> debug('Initialized API @'.$this -> rpcMethod);
    }
    
    public function bind($amqp) {
        $th = $this;
        
        $amqp -> method(
            $this -> rpcMethod,
            function($body) use($th) {
                return $th -> request($body);
            }
        );
    }
    
    public function request($body) {
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
                        $resolve($routeInfo[1]($routeInfo[2], $body['query'], $body['body'], $body['auth'], $body['userAgent']));
                }
            }
        );
        
        return $promise -> then(
            function($response) {
                return [
                    'status' => 200,
                    'body' => $response
                ];
            }
        ) -> catch(
            function(APIException $e) {
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