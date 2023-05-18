<?php

function info($message) {
    print("$message\n");
}

function warning($message) {
    if(!is_array($message)) $message = array($message);
    print(json_encode(array('errors' => $message)));
}

function usage() {
    info("Usage echo '<json_config>' | {$_SERVER['argv'][0]}");
    info("Config definition:");
    info(json_encode(get_config_definition()));
    info("Output definition:");
    info(json_encode(get_output_definition()));
}

function get_config_definition() {
    $config = array(
        'webroot_file' => array(
            'type' => 'string',
            'required' => true
        ),
        'super_globals' => array(
            'type' => 'dictionary',
            'default' => new stdClass,
            'required' => false
        ),
        'payloads' => array(
            'type' => 'list',
            'required' => true
        ),
        'oneParamPerPayload' => array(
            'type' => 'bool',
            'default' => false,
            'required' => false
        ),
        'PAYLOAD_GUID_phrase' => array(
            'type' => 'string',
            'default' => '§CAKEFUZZER_PAYLOAD_GUID§',
            'required' => false
        ),
        'injectable' => array(
            'type' => 'dictionary',
            'default' => null,
            'required' => false
        ),
        'global_targets' => array(
            'type' => 'dictionary',
            'default' => new stdClass,
            'required' => false
        ),
        'global_exclude' => array(
            'type' => 'list',
            'default' => array(),
            'required' => false
        ),
        'fuzz_skip_keys' => array(
            'type' => 'dictionary',
            'default' => new stdClass,
            'required' => false
        ),
        'known_keywords' => array(
            'type' => 'list',
            'default' => array(),
            'required' => false
        )
    );
    return $config;
}

function get_output_definition() {
    global $_CAKEFUZZER_PATH;
    $result = array(
        'output' => 'string',
        'path' => 'string',
        'method' => 'string',
        '_GET' => 'dictionary',
        '_POST' => 'dictionary',
        '_REQUEST' => 'dictionary',
        '_COOKIE' => 'dictionary',
        '_FILES' => 'dictionary',
        '_SERVER' => 'dictionary',
        'first_http_line' => 'string',
        'headers' => 'dictionary',
        'PAYLOAD_GUIDs' => 'list',
        'exec_time' => 'float'
    );
    return $result;
}

if(in_array('-h', array_slice($_SERVER['argv'], 1)) || in_array('--help', array_slice($_SERVER['argv'], 1))) {
    usage();
    die;
}

$data = stream_get_contents(STDIN);
$config = json_decode($data, true);

// For some reason exit is initiated more than once on every execution.
// This makes sure that the output is prepared only once
$_CAKEFUZZER_OUTPUT_SENT = false;

include 'instrumented_functions.php';
include 'AppInstrument.php';

$_CAKEFUZZER_PATH = __cake_fuzzer_prepare_path($config);

$_CAKEFUZZER_INSTRUMENTOR = new AppInstrument($config);
$_CAKEFUZZER_INSTRUMENTOR->reinitializeGlobals();

function stop_intercept(){}
register_shutdown_function([$_CAKEFUZZER_INSTRUMENTOR, 'handleExit']); // Handle all executions of exit/die instructions

// Below are three just in case there are some ob_get_clean inside the application
ob_start();
ob_start();
ob_start();

unset($data, $config);

$_CAKEFUZZER_INSTRUMENTOR->includeApp();
$_CAKEFUZZER_INSTRUMENTOR->handleExit(); // If app did not run exit/die then run it manually
