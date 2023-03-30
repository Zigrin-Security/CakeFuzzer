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

function prepare_results() {

    global $_ResponseHeaders, $_CAKEFUZZER_PATH, $_CakeFuzzerPayloadGUIDs, $_CakeTimer, $_CAKEFUZZER_INSTRUMENTOR;
    $output = ob_get_clean();
    while(ob_get_level()) $output .= ob_get_clean();
    // if(ob_get_length() !== false) ob_end_clean();

    if($_GET instanceof MagicArray) $get = $_GET->getCopy();
    else $get = $_GET;
    if($_POST instanceof MagicArray) $post = $_POST->getCopy();
    else $post = $_POST;
    if($_REQUEST instanceof MagicArray) $request = $_REQUEST->getCopy();
    else $request = $_REQUEST;
    if($_COOKIE instanceof MagicArray) $cookie = $_COOKIE->getCopy();
    else $cookie = $_COOKIE;
    if($_FILES instanceof MagicArray) $files = $_FILES->getCopy();
    else $files = $_FILES;
    if($_SERVER instanceof MagicArray) $server = $_SERVER->getCopy();
    else $server = $_SERVER;

    $result = array(
        'first_http_line' => $_ResponseHeaders->GetFirstLine(),
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $_CAKEFUZZER_INSTRUMENTOR->updatePath($_CAKEFUZZER_PATH),
        'headers' => $_ResponseHeaders->GetAllHeaders(),
        'output' => $output,
        '_GET' => $get,
        '_POST' => $post,
        '_REQUEST' => $request,
        '_COOKIE' => $cookie,
        '_FILES' => $files,
        '_SERVER' => $server,
        'PAYLOAD_GUIDs' => $_CakeFuzzerPayloadGUIDs->GetPayloadGUIDs(),
        'exec_time' => $_CakeTimer->FinishTimer(true)
    );

    return $result;
}

function __cakefuzzer_exit($code = null) {
    global $_CAKEFUZZER_OUTPUT_SENT;
    if(!$_CAKEFUZZER_OUTPUT_SENT) {
        $results = prepare_results();
        $_CAKEFUZZER_OUTPUT_SENT = true;
        print(json_encode($results)."\n");
    }
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
register_shutdown_function('__cakefuzzer_exit'); // Handle all executions of exit/die instructions

// Below are three just in case there are some ob_get_clean inside the application
ob_start();
ob_start();
ob_start();

unset($data, $config);

$_CAKEFUZZER_INSTRUMENTOR->includeApp();
__cakefuzzer_exit(); // If app did not run exit/die then run it manually
