<?php

namespace Infinex\Pagination;

use Infinex\Exceptions\Error;

class Offset {
    private $offset;
    private $limit;
    private $count;
    public $more;
    
    function __construct($defaultLimit, $maxLimit, $userParams) {
        if(isset($userParams['offset'])) {
            if(!$this -> validateOffset($userParams['offset']))
                throw new Error('VALIDATION_ERROR', 'Invalid pagination offset', 400);
            
            $this -> offset = $userParams['offset'];
        } else {
            $this -> offset = 0;
        }
        
        if(isset($userParams['limit'])) {
            if(!$this -> validateLimit($userParams['limit']))
                throw new Error('VALIDATION_ERROR', 'Invalid pagination limit', 400);
            
            if($userParams['limit'] > $maxLimit)
                throw new Error('VALIDATION_ERROR', 'Pagination limit out of range', 400);
            
            $this -> limit = $userParams['limit'];
        } else {
            $this -> limit = $defaultLimit;
        }
        
        $this -> count = 0;
        $this -> more = false;
    }
    
    public function iter() {
        if($this -> count == $this -> limit) {
            $this -> more = true;
            return true;
        }
        $this -> count++;
        return false;
    }
    
    public function sql() {
        return ' LIMIT '.($this -> limit + 1).' OFFSET '.$this -> offset;
    }
    
    private function validateOffset($offset) {
        if(!is_int($offset)) return false;
        if($offset < 0) return false;
        return true;
    }
    
    private function validateLimit($limit) {
        if(!is_int($limit)) return false;
        if($limit < 1) return false;
        return true;
    }
}

?>