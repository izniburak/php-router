<?php

$router->group('/test', function ($router) {
    $router->get('/',function () use ($router) {
        return 'text page';
    });
    $router->get('/blue',function () {
       echo 'blue page';
    });
    $router->group('/testing', function ($rou) {
        $rou->get('/',function () {
            echo 'testing page';
        });
        $rou->error(function () {
            echo 'testing error page';
        });
    });


    $router->error(function () {
        echo 'test error page';
    });
});

$router->group('/test2', function ($router) {
    $router->get('/',function () {
        echo 'test2 page';
    });
    $router->get('/yellow',function () {
        echo 'yellow page';
    });
    $router->error(function () {
        die('test2 error page');
    });
});


$router->error(function () {
    echo 'main error page';
});

$router->error('home@notfound',['prefix' => '/test3']);
