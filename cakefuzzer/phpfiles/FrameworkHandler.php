<?php

class FrameworkHandler {
    private $_frameworks_path = "";
    private $_framework_path = null;
    public function __construct($framework_path = null) {
        $this->_frameworks_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . "frameworks";
        set_include_path(get_include_path() . PATH_SEPARATOR . $this->_frameworks_path);
        if(!is_null($framework_path)) $this->_framework_path = $framework_path;
    }
    public function getFrameworkName() {
        return "CakePHP";
    }

    public function getFrameworkVersion() {
        $framework_path = $this->_framework_path ? $this->_framework_path : CORE_PATH;
        $cake_version = "";
        $version_path = $this->_searchFileInDir($framework_path, 'VERSION.txt');
        if(file_exists($version_path)) {
            $pattern = "/^[0-9]+\.[0-9]+\.[0-9]+$/m";
            $f_content = file_get_contents($version_path);
            preg_match($pattern, $f_content, $matches);
            if(count($matches) > 0) $cake_version = $matches[0];
        }
        return $cake_version;
    }

    public function isFrameworkSupported() {
        $framework_path = $this->getFrameworkHandlerPath();
        return is_file($framework_path);
    }

    public function getFrameworkHandlerPath() {
        $name = strtolower($this->getFrameworkName());
        $main_version = explode(".", $this->getFrameworkVersion())[0];
        $path = $this->_frameworks_path . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . $main_version . DIRECTORY_SEPARATOR . "TargetAppHandler.php";
        return $path;
    }

    public function loadTargetAppHandler($command, $version, $app_vars) {
        include $this->getFrameworkHandlerPath();
        return new TargetAppHandler($command, $version, $app_vars);
    }

    private function _searchFileInDir($directory, $file) {
        $scan = scandir($directory);
        foreach($scan as $value) {
            if($value === "." || $value === "..") continue;
            $path = realpath($directory . DIRECTORY_SEPARATOR . $value);
            if(is_file($path) && $value === $file) return $path;
            elseif(is_dir($path)) {
                $tmp = $this->_searchFileInDir($path, $file);
                if(is_string($tmp)) return $tmp;
            }
        }
        return false;
    }
}