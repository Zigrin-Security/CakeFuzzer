<?php

if(!class_exists("CakePHPHandler")) include "CakePHP/CakePHPHandler.php";

class CakePHP2AppHandler extends CakePHPHandler {
    public function __construct($web_root, $command, $app_vars) {
        parent::__construct($web_root, $command, $app_vars);
        $this->required_classes = array(
            'App', 'Router', 'CakeLog', 'ClassRegistry', 'Configure', 'ConnectionManager'
        );
        
        $commands = array(
            'get_routes' => '_GetRoutesAsStringsCommand',
            'get_controllers' => '_GetAllControllersCommand',
            'get_plugins' => '_GetAllPluginsCommand',
            'get_components' => '_GetAllComponentsCommand',
            'get_actions' => '_GetAllActionsCommand',
            'get_controllers_actions_arguments' => '_GetControllersActionsArgumentsCommand'
        );
        $this->available_commands = array_merge($this->available_commands, $commands);
    }

    /**
     * Handle app_info commands for CakePHP 2.
     *
     * @return bool
     */
    public function CommandHandler($args) {
        $result = parent::CommandHandler($args);
        if($result === false) return;
        if(is_array($result)) return $result;

        if(in_array($this->_command, array_keys($this->available_commands))) {
            return $this->{$this->available_commands[$this->_command]}();
        }
        return $this->_CommandNotFound($this->_command);
    }

    /**
     * Return updated path with named parameters fuzzed
     * Example: /controller/action/param1:val1/param2[key1]:val2/param2[key2]:val3
     */
    public function updatePath($path) {
        global $_CakeFuzzerFuzzedObjects;
        $key = '_parseArgs_named_args';
        $named_params = $_CakeFuzzerFuzzedObjects->getObject($key);
        if($named_params === false) return $path;
        $named_params = $named_params->getCopy();
        // Example nested array of named parameters
        // $named_params = array(
        //     "asd"=>"qwe",
        //     "xx"=>array("bb","qqq"),
        //     "www"=>4,
        //     "VVV"=>array("key1"=>"uuu", "key2"=>"yyy", "key3"=>array("x"=>"recursv","y"=>"new_rec", "z"=>array("new_z"=>array("wtf"=>"final_value"))))
        // );
        // It should resolve to the following path: asd:qwe/xx[0]:bb/xx[1]:qqq/www:4/VVV[key1]:uuu/VVV[key2]:yyy/VVV[key3][x]:recursv/VVV[key3][y]:new_rec/

        $parsed_named_params = array();
        foreach($named_params as $key=>$value) {
            $parsed_named_params = array_merge($parsed_named_params, $this->_FlattenNamedParam($key, $value));
        }
        if(substr($path, -1) !== '/') $path .= '/';
        $path .= implode('/', $parsed_named_params);
        return $path;
    }

    /**
     * Reconstructs URL path part with named parameter
     * Example /controller/action/asd:qwe/xx[0]:bb/xx[1]:qqq/www:4/VVV[key1]:uuu/VVV[key2]:yyy/VVV[key3][x]:recursv/VVV[key3][y]:new_rec
     * Example inputs:
     *  param_name: asd, param_val: "qwe"
     *  param_name: xx, param_val: array("bb","qqq")
     *  param_name: www, param_val: 4
     *  param_name: VVV, param_val: array("key1"=>"uuu", "key2"=>"yyy", "key3"=>array("x"=>"recursv","y"=>"new_rec", "z"=>array("new_z"=>array("wtf"=>"final_value"))))
     * Above inputs should resolve to the following elements:
     *   asd:qwe
     *   xx[0]:bb
     *   xx[1]:qqq
     *   www:4
     *   VVV[key1]:uuu
     *   VVV[key2]:yyy
     *   VVV[key3][x]:recursv
     *   VVV[key3][y]:new_rec
     */
    private function _FlattenNamedParam($param_name, $param_val) {
        if(is_string($param_val) || is_int($param_val)) return array("$param_name:$param_val");
        if($param_val instanceof MagicArray) $param_val = $param_val->getCopy();

        // Assume $param_val is an array
        $named_params_list = array();
        $tmp_vals = array();
        foreach($param_val as $key=>$value) {
            $tmp_vals = array_map(
                function($item) use ($param_name) {
                    return $param_name.$item;
                },
                $this->_GetRecursiveNamedParam($key, $value)
            );
            $named_params_list = array_merge($named_params_list, $tmp_vals);
        }
        return $named_params_list;
    }

    /**
     * Produces flat list of named params from nested array
     * Example inputs:
     *  key: 0, value: "bb"
     *  key: 1, value: "qqq"
     *  key: "key1", value: "uuu"
     *  key: "key2", value: "yyy",
     *  key: "key3", value: array("x"=>"recursv","y"=>"new_rec", "z"=>array("new_z"=>array("wtf"=>"final_value")))
     */
    private function _GetRecursiveNamedParam($key, $value) {
        if(is_string($value) || is_int($value)) return array("[$key]:$value");
        if($value instanceof MagicArray) $value = $value->getCopy();

        // Assume $value is an array
        $tmp_arr = array();
        foreach($value as $k=>$v) {
            $tmp_arr = array_merge($tmp_arr, $this->_GetRecursiveNamedParam($k, $v));
        }
        return array_map(
            function($item) use ($key) {
                return "[$key]$item";
            },
            $tmp_arr
        );
    }

    /**
     * Get regular expressions of all available route definitions
     *
     * @return array
     */
    protected function _GetRoutesAsStringsCommand() {
        // var_dump(Router::namedConfig());die;
        // App::_mapped('AppController');
        $str_routes = array();
        foreach(Router::$routes as $route) {
            // var_dump($route->defaults['controller']);
            $str_routes[] = $route->compile();
        }
        $str_routes = array_values(array_unique($str_routes));
        return $str_routes;
    }

    /**
     * Get list of all available controllers.
     *
     * @return array
     */
    protected function _GetAllControllersCommand() {
        $controllers = array();
        $c_const = 'Controller'; // TODO: Take this from Cake
        if(!empty(App::$types['controller']['suffix']) && App::$types['controller']['suffix'] !== "") $c_const = App::$types['controller']['suffix'];

        $tmp_controllers = App::objects('controller');
        foreach($tmp_controllers as $path) $controllers[] = substr($path, 0, -strlen($c_const));
        return $controllers; // var_dump(App::$_map);
    }

    /**
     * Get list of all available plugins.
     *
     * @return array
     */
    protected function _GetAllPluginsCommand() {
        // App::$_packages - Holds the possible paths for each package name
        return App::objects('plugin');
    }

    /**
     * Get list of all available components
     *
     * @return array
     */
    protected function _GetAllComponentsCommand() {
        return App::objects('component');
    }

    /**
     * Get array of all controllers and their corresponding actions
     * 
     * @return array(ControllerX => array(action_list))
     */
    protected function _GetAllActionsCommand($controller = null) {
        if(is_null($controller)) {
            $result = array();
            $controllers = $this->_GetAllControllersCommand();
            foreach($controllers as $controller) {
                $class = $this->_loadController($controller);
                if ($class!==false && class_exists($class)) {
                    $result[$controller] = $this->_getControllerActions($class);
                }
            }
            return $result;
        }
        
        $actions = array();
        $class = $this->_loadController($controller);
        if ($class!==false && class_exists($class)) {
            $actions = $this->_getControllerActions($class);
        }
        // else // TODO: Add error: Controller does not exist
        
        return array($controller => $actions);
    }

    /**
     * Get all available details of controllers, actions and arguments
     * 
     * @return array
     */
    protected function _GetControllersActionsArgumentsCommand() {
        $all_info = array();
        $controllers = $this->_GetAllControllersCommand();
        foreach($controllers as $controller) {
            $all_info[$controller] = array();
            $class = $this->_loadController($controller);
            if ($class!==false && class_exists($class)) {
                $actions = $this->_getControllerActions($class);
                foreach($actions as $action) {
                    $all_info[$controller][$action] = $this->_getActionArguments($class, $action);
                }
            }
        }
        return $all_info;
    }

    /**
     * Get list of paths for log collection.
     *
     * @return array
     */
    protected function _GetLogPathsCommand() {
        $paths = array();
        foreach(CakeLog::configured() as $stream) {
            $obj = CakeLog::stream($stream);
            $path = $this->_getObjectProperty($obj, '_path');
            $file = $this->_getObjectProperty($obj, '_file');

            $paths[] = $path.$file;
        }
        return $paths;
    }

    /**
     * Get list of all available users
     *
     * @return array
     */
    protected function _GetUsersCommand() {
        $user = ClassRegistry::init('User');
        $users = $user->find('all', [
            // 'conditions' => ['User.email' => '*@zigrin.com'],
            'fields' => ['*'],
            'recursive' => -1,
        ]);
        $result = array();
        foreach($users as $user) $result[] = $user["User"];
        return $result;
    }

    /**
     * Get database configuration
     *
     * @return array
     */
    protected function _GetDBInfoCommand() {
        return ConnectionManager::getDataSource('default')->config;
    }

    /**
     * Get arguments of a controller action
     * 
     * @return array
     */
    private function _getActionArguments($controller, $action) {
        $namespace = 'Controller';
        if(substr($controller, -strlen($namespace)) !== $namespace) $controller = "${controller}$namespace";
        $r = new ReflectionMethod($controller, $action);
        $params = $r->getParameters();
        $arguments = array();
        foreach ($params as $param) {
            $tmp = array(
                'name' => $param->getName(),
                'position' => $param->getPosition(),
                'optional' => $param->isDefaultValueAvailable()
            );
            if($param->hasType()) $tmp['type'] = strval($param->getType());
            if($param->isDefaultValueAvailable()) $tmp['default'] = $param->getDefaultValue();
            if($param->isVariadic()) $tmp['is_variadic'] = true;
            $arguments[] = $tmp;
        }
        return $arguments;
    }

    /**
     * Check if method is controller's action.
     * Taken from Controller.php _isPrivateAction
     * Note: This method does not take prefixes of specific controllers into account
     * 
     * @return bool
     */
    private function _isControllerAction($controllerObject, $method) {
        $reflectionMethod = new ReflectionMethod($controllerObject, $method);
        $objectReflection = new ReflectionClass($controllerObject);
        $all_methods = $objectReflection->getMethods(ReflectionMethod::IS_PUBLIC);

        if($reflectionMethod->class !== $objectReflection->getName()) return false;

		$privateAction = (
			$reflectionMethod->name[0] === '_' ||
			!$reflectionMethod->isPublic()
		);

		$prefixes = array_map('strtolower', Router::prefixes());

		if (!$privateAction && !empty($prefixes)) {
			// if (empty($request->params['prefix']) && strpos($request->params['action'], '_') > 0) {
			if (strpos($method, '_') > 0) {
				list($prefix) = explode('_', $method);
				$privateAction = in_array(strtolower($prefix), $prefixes);
			}
		}
		return !$privateAction;
    }

    /**
     * Get list of actions of a specific controller object
     * 
     * @return array
     */
    private function _getControllerActions($class) {
        $controllerObject = new $class();
        // return array_filter($controllerObject->methods, array(__CLASS__, '_isControllerAction'));
        $actions = array();
        foreach($controllerObject->methods as $method) {
            if($this->_isControllerAction($controllerObject, $method)) {
                $actions[] = $method;
            }
        }
        return $actions;
    }

    /**
     * Load controller and return controller class name.
     * Taken from Dispatcher.php _loadController method.
     * 
     * @param String $controller Controller name.
     * @return string|bool Name of controller class name
     */
    private function _loadController($controller) {
        $pluginName = $pluginPath = null; // TODO: should be taken dynamically
        $namespace = "Controller";
        $className = $controller . $namespace;
        App::uses('AppController', $namespace);
        App::uses($pluginName . 'AppController', $pluginPath . $namespace);
        App::uses($className, $pluginPath . $namespace);
        if (class_exists($className)) {
            return $className;
        }
        return false;
    }

    // protected function _GetDBConnections_UNUSED() {
    //     return ConnectionManager::enumConnectionObjects();
    // }

    // protected function _ExpandRegexRouteToRealPaths_UNUSED($route_string) {
    //     /* Named subpaterns: https://www.php.net/manual/en/regexp.reference.subpatterns.php
    //         Method not used.
    //         - <_args_>
    //         - <id>
    //         - <plugin>
    //         - <controller>
    //         - <action>
    //     */
    //     print("Expanding: $route_string\n");
    //     $route_string = $this->_PreprocessRouteRegexp_UNUSED($route_string);
    //     $dynamic_parts = array(
    //         '<_args_>' => null,
    //         '<id>' => null,
    //         '<plugin>' => null,
    //         '<controller>' => '_GetAllControllersCommand',
    //         '<action>' => null,
    //     );
    //     $url_paths = array();
    //     foreach($dynamic_parts as $dyn_part => $method) {
    //         if(!is_null($method) && strpos($route_string, $dyn_part) !== false) {
    //             $all_components = $this->$method();
    //             foreach($all_components as $component) {
    //                 $url_paths[] = str_replace($dyn_part, $component, $route_string);
    //             }
    //         }
    //     }
    //     return $url_paths;
    // }

    // protected function _PreprocessRouteRegexp_UNUSED($regexp) {
    //     // Removing regular expression mark
    //     if($regexp[0] == $regexp[strlen($regexp)-1]) $processed = substr($regexp, 1, -1);
        
    //     return $processed;
    // }

    // protected function _print_session_UNUSED() {
    //     // Get CakePHP session stuff
    //     $c = new ReflectionClass('CakeSession');
    //     var_dump($c->getStaticProperties());
    // }
}