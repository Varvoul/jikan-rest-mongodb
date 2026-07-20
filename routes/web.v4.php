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
        $router->get('/anime[/{page:[0-9]+}]', [
            'uses' => 'ListController@topAnime'
        ]);

        $router->get('/manga[/{page:[0-9]+}]', [
            'uses' => 'ListController@topManga'
        ]);

        $router->get('/characters[/{page:[0-9]+}]', [
            'uses' => 'ListController@topCharacters'
        ]);

        $router->get('/people[/{page:[0-9]+}]', [
            'uses' => 'ListController@topPeople'
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
