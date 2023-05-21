<?php

# Todo:
# - Replace is_array

define('LOGGING_LEVELS', array(
    'ERROR' => 1,
    'WARNING' => 2,
    'INFO' => 3,
    'DEBUG' => 4,
));
define('LOGGING_LEVEL', LOGGING_LEVELS['INFO']);

function logMessage($module, $log_level, $message, $destination=STDERR) {
    // Unifies logging messages
    $time = date('Y-m-d H:i:s');
    $log_entry = "[$time]:$module:$log_level:$message";
    if($log_level<=LOGGING_LEVEL) {
        fwrite($destination, $log_entry);
    }
}

function logError($module, $message, $destination=STDERR) {
    return logMessage($module, LOGGING_LEVELS['ERROR'], $message, $destination);
}

function logWarning($module, $message, $destination=STDERR) {
    return logMessage($module, LOGGING_LEVELS['WARNING'], $message, $destination);
}

function logInfo($module, $message, $destination=STDERR) {
    return logMessage($module, LOGGING_LEVELS['INFO'], $message, $destination);
}

function logDebug($module, $message, $destination=STDERR) {
    return logMessage($module, LOGGING_LEVELS['DEBUG'], $message, $destination);
}

class MagicPayloadDictionary implements JsonSerializable {
    // Main class responsible for superglobal variable access handling
    // Modified version of https://github.com/kazet/wpgarlic/blob/main/docker_image/magic_payloads.php

    protected $_superglobal_name = null;
    protected $_prefix = '';
    protected $_payloads = null;
    protected $_injectable = null;
    protected $_skip_fuzz = array();
    protected $_options = array();
    protected $_global_probability = null;
    protected $_injected = null;
    protected $_payload_types = array();
    public $original = null;
    public $parameters = null;
    public function __construct(
            $name,
            $prefix='',
            $original=array(),
            $payloads = null,
            $injectable = null,
            $skip_fuzz = array(),
            $options = array(),
            $payload_types = array(), // So far it does not have any effect. It has to be implemented in getAndSaveForFurtherGets
            $global_probability = 50) {
        global $_CakeFuzzerFuzzedObjects;

        $_CakeFuzzerFuzzedObjects->addObject($name, $this);

        $this->_superglobal_name = $name;
        $this->_prefix = $prefix;
        $this->original = $original;
        $this->parameters = array();
        $this->_payloads = $payloads;
        $this->_injectable = $injectable;
        $this->_skip_fuzz = $skip_fuzz;
        $this->_options = $options;
        $this->_global_probability = $global_probability;
        $this->_injected = false;
        if(empty($payload_types)) $this->_payload_types = array('string', 'MagicArrayOrObject', 'array_value', 'array_key');
        else $this->_payload_types = $payload_types;
    }

    public function setPrefix($prefix) {
        $this->_prefix = $prefix;
    }

    public function getPrefix() {
        return $this->_prefix;
    }

    public function setPayloads($payloads) {
        // Saves payloads to be used a superglobal key is requested
        if(!empty($this->_payloads)) throw new Exception("Payloads already set for {$this->_superglobal_name}");
        $this->_payloads = $payloads;
    }

    public function addPayloads($payloads) {
        if(is_null($this->_payloads)) $this->setPayloads($payloads);
        else $this->_payloads = array_unique(array_merge($this->_payloads, $payloads));
    }

    public function getPayloads() {
        return $this->_payloads;
    }

    public function jsonSerialize() {
        $dict = array();

        foreach($this->parameters as $key => $value) {
            if(!is_null($value)) $dict[$key] = $value;
        }

        // original defined keys should always have priority
        foreach($this->original as $key => $value) {
            if(!is_null($value)) $dict[$key] = $value;
        }

        return $dict;
    }

    public function getAndSaveForFurtherGets($key) {
        // Decides if the payload should be provided or null
        // Assigns a payload type in the response

        if (array_key_exists($key, $this->original)) {
            return $this->original[$key];
        }

        if (array_key_exists($key, $this->parameters)) {
            return $this->parameters[$key];
        }

        $is_injectable = $this->isInjectable($key);

        if($is_injectable === false) {
            $this->parameters[$key] = null;
            return null;
        }

        $this->_injected = true;
        // This is not supported and lacks saving payload first probably
        if(is_string($is_injectable)) return $is_injectable;

        // Draw payload
        if(is_null($this->_payloads)) {
            $details = array(
                'superglobal'=> $this->_superglobal_name,
                'original' => $this->original,
                'parameters' => $this->parameters
            );
            warning(array(array('error'=>'Superglobal does not have any payloads. This should not happen.', 'details'=>$details)));
            die;
        }

        // Draw payload type
        $r = rand() % 5;
        if($this->_prefix !== '') $r = 5; // Assume prefix is only in _SERVER. No arrays there.

        if ($r == 0) {
            $payload = new MagicArrayOrObject("sub_array", "", array(), $this->_payloads, $this->_injectable, $this->_skip_fuzz, $this->_options, $this->_payload_types, $this->_global_probability);
        } else if ($r == 1) {
            $payload = array($this->_replaceDynamic($this->_payloads[array_rand($this->_payloads)]));
        } else if ($r == 2) {
            $sub_key = $this->_payloads[array_rand($this->_payloads)];
            $sub_key = $this->_replaceDynamic($sub_key);
            $sub_val = $this->_payloads[array_rand($this->_payloads)];
            $sub_val = $this->_replaceDynamic($sub_val);
            $payload = array($sub_key => $sub_val);
        } else {
            $payload = $this->_replaceDynamic($this->_payloads[array_rand($this->_payloads)]);
        }

        $this->parameters[$key] = $payload;
        return $payload;
    }

    public function checkIfNotIdenticalAndSet($offset, $value) {
        if($value === $this) $value = clone $value;
        $this->original[$offset] = $value;
        return $value;
    }

    private function __recursiveSerialize($object) {
        if(is_array($object)) {
            $serialized = array();
            foreach($object as $main_key=>$main_val) {
                if($main_val instanceof MagicArray) $serialized[$main_key] = $main_val->getCopy();
                else if(is_array($main_val)) {
                    $tmp = array();
                    foreach($main_val as $k=>$v) {
                        $tmp_val = $this->__recursiveSerialize($v);
                        if(!is_null($object) || (!empty($this->_options['oneParamPerPayload']) && $this->_options['oneParamPerPayload'])) {
                            $tmp[$k] = $tmp_val;
                        }
                    }
                    $serialized[$main_key] = $tmp;
                }
                else if(!is_null($main_val) || (!empty($this->_options['oneParamPerPayload']) && $this->_options['oneParamPerPayload'])) $serialized[$main_key] = $main_val;
            }
            return $serialized;
        }
        if($object instanceof MagicArray) return $object->getCopy();
        return $object;
    }

    /**
     * Serialize Magic object (parameters and original) to recursive array
     */
    public function getCopy() {
        // $z = array('3'=>array('nice'=>'hellloo'));   
        // $x = new MagicArray('tmp', '', array('asd'=>array('zzzz'=>'qqq', 'x'=> new MagicArray('tmp3', '', $z))), array('payload1'));
        // $y = new MagicArray('tmp2', '', array(array('CCC'=>'WWW', 'magic-is-here' => $x)));
        // var_dump($y);
        // echo "----------------\n";
        // var_dump($y->getCopy());
        // die;

        if($this->_prefix === '') {
            $parameters = $this->parameters;
            $original = $this->original;
        }
        else $parameters = $original = array();

        foreach($this->parameters as $key=>$value) {
            if(!empty($this->_prefix) && strpos($key, $this->_prefix) === 0) $parameters[$key] = $value;
        }
        foreach($this->original as $key=>$value) {
            if(!empty($this->_prefix) && strpos($key, $this->_prefix) === 0) $original[$key] = $value;
        }

        return $this->__recursiveSerialize(array_merge($parameters, $original));
    }

    public function isInjectable($key) {
        // Checks if the key is injectable based on the provided configuration
        if(in_array($key, $this->_skip_fuzz)) return false;

        # Built in definition - Ugly - TODO: Move to configuration files
        $_accepted = ['SERVER_PROTOCOL','REQUEST_METHOD','QUERY_STRING','REQUEST_URI'];
        if($this->_prefix !== '' && strpos($key, $this->_prefix) !== 0) {
            if($this->_superglobal_name !== '_SERVER') return false;
            if(!in_array($key, $_accepted)) return false;
        }

        // Inject only into one parameter in one execution
        if(!empty($this->_options['oneParamPerPayload']) && $this->_options['oneParamPerPayload']) {
            if(!empty($this->_injectable)) {
                if(empty($this->_injectable[$this->_superglobal_name])) return false;
                if($this->_injectable[$this->_superglobal_name] == $key) return true;
                return false;
            }
            else if($this->_injected) return false;
            else return true;
        }

        // Decide if payload is going to be injected
        // Warning: This does not apply if the oneParamPerPayload setting is defined.
        //  It's handled higher in that function
        if(rand() % 100 >= intval($this->_global_probability)) return false;
        return true;
    }

    protected function _replaceDynamic($payload) {
        global $_CakeFuzzerPayloadGUIDs;
        return $_CakeFuzzerPayloadGUIDs->replaceDynamicPayload($payload);
    }

    public function __toString() {
        return $this->_superglobal_name.'_magic';
    }
}


class MagicArray extends MagicPayloadDictionary implements ArrayAccess, Iterator, Countable {
    private $_index = 0;
    function offsetSet($offset, $value) {
        return $this->checkIfNotIdenticalAndSet($offset, $value);
    }

    function offsetExists($offset) {
        return $this->getAndSaveForFurtherGets($offset) !== null;
    }

    function offsetUnset($offset) {
        $this->original[$offset] = null;
    }

    function offsetGet($offset) {
        return $this->getAndSaveForFurtherGets($offset);
    }

    // Iterator methods. This supports foreach($_GET as $k=>$v) constructs
    function _getAllKeys() {
        $copy = $this->getCopy();
        return array_keys($copy);
    }
    function rewind() {
        $this->_index = 0;
    }

    function current() {
        $parameters = $this->getCopy();
        $k = array_keys($parameters);
        return $parameters[$k[$this->_index]];
    }

    function key() {
        $parameters = $this->getCopy();
        $k = array_keys($parameters);
        return $k[$this->_index];
    }

    function next() {
        $parameters = $this->getCopy();
        $k = array_keys($parameters);
        if(isset($k[++$this->_index])) return $parameters[$k[$this->_index]];
        return false;
    }

    function valid() {
        $parameters = $this->getCopy();
        $k = array_keys($parameters);
        return isset($k[$this->_index]);
    }

    // Countable methods. This supports count($_GET)
    function count() {
        if($this->_prefix === '') {
            $parameters = $this->parameters;
            $original = $this->original;
        }
        else $parameters = $original = array();

        foreach($this->parameters as $key=>$value) {
            if(!empty($this->_prefix) && strpos($key, $this->_prefix) === 0) $parameters[$key] = $value;
        }
        foreach($this->original as $key=>$value) {
            if(!empty($this->_prefix) && strpos($key, $this->_prefix) === 0) $original[$key] = $value;
        }

        $merged = array_merge($parameters, $original);
        foreach($merged as $k=>$v) {
            if(!isset($merged[$k])) unset($merged[$k]);
        }
        return count($merged);
    }
}

class MagicArrayOrObject extends MagicArray {
    function __get($offset) {
        return $this->getAndSaveForFurtherGets($offset);
    }
}

class AccessLoggingArray implements ArrayAccess {
    // $_FILES superglobal class
    private $_superglobal_name = '';
    function __construct($name) {
        global $_CakeFuzzerFuzzedObjects;
        $_CakeFuzzerFuzzedObjects->addObject($name, $this);

        $this->_superglobal_name = $name;
    }

    function offsetSet($offset, $value) {
        /* Currently a noop */
    }

    function offsetExists($offset) {
        return true;
    }

    function offsetUnset($offset) {
        /* Currently a noop */
    }

    function offsetGet($offset) {
        logDebug('AccessLoggingArray', $this->_superglobal_name . "[$offset]");
    }
}

class AppInstrument {
    // Dynamic application instrumentation.
    // Overwrites superglobal variables and provides more control of their access.
    private $_app_handler = null;
    private $_web_root = null;
    private $_webroot_file = null;
    private $_payloads = array();
    private $_injectable = null;
    private $_attack_target = array();
    private $_attack_exclude = array();
    private $_attack_skip_keys = array();
    private $_options = array();

    public function __construct($config) {
        if(!$this->_configVerify($config)) die;
        $this->_parseConfig($config);
        $this->_cleanGlobal('_SERVER');
    }

    protected function _configVerify($config) {
        $warnings = array();
        if(empty($config)) {
            warning("Please provide a valid execution configuration in a JSON format");
            die;
        }

        if(empty($config['web_root'])) {
            $warnings[] = "Provide the 'web_root' configuration option.";
        }
        else if(!is_dir($config['web_root'])) {
            $warnings[] = "File ${config['web_root']} does not exist or is not a directory. Provide a correct path to your webroot directory";
        }

        if(empty($config['webroot_file'])) {
            $warnings[] = "Provide the 'webroot_file' configuration option.";
        }
        else if(!file_exists($config['webroot_file'])) {
            $warnings[] = "File ${config['webroot_file']} does not exist. Provide a correct path to php file to fuzz.";
        }

        if(empty($config['payloads'])) {
            $warnings[] = "No payloads provided for fuzzing. Exiting.";
        }

        // This is optional so not stoping atm
        // if(isset($config['super_globals']) && !is_array($config['super_globals'])) {
        //     $warnings[] = "super_globals has to be a dictionary";
        //     return false;
        // }

        if(!empty($warnings)) {
            warning($warnings);
            die;
        }

        return true;
    }

    protected function _parseConfig($config) {
        global $_CakeFuzzerPayloadGUIDs;
        // Assign static super globals according to the config
        if(isset($config['super_globals'])) foreach($config['super_globals'] as $global => $values) {
            global $$global;
            foreach($values as $key => $value) {
                $$global[$key] = $value;
            }
        }

        # Assign HTTP method if not provided. TODO: Move to different part of the code.
        if(empty($_SERVER['REQUEST_METHOD'])) {
            $methods = array('GET', 'POST');
            $_SERVER['REQUEST_METHOD'] = $methods[array_rand($methods)];
            if($_SERVER['REQUEST_METHOD'] == "POST") $_POST["_method"] = "POST";
        }

        # Assign Accept header if not provided. TODO: Move to different part of the code.
        if(empty($_SERVER['HTTP_ACCEPT'])) {
            $accept = array('application/json',
                'application/xml',
                'text/csv',
                'text/html',
                'text/plain'
            );
            $_SERVER['HTTP_ACCEPT'] = $accept[array_rand($accept)];
        }

        $payloads = $config['payloads'];

        $options = [];

        if(empty($config['oneParamPerPayload'])) $options['oneParamPerPayload'] = false;
        else $options['oneParamPerPayload'] = $config['oneParamPerPayload'];

        if(!empty($config['PAYLOAD_GUID_phrase'])) $_CakeFuzzerPayloadGUIDs->set_payload_guid_phrase($config['PAYLOAD_GUID_phrase']);

        $injectable = null;
        if(!empty($config['injectable'])) $injectable = $config['injectable'];

        $targets = null;
        if(!empty($config['global_targets'])) $targets = $config['global_targets'];

        $exclude = null;
        if(!empty($config['global_exclude'])) $exclude = $config['global_exclude'];

        $fuzz_skip_keys = null;
        if(!empty($config['fuzz_skip_keys'])) $fuzz_skip_keys = $config['fuzz_skip_keys'];

        if(!empty($config['known_keywords'])) $payloads = array_unique(array_merge($payloads, $config['known_keywords']));

        $this->_web_root = $config['web_root'];
        chdir($this->_web_root); // This is required if the app does "include './file.php'
        
        $this->_webroot_file = $config['webroot_file'];
        $this->initialize($payloads, $injectable, $targets, $exclude, $fuzz_skip_keys, $options);
    }

    protected function _cleanGlobal($global) {
        $globals = array('_GET', '_POST', '_REQUEST', '_COOKIE', '_SERVER', '_FILES');
        if(!in_array($global, $globals)) return;

        if($global == '_SERVER') {
            unset($_SERVER['argc']);
            unset($_SERVER['argv']);
            unset($_SERVER['PATH_TRANSLATED']);
            unset($_SERVER['_']);
            $_SERVER['SCRIPT_FILENAME'] = $this->_webroot_file; // Not tested.
            $_SERVER['PHP_SELF'] = DIRECTORY_SEPARATOR . basename($this->_webroot_file);
            $_SERVER['SCRIPT_NAME'] = DIRECTORY_SEPARATOR . basename($this->_webroot_file);
            $_SERVER['DOCUMENT_ROOT'] = dirname($this->_webroot_file);
        }
    }

    private function _loadAppHandler() {
        if(!is_null($this->_app_handler)) return true;

        include "FrameworkLoader.php";
        try {
            $loader = new FrameworkLoader($this->_web_root, 'get_framework_info', array());
            if(!$loader->isFrameworkSupported()) {
                logError(get_class($this), "The application is based on unsupported framework. Supported frameworks: ".implode(", ",$loader->GetSupportedFrameworks()));
                return false;
            }
        } catch(Exception $e) {
            logError(get_class($this), $e->getMessage());
            return false;
        }

        // Consider merging this method with AppInfo
        $this->_app_handler = $loader->getAppHandler();
        return true;
    }

    public function initialize($payloads, $injectable = null, $target = null, $exclude = null, $fuzz_skip_keys = null, $options = array()) {
        $this->_payloads = $payloads;
        if(!empty($injectable)) $this->_injectable = $injectable;
        if(is_array($target)) $this->_attack_target = $target; // TODO: Not implemented
        if(is_array($exclude)) $this->_attack_exclude = $exclude;
        if(is_array($fuzz_skip_keys)) $this->_attack_skip_keys = $fuzz_skip_keys;
        // $config = false;
        // if(file_exists('../config/config.yaml')) {
        //     $config = '../config/config.yaml';
        // }
        $this->_options = $options;
    }

    public function reinitializeGlobals() {
        // Overwriting superglobal variables
        $globals = array('_GET', '_REQUEST', '_COOKIE', '_SERVER'); // TODO: Implement _FILES
            // Currently overwriting _FILES (if is MagicArraY) return: PHP Fatal error:  Uncaught TypeError: Argument 1 passed to Hash::insert() must be of the type array, object given, called in /var/www/MISP/app/Lib/cakephp/lib/Cake/Network/CakeRequest.php on line 402 and defined in /var/www/MISP/app/Lib/cakephp/lib/Cake/Utility/Hash.php:260
            // If it's AccesLoggingArray the output breaks because there is no getCopy() method in it
        if($_SERVER['REQUEST_METHOD'] == 'POST') $globals[] = '_POST';

        foreach($globals as $global) {
            if(!in_array($global, $this->_attack_exclude)) {
                global $$global;
                $files = false;
                $original = array();
                $prefix = '';

                $skip_fuzz = array();
                if(!empty($this->_attack_skip_keys[$global]) && is_array($this->_attack_skip_keys[$global])) $skip_fuzz = $this->_attack_skip_keys[$global];

                switch($global) {
                    case '_FILES':
                        $files = true;
                        break;
                    case '_SERVER':
                    // TODO: Remove tables injected into server variables & _COOKIE
                        if($$global instanceof MagicArray) $original = $$global->original;
                        else $original = $$global;
                        $prefix = 'HTTP_';
                        break;
                    default:
                        if(!empty($$global)) $original = $$global;
                }

                if($files) $$global = new AccessLoggingArray('_FILES');
                else $$global = new MagicArray($global, $prefix, $original, $this->_payloads, $this->_injectable, $skip_fuzz, $this->_options);
            }
        }
    }

    public function getWebRootFile() {
        return $this->_webroot_file;
    }

    public function handleExit() {
        global $_CAKEFUZZER_OUTPUT_SENT;
        $this->_loadAppHandler();
        if(!$_CAKEFUZZER_OUTPUT_SENT) {
            $results = $this->_prepareResults();
            $_CAKEFUZZER_OUTPUT_SENT = true;
            print(json_encode($results)."\n");
        }
    }

    private function _prepareResults() {
        global $_CakeFuzzerResponseHeaders, $_CAKEFUZZER_PATH, $_CakeFuzzerPayloadGUIDs, $_CakeFuzzerTimer, $_CAKEFUZZER_INSTRUMENTOR;
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
            'first_http_line' => $_CakeFuzzerResponseHeaders->GetFirstLine(),
            'method' => $_SERVER['REQUEST_METHOD'],
            'path' => $_CAKEFUZZER_INSTRUMENTOR->updatePath($_CAKEFUZZER_PATH),
            'headers' => $_CakeFuzzerResponseHeaders->GetAllHeaders(),
            'output' => $output,
            '_GET' => $get,
            '_POST' => $post,
            '_REQUEST' => $request,
            '_COOKIE' => $cookie,
            '_FILES' => $files,
            '_SERVER' => $server,
            'PAYLOAD_GUIDs' => $_CakeFuzzerPayloadGUIDs->GetPayloadGUIDs(),
            'exec_time' => $_CakeFuzzerTimer->FinishTimer(true)
        );
        return $result;
    }

    public function getLoadedPayloads() {
        return $this->_payloads;
    }

    public function updatePath($path) {
        if(!is_null($this->_app_handler) && method_exists($this->_app_handler, 'updatePath'))
            return $this->_app_handler->updatePath($path);
        return $path;
    }
}

function __get_http_path($path, $payloads, $payload_guid_phrase=null) {
    global $_CakeFuzzerPayloadGUIDs;
    $probability = 50; # TODO: From config
    $default = rand(0,100);  # TODO: Take this from config maybe?
    $fuzz_const = "_CAKE_FUZZER_";
    $pattern = "/${fuzz_const}[a-zA-Z0-9_]+/i";
    $inject = null;

    preg_match_all($pattern, $path, $dynamic_parts);

    if(!empty($dynamic_parts[0]) && rand() % 100 < $probability) {
        $inject = $dynamic_parts[0][array_rand($dynamic_parts[0])];
    }

    $selected_payload = $payloads[array_rand($payloads)];
    // Don't urlencode Payload GUID Phrase
    if($payload_guid_phrase) $selected_payload = implode($payload_guid_phrase, array_map('urlencode', explode($payload_guid_phrase, $selected_payload)));

    $path = str_replace($inject, $selected_payload, $path);
    foreach($dynamic_parts[0] as $dyn_part) {
        if($inject !== $dyn_part) {
            $path = str_replace($dyn_part, $default, $path);
        }
    }

    return $_CakeFuzzerPayloadGUIDs->replaceDynamicPayload($path);
}

function __cake_fuzzer_prepare_path($config) {
    $path = __get_http_path($config["path"], $config["payloads"], $config["PAYLOAD_GUID_phrase"]);
    $_SERVER["PATH_INFO"] = $path;
    $_SERVER["REQUEST_URI"] = $path;
    $_SERVER["QUERY_STRING"] = $path;
    return $path;
}

function __get_http_path_complex_dict_UNUSED($path, $payloads) {
    /* Here is an example JSON that can be parsed by this function:
            {
                "type": "all",
                "values": [
                    self.path,
                    {
                        "type": "string",
                        "default": ""
                    },
                    {
                        "type": "any",
                        "values": [
                            "",
                            "/",
                            {
                                "type": "all",
                                "values": [
                                    "/",
                                    {
                                        "type": "string",
                                        "default": ""
                                    },
                                    {
                                        "type": "any",
                                        "values": ["", "/"]
                                    }
                                ]
                            },
                            {
                                "type": "all",
                                "values": [
                                    "/",
                                    {
                                        "type": "string",
                                        "default": ""
                                    },
                                    "/",
                                    {
                                        "type": "all",
                                        "values": [
                                            {
                                                "type": "string",
                                                "default": ""
                                            },
                                            {
                                                "type": "any",
                                                "values": ["", "/"]
                                            }
                                        ]
                                    }
                                ]
                            },
                        ]
                    }
                ]
            }
     */
    $probability = 50;
    if(is_string($path)) return $path;
    if(is_array($path)) {
        if(empty($path["type"])) throw Exception("[!] Error: Path fragment does not contain 'type' key.");
        $result = '';
        switch($path["type"]) {
            case "string":
                $result = '';
                if(!empty($path["default"])) $result = $path["default"];
                if(rand() % 100 < $probability) $result = $payloads[array_rand($payloads)];
                break;
            case "any":
                $result = '';
                if(empty($path["values"])) logWarning("Path fragment type 'any' has no key: 'values'");
                $result = __get_http_path_complex_dict_UNUSED($path["values"][array_rand($path["values"])], $payloads);
                break;
            case "all":
                if(empty($path["values"])) logWarning("Path fragment type 'all' has no key: 'values'");
                foreach($path["values"] as $fragment) {
                    $result .= __get_http_path_complex_dict_UNUSED($fragment, $payloads);
                }
                break;
            default:
                logWarning("Unsupported path fragment type: ${path['type']}");
        }
        return $result;
    }
    throw Exception("[!] Error: Path variable cannot be type other than string or array. '".type($path)."' given.");
}
