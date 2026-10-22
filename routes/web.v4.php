<?php

/**
 * V4-Specific Routes
 * 
 * These routes are ONLY for endpoints that don't exist in V3.
 * Routes for /top/*, /anime/{id}, etc. are defined in web.v3.php
 * and loaded under /v4 prefix in bootstrap/app.php.
 */

// Seasons endpoints (V4-specific: /seasons/now, /seasons/upcoming)
$router->group(
    ['prefix' => 'seasons'],
    function () use ($router) {
        $router->get('/now', [
            'uses' => 'ListController@seasonsNow'
        ]);

        $router->get('/upcoming', [
            'uses' => 'ListController@seasonsUpcoming'
        ]);

        // Season archive: /v4/seasons/{year}/{season} (e.g. /v4/seasons/2026/fall)
        // Registered AFTER the literal /now and /upcoming routes so those keep
        // matching first; the [0-9]{4} constraint keeps them unambiguous anyway.
        $router->get('/{year:[0-9]{4}}/{season:[A-Za-z]+}', [
            'uses' => 'ListController@seasonsArchive'
        ]);
    }
);

// Recommendations endpoints (V4-specific format)
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

// Random endpoints (V4-specific)
$router->group(
    ['prefix' => 'random'],
    function () use ($router) {
        $router->get('/anime', [
            'uses' => 'ListController@randomAnime'
        ]);
    }
);
