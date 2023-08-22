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

if (!function_exists("__cakefuzzer_in_array")) {
    function __cakefuzzer_in_array($needle, $haystack, $strict=false) {
        if($haystack instanceof MagicArray) return in_array($needle, $haystack->getCopy(), $strict);
        else if(is_string($haystack)) return false;
        return in_array($needle, $haystack, $strict);
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

// TODO: Missing case when not-first arg object is MagicArray
if (!function_exists("__cakefuzzer_array_map")) {
    function __cakefuzzer_array_map($callback, $object, ...$objects) {
        // Case when the first arg object is MagicArray
        if($object instanceof MagicArray) {
            $new_magic = clone $object;
            $copy = $new_magic->getCopy();

            $args = array($callback, $copy);
            if(!empty($objects)) $args = array_merge($args, $objects);

            $result = call_user_func_array('array_map', $args);
            foreach($result as $k=>$v) {
                $new_magic[$k] = $v;
            }
            return $new_magic;
        }

        // Case where non of the objects are MagicArray
        $args = array($callback, $object);
        if(!empty($objects)) $args = array_merge($args, $objects);
        $result = call_user_func_array('array_map', $args);
        return $result;
    }
}
// array_merge tests
// $r = __cakefuzzer_array_map('trim', $_POST);
// var_dump($r);
// die;
// // Array map Test 2 - default
// function show_Spanish(int $n, string $m): string
// {
//     return "The number {$n} is called {$m} in Spanish";
// }

// $a = [1, 2, 3, 4, 5];
// $b = ['uno', 'dos', 'tres', 'cuatro', 'cinco'];

// $r = array_map('show_Spanish', $a , $b);
// var_dump($r);
// echo "------------------------\n";

// // Array map Test 2
// $r = __cakefuzzer_array_map('show_Spanish', $a , $b);
// var_dump($r);
// echo "------------------------\n";

// // Array map Test 3
// $_GET = array('asd'=>' qwe   ');
// var_dump(__cakefuzzer_array_map('trim', $_GET));
// echo "------------------------\n";

// // Array map Test 4
// $func = function(int $value): int {
//     return $value * 2;
// };
// var_dump(__cakefuzzer_array_map($func, range(1,5)));
// echo "------------------------\n";

// // Array map Test 5
// $a = [1, 2, 3, 4, 5];
// $b = ['one', 'two', 'three', 'four', 'five'];
// $c = ['uno', 'dos', 'tres', 'cuatro', 'cinco'];

// $d = __cakefuzzer_array_map(null, $a, $b, $c);
// var_dump($d);
// echo "------------------------\n";

// // Array map Test 6
// $arr = [
//     'v1' => 'First release',
//     'v2' => 'Second release',
//     'v3' => 'Third release',
// ];
// $callback = fn(string $k, string $v): string => "$k was the $v";
// $result = __cakefuzzer_array_map($callback, array_keys($arr), array_values($arr));
// var_dump($result);
// echo "------------------------\n";

if (!function_exists("__cakefuzzer_ksort")) {
    function __cakefuzzer_ksort(&$object, $flags = SORT_REGULAR) {
        if($object instanceof MagicArray) {
            ksort($object->original);
            ksort($object->parameters);
            return $object;
        }
        return ksort($object, $flags);
    }
}

// Function to ignore non-string input. In case input is Array/MagicObject
if (!function_exists("__cakefuzzer_trim")) {
    function __cakefuzzer_trim($string, $characters = " \n\r\t\v\x00") {
        if(is_string($string)) return trim($string, $characters);
        return trim("", $characters);
    }
}

// Function to ignore non-string input. In case input is Array/MagicObject
if (!function_exists("__cakefuzzer_strlen")) {
    function __cakefuzzer_strlen($string) {
        if(is_string($string)) return strlen($string);
        return strlen("");
    }
}

// Function to ignore non-string input. In case input is Array/MagicObject
if (!function_exists("__cakefuzzer_strtolower")) {
    function __cakefuzzer_strtolower($string) {
        if(is_string($string)) return strtolower($string);
        return "";
    }
}

// Function to ignore non-string input. In case input is Array/MagicObject
if (!function_exists("__cakefuzzer_strpos")) {
    function __cakefuzzer_strpos($haystack, $needle, $offset = 0) {
        if(is_string($haystack) && is_string($needle)) return strpos($haystack, $needle, intval($offset));
        return false;
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
