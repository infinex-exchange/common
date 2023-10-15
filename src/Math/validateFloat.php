<?php

namespace Infinex\Math;

function validateFloat($float, $allowNegative = false) {
    if(gettype($float) != 'string') return false;
    if($allowNegative && @$float[0] == '-')
        $float = substr($float, 1);
    return preg_match('/^[0-9]{1,33}(\.[0-9]{1,32})?$/', $float);
}

?>