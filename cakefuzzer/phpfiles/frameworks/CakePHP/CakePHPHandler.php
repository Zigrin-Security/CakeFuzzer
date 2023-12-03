<?php
if(!class_exists("FrameworkHandler")) include 'FrameworkHandler.php';

class CakePHPHandler extends FrameworkHandler{
    /**
     * Sets the version of the framework supported by the handler class.
     *
     * @return bool
     */
    public function SetAppFrameworkVersion() {
        if($this->_app_framework_version !== "") return True;
        if(class_exists("Configure")) {
            $this->_app_framework_version = Configure::version();
            return True;
        }
        if(!defined("CORE_PATH")) return False;
        $version_path = $this->_searchFileInDir(CORE_PATH, 'VERSION.txt');
        if(file_exists($version_path)) {
            $pattern = "/^[0-9]+\.[0-9]+\.[0-9]+$/m";
            $f_content = file_get_contents($version_path);
            preg_match($pattern, $f_content, $matches);
            if(count($matches) > 0) $this->_app_framework_version = $matches[0];
        }
        return $this->_app_framework_version !== "";
    }
    
    /**
     * Check if a specific class, array of classes, or all required Framework classes
     * are available.
     * @param mixed $class Class, array of classes, or null
     *
     * @return bool
     */
    public function CheckIfFrameworkIsLoaded($class = null) {
        if(is_string($class)) return class_exists($class);
        if(is_null($class)) $classes = $this->required_classes;
        if(is_array($classes)) {
            foreach($classes as $class) {
                if(!class_exists($class)) return false;
            }
            // TODO: Add here version verification
            return true;
        }
    }

    /**
     * Get information about the Framework such as version and filesystem path.
     * Tested for CakePHP: 2 and 4
     *
     * @return array
     */
    protected function _GetFrameworkInfoCommand() {
        $app_dir = null;
        if(defined('APP')) $app_dir = APP;

        $info = array(
            "framework_handler" => get_class($this),
            'framework_name' => $this->_framework_name,
            'framework_version' => $this->_app_framework_version,
            'framework_path' => CORE_PATH,
            'app_dir' => $app_dir,
            'app_root_dir' => ROOT,
            'php_ini' => php_ini_loaded_file(),
            'extra_app_info' => $this->_GetExtraAppInfo()
        );
        return $info;
    }

    /**
     * Get config elements custom for specific framework.
     * 
     * @return array
     */
    protected function _GetCustomConfigCommand() {
        $super_globals = array('_SERVER' => array('HTTP_HOST' => '127.0.0.1', 'HTTP_SEC_FETCH_SITE' => 'same-origin'));

        $fuzz_skip_keys = array(
            '_SERVER' => array('HTTP_CONTENT_ENCODING', 'HTTP_X_HTTP_METHOD_OVERRIDE', 'HTTP_AUTHORIZATION')
        );
        return array(
            'super_globals' => $super_globals,
            'fuzz_skip_keys' => $fuzz_skip_keys
        );
    }

    protected function _getPathsForPHPFile($file) {
        if(basename($file) === "index.php") {
            $result = array();
            $routes = $this->_GetRoutesAsStringsCommand();
            $controllers = $this->_GetControllersActionsArgumentsCommand();
            // $plugins = $this->_GetAllPluginsCommand();
            $routes = array($routes[count($routes)-1]); // Dev Temp. TODO: Remove when all types of routes are handled
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
        else {
            $result = parent::_getPathsForPHPFile($file);
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