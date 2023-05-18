<?php

class FrameworkLoader {
    private $_web_root = "";
    private $_frameworks_path = "";
    private $_framework_path = null;
    private $_framework_handler = null;
    private $_supported_frameworks = array();
    private $_language_version = "";

    public function __construct($web_root, $command, $app_vars=array(), $framework_path = null) {
        $this->_web_root = $web_root;
        $this->_language_version = phpversion();
        $this->_frameworks_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . "frameworks";
        set_include_path(get_include_path() . PATH_SEPARATOR . $this->_frameworks_path);
        if(!is_null($framework_path)) $this->_framework_path = $framework_path;
        $this->_framework_handler = $this->_loadFrameworkHandler($command, $app_vars);
        $this->_framework_handler->SetAppFrameworkVersion();
    }

    private function _loadFrameworkHandler($command, $app_vars) {
        $handler_files = $this->_searchFilesInDir($this->_frameworks_path, "AppHandler.php", True);
        foreach($handler_files as $file) {
            $class_name = substr(basename($file), 0, strlen(basename($file))-4);
            if(!class_exists($class_name)) include $file;
            $handler = new $class_name($this->_web_root, $command, $app_vars);
            $this->_supported_frameworks[] = $handler->GetFrameworkName();
            if($handler->CheckIfFrameworkIsLoaded()) return $handler;
        }
        throw new ErrorException("Framework Handler was not properly loaded. Framework use by the application is not supported");
    }

    public function GetSupportedFrameworks() {
        $this->_supported_frameworks = array_unique($this->_supported_frameworks);
        return $this->_supported_frameworks;
    }

    public function getFrameworkName() {
        return $this->_framework_handler->GetFrameworkName();
    }

    public function isFrameworkSupported() {
        // Not sure if this is even necessary as exception is issued in constructor if framework is not supported.
        return !is_null($this->_framework_handler);
    }

    public function getAppHandler() {
        return $this->_framework_handler;
    }

    // public function loadTargetAppHandler($command, $app_vars=array()) {
    //     if(is_null($this->_framework_handler)) throw new ErrorException("Framework Handler was not properly loaded");
    //     $this->_framework_handler->SetCommand($command);
    //     $this->_framework_handler->SetAppVars($app_vars);
    //     return $this->_framework_handler;
    // }

    private function _searchFilesInDir($directory, $file_name, $ends_with = False) {
        $dir = new RecursiveDirectoryIterator($directory);
        $filter = new \RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) use ($file_name, $ends_with) {
            if (!$current->isDir()) {
                if($ends_with) {
                    if(strlen($current->getFilename()) <= strlen($file_name)) return False;
                    return strpos(substr($current->getFilename(), -strlen($file_name)), $file_name) === 0;
                }
                return strpos($current->getFilename(), $file_name) === 0;
            }
            return True;
        });
        $iterator = new \RecursiveIteratorIterator($filter);
        $files = array();
        foreach ($iterator as $info) {
            if(is_file($info->getPathname())) $files[] = $info->getPathname();
        }
        return $files;
    }
}