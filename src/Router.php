<?php
/**
 * @Package: Router - simple router class for php
 * @Class  : Router
 * @Author : izni burak demirtas / @izniburak <info@burakdemirtas.org>
 * @Web    : https://burakdemirtas.org
 * @URL    : https://github.com/izniburak/php-router
 * @Licence: The MIT License (MIT) - Copyright (c) - http://opensource.org/licenses/MIT
 */

namespace Buki\Router;

use Closure;
use Exception;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class Router
 *
 * @method $this any($route, $callback, array $options = [])
 * @method $this get($route, $callback, array $options = [])
 * @method $this post($route, $callback, array $options = [])
 * @method $this put($route, $callback, array $options = [])
 * @method $this delete($route, $callback, array $options = [])
 * @method $this patch($route, $callback, array $options = [])
 * @method $this head($route, $callback, array $options = [])
 * @method $this options($route, $callback, array $options = [])
 * @method $this ajax($route, $callback, array $options = [])
 * @method $this xpost($route, $callback, array $options = [])
 * @method $this xput($route, $callback, array $options = [])
 * @method $this xdelete($route, $callback, array $options = [])
 * @method $this xpatch($route, $callback, array $options = [])
 *
 * @package Buki
 */
class Router
{
    /**
     * Router Version
     */
    const VERSION = '2.3.0';

    /**
     * @var string $baseFolder Pattern definitions for parameters of Route
     */
    protected $baseFolder;

    /**
     * @var array $routes Routes list
     */
    protected $routes = [];

    /**
     * @var array $groups List of group routes
     */
    protected $groups = [];

    /**
     * @var array List of error group routes
     */
    protected $errorGroupNames = [];

    /**
     * @var string Error group of requested page
     */
    protected $currentErrorGroupName = '/';

    /**
     * @var array $patterns Pattern definitions for parameters of Route
     */
    protected $patterns = [
        ':all' => '(.*)',
        ':any' => '([^/]+)',
        ':id' => '(\d+)',
        ':int' => '(\d+)',
        ':number' => '([+-]?([0-9]*[.])?[0-9]+)',
        ':float' => '([+-]?([0-9]*[.])?[0-9]+)',
        ':bool' => '(true|false|1|0)',
        ':string' => '([\w\-_]+)',
        ':slug' => '([\w\-_]+)',
        ':uuid' => '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})',
        ':date' => '([0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1]))',
    ];

    /**
     * @var array $namespaces Namespaces of Controllers and Middlewares files
     */
    protected $namespaces = [
        'middlewares' => '',
        'controllers' => '',
    ];

    /**
     * @var array $path Paths of Controllers and Middlewares files
     */
    protected $paths = [
        'controllers' => 'Controllers',
        'middlewares' => 'Middlewares',
    ];

    /**
     * @var string $mainMethod Main method for controller
     */
    protected $mainMethod = 'main';

    /**
     * @var string $cacheFile Cache file
     */
    protected $cacheFile = '';

    /**
     * @var bool $cacheLoaded Cache is loaded?
     */
    protected $cacheLoaded = false;

    /**
     * @var Closure $errorCallback Route error callback function
     */
    protected $errorCallback;

    /**
     * @var array $middlewares General middlewares for per request
     */
    protected $middlewares = [];

    /**
     * @var array $routeMiddlewares Route middlewares
     */
    protected $routeMiddlewares = [];

    /**
     * @var array $middlewareGroups Middleware Groups
     */
    protected $middlewareGroups = [];

    /**
     * @var RouterRequest
     */
    private $request;

    /**
     * Router constructor method.
     *
     * @param array         $params
     * @param Request|null  $request
     * @param Response|null $response
     */
    public function __construct(array $params = [], Request $request = null, Response $response = null)
    {
        $this->baseFolder = realpath(getcwd());

        if (isset($params['debug']) && is_bool($params['debug'])) {
            RouterException::$debug = $params['debug'];
        }

        // RouterRequest
        $request = $request ?? Request::createFromGlobals();
        $response = $response ?? new Response('', Response::HTTP_OK, ['content-type' => 'text/html']);
        $this->request = new RouterRequest($request, $response);

        $this->setPaths($params);
        $this->loadCache();
    }

    /**
     * Add route method;
     * Get, Post, Put, Delete, Patch, Any, Ajax...
     *
     * @param $method
     * @param $params
     *
     * @return mixed
     * @throws
     */
    public function __call($method, $params)
    {
        if ($this->cacheLoaded) {
            return true;
        }

        if (is_null($params)) {
            return false;
        }

        if (!in_array(strtoupper($method), explode('|', $this->request->validMethods()))) {
            return $this->exception("Method is not valid. [{$method}]");
        }

        [$route, $callback] = $params;
        $options = $params[2] ?? null;
        if (strstr($route, ':')) {
            $route1 = $route2 = '';
            foreach (explode('/', $route) as $key => $value) {
                if ($value != '') {
                    if (!strpos($value, '?')) {
                        $route1 .= '/' . $value;
                    } else {
                        if ($route2 == '') {
                            $this->addRoute($route1, $method, $callback, $options);
                        }

                        $route2 = $route1 . '/' . str_replace('?', '', $value);
                        $this->addRoute($route2, $method, $callback, $options);
                        $route1 = $route2;
                    }
                }
            }

            if ($route2 == '') {
                $this->addRoute($route1, $method, $callback, $options);
            }
        } else {
            $this->addRoute($route, $method, $callback, $options);
        }

        return $this;
    }

    /**
     * Add new route method one or more http methods.
     *
     * @param string         $methods
     * @param string         $route
     * @param string|closure $callback
     * @param array          $options
     *
     * @return bool
     */
    public function add(string $methods, string $route, $callback, array $options = [])
    {
        if ($this->cacheLoaded) {
            return true;
        }

        if (strstr($methods, '|')) {
            foreach (array_unique(explode('|', $methods)) as $method) {
                if (!empty($method)) {
                    $this->addRoute($route, $method, $callback, $options);
                }
            }
        } else {
            $this->addRoute($route, $methods, $callback, $options);
        }

        return true;
    }

    /**
     * Add new route rules pattern; String or Array
     *
     * @param string|array $pattern
     * @param null|string  $attr
     *
     * @return mixed
     * @throws
     */
    public function pattern($pattern, $attr = null)
    {
        if (is_array($pattern)) {
            foreach ($pattern as $key => $value) {
                if (in_array($key, array_keys($this->patterns))) {
                    return $this->exception($key . ' pattern cannot be changed.');
                }
                $this->patterns[$key] = '(' . $value . ')';
            }
        } else {
            if (in_array($pattern, array_keys($this->patterns))) {
                return $this->exception($pattern . ' pattern cannot be changed.');
            }
            $this->patterns[$pattern] = '(' . $attr . ')';
        }

        return true;
    }

    /**
     * Run Routes
     *
     * @return void
     * @throws
     */
    public function run(): void
    {
        $uri = $this->getRequestUri();
        $method = $this->request->getMethod();
        $searches = array_keys($this->patterns);
        $replaces = array_values($this->patterns);
        $foundRoute = false;

        foreach ($this->routes as $data) {
            $route = $data['route'];
            if (!$this->request->validMethod($data['method'], $method)) {
                continue;
            }

            // Direct Route Match
            if ($route === $uri) {
                $foundRoute = true;
                $this->runRouteMiddleware($data, 'before');
                $this->runRouteCommand($data['callback']);
                $this->runRouteMiddleware($data, 'after');
                break;

                // Parameter Route Match
            } elseif (strstr($route, ':') !== false) {
                $route = str_replace($searches, $replaces, $route);
                if (preg_match('#^' . $route . '$#', $uri, $matched)) {
                    $foundRoute = true;

                    $this->runRouteMiddleware($data, 'before');

                    array_shift($matched);
                    $matched = array_map(function ($value) {
                        return trim(urldecode($value));
                    }, $matched);

                    foreach ($data['groups'] as $group) {
                        if (strstr($group, ':') !== false) {
                            array_shift($matched);
                        }
                    }

                    $this->runRouteCommand($data['callback'], $matched);
                    $this->runRouteMiddleware($data, 'after');
                    break;
                }
            }
        }

        // If it originally was a HEAD request, clean up after ourselves by emptying the output buffer
        if ($this->request()->isMethod('HEAD')) {
            ob_end_clean();
        }

        if ($foundRoute === false) {
            $this->notFoundRoute();
        }
    }

    /**
     * Routes Group
     *
     * @param string  $prefix
     * @param closure $callback
     * @param array   $options
     *
     * @return bool
     */
    public function group(string $prefix, Closure $callback, array $options = []): bool
    {
        if ($this->cacheLoaded) {
            return true;
        }

        $group = [];
        $group['route'] = $this->clearRouteName($prefix);
        $group['before'] = $this->calculateMiddleware($options['before'] ?? []);
        $group['after'] = $this->calculateMiddleware($options['after'] ?? []);

        array_push($this->groups, $group);

        $this->getErrorGroupNames();

        if (is_object($callback)) {
            call_user_func_array($callback, [$this]);
        }

        $this->endGroup();

        return true;
    }

    /**
     * Added route from methods of Controller file.
     *
     * @param string $route
     * @param string $controller
     * @param array  $options
     *
     * @return mixed
     * @throws
     */
    public function controller(string $route, string $controller, array $options = [])
    {
        if ($this->cacheLoaded) {
            return true;
        }

        $only = $options['only'] ?? [];
        $except = $options['except'] ?? [];
        $controller = $this->resolveClassName($controller);
        $classMethods = get_class_methods($controller);
        if ($classMethods) {
            foreach ($classMethods as $methodName) {
                if (!strstr($methodName, '__')) {
                    $method = 'any';
                    foreach (explode('|', $this->request->validMethods()) as $m) {
                        if (stripos($methodName, $m = strtolower($m), 0) === 0) {
                            $method = $m;
                            break;
                        }
                    }

                    $methodVar = lcfirst(
                        preg_replace('/' . $method . '_?/i', '', $methodName, 1)
                    );
                    $methodVar = strtolower(preg_replace('%([a-z]|[0-9])([A-Z])%', '\1-\2', $methodVar));

                    if ((!empty($only) && !in_array($methodVar, $only))
                        || (!empty($except) && in_array($methodVar, $except))) {
                        continue;
                    }

                    $ref = new ReflectionMethod($controller, $methodName);
                    $endpoints = [];
                    foreach ($ref->getParameters() as $param) {
                        $typeHint = $param->hasType() ? $param->getType()->getName() : null;
                        if (!in_array($typeHint, ['int', 'float', 'string', 'bool']) && $typeHint !== null) {
                            continue;
                        }
                        $pattern = isset($this->patterns[":{$typeHint}"]) ? ":{$typeHint}" : ":any";
                        $endpoints[] = $param->isOptional() ? "{$pattern}?" : $pattern;
                    }

                    $value = ($methodVar === $this->mainMethod ? $route : $route . '/' . $methodVar);
                    $this->{$method}(
                        ($value . '/' . implode('/', $endpoints)),
                        ($controller . '@' . $methodName),
                        $options
                    );
                }
            }
            unset($ref);
        }

        return true;
    }

    /**
     * Routes error function.
     *
     * @param $callback
     * @param array $options
     *
     * @return void
     */
    public function error($callback,array $options = []): void
    {
        if (isset($options['prefix'])) {
            $prefix = $options['prefix'];
            $this->errorGroupNames[] = $prefix.'/';
        } else {
            $prefix = '';
            foreach ($this->groups as $item) {
                $prefix .= $item['route'];
            }
        }
        $this->errorCallback[$prefix.'/'] = $callback;
    }

    /**
     * Display all Routes.
     *
     * @return void
     */
    public function getList(): void
    {
        $routes = var_export($this->getRoutes(), true);
        die("<pre>{$routes}</pre>");
    }

    /**
     * Get all Routes
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Cache all routes
     *
     * @return bool
     *
     * @throws Exception
     */
    public function cache(): bool
    {
        foreach ($this->getRoutes() as $key => $route) {
            if (!is_string($route['callback'])) {
                throw new Exception(sprintf('Routes cannot contain a Closure/Function callback while caching.'));
            }
        }

        $cacheContent = '<?php return ' . var_export($this->getRoutes(), true) . ';' . PHP_EOL;
        if (false === file_put_contents($this->cacheFile, $cacheContent)) {
            throw new Exception(sprintf('Routes cache file could not be written.'));
        }

        return true;
    }

    /**
     * Set general middlewares
     *
     * @param array $middlewares
     *
     * @return void
     */
    public function setMiddleware(array $middlewares): void
    {
        $this->middlewares = $middlewares;
    }

    /**
     * Set Route middlewares
     *
     * @param array $middlewares
     *
     * @return void
     */
    public function setRouteMiddleware(array $middlewares): void
    {
        $this->routeMiddlewares = $middlewares;
    }

    /**
     * Set middleware groups
     *
     * @param array $middlewareGroup
     *
     * @return void
     */
    public function setMiddlewareGroup(array $middlewareGroup): void
    {
        $this->middlewareGroups = $middlewareGroup;
    }

    /**
     * Get All Middlewares
     *
     * @return array
     */
    public function getMiddlewares(): array
    {
        return [
            'middlewares' => $this->middlewares,
            'routeMiddlewares' => $this->routeMiddlewares,
            'middlewareGroups' => $this->middlewareGroups,
        ];
    }

    /**
     * Gets errors generated under group
     *
     * @return void
     */
    protected function getErrorGroupNames()
    {
        $group = '';
        foreach ($this->groups as $item) {
            $group .= $item['route'];
        }
        $this->errorGroupNames[] = $group.'/';
    }

    protected function notFoundRoute()
    {
        foreach ($this->errorGroupNames as $key => $item) {
            if (str_starts_with($this->getRequestUri(), $item))
                $groupKey = $key;
        }
        if (isset($groupKey))
            $this->currentErrorGroupName = $this->errorGroupNames[$groupKey];

        if (!isset($this->errorCallback[$this->currentErrorGroupName])) {
            if (!isset($this->errorCallback['/']))
                $this->errorCallback['/'] = function () {
                    $this->response()
                        ->setStatusCode(Response::HTTP_NOT_FOUND)
                        ->sendHeaders();
                    return $this->exception('Looks like page not found or something went wrong. Please try again.');
                };
            $this->currentErrorGroupName = '/';
        }
        
        $this->response()->setStatusCode(Response::HTTP_NOT_FOUND)->sendHeaders();
        $this->routerCommand()->runRoute($this->errorCallback[$this->currentErrorGroupName]);
    }

    /**
     * Detect Routes Middleware; before or after
     *
     * @param array  $middleware
     * @param string $type
     *
     * @return void
     */
    protected function runRouteMiddleware(array $middleware, string $type): void
    {
        $this->routerCommand()->beforeAfter($middleware[$type]);
    }

    /**
     * @return Request
     */
    protected function request(): Request
    {
        return $this->request->symfonyRequest();
    }

    /**
     * @return Response
     */
    protected function response(): Response
    {
        return $this->request->symfonyResponse();
    }

    /**
     * Throw new Exception for Router Error
     *
     * @param string $message
     * @param int    $statusCode
     *
     * @return RouterException
     * @throws Exception
     */
    protected function exception($message = '', int $statusCode = 500): RouterException
    {
        return new RouterException($message, $statusCode);
    }

    /**
     * RouterCommand class
     *
     * @return RouterCommand
     */
    protected function routerCommand(): RouterCommand
    {
        return RouterCommand::getInstance(
            $this->baseFolder, $this->paths, $this->namespaces,
            $this->request(), $this->response(),
            $this->getMiddlewares()
        );
    }

    /**
     * Set paths and namespaces for Controllers and Middlewares.
     *
     * @param array $params
     *
     * @return void
     */
    protected function setPaths(array $params): void
    {
        if (empty($params)) {
            return;
        }

        if (isset($params['paths']) && $paths = $params['paths']) {
            $this->paths['controllers'] = isset($paths['controllers'])
                ? trim($paths['controllers'], '/')
                : $this->paths['controllers'];

            $this->paths['middlewares'] = isset($paths['middlewares'])
                ? trim($paths['middlewares'], '/')
                : $this->paths['middlewares'];
        }

        if (isset($params['namespaces']) && $namespaces = $params['namespaces']) {
            $this->namespaces['controllers'] = isset($namespaces['controllers'])
                ? trim($namespaces['controllers'], '\\') . '\\'
                : '';

            $this->namespaces['middlewares'] = isset($namespaces['middlewares'])
                ? trim($namespaces['middlewares'], '\\') . '\\'
                : '';
        }

        if (isset($params['base_folder'])) {
            $this->baseFolder = rtrim($params['base_folder'], '/');
        }

        if (isset($params['main_method'])) {
            $this->mainMethod = $params['main_method'];
        }

        $this->cacheFile = isset($params['cache']) ? $params['cache'] : realpath(__DIR__ . '/../cache.php');
    }

    /**
     * @param string $controller
     *
     * @return RouterException|string
     */
    protected function resolveClassName(string $controller)
    {
        $controller = str_replace([$this->namespaces['controllers'], '\\', '.'], ['', '/', '/'], $controller);
        $controller = trim(
            preg_replace(
                '/' . str_replace('/', '\\/', $this->paths['controllers']) . '/i',
                '',
                $controller,
                1
            ),
            '/'
        );

        $file = realpath("{$this->paths['controllers']}/{$controller}.php");
        if (!file_exists($file)) {
            return $this->exception("{$controller} class is not found! Please check the file.");
        }

        $controller = $this->namespaces['controllers'] . str_replace('/', '\\', $controller);
        if (!class_exists($controller)) {
            require_once $file;
        }

        return $controller;
    }

    /**
     * Load Cache file
     *
     * @return bool
     */
    protected function loadCache(): bool
    {
        if (file_exists($this->cacheFile)) {
            $this->routes = require_once $this->cacheFile;
            $this->cacheLoaded = true;
            return true;
        }

        return false;
    }

    /**
     * Add new Route and it's settings
     *
     * @param string $uri
     * @param string $method
     * @param        $callback
     * @param array  $options
     *
     * @return void
     */
    protected function addRoute(string $uri, string $method, $callback, $options = [])
    {
        $groupUri = '';
        $groupStack = [];
        $beforeMiddlewares = [];
        $afterMiddlewares = [];
        if (!empty($this->groups)) {
            foreach ($this->groups as $key => $value) {
                $groupUri .= $value['route'];
                $groupStack[] = trim($value['route'], '/');
                $beforeMiddlewares = array_merge($beforeMiddlewares, $value['before']);
                $afterMiddlewares = array_merge($afterMiddlewares, $value['after']);
            }
        }

        $beforeMiddlewares = array_merge($beforeMiddlewares, $this->calculateMiddleware($options['before'] ?? []));
        $afterMiddlewares = array_merge($afterMiddlewares, $this->calculateMiddleware($options['after'] ?? []));

        $callback = is_array($callback) ? implode('@', $callback) : $callback;
        $routeName = is_string($callback)
            ? strtolower(preg_replace(
                '/[^\w]/i', '.', str_replace($this->namespaces['controllers'], '', $callback)
            ))
            : null;
        $data = [
            'route' => $this->clearRouteName("{$groupUri}/{$uri}"),
            'method' => strtoupper($method),
            'callback' => $callback,
            'name' => $options['name'] ?? $routeName,
            'before' => $beforeMiddlewares,
            'after' => $afterMiddlewares,
            'groups' => $groupStack,
        ];
        array_unshift($this->routes, $data);
    }

    /**
     * @param array|string $middleware
     *
     * @return array
     */
    protected function calculateMiddleware($middleware): array
    {
        if (is_null($middleware)) {
            return [];
        }

        return is_array($middleware) ? $middleware : [$middleware];
    }

    /**
     * Run Route Command; Controller or Closure
     *
     * @param $command
     * @param $params
     *
     * @return void
     */
    protected function runRouteCommand($command, $params = [])
    {
        $this->routerCommand()->runRoute($command, $params);
    }

    /**
     * Routes Group endpoint
     *
     * @return void
     */
    protected function endGroup(): void
    {
        array_pop($this->groups);
    }

    /**
     * @param string $route
     *
     * @return string
     */
    protected function clearRouteName(string $route = ''): string
    {
        $route = trim(preg_replace('~/{2,}~', '/', $route), '/');
        return $route === '' ? '/' : "/{$route}";
    }

    /**
     * @return string
     */
    protected function getRequestUri(): string
    {
        $script = $this->request()->server->get('SCRIPT_NAME');
        $dirname = dirname($script);
        $dirname = $dirname === '/' ? '' : $dirname;
        $basename = basename($script);
        $uri = str_replace([$dirname, $basename], '', $this->request()->server->get('REQUEST_URI'));
        return $this->clearRouteName(explode('?', $uri)[0]);
    }
}
