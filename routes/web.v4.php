<?php

$router->get('/anime', [
    'uses' => 'ListController@anime'
]);

$router->get('/manga', [
    'uses' => 'ListController@manga'
]);

$router->group(
    ['prefix' => 'seasons'],
    function () use ($router) {
        $router->get('/now', [
            'uses' => 'ListController@seasonsNow'
        ]);

        $router->get('/upcoming', [
            'uses' => 'ListController@seasonsUpcoming'
        ]);
    }
);

$router->group(
    ['prefix' => 'top'],
    function () use ($router) {
        $router->get('/anime', [
            'uses' => 'ListController@topAnime'
        ]);

        $router->get('/manga', [
            'uses' => 'ListController@topManga'
        ]);
    }
);

$router->group(
    ['prefix' => 'recommendations'],
    function () use ($router) {
        $router->get('/anime', [
            'uses' => 'ListController@recommendationsAnime'
        ]);

        $router->get('/manga', [
            'uses' => 'ListController@recommendationsManga'
        ]);
    }
);
