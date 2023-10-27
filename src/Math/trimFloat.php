<?php

namespace Infinex\Math;

function trimFloat($float) {
    $exp = explode('.', $float);
    $integer = ltrim($exp[0], '0');
    if($integer == '')
        $integer = '0';
    $fractional = isset($exp[1]) ? rtrim($exp[1], '0') : '';
    return $integer . ($fractional != '' ? '.'.$fractional : '');
}

?>