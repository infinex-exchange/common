<?php

namespace Infinex\Database;

use Infinex\Exceptions\Error;

class Sorting {
    private $orderBy;
    private $orderDir;
    
    function __construct($colMap, $defaultBy, $defaultDir, $userParams) {
        if(isset($userParams['orderBy'])) {
            if(!array_key_exists($userParams['orderBy'], $colMap))
                throw new Error('VALIDATION_ERROR', 'orderBy', 400);
            
            $this -> orderBy = $colMap[ $userParams['orderBy'] ];
        }
        else
            $this -> orderBy = $defaultBy;
        
        if(isset($userParams['orderDir'])) {
            if(!in_array($userParams['orderDir'], ['ASC', 'DESC']))
                throw new Error('VALIDATION_ERROR', 'orderDir', 400);
            
            $this -> orderDir = $userParams['orderDir'];
        }
        else
            $this -> orderDir = $defaultDir;
    }
    
    public function sql() {
        return ' ORDER BY '.$this -> orderBy.' '.$this -> orderDir;
    }
}

?>