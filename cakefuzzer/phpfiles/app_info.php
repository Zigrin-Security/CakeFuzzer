<?php

define('CAKE_FUZZER_APP_INFO', true);

include 'AppInfo.php';
$appInfo = new AppInfo();

if(!$appInfo->loadInput()) {
    $appInfo->printHelp();
    die;
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

function exception_handler(Throwable $exception) {
    // echo "Uncaught exception: " , $exception->getMessage(), "\n";
}

function test_error_handling($errno, $errstr, $errfile, $errline) {
}

register_shutdown_function([$appInfo, 'handleExit']);
include 'instrumented_functions.php';

set_error_handler('test_error_handling');
set_exception_handler('exception_handler');

// The include has to be in the global scope to make taret app global vars really global
// If the app is included in class, they won't have the global scope.

// Below are three just in case there are some ob_get_clean inside the application
ob_start();
ob_start();
ob_start();
$appInfo->prepareGlobals();
include $appInfo->getIndex();
$output = ob_get_clean();
while(ob_get_level()) $output .= ob_get_clean();
$app_vars = get_defined_vars();
unset($app_vars['output'], $app_vars['appInfo']);
$appInfo->setAppVars($app_vars);