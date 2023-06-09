<?php

if(!class_exists("CakePHPHandler")) include "CakePHP/CakePHPHandler.php";

class CakePHP4AppHandler extends CakePHPHandler {
    public function __construct($web_root, $command, $app_vars) {
        parent::__construct($web_root, $command, $app_vars);
        $this->required_classes = array(
            'Authentication\Identifier\IdentifierCollection',
            'Cake\Core\App',
            'Cake\Controller\Controller',
            'Cake\Datasource\ConnectionManager',
            'Cake\Http\Server',
            'Cake\Log\Log',
            'Cake\Routing\Router'
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
     * Get regular expressions of all available route definitions
     * Router::getRouteCollection()
     *
     * @return array
     */
    protected function _GetRoutesAsStringsCommand() {
        $str_routes = array();
        $routes = Cake\Routing\Router::routes();
        foreach($routes as $route) {
            $str_routes[] = $route->compile();
        }
        $str_routes = array_unique($str_routes);
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
        $ext = '.php';

        $dirs = Cake\Core\App::classPath('Controller');
        foreach($dirs as $dir) {
            $paths = $this->_GetDirContents($dir);
            foreach($paths as $path) {
                if(strpos($path, $c_const.$ext) !== false && substr(basename($path),-strlen($c_const.$ext)) === $c_const.$ext) {
                    $controllers[] = substr($path, strlen($dir), -strlen($c_const)-strlen($ext));
                }
            }
        }
        $controllers = array_unique($controllers);
        return $controllers;
    }

    /**
     * Get list of all available plugins.
     *
     * @return array
     */
    protected function _GetAllPluginsCommand() {
        $server = $this->_GetServerVar();
        return $this->_getObjectProperty($server->getApp()->getPlugins(), 'names');
    }

    /**
     * Get list of all available components.
     * TODO: Currently it's limited to just one Controller (Instance). Expand to other controllers.
     *
     * @return array
     */
    protected function _GetAllComponentsCommand() {
        $server = $this->_GetServerVar();
        $app = $server->getApp();
        $controllerFactory = $this->_getObjectProperty($app, 'controllerFactory');
        $controller = $this->_getObjectProperty($controllerFactory, 'controller');
        $components = $controller->components()->loaded();
        return $components;
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
        $levels = array('debug', 'error'); // TODO: Find a way to extract defined levels
        foreach($levels as $level) {
            $log = Cake\Log\Log::getConfig($level);
            if(is_null($log)) continue;
            $paths[] = $log['path'].$log['file'].".log"; // TODO: Extract the extension
        }
        return $paths;
    }

    /**
     * Get list of all available users.
     * Requires instrumented app.
     *
     * @return array
     */
    protected function _GetUsersCommand() {

        $identifiers = new Authentication\Identifier\IdentifierCollection();
        $identifier = $identifiers->load('Authentication.Fake');
        $resolver = $identifier->getResolver();
        $table = $resolver->getTableLocator()->get('Users');
        $query = $table->query();
        $result = $query->find('all');
        return $result->all();
    }

    /**
     * Get database configuration
     *
     * @return array
     */
    protected function _GetDBInfoCommand() {
        return Cake\Datasource\ConnectionManager::get('default')->config();
    }

    /**
     * Recursively get list of files inside a directory.
     *
     * @return array
     */
    private function _GetDirContents($dir, &$results = array()) {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                $results[] = $path;
            } else if ($value != "." && $value != "..") {
                $this->_GetDirContents($path, $results);
                $results[] = $path;
            }
        }

        return $results;
    }

    /**
     * Get arguments of a controller action
     * 
     * @return array
     */
    private function _getActionArguments($controller, $action) {
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
     * Taken from Controller.php isAction()
     * 
     * @return bool
     */
    private function _isControllerAction($controllerObject, $action) {
        $baseClass = new ReflectionClass(new Cake\Controller\Controller);
        if ($baseClass->hasMethod($action)) {
            return false;
        }
        try {
            $reflectionMethod = new ReflectionMethod($controllerObject, $action);
        } catch (ReflectionException $e) {
            return false;
        }
        $objectReflection = new ReflectionClass($controllerObject);
        if($reflectionMethod->class !== $objectReflection->getName()) {
            return false;
        }
        return $reflectionMethod->isPublic() && $reflectionMethod->getName() === $action;
        
    }

    /**
     * Get list of actions of a specific controller object.
     * TODO: Consider removing actions of parent class if any.
     *  These actions would be visible only when parent Controller is analyzed in "$class" var.
     *  This however, could miss some vulnerabilities the output of those actions may depend on the controller.
     *  Example in action of parent class: if(get_class($this) === "App\Controller\SecretController") system($_GET['x']);
     * 
     * @return array
     */
    private function _getControllerActions($class) {
        $controllerObject = new $class();
        $actions = array();
        $methods = get_class_methods($controllerObject);
        foreach($methods as $method) {
            if($this->_isControllerAction($controllerObject, $method)) {
                $actions[] = $method;
            }
        }
        return $actions;
    }

    /**
     * Load controller and return controller class name
     * Taken from ControllerFactory.php
     *  - create method
     *  - getControllerClass method
     * 
     * @param String $controller Controller name.
     * @return string|bool Name of controller class name
     */
    private function _loadController($controller) {
        $pluginPath = "";
        $namespace = "Controller";
        $className = Cake\Core\App::className($pluginPath . $controller, $namespace, 'Controller');

        $reflection = new ReflectionClass($className);
        if ($reflection->isAbstract()) {
            return false;
        }
        if (class_exists($className)) {
            return $className;
        }
        return false;
        $controller = $reflection->newInstance($request);

        return $controller;
    }

    /**
     * Get the Server variable.
     * TODO: If this is universal in all Cake versions than move to CakePHPHandler.
     *
     * @return object
     */
    private function _GetServerVar() {
        foreach($this->_app_vars as $k=>$v) {
            if($v instanceof Cake\Http\Server) return $v;
        }
        return null;
    }

    /**
     * Get the Request variable.
     * TODO: If this is universal in all Cake versions than move to CakePHPHandler.
     *
     * @return object
     */
    private function _GetRequestVar() {
        $server = $this->_GetServerVar();
        if(is_null($server)) return null;
        $controller = $server->getApp()->controllerFactory->controller;
        $request = $controller->getRequest();
        return $request;
    }

    /**
     * Get the Container variable.
     * TODO: If this is universal in all Cake versions than move to CakePHPHandler.
     * getContainer() result is in vendor/league/container/src/Container.php
     *
     * @return object
     */
    private function _GetContainerVar() {
        $server = $this->_GetServerVar();
        if(is_null($server)) return null;
        $container = $server->getApp()->getContainer();
        return $container;
    }

    /**
     * Get the ControllerFactory variable.
     * TODO: If this is universal in all Cake versions than move to CakePHPHandler.
     * getContainer() result is in vendor/league/container/src/Container.php
     *
     * @return object
     */
    private function _GetControllerFactoryVar() {
        $server = $this->_GetServerVar();
        if(is_null($server)) return null;
        $controller_factory = $server->getApp()->controllerFactory;
        return $controller_factory;
    }
}

// Below class creates controller. Could be useful in later stages of the development to extract information.
// class CakeFuzzerController extends Controller {
//     public function getUsersIncorrectExample() {
//         // $this->initialize([]);
//         $this->loadModel('Users'); // This model name is defined by the target application and may not exist in different applications.
//         return $this->Users->find()->all();
//     }
// }