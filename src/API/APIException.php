<?php

namespace Infinex\API;

use Infinex\App\InfinexException;

class APIException extends InfinexException {
    function __construct($status, $strCode, $message) {
        parent::__construct($strCode, $message, $status);
    }
}

?>