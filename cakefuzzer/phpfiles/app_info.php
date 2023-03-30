<?php

include 'AppInfo.php';
$appInfo = new AppInfo();

if(!$appInfo->loadInput()) {
    $appInfo->printHelp();
    die;
}

function exception_handler(Throwable $exception) {
    // echo "Uncaught exception: " , $exception->getMessage(), "\n";
}

function test_error_handling($errno, $errstr, $errfile, $errline) {
}

register_shutdown_function([$appInfo, 'handleExit']);
include 'instrumented_functions.php';

set_error_handler('test_error_handling');
set_exception_handler('exception_handler');

$appInfo->includeApp();
