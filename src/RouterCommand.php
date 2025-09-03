<?php

namespace Buki\Router;

use Closure;
use Exception;
use JetBrains\PhpStorm\NoReturn;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use Reflector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RouterCommand
{
    protected static ?RouterCommand $instance = null;

    protected string $baseFolder;

    protected array $paths;

    protected array $namespaces;

    protected Request $request;

    protected Response $response;

    protected array $middlewares = [];

    protected array $markedMiddlewares = [];

    public function __construct(
        string   $baseFolder,
        array    $paths,
        array    $namespaces,
        Request  $request,
        Response $response,
        array    $middlewares
    )
    {
        $this->baseFolder = $baseFolder;
        $this->paths = $paths;
        $this->namespaces = $namespaces;
        $this->request = $request;
        $this->response = $response;
        $this->middlewares = $middlewares;

        // Execute general Middlewares
        foreach ($this->middlewares['middlewares'] as $middleware) {
            $this->beforeAfter($middleware);
        }

    }

    public function getMiddlewareInfo(): array
    {
        return [
            'path' => "{$this->paths['middlewares']}",
            'namespace' => $this->namespaces['middlewares'],
        ];
    }

    public function getControllerInfo(): array
    {
        return [
            'path' => "{$this->paths['controllers']}",
            'namespace' => $this->namespaces['controllers'],
        ];
    }

    public static function getInstance(
        string   $baseFolder,
        array    $paths,
        array    $namespaces,
        Request  $request,
        Response $response,
        array    $middlewares
    ): ?RouterCommand
    {
        if (null === self::$instance) {
            self::$instance = new static(
                $baseFolder, $paths, $namespaces,
                $request, $response, $middlewares
            );
        }

        return self::$instance;
    }

    /**
     * Run Route Middlewares
     *
     * @param $command
     *
     * @throws
     */
    public function beforeAfter($command)
    {
        if (empty($command)) {
            return;
        }

        $info = $this->getMiddlewareInfo();
        if (is_array($command)) {
            foreach ($command as $value) {
                $this->beforeAfter($value);
            }
        } elseif (is_string($command)) {
            $middleware = explode(':', $command);
            $params = [];
            if (count($middleware) > 1) {
                $params = explode(',', $middleware[1]);
            }

            $resolvedMiddleware = $this->resolveMiddleware($middleware[0]);
            $response = false;
            if (is_array($resolvedMiddleware)) {
                foreach ($resolvedMiddleware as $middleware) {
                    $response = $this->runMiddleware(
                        $command,
                        $this->resolveMiddleware($middleware),
                        $params,
                        $info
                    );
                }
                return $response;
            }

            return $this->runMiddleware($command, $resolvedMiddleware, $params, $info);
        }
    }

    /**
     * Run Route Command; Controller or Closure
     *
     * @throws Exception
     */
    public function runRoute(string|Closure $command, array $params = []): mixed
    {
        $info = $this->getControllerInfo();
        if (is_object($command)) {
            return $this->runMethodWithParams($command, $params);
        }

        $invokable = !str_contains($command, '@');
        $class = $command;
        if (!$invokable) {
            [$class, $method] = explode('@', $command);
        }

        $class = str_replace([$info['namespace'], '\\', '.'], ['', '/', '/'], $class);

        $controller = $this->resolveClass($class, $info['path'], $info['namespace']);
        if (!$invokable && !method_exists($controller, $method)) {
            $this->exception("{$method} method is not found in {$class} class.");
        }

        if (property_exists($controller, 'middlewareBefore') && is_array($controller->middlewareBefore)) {
            foreach ($controller->middlewareBefore as $middleware) {
                $this->beforeAfter($middleware);
            }
        }

        $response = $this->runMethodWithParams([$controller, (!$invokable ? $method : '__invoke')], $params);

        if (property_exists($controller, 'middlewareAfter') && is_array($controller->middlewareAfter)) {
            foreach ($controller->middlewareAfter as $middleware) {
                $this->beforeAfter($middleware);
            }
        }

        return $response;
    }

    /**
     * Resolve Controller or Middleware class.
     *
     * @throws Exception
     */
    protected function resolveClass(string $class, string $path, string $namespace): object
    {
        $class = str_replace([$namespace, '\\'], ['', '/'], $class);
        $file = realpath("{$path}/{$class}.php");
        if (!file_exists($file)) {
            $this->exception("{$class} class is not found. Please check the file.");
        }

        $class = $namespace . str_replace('/', '\\', $class);
        if (!class_exists($class)) {
            require_once($file);
        }

        return new $class();
    }

    /**
     * @throws ReflectionException
     */
    protected function runMethodWithParams(array|Closure $function, array $params): Response|string|int
    {
        $reflection = is_array($function)
            ? new ReflectionMethod($function[0], $function[1])
            : new ReflectionFunction($function);
        $parameters = $this->resolveCallbackParameters($reflection, $params);
        $response = call_user_func_array($function, $parameters);
        return $this->sendResponse($response);
    }

    /**
     * @throws
     */
    protected function resolveCallbackParameters(Reflector $reflection, array $uriParams): array
    {
        $parameters = [];
        foreach ($reflection->getParameters() as $key => $param) {
            $class = $param->getType() && !$param->getType()->isBuiltin()
                ? new ReflectionClass($param->getType()->getName())
                : null;
            if (!is_null($class) && $class->isInstance($this->request)) {
                $parameters[] = $this->request;
            } elseif (!is_null($class) && $class->isInstance($this->response)) {
                $parameters[] = $this->response;
            } elseif (!is_null($class)) {
                $parameters[] = null;
            } else {
                if (empty($uriParams)) {
                    continue;
                }
                $uriParams = array_reverse($uriParams);
                $parameters[] = array_pop($uriParams);
                $uriParams = array_reverse($uriParams);
            }
        }

        return $parameters;
    }

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    protected function runMiddleware(string $command, string $middleware, array $params, array $info): bool
    {
        $middlewareMethod = 'handle'; // For now, it's constant.
        $controller = $this->resolveClass($middleware, $info['path'], $info['namespace']);

        if (in_array($command, $this->markedMiddlewares)) {
            return true;
        }
        $this->markedMiddlewares[] = $command;

        if (!method_exists($controller, $middlewareMethod)) {
            $this->exception("{$middlewareMethod}() method is not found in {$middleware} class.");
        }

        $parameters = $this->resolveCallbackParameters(new ReflectionMethod($controller, $middlewareMethod), $params);
        $response = call_user_func_array([$controller, $middlewareMethod], $parameters);
        if ($response !== true) {
            $this->sendResponse($response);
            exit;
        }

        return true;
    }

    protected function resolveMiddleware(string $middleware): array|string
    {
        $middlewares = $this->middlewares;
        if (isset($middlewares['middlewareGroups'][$middleware])) {
            return $middlewares['middlewareGroups'][$middleware];
        }

        $name = explode(':', $middleware)[0];
        if (isset($middlewares['routeMiddlewares'][$name])) {
            return $middlewares['routeMiddlewares'][$name];
        }

        return $middleware;
    }

    public function sendResponse($response): Response|string|int
    {
        if (is_array($response) || str_contains($this->request->headers->get('Accept') ?? '', 'application/json')) {
            $this->response->headers->set('Content-Type', 'application/json');
            return $this->response
                ->setContent($response instanceof Response ? $response->getContent() : json_encode($response))
                ->send();
        }

        if (!is_string($response)) {
            return $response instanceof Response ? $response->send() : print_r($response, true);
        }

        return $this->response->setContent($response)->send();
    }

    /**
     * Throw new Exception for Router Error
     *
     * @throws Exception
     */
    #[NoReturn]
    protected function exception(string $message = '', int $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR): void
    {
        throw new RouterException($message, $statusCode);
    }
}
