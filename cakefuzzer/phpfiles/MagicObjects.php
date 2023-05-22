<?php
function _cakefuzzer_logMessage($module, $log_level, $message, $destination=STDERR) {
    // Unifies logging messages
    $time = date('Y-m-d H:i:s');
    $log_entry = "[$time]:$module:$log_level:$message";
    if($log_level<=LOGGING_LEVEL) {
        fwrite($destination, $log_entry);
    }
}

function _cakefuzzer_logError($module, $message, $destination=STDERR) {
    return _cakefuzzer_logMessage($module, LOGGING_LEVELS['ERROR'], $message, $destination);
}

function _cakefuzzer_logWarning($module, $message, $destination=STDERR) {
    return _cakefuzzer_logMessage($module, LOGGING_LEVELS['WARNING'], $message, $destination);
}

function _cakefuzzer_logInfo($module, $message, $destination=STDERR) {
    return _cakefuzzer_logMessage($module, LOGGING_LEVELS['INFO'], $message, $destination);
}

function _cakefuzzer_logDebug($module, $message, $destination=STDERR) {
    return _cakefuzzer_logMessage($module, LOGGING_LEVELS['DEBUG'], $message, $destination);
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
        _cakefuzzer_logDebug('AccessLoggingArray', $this->_superglobal_name . "[$offset]");
    }
}