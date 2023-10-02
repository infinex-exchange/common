<?php

namespace Infinex\Database;

use Infinex\Exceptions\Error;

class Search {
    private $task;
    private $sql;
    
    function __construct($cols, $userParams) {
        $this -> task = [];
        $this -> sql = '';
        
        if(!isset($userParams['q']))
            return;
        
        if(!$this -> validateSearch($userParams['q']))
            throw new Error('VALIDATION_ERROR', 'Search query too long', 400);
        
        $q = $this -> filterSearch($userParams['q']);
        if($q == '')
            return;
        
        foreach($cols as $i => $col) {
            $paramName = ':search_'.$i;
            $this -> task[$paramName] = '%'.$q.'%';
            $this -> sql .= ' OR LOWER('.$col.') LIKE '.$paramName;
        }
    }
    
    public function updateTask(&$task) {
        $task = array_merge($task, $this -> task);
    }
    
    public function sql() {
        if($this -> sql == '')
            return '';
        
        return ' AND (1=2'.$this -> sql.')';
    }
    
    private function validateSearch($q) {
        return strlen($q) <= 256;
    }
    
    private function filterSearch($q) {
        return strtolower(preg_replace('/[^a-zA-Z0-9 _]/', '', $q));
    }
}

?>