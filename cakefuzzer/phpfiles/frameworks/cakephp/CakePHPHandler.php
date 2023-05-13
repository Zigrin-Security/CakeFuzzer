<?php
class CakePHPHandler {
    public $executed = false;
    public $available_commands = array('get_cakephp_info'=>'_GetCakePHPInfoCommand', 'get_paths'=>'_GetPathsCommand');
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

        if(in_array($this->_command, array_keys($this->available_commands))) return $this->{$this->available_commands[$this->_command]}();
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
     * To be overwritten by the child class
     */
    protected function _GetRoutesAsStringsCommand() {
        return array();
    }

    /**
     * To be overwritten by the child class
     */
    protected function _GetControllersActionsArgumentsCommand() {
        return array();
    }

    /**
     * Get dict of PHP files with paths to be scanned
     */
    protected function _GetPathsCommand() {
        $php_files = $this->_searchFilesInDir(WWW_ROOT, '.php');
        $paths = array();
        foreach($php_files as $file) {
            $paths[$file] = $this->_getPathsFromPHPFile($file);
        }
        return $paths;
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

    protected function _getPathsFromPHPFile($file) {
        $result = array();
        if(basename($file) === "index.php") {
            $results[] = "/";
            $routes = $this->_GetRoutesAsStringsCommand();
            $controllers = $this->_GetControllersActionsArgumentsCommand();
            // $plugins = $this->_GetAllPluginsCommand();
            $routes = array($routes[count($routes)-1]); // Dev Temp. To do: Remove
            foreach($routes as $route) {
                if($route[0] === $route[-1]) $route = substr($route, 1, -1); // Removing regex markers
                if($route[0] === "^") $route = substr($route, 1); // Removing route beginning
                if($route[-1] === "$") $route = substr($route, 0, -1); // Removng route ending

                $matchPattern = "/(?:((?:\[[\w\/]+\](?:[\+\*])?)|\([\w|]+\))\??|(\w\?)|(\(\?:\/?\(\?P<(controller|action|_args_|plugin|id)>[^\)]+\)\)\??))/";

                $route = preg_replace_callback("/(\(|\|)(\w+)(?:\(([\w\|]+)\)\??)/", function($array){
                    $output = explode("|", $array[3]);
                    if ($array[0][-1] === "?") {
                        $output[] = "";
                    }
                    foreach ($output as &$option) {
                        $option = $array[2] . $option;
                    }
                    return $array[1] . implode("|", $output);
                }, $route);


                preg_match_all($matchPattern, $route, $matches);

                // Detect match group indexes of controller, action, and args
                $indexes = array("controller", "action", "_args_");
                $controller_index = $action_index = -1;
                foreach($indexes as $index) {
                    $i_name = $index."_index";
                    $$i_name = -1;
                    for($i=0;$i<count($matches[0]);++$i) if(strpos($matches[0][$i], "<$index>") !== false) {
                        $$i_name = $i;
                        break;
                    }
                }

                $expanded_routes = $this->_expandControllerActionsInRoute(
                    $route,
                    $matches[0][$controller_index],
                    $matches[0][$action_index],
                    $matches[0][$_args__index],
                    $controllers
                );
                // Removing controller and action matches for further processing
                // They were replaced already in _expandControllerActionsInRoute
                for($i=0;$i<count($matches);++$i) {
                    unset($matches[$i][$controller_index]);
                    unset($matches[$i][$action_index]);
                    unset($matches[$i][$_args__index]);
                }
                foreach($expanded_routes as $expanded_route) {
                    // Expands the () and [] and ? parts of the regular expressions
                    $result = array_merge($result, $this->_getMatches(
                        $expanded_route,
                        $this->_prepOptions($matches[0]),
                        $matchPattern,
                        $controllers
                    ));
                }
            }
        }
        return $result;
    }

    /**
     * Takes regex and creates all possible combinations of it replacing
     * controllers and actions. It doesn't touch the rest of the regex.
     * 
     * To do: Find a way to add fuzzable controller and action
     */
    private function _expandControllerActionsInRoute($pattern, $controllerMatch, $actionMatch, $argsMatch, $controllers) {
        $new_patterns = array();
        foreach($controllers as $controller => $actions) {
            foreach($actions as $action => $params) {
                $tmp_pattern = str_replace($controllerMatch, $controller, $pattern);
                $tmp_pattern = str_replace($actionMatch, $action, $tmp_pattern);
                if(!empty($params)) {
                    for($i=0;$i<count($params);++$i) {
                        // The slash below is a hack assuming this method knows the structure of the URL paths.
                        $tmp_pattern = str_replace($argsMatch, "/_CAKE_FUZZER_FUZZABLE_".$params[$i]['position']."_".$argsMatch, $tmp_pattern);
                    }
                }
                $tmp_pattern = str_replace($argsMatch, "", $tmp_pattern);
                $new_patterns[] = $tmp_pattern;
            }
        }
        return $new_patterns;
    }

    private function _getMatches($pattern, $array, $matchPattern) {
        $currentArray = array_shift($array);
        $result = array();

        foreach ($currentArray as $option) {
            $patternModified = preg_replace($matchPattern, $option, $pattern, 1);
            if (!count($array)) {
                // echo $patternModified, PHP_EOL;
                $result[] = $patternModified;
            } else {
                $result = array_merge($result, $this->_getMatches($patternModified, $array, $matchPattern));
            }
        }
        return $result;
    }

    private function _prepOptions($matches)
    {
        foreach ($matches as $match) {
            $cleanString = preg_replace("/[\[\]\(\)\?\+\*]/", "", $match);
            
            if ($match[0] === "[") {
                $array = str_split($cleanString, 1);
            } elseif ($match[0] === "(") {
                $array = explode("|", $cleanString);
            } else {
                $array = [$cleanString];
            }
            // Treat + * the same way as ?
            // Don't do it if the only char in the match is /
            if ($cleanString !== "/" && in_array($match[-1], array("?", "+", "*"))) {
                $array[] = "";
            }
            $possibilites[] = $array;
        }
        return $possibilites;
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