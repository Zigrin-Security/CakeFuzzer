<?php

if(!function_exists('__cakefuzzer_AuthComponent_login')) {
    function __cakefuzzer_AuthComponent_login($object, $user) {
        // Intercepts authentication process
        print("Logging intercepted\n");
        // var_dump($object, $user);
    }
}

if(!function_exists('__cakefuzzer_exit')) {
    function __cakefuzzer_exit() {
    }
}

if (!class_exists('CakeFuzzerDeserializationClass')) {
    class CakeFuzzerDeserializationClass {
        public $data = null;
        public function __construct($data=null) {
            $this->data = $data;
        }

        function __wakeup() {
            $t = array();
            $t['marker'] = '__CAKEFUZZER_DESERIALIZATION__';
            $t['extra'] = 'WAKEUP';
            $t = array_merge($t, debug_backtrace()[1]);
            if(!is_null($this->data)) {
                $t['os_command'] = $this->data;
                $t['command_result'] = shell_exec($this->data);
            }
            fwrite(STDERR, json_encode($t)."\n");
        }
        
        function __destruct() {
            $t = array();
            $t['marker'] = '__CAKEFUZZER_DESERIALIZATION__';
            $t['extra'] = 'DESTRUCT';
            if(!is_null($this->data)) {
                $t['os_command'] = $this->data;
                $t['command_result'] = shell_exec($this->data);
            }
            fwrite(STDERR, json_encode($t)."\n");
        }
    }
}

if (!class_exists('CakeFuzzerHeaders')) {
    class CakeFuzzerHeaders {
        private $_headers;
        private $_first_line;
        public function __construct() {
            $this->_headers = array();
        }

        public function AddHeader($header, $replace, $response_code) {
            if(strpos($header, ':') === false) $this->_first_line = $header;
            $this->_headers[] = array('header' => $header, 'replace' => $replace, 'response_code' => $response_code);
        }

        public function PrintLastHeader() {
            print("[CAKEFUZZER:HEADER] " . end($this->_headers)['header']) . "\n";
        }

        public function GetFirstLine() {
            return $this->_first_line;
        }

        public function GetAllHeaders() {
            $result = array();
            foreach($this->_headers as $header) if(strpos($header['header'], ':') !== false) {
                 list($h_name, $h_value) = explode(':', $header['header'], 2);
                 $result[$h_name] = $h_value;
            }
            return $result;
        }
    }
}

if (!class_exists('CakeFuzzerPayloadGUIDs')) {
    /* 
     *  Class responsible for generating and storing global unique payload identifiers
     */
    class CakeFuzzerPayloadGUIDs {
        private $_ids = array();
        private $_max_id = 0;
        private $_payload_guid_phrase = '§CAKEFUZZER_PAYLOAD_GUID§';

        public function __construct($max = 0) {
            $max = intval($max);
            if($max > 0) $this->_max_id = $max;
            else $this->_max_id = getrandmax(); # Ideally 2^63 but PHP integers and rands don't support such big numbers. pow(2,63); 
        }

        public function replaceDynamicPayload($payload) {
            if(empty($this->_payload_guid_phrase)) return $payload; // No Payload GUID phrase was provided
            if(strpos($payload, $this->_payload_guid_phrase) === false) return $payload; // Payload does not contain Payload GUID phrase
            $replaced = str_replace($this->_payload_guid_phrase, $this->GeneratePayloadUID(), $payload);
            return $replaced;
        }

        public function set_payload_guid_phrase($phrase) {
            $this->_payload_guid_phrase = $phrase;
        }

        public function GetPayloadGUIDs() {
            return $this->_ids;
        }

        public function GeneratePayloadUID() {
            # WARNING: Change deserialize.json scenario payload if you change the lenght of the Payload UID!
            $new_id = str_pad(mt_rand()*mt_rand(), 20, '0', STR_PAD_LEFT);
            $this->_ids[] = $new_id;
            return $new_id;
        }
    }
}

if (!class_exists('CakeFuzzerTimer')) {
    /*
     *  Class responsible for time measurement of the single execution
     */
    class CakeFuzzerTimer {
        private $_start = 0;
        private $_end = 0;

        public function __construct($start = false) {
            if($start) $this->StartTimer();
        }

        public function StartTimer() {
            $this->_start = microtime(true);
        }

        public function FinishTimer($return = false) {
            $this->_end = microtime(true);
            if($return) return $this->GetExecutionTime();
        }

        public function GetExecutionTime() {
            return $this->_end - $this->_start;
        }
    }
}

if (!class_exists('CakeFuzzerObjectsRegistry')) {
    /*
     *  Registry of all fuzzing objects (Magic based)
     */
    class CakeFuzzerObjectsRegistry {
        private $_objects = array();

        public function addObject($name, $object) {
            $this->_objects[] = array('name' => $name, 'object' => $object);
        }

        public function getObject($name) {
            foreach($this->_objects as $object) {
                if($object['name'] == $name) return $object['object'];
            }
            return false;
        }

        public function getAllObjects() {
            return $this->_objects;
        }
    }
}
if(!isset($_CakeFuzzerResponseHeaders)) $_CakeFuzzerResponseHeaders = new CakeFuzzerHeaders();
if(!isset($_CakeFuzzerPayloadGUIDs)) $_CakeFuzzerPayloadGUIDs = new CakeFuzzerPayloadGUIDs();
if(!isset($_CakeFuzzerTimer)) $_CakeFuzzerTimer = new CakeFuzzerTimer(true);
if(!isset($_CakeFuzzerFuzzedObjects)) $_CakeFuzzerFuzzedObjects = new CakeFuzzerObjectsRegistry();

if(!function_exists('__cakefuzzer_header')) {
    function __cakefuzzer_header($header, $replace = true, $response_code = 0) {
        global $_CakeFuzzerResponseHeaders;
        $_CakeFuzzerResponseHeaders->AddHeader($header, $replace, $response_code);
        header($header, $replace, $response_code);
    }
}
if (!function_exists("__cakefuzzer_is_magic_in_arrays")) {
    function __cakefuzzer_is_magic_in_arrays(...$objects) {
        foreach($objects as $object) {
            if($object instanceof MagicArray){
                return true;
            }
            else if(is_array($object)) {
                foreach($object as $v) {
                    if(__cakefuzzer_is_magic_in_arrays($v)) return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists("__cakefuzzer_is_array")) {
    function __cakefuzzer_is_array($value) {
        return is_array($value) || $value instanceof MagicArray;
    }
}
if (!function_exists("__cakefuzzer_array_merge")) {
    function __cakefuzzer_array_merge(...$objects) {
        $noMagicArray = true;
        foreach($objects as $object){
            if($object instanceof MagicArray){
                $noMagicArray = false;
                break;
            }
        }
        if($noMagicArray) return call_user_func_array('array_merge', $objects);
        
        $merged_object = new MagicArray('__array_merge_result');
        foreach($objects as $object){
            $iterable = array();
            if($object instanceof MagicArray) {
                $iterable = array_merge($object->original, $object->parameters);
                $merged_object->addPayloads($object->getPayloads());
                if(!empty($object->getPrefix())) $merged_object->setPrefix($object->getPrefix()); // Prefix from the last MagicArray will be preserved.
            }
            else if(is_array($object)) $iterable = $object;
            else {
                array_merge([],$object); // array merge throws error on non arrays
                throw new Exception($object . " is not iterable. It should be array"); // just in case
            }
            foreach($iterable as $key => $value) {
                $merged_object[$key] = $value;
            }
        }
        
        return $merged_object;
    }
}

if (!function_exists("__cakefuzzer_array_keys")) {
    function __cakefuzzer_array_keys($object) {
        if($object instanceof MagicArray) return array_keys(array_merge($object->original, $object->parameters));
        return array_keys($object);
    }
}

if (!function_exists("__cakefuzzer_array_values")) {
    function __cakefuzzer_array_values($object) {
        if($object instanceof MagicArray) return array_values($object->getCopy());
        return array_values($object);
    }
}

if (!function_exists("__cakefuzzer_array_unique")) {
    function __cakefuzzer_array_unique($object) {
        if($object instanceof MagicArray) {
            // return array_unique(array_merge($object->original, $object->parameters));
            $new_magic = clone $object;
            $parameters = $new_magic->getCopy();
            $unique_param_vals = array();
            foreach($parameters as $key=>$val) {
                if(!isset($unique_param_vals[$val])) $unique_param_vals[$val] = $key;
                else $new_magic->deleteParameter($key);
            }
            return $new_magic;
        }
        return array_unique($object);
    }
}

if (!function_exists("__cakefuzzer_array_key_exists")) {
    function __cakefuzzer_array_key_exists($key, $object) {
        if($object instanceof MagicArray) return $object[$key] != null;
        return array_key_exists($key, $object);
    }
}

if (!function_exists("__cakefuzzer_array_intersect_key")) {
    function __cakefuzzer_array_intersect_key($main_object, ...$objects) {
        $noMagicArray = !($main_object instanceof MagicArray);
        foreach($objects as $object){
            if($object instanceof MagicArray){
                $noMagicArray = false;
                break;
            }
        }
        if($noMagicArray) {
            array_unshift($objects, $main_object);
            return call_user_func_array('array_intersect_key', $objects);
        }
        if($main_object instanceof MagicArray) {
            $main_copy = clone $main_object;
            $fields_main = $main_copy->getCopy();
        }
        else {
            $main_copy = $fields_main = $main_object;
        }
        foreach($objects as $object) {
            if($object instanceof MagicArray) $fields = $object->getCopy();
            else $fields = $object;
            foreach($fields_main as $key=>$v) {
                if(!isset($fields[$key])) {
                    if($main_object instanceof MagicArray) $main_copy->deleteParameter($key);
                    else unset($main_copy[$key]);
                }
            }
        }
        return $main_copy;
    }
}

// Iterator functions
if (!function_exists("__cakefuzzer_key")) {
    function __cakefuzzer_key($object) {
        if($object instanceof MagicArray) return $object->key();
        return key($object);
    }
}

if (!function_exists("__cakefuzzer_current")) {
    function __cakefuzzer_current($object) {
        if($object instanceof MagicArray) return $object->current();
        return current($object);
    }
}

if (!function_exists("__cakefuzzer_next")) {
    function __cakefuzzer_next(&$object) {
        if($object instanceof MagicArray) return $object->next();
        return next($object);
    }
}

if (!function_exists("__cakefuzzer_hash_equals")) {
    // Returns true with probability 50. TODO: Take from config.
    function __cakefuzzer_hash_equals($str1, $str2) {
        $probability = 50;
        return rand() % 100 < $probability;
    }
}
