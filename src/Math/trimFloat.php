<?php

namespace Infinex\Math;

function trimFloat($float) {
    if($float === '0' || $float === NULL) return $float;
    if(strpos($float, '0.') !== 0)
        $float = ltrim($float, '0');
    return rtrim(rtrim($float, '0'), '.');
}

?>