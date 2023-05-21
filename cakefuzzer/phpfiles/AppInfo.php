<?php

class AppInfo {
    private $_command = "";
    private $_output_format = null;
    private $_app_handler = null;
    private $_web_root = "";
    private $_index_path = "";
    private $_app_vars = array();

    public function loadInput() {
        // if(!$this->_loadAppHandler()) return false; // this requires to include the target app first
        if(count($_SERVER['argv']) < 3) return false;
        if(!in_array($_SERVER['argv'][2], $this->_GetAvailableCommands())) {
            $this->_info("Command '{$_SERVER['argv'][2]}' not found.");
            return false;
        }

        // Parse index
        $this->_index_path = $this->_getIndex($_SERVER['argv'][1]);
        $this->_web_root = $_SERVER['argv'][1];
        if(!is_dir($this->_web_root)) {
            $this->_info($this->_web_root." was not found");
            return false;
        }
        set_include_path(get_include_path() . PATH_SEPARATOR . $this->_web_root);
        chdir($this->_web_root);

        // Parse output format
        $this->_output_format = "json";
        if(count($_SERVER['argv']) > 3 && in_array($_SERVER['argv'][3], $this->_GetAvailableFormats())) {
            $this->_output_format = $_SERVER['argv'][3];
        }
        $this->_command = $_SERVER['argv'][2];

        return true;
    }

    public function printHelp() {
        $this->_info("Usage: php {$_SERVER['argv'][0]} path/to/cakephp/app/webroot <commad> [output_format]");
        $this->_info(" Available actions: ".implode(", ", $this->_GetAvailableCommands()));
        $this->_info(" Available output formats: ".implode(", ", $this->_GetAvailableFormats()));
        $this->_info("Example: php app_info.php /var/www/MISP/app/webroot/ get_controllers raw");
    }

    public function getIndex() {
        return $this->_index_path;
    }

    public function setAppVars($app_vars) {
        $keyword = "_CakeFuzzer";
        $to_remove = array("_GET", "_POST", "_COOKIE", "_FILES", "_ENV", "_REQUEST", "_SERVER", "argv", "argc", "GLOBALS");
        foreach($to_remove as $key) if(isset($app_vars[$key])) unset($app_vars[$key]);
        foreach($app_vars as $key=>$val) if(strlen($key)>strlen($keyword) && substr($key, 0, strlen($keyword)) === $keyword) unset($app_vars[$key]);
        $this->_app_vars = $app_vars;
    }

    public function handleExit() {
        if($this->_loadAppHandler()) {
            $x = func_get_args();
            $result = $this->_app_handler->CommandHandler($x);
            print($this->_FormatResponse($result, $this->_output_format));
        }
        if(count($x)) exit($x[0]);
        exit();
    }

    private function _loadAppHandler() {
        if(!is_null($this->_app_handler)) return true;

        include "FrameworkLoader.php";
        try {
            $loader = new FrameworkLoader($this->_web_root, $this->_command, $this->_app_vars);
            if(!$loader->isFrameworkSupported()) {
                $this->_error("The application is based on unsupported framework. Supported frameworks: ".implode(", ",$loader->GetSupportedFrameworks()));
                return false;
            }
        } catch(Exception $e) {
            $this->_error($e->getMessage());
            return false;
        }

        $this->_app_handler = $loader->getAppHandler();
        return true;
    }

    private function _getIndex($webroot) {
        // Returns index.php path of the Cake PHP web application
        $start_file = 'index.php'; // Note: There could be several index.php files
        if($webroot[-1] === '/') $webroot = substr_replace($webroot, '', -1);
        if(substr($webroot, -4) === '.php') $app_index = $webroot;
        else $app_index = $webroot.'/'.$start_file;
        if(!file_exists($app_index)) {
            $this->_warning("File $app_index does not exist. Provide a correct path to your CakePHP webroot directory");
            die;
        }
        return $app_index;
    }

    /**
     * Format response of the app_info script
     * @param mixed Output to be returned to the user
     * @param string Format of the output. Available: json, raw, var_dump
     *
     * @return string
     */
    private function _FormatResponse($php_output, $format='json') {
        if($format === "" || is_null($format)) $format = "json";
        if($format === 'var_dump') return var_export($php_output, true);

        $php_output = $this->_PreprocessOutput($php_output);

        if($format === 'raw') {
            if(is_array($php_output)) {
                $result = "";
                foreach($php_output as $v) $result .= "$v\n"; // This ommits the array key
                return $result;
            }
            if(is_string($php_output) || is_int($php_output)) return strval($php_output)."\n";
            return $this->_FormatResponse($this->_getErrorArray("error", "PHP output is something different than array, string, or int. Can't use raw output. Only json or var_dump"));
        }
        if($format === 'json') return json_encode($php_output, JSON_PRETTY_PRINT)."\n";
        return $this->_FormatResponse($this->_getErrorArray("error", "Requested output format \"$format\" not impleented."));
    }

    /**
     * Prepare arrays without string in keys to be treated as list not dicts.
     * Prepare nested arrays.
     * TODO: Handle circular reference objects.
     *
     * @return mixed
     */
    private function _PreprocessOutput($php_output) {
        if(is_array($php_output)) {
            $is_string = false;
            $keys = array_keys($php_output);
            foreach($keys as $key) if(is_string($key)) {
                $is_string = true;
                break;
            }
            if(!$is_string) {
                $new_array = array();
                foreach($php_output as $val) $new_array[] = $val;
                $php_output = $new_array;
            }
            $result = array();
            foreach($php_output as $key => $value) {
                if(is_array($value)) {
                    $result[$key] = $this->_PreprocessOutput($value);
                }
                else $result[$key] = $value;
            }
        }
        else $result = $php_output;
        return $result;
    }

    private function _GetAvailableCommands() {
        if(is_null($this->_app_handler)) {
            return array(
                'get_routes', 'get_controllers', 'get_components',
                'get_actions', 'get_controllers_actions_arguments', 'get_plugins',
                'get_log_paths', 'get_users', 'get_db_info', 'get_framework_info', 'get_paths'
            );
        }
        return array_keys($this->_app_handler->available_commands);
    }

    private function _GetAvailableFormats() {
        return array('raw', 'json', 'var_dump');
    }

    private function _getErrorArray($type, $message) {
        return array('error' => array('type' => $type, 'message' => $message));
    }

    private function _genericError($type, $message) {
        $result = $this->_getErrorArray($type, $message);
        print($this->_FormatResponse($result, $this->_output_format));
    }

    private function _info($message) {
        $this->_genericError('info', $message);
    }

    private function _warning($message) {
        $this->_genericError('warning', $message);
    }

    private function _error($message, $exit=true) {
        $this->_genericError('error', $message);
        if($exit) exit();
    }
}