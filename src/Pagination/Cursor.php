<?php

namespace Infinex\Pagination;

use Infinex\Exceptions\Error;

class Cursor {
    private $column;
    private $reverse;
    private $currentCursor;
    private $limit;
    private $count;
    private $potentialCursor;
    public $cursor;
    
    function __construct($column, $reverse, $defaultLimit, $maxLimit, $userParams) {
        $this -> column = $column;
        $this -> reverse = $reverse;
        
        if(isset($userParams['cursor'])) {
            if(!$this -> validateIntNz($userParams['cursor']))
                throw new Error('VALIDATION_ERROR', 'Invalid pagination cursor', 400);
            
            $this -> currentCursor = $userParams['cursor'];
        } else {
            $this -> currentCursor = null;
        }
        
        if(isset($userParams['limit'])) {
            if(!$this -> validateIntNz($userParams['limit']))
                throw new Error('VALIDATION_ERROR', 'Invalid pagination limit', 400);
            
            if($userParams['limit'] > $maxLimit)
                throw new Error('VALIDATION_ERROR', 'Pagination limit out of range', 400);
            
            $this -> limit = $userParams['limit'];
        } else {
            $this -> limit = $defaultLimit;
        }
        
        $this -> count = 0;
        $this -> potentialCursor = null;
        $this -> cursor = null;
    }
    
    public function iter($row) {
        if($this -> count == $this -> limit) {
            $this -> cursor = $this -> potentialCursor;
            return true;
        }
        $this -> count++;
        $this -> potentialCursor = $row[$this -> column];
        return false;
    }
    
    public function sql() {
        $sql = '';
        
        if($this -> currentCursor !== null)
            $sql .= ' AND '.$this -> column.' '.($this -> reverse ? '<' : '>').' '.$this -> currentCursor;
        
        $sql .= ' ORDER BY '.$this -> column.' '.($this -> reverse ? 'DESC' : 'ASC')
             .  ' LIMIT '.($this -> limit + 1);
        
        return $sql;
    }
    
    private function validateIntNz($int) {
        if(!is_int($int)) return false;
        if($int < 1) return false;
        return true;
    }
}

?>