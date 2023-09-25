<?php

namespace Infinex\Exceptions;

class Error extends \Exception {
    private $strCode;
    
    function __construct($strCode, $message, $status = 0) {
        parent::__construct($message, $status);
        $this -> strCode = $strCode;
    }
    
    public function getStrCode() {
        return $this -> strCode;
    }
    
    public function __toString() {
        return "{$this -> strCode}[{$this -> code}]: {$this -> message}";
    }
}

?>