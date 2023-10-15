<?php

namespace Infinex\Math;

function validateFloat($float) {
    if(gettype($float) != 'string') return false;
    return preg_match('/^[0-9]{1,33}(\.[0-9]{1,32})?$/', $float);
}

?>