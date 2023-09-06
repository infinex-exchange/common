<?php

namespace Infinex\App;

class InfinexException extends Exception {
    protected $strCode;
    
    function __construct($strCode, $message, $code = 0) {
        parent::__construct($message, $code);
        $this -> strCode = $strCode;
    }
    
    public function getStrCode() {
        return $this -> strCode;
    }
    
    public function __toString() {
        return __CLASS__ . ": {$this -> strCode}: {$this -> message}";
    }
}
?>