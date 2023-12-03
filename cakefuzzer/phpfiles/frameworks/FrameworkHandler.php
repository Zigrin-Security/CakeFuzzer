<?php
class FrameworkHandler {
    public $executed = false;
    public $available_commands = array(
        'get_framework_info' => '_GetFrameworkInfoCommand',
        'get_custom_config' => '_GetCustomConfigCommand',
        'get_paths' => '_GetPathsCommand',
        'get_log_paths' => '_GetLogPathsCommand',
        'get_users' => '_GetUsersCommand',
        'get_db_info' => '_GetDBInfoCommand'
    );
    protected $_command = "";
    protected $_framework_name = "";
    protected $_supported_framework_version = "";
    protected $_app_framework_version = "";
    protected $_app_vars = array();
    protected $_web_root = null;
    protected $_extra_app_info = array();
    // Manually typed Framework classes used by app_info
    public $required_classes = array();
    public $required_constants = array();

    public function __construct($web_root, $command, $app_vars) {
        $this->_web_root = $web_root;
        $this->_command = $command;
        $this->_app_vars = $app_vars;
        $this->SetSupportedFrameworkDetails();
    }

    public function GetFrameworkName() {
        if($this->_framework_name) return $this->_framework_name;
        $this->SetSupportedFrameworkDetails();
        return $this->_framework_name;
    }

    /**
     * Returns version of the framework supported by the parrent class.
     *
     * @return string
     */
    public function GetAppFrameworkVersion() {
        if($this->_supported_framework_version) return $this->_supported_framework_version;
        $this->SetAppFrameworkVersion();
        return $this->_supported_framework_version;
    }

    /**
     * Sets the framework name and version of the instantiated class.
     * NOT the framework of the target application!
     */
    public function SetSupportedFrameworkDetails() {
        $h_string = "AppHandler";
        $class_name = get_class($this);
        if(strlen($class_name) <= strlen($h_string)) throw new ErrorException("Something went terribly wrong");
        $class_name = substr($class_name, 0, strlen($class_name)-strlen($h_string));
        $r = preg_match_all("/.*?(\d+)$/", $class_name, $matches);
        if($r>0) {
            $version = $matches[1][0];
            $class_name = substr($class_name, 0, strlen($class_name)-strlen($version));
            $this->_supported_framework_version = $version;
        }
        $this->_framework_name = $class_name;
    }

    /**
     * Sets the framework version used by the app.
     * To be overwritten by the child class.
     *
     */
    public function SetAppFrameworkVersion() {
        throw new ErrorException("The SetAppFrameworkVersion method has to be overwritten by the specific framework handler class");
    }

    /**
     * Handle app_info commands
     *
     * @return bool
     */
    public function CommandHandler($args=array()) {
        // Interrupts exit calls to extract information about the application

        // This is needed because sometimes exit and shutdown functions are both executed without app actually exitting
        if($this->executed) {
            return false;
        }
        $this->executed = true;

        if(!in_array($this->_command, array_keys($this->available_commands))) return $this->_CommandNotFound($this->_command);

        // Check if all framework components are properly loaded
        if(!$this->CheckIfFrameworkIsLoaded()) {
            $result = array('error' => "{$this->_framework_name} was not properly loaded. Missing {$this->_framework_name} classes: ".implode(', ', $this->_GetMissingFrameworkObjects()));
            return $result;
        }

        if(in_array($this->_command, array_keys($this->available_commands))) return $this->{$this->available_commands[$this->_command]}();

        // Clean all previous app output
        // ob_start();
        // $output = ob_get_clean();
        // ob_end_clean();

        // $target_app_args = func_get_args();
        // foreach($target_app_args as $arg) {
        //     var_dump($arg);
        // }
        return $this->_CommandNotFound($this->_command);
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
     * Check if a specific class, array of classes, or all required Framework classes
     * are available.
     * To be overwritten by the child class.
     * @param mixed $class Class, array of classes, or null
     *
     * @return bool
     */
    public function CheckIfFrameworkIsLoaded($class = null) {
        throw new ErrorException("The CheckIfFrameworkIsLoaded method has to be overwritten by the specific framework handler class");
    }

    /**
     * Saves extra information about the application
     */
    public function SetExtraAppInfo($extra) {
        $this->_extra_app_info = $extra;
    }

    /**
     * Conducts framework custom preparation before fuzzing.
     * To be overwritten by child classes
     *
     * @return bool True if success, false otherwise.
     */
    public function PrepareFuzzing() {
        return True;
    }

    /**
     * Get missing Framework classes
     *
     * @return array
     */
    protected function _GetMissingFrameworkObjects() {
        $missing = array();
        foreach($this->required_classes as $class) {
            if(!$this->CheckIfFrameworkIsLoaded($class)) $missing[] = $class;
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
     * Get information about the Framework such as version and filesystem path.
     *
     * @return array
     */
    protected function _GetFrameworkInfoCommand() {
        $info = array(
            "framework_handler" => get_class($this),
            'framework_name' => $this->_framework_name,
            'framework_path' => "",
            'framework_version' => $this->_supported_framework_version,
            'app_dir' => "",
            'app_root_dir' => "",
            'php_ini' => php_ini_loaded_file(),
            'extra_app_info' => $this->_GetExtraAppInfo()
        );
        return $info;
    }

    /**
     * Get config elements custom for specific framework.
     * To be overwritten by the child class if necessary.
     * 
     * @return array
     */
    protected function _GetCustomConfigCommand() {
        return array();
    }

    /**
     * To be overwritten by the child class
     */
    protected function _GetRoutesAsStringsCommand() {
        throw new ErrorException("The _GetRoutesAsStringsCommand method has to be overwritten by the specific framework handler class");
    }

    /**
     * To be overwritten by the child class
     */
    protected function _GetControllersActionsArgumentsCommand() {
        throw new ErrorException("The _GetControllersActionsArgumentsCommand method has to be overwritten by the specific framework handler class");
    }

    /**
     * To be overwritten by the child class
     */
    protected function _GetLogPathsCommand() {
        throw new ErrorException("The _GetLogPathsCommand method has to be overwritten by the specific framework handler class");
    }

    /**
     * To be overwritten by the child class
     */
    protected function _GetUsersCommand() {
        throw new ErrorException("The _GetUsersCommand method has to be overwritten by the specific framework handler class");
    }

    /**
     * To be overwritten by the child class
     */
    protected function _GetDBInfoCommand() {
        throw new ErrorException("The _GetDBInfoCommand method has to be overwritten by the specific framework handler class");
    }

    /**
     * Get dict of PHP files with paths to be scanned.
     */
    protected function _GetPathsCommand() {
        $php_files = $this->_searchFilesInDir($this->_web_root, '.php');
        $paths = array();
        foreach($php_files as $file) {
            $paths[$file] = $this->_getPathsForPHPFile($file);
        }
        return $paths;
    }

    /**
     * Get additional information about the application.
     * If necessary this can be overwritten by the specific framework handler.
     * This information is going to be passed in the get_framework_info app_info command.
     */
    protected function _GetExtraAppInfo() {
    }

    /**
     * Returns one URL path in a list for a specific file.
     * For example. If the file is /var/www/html/webroot/dir1/images.php
     * it returns on element array of: /dir1/images.php
     * This corresponds to the following URL: http://domain/dir1/images.php
     * 
     * It is usually overwritten by the specific framework handlers.
     * This is because different frameworks handle paths differently.
     */
    protected function _getPathsForPHPFile($file) {
        return array("/".str_replace($this->_web_root, "", $file));
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

    protected function _getObjectPropertyValue($object, $property) {
        // https://medium.com/docler-engineering/how-to-access-a-private-property-of-any-object-in-php-asap-afa55987d445
        include realpath(dirname(__FILE__).'/../libraries/php-reflection/src/Reflection.php');
        $reflection = new \Ivastly\PhpReflection\Reflection();
        $value = $reflection->getProperty($object, $property);
        // $visibility = $reflection->getVisibility($object, $property);
        return $value;
    }

    private function _searchFilesInDir($directory, $extension) {
        $dir = new RecursiveDirectoryIterator($directory);
        $ite = new RecursiveIteratorIterator($dir);
        $regPattern = "@$directory.*".str_replace(".","\.",$extension)."@";
        $files = new RegexIterator($ite, $regPattern, RegexIterator::GET_MATCH);
        $fileList = array();
        foreach($files as $file) {
            $fileList = array_merge($fileList, $file);
        }
        return array_unique($fileList);
    }
}