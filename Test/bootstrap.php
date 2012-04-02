<?php

spl_autoload_register(function($class)
{
    if (0 === strpos($class, 'ML\\JsonLD\\')) {
        $path = implode('/', array_slice(explode('\\', $class), 2)).'.php';
        require_once __DIR__.'/../'.$path;
        return true;
    }
});
