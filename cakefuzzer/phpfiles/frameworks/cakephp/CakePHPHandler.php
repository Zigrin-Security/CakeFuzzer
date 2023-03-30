<?php
class CakePHPHandler {
    public $executed = false;
    public $available_commands = array('get_cakephp_info'=>'_GetCakePHPInfoCommand');
    protected $_command = "";
    protected $_cake_version = "";
    protected $_app_vars = array();
    // Manually typed CakePHP classes used by app_info
    public $required_classes = array();

    public function __construct($command, $version, $app_vars) {
        $this->_command = $command;
        $this->_cake_version = $version;
        $this->_app_vars = $app_vars;
    }

    /**
     * Prehandler for app_info commands
     *
     * @return bool
     */
    public function CommandHandler($args) {
        // Interrupts exit calls to extract information about CakePHP application

        // This is needed because sometimes exit and shutdown functions are both executed without app actually exitting
        if($this->executed) {
            return false;
        }
        $this->executed = true;

        if($this->_command === 'get_cakephp_info') return $this->{$this->available_commands[$this->_command]}();
        if(!in_array($this->_command, array_keys($this->available_commands))) return $this->_CommandNotFound($this->_command);

        // Check if all CakePHP components are properly loaded
        if(!$this->_CheckIfCakeIsLoaded()) {
            $result = array('error' => 'CakePHP was not loaded properly. Missing CakePHP classes: '.implode(', ', $this->_GetMissingCakeObjects()));
            return $result;
        }

        // Clean all previous app output
        // ob_start();
        // $output = ob_get_clean();
        // ob_end_clean();

        // $target_app_args = func_get_args();
        // foreach($target_app_args as $arg) {
        //     var_dump($arg);
        // }
        return true;
    }

    /**
     * Return updated path with fuzzed parameters.
     * Frameworks might add meanings to different parts of the URL.
     * Example for CakePHP 2: /controller/action/param1:val1/param2:val2
     * Specific Framework handler should overwrite this method, fuzz those params and reconstruct the URL
     */
    public function updatePath($path) {
        return $path;
    }

    /**
     * Check if a specific class, array of classes, or all required CakePHP classes
     * are available.
     * @param mixed $class Class, array of classes, or null
     *
     * @return bool
     */
    protected function _CheckIfCakeIsLoaded($class = null) {
        if(is_string($class)) return class_exists($class);
        if(is_null($class)) $class = $this->required_classes;
        if(is_array($class)) {
            $classes = $class;
            foreach($classes as $class) {
                if(!class_exists($class)) return false;
            }
            return true;
        }
    }

    /**
     * Get missing CakePHP classes
     *
     * @return array
     */
    protected function _GetMissingCakeObjects() {
        $missing = array();
        foreach($this->required_classes as $class) {
            if(!$this->_CheckIfCakeIsLoaded($class)) $missing[] = $class;
        }
        return $missing;
    }

    /**
     * Return error message about not existing command
     * @param string Command that does not exist.
     *
     * @return array array('error' => 'Error message')
     */
    protected function _CommandNotFound($command = '') {
        $msg = 'Provided command not found';
        if(!empty($command)) $msg .= ': '.$command;
        return array('error' => $msg);
    }

    /**
     * Get information about CakePHP such as version and filesystem path.
     * Tested for CakePHP: 2 and 4
     *
     * @return array
     */
    protected function _GetCakePHPInfoCommand() {
        $app_dir = null;
        if(defined('APP')) $app_dir = APP;

        $info = array('cake_path' => CORE_PATH,
            'cake_version' => $this->_cake_version,
            'app_dir' => $app_dir,
            'app_root_dir' => ROOT
        );
        return $info;
    }

    /**
     * Get value of object's protected or private property
     */
    protected function _getObjectProperty($object, $property) {
        $object_reflection = new ReflectionClass($object);
        $property_reflection = $object_reflection->getProperty($property);
        $property_reflection->setAccessible(true);
        return $property_reflection->getValue($object);
    }
}