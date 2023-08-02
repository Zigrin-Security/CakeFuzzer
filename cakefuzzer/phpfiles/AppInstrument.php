<?php
include_once "MagicObjects.php";
# Todo:
# - Replace is_array

define('LOGGING_LEVELS', array(
    'ERROR' => 1,
    'WARNING' => 2,
    'INFO' => 3,
    'DEBUG' => 4,
));
define('LOGGING_LEVEL', LOGGING_LEVELS['INFO']);

class AppInstrument {
    // Dynamic application instrumentation.
    // Overwrites superglobal variables and provides more control of their access.
    private $_app_handler = null;
    private $_framework_handler = null;
    private $_web_root = null;
    private $_webroot_file = null;
    private $_payloads = array();
    private $_injectable = null;
    private $_attack_target = array();
    private $_attack_exclude = array();
    private $_attack_skip_keys = array();
    private $_options = array();
    private $_extra_app_info = array();

    public function __construct($config) {
        global $_CAKEFUZZER_PATH;
        $_CAKEFUZZER_PATH = $this->__cake_fuzzer_prepare_path($config);

        if(!$this->_configVerify($config)) die;
        $this->_parseConfig($config);
        $this->_cleanGlobal('_SERVER');
        $this->_loadAppHandler();
    }

    protected function _configVerify($config) {
        $warnings = array();
        if(empty($config)) {
            warning("Please provide a valid execution configuration in a JSON format");
            die;
        }

        if(empty($config['framework_handler'])) {
            $warnings[] = "Provide the 'framework_handler' configuration option.";
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
            $accept = array('application/json' => 20,
                'application/xml' => 20,
                'text/csv' => 10,
                'text/html' => 40,
                'text/plain' => 10
            );
            $accept = $this->_prepareProbableArray($accept);
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

        $this->_framework_handler = $config['framework_handler'];
        $this->_web_root = $config['web_root'];
        chdir($this->_web_root); // This is required if the app does "include './file.php'
        
        $this->_webroot_file = $config['webroot_file'];

        if(!empty($config['extra_app_info'])) $this->_extra_app_info = $config['extra_app_info'];

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

        if(!class_exists("FrameworkLoader")) include "FrameworkLoader.php";
        try {
            $loader = new FrameworkLoader($this->_web_root, 'get_framework_info', array(), $this->_framework_handler);
            if(!$loader->isFrameworkSupported()) {
                _cakefuzzer_logError(get_class($this), "The application is based on unsupported framework. Supported frameworks: ".implode(", ",$loader->GetSupportedFrameworks()));
                return false;
            }
        } catch(Exception $e) {
            _cakefuzzer_logError(get_class($this), $e->getMessage());
            return false;
        }

        // Consider merging this method with AppInfo
        $this->_app_handler = $loader->getAppHandler();
        $this->_app_handler->SetExtraAppInfo($this->_extra_app_info);
        $this->_app_handler->PrepareFuzzing(); // Prepares framework specific stuff before fuzzing
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

    private function __get_http_path($path, $payloads, $payload_guid_phrase=null) {
        global $_CakeFuzzerPayloadGUIDs;
        $probability = 50; # TODO: From config
        $fuzz_const = "_CAKE_FUZZER_";
        $pattern = "/${fuzz_const}[a-zA-Z0-9_]+/i";
        $inject = null;
        $defaults = range(0,100);
        $defaults = array_merge($defaults, array_fill(0, 200, null));
    
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
                $path = str_replace($dyn_part, $defaults[array_rand($defaults)], $path); # TODO: Take this from config maybe?
            }
        }
    
        return $_CakeFuzzerPayloadGUIDs->replaceDynamicPayload($path);
    }
    
    private function __cake_fuzzer_prepare_path($config) {
        $path = $this->__get_http_path($config["path"], $config["payloads"], $config["PAYLOAD_GUID_phrase"]);
        $_SERVER["PATH_INFO"] = $path;
        $_SERVER["REQUEST_URI"] = $path;
        $_SERVER["QUERY_STRING"] = $path;
        $url = parse_url($path);
        if(isset($url['query'])) {
            $query = array();
            parse_str($url['query'], $query);
            foreach($query as $k=>$v) {
                $_GET[$k] = $v; // This can overwrite already defined _GET parameters passed via config
            }
        }
        return $path;
    }

    /**
     * Prepare the array to include probabilities of choices.
     * It's done by creating a flat array that contains more identical items
     * of higher probability and fewer items of lower probability.
     * 
     * @return array
     */
    protected function _prepareProbableArray($array) {
        // This should throw error instead of return empty array.
        if(!$this->_verifyProbableArray($array)) return array();
        
        // First calculate GCD, then divide all probabilities by the GCD
        $gcd = max($array);
        foreach($array as $k=>$v) $gcd = $this->_calculateGcd($gcd, $v);

        $probable_array = array();
        foreach($array as $k=>$v) {
        $probable_array = array_merge($probable_array, array_fill(0, $v/$gcd, $k));
        }
        return $probable_array;
    }
  
    /**
     * Verify if the array with probabilities is correctly formed.
     * Check if probabilities are integer and sum of them is equal to 100
     * 
     * @return bool
     */
    protected function _verifyProbableArray($array) {
        $sum = 0;
        foreach($array as $k=>$v) {
            if(!is_int($v)) return false;
            $sum += $v;
        }
        return $sum === 100;
    }
  
    /**
     * Calculate greatest common division of two numbers
     * 
     * @return int
     */
    protected function _calculateGcd($a, $b) {
        while ($b<>0) {
        $c = $a;
        $a = $b;
        $b = $c%$b;
        }
        return $a;
    }
}
