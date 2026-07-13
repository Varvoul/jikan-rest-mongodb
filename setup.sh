#!/bin/bash
set -e
echo "=== Jikan v3 MongoDB + /full endpoint Setup ==="

# Create directories
mkdir -p app/Cache

# Create MongoDbStore.php
cat > app/Cache/MongoDbStore.php << 'MONGOEOF'
<?php
namespace App\Cache;
use Illuminate\Cache\StoreInterface;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\BSON\UTCDateTime;
class MongoDbStore implements StoreInterface
{
    protected $collection;
    protected $prefix;
    public function __construct(string $uri, string $database = 'jikan', string $collectionName = 'cache', array $options = [], string $prefix = '')
    {
        $client = new Client($uri, $options);
        $this->collection = $client->selectCollection($database, $collectionName);
        try {
            $this->collection->createIndex(['expires_at' => 1], ['expireAfterSeconds' => 0, 'background' => true]);
            $this->collection->createIndex(['key' => 1], ['background' => true]);
        } catch (\Exception $e) {}
        $this->prefix = $prefix;
    }
    public function get($key)
    {
        $prefixedKey = $this->prefix . $key;
        $document = $this->collection->findOne(
            ['key' => $prefixedKey],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
        );
        if ($document === null) return null;
        if (isset($document['expires_at']) && $document['expires_at'] instanceof UTCDateTime) {
            if ($document['expires_at']->toDateTime() < new \DateTime()) {
                $this->forget($key);
                return null;
            }
        }
        return $document['value'] ?? null;
    }
    public function put($key, $value, $seconds)
    {
        $prefixedKey = $this->prefix . $key;
        $expiresAt = null;
        if ($seconds > 0) $expiresAt = new UTCDateTime((time() + $seconds) * 1000);
        $this->collection->updateOne(
            ['key' => $prefixedKey],
            ['$set' => ['key' => $prefixedKey, 'value' => $value, 'expires_at' => $expiresAt, 'updated_at' => new UTCDateTime(time() * 1000)]],
            ['upsert' => true]
        );
        return true;
    }
    public function increment($key, $value = 1)
    {
        $current = $this->get($key);
        if ($current === null) { $this->put($key, $value, 0); return $value; }
        $newValue = (int) $current + $value;
        $this->put($key, $newValue, 0);
        return $newValue;
    }
    public function decrement($key, $value = 1) { return $this->increment($key, -$value); }
    public function forever($key, $value)
    {
        $prefixedKey = $this->prefix . $key;
        $expiresAt = new UTCDateTime((time() + 315360000) * 1000);
        $this->collection->updateOne(
            ['key' => $prefixedKey],
            ['$set' => ['key' => $prefixedKey, 'value' => $value, 'expires_at' => $expiresAt, 'updated_at' => new UTCDateTime(time() * 1000)]],
            ['upsert' => true]
        );
        return true;
    }
    public function forget($key)
    {
        $prefixedKey = $this->prefix . $key;
        $result = $this->collection->deleteOne(['key' => $prefixedKey]);
        return $result->getDeletedCount() > 0;
    }
    public function flush()
    {
        if ($this->prefix) { $this->collection->deleteMany(['key' => ['$regex' => '^' . preg_quote($this->prefix, '/')]]); }
        else { $this->collection->deleteMany([]); }
        return true;
    }
    public function getPrefix() { return $this->prefix; }
    public function has($key)
    {
        $prefixedKey = $this->prefix . $key;
        $count = $this->collection->countDocuments([
            'key' => $prefixedKey,
            '$or' => [['expires_at' => null], ['expires_at' => ['$gte' => new UTCDateTime(time() * 1000)]]]
        ]);
        return $count > 0;
    }
}
MONGOEOF
echo "Created MongoDbStore.php"

# 5. Modify composer.json - add mongodb dependency and ext-mongodb
python3 << 'PYEOF'
import json
with open('composer.json', 'r') as f:
    data = json.load(f)

req = data.get('require', {})
req['mongodb/mongodb'] = '^1.15'
req['ext-mongodb'] = '*'
req['ext-curl'] = '*'

# Remove redis dependencies
req.pop('predis/predis', None)
req.pop('illuminate/redis', None)
req.pop('danielmewes/php-rql', None)

data['require'] = req
with open('composer.json', 'w') as f:
    json.dump(data, f, indent=4)
print("composer.json updated")
PYEOF

# 6. Update cache.php config
cat > config/cache.php << 'CACHEEOF'
<?php
return [
    'default' => env('CACHE_DRIVER', 'mongodb'),
    'stores' => [
        'apc' => ['driver' => 'apc'],
        'array' => ['driver' => 'array'],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache')],
        'mongodb' => [
            'driver' => 'mongodb',
            'uri' => env('MONGODB_URI'),
            'database' => env('MONGODB_DATABASE', 'jikan'),
            'collection' => env('MONGODB_CACHE_COLLECTION', 'cache'),
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'jikan'),
];
CACHEEOF
echo "Created cache.php config"

# 7. Update bootstrap/app.php
cat > bootstrap/app.php << 'BOOTEOF'
<?php
use PackageVersions\Versions;
require_once __DIR__.'/../vendor/autoload.php';
(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(dirname(__DIR__)))->bootstrap();
defined('JIKAN_PARSER_VERSION') or define('JIKAN_PARSER_VERSION', Versions::getVersion('jikan-me/jikan'));
defined('JIKAN_REST_API_VERSION') or define('JIKAN_REST_API_VERSION', '3.4.3');
$app = new Laravel\Lumen\Application(realpath(__DIR__.'/../'));
$app->withFacades();
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, App\Exceptions\Handler::class);
$app->singleton(Illuminate\Contracts\Console\Kernel::class, App\Console\Kernel::class);
$app->routeMiddleware([
    'meta' => App\Http\Middleware\Meta::class,
    'jikan-response' => App\Http\Middleware\JikanResponseHandler::class,
    'throttle' => App\Http\Middleware\Throttle::class,
    'etag' => \App\Http\Middleware\EtagMiddleware::class,
    'microcaching' => \App\Http\Middleware\MicroCaching::class,
    'brownout' => \App\Http\Middleware\BrownoutMiddleware::class
]);
$app->configure('cache');
$app->register(Flipbox\LumenGenerator\LumenGeneratorServiceProvider::class);
$app->singleton('cache', function ($app) { return new \Illuminate\Cache\CacheManager($app); });
$app->make('cache')->extend('mongodb', function ($app, $config) {
    $uri = $config['uri'] ?? env('MONGODB_URI');
    $database = $config['database'] ?? env('MONGODB_DATABASE', 'jikan');
    $collection = $config['collection'] ?? env('MONGODB_CACHE_COLLECTION', 'cache');
    $prefix = $config['prefix'] ?? env('CACHE_PREFIX', 'jikan');
    $store = new \App\Cache\MongoDbStore($uri, $database, $collection, [], $prefix);
    return new \Illuminate\Cache\Repository($store);
});
$guzzleClient = new \GuzzleHttp\Client(['timeout' => 30, 'connect_timeout' => 10]);
$app->instance('GuzzleClient', $guzzleClient);
$jikan = new \Jikan\MyAnimeList\MalClient(app('GuzzleClient'));
$app->instance('JikanParser', $jikan);
$commonMiddleware = ['meta', 'etag', 'microcaching', 'jikan-response', 'throttle'];
if (env('APP_BROWNOUT')) { $commonMiddleware[] = 'brownout'; }
$app->router->group(['prefix' => 'v3', 'namespace' => 'App\Http\Controllers\V3', 'middleware' => $commonMiddleware], function ($router) { require __DIR__.'/../routes/web.v3.php'; });
$app->router->group(['prefix' => '/', 'namespace' => 'App\Http\Controllers\V3', 'middleware' => $commonMiddleware], function ($router) {
    $router->get('/', function () {
        $body = ['NOTICE' => 'Append an API version for API requests.', 'Author' => '@irfanDahir', 'Discord' => 'http://discord.jikan.moe', 'Version' => JIKAN_REST_API_VERSION, 'JikanPHP' => JIKAN_PARSER_VERSION, 'Website' => 'https://jikan.moe', 'Docs' => 'https://jikan.docs.apiary.io', 'GitHub' => 'https://github.com/jikan-me/jikan'];
        return response()->json($body);
    });
});
$app->router->group(['prefix' => 'v1'], function ($router) { $router->get('/', function () { return response()->json(['status' => 400, 'type' => 'HttpException', 'message' => 'This version is discontinued.', 'error' => null], 400); }); });
$app->router->group(['prefix' => 'v2'], function ($router) { $router->get('/', function () { return response()->json(['status' => 400, 'type' => 'HttpException', 'message' => 'This version is discontinued.', 'error' => null], 400); }); });
return $app;
BOOTEOF
echo "Created bootstrap/app.php"

# 8. Update JikanResponseHandler - remove Redis dependency
cat > app/Http/Middleware/JikanResponseHandler.php << 'JREOF'
<?php
namespace App\Http\Middleware;
use App\Http\HttpHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
class JikanResponseHandler
{
    private $requestUriHash;
    private $requestType;
    private $requestCacheExpiry = 0;
    private $requestCached = false;
    private $requestCacheTtl;
    private $fingerprint;
    private $cacheExpiryFingerprint;
    private $route;
    private $queueable = true;
    private const NON_QUEUEABLE = ['UserController@profile','UserController@history','UserController@friends','UserController@animelist','UserController@mangalist','AnimeController@full','MangaController@full'];
    private const HIGH_PRIORITY_QUEUE = ['ScheduleController@main'];
    public function handle(Request $request, Closure $next)
    {
        if ($request->header('auth') === env('APP_KEY')) return $next($request);
        if (empty($request->segments())) return $next($request);
        if (!isset($request->segments()[1])) return $next($request);
        if (\in_array('meta', $request->segments())) return $next($request);
        $this->requestUriHash = HttpHelper::getRequestUriHash($request);
        $this->requestType = HttpHelper::requestType($request);
        $this->requestCacheTtl = HttpHelper::requestCacheExpiry($this->requestType);
        $this->fingerprint = HttpHelper::resolveRequestFingerprint($request);
        $this->cacheExpiryFingerprint = "ttl:{$this->fingerprint}";
        $this->requestCached = Cache::has($this->fingerprint);
        $this->route = explode('\\', $request->route()[1]['uses']);
        $this->route = end($this->route);
        if (Cache::has("request:404:{$this->requestUriHash}")) {
            return response()->json(['status' => 404, 'type' => 'BadResponseException', 'message' => 'Resource does not exist', 'error' => Cache::get("request:404:{$this->requestUriHash}")], 404);
        }
        if (\in_array($this->route, self::NON_QUEUEABLE) || env('CACHE_METHOD', 'legacy') === 'legacy') { $this->queueable = false; }
        if (!$this->requestCached) {
            $response = $next($request);
            if (HttpHelper::hasError($response)) return $response;
            Cache::forever($this->fingerprint, $response->original);
            Cache::forever($this->cacheExpiryFingerprint, time() + $this->requestCacheTtl);
        }
        $this->requestCacheExpiry = (int) Cache::get($this->cacheExpiryFingerprint);
        if ($this->requestCached && $this->requestCacheExpiry <= time() && !$this->queueable) {
            $response = $next($request);
            if (HttpHelper::hasError($response)) return $response;
            Cache::forever($this->fingerprint, $response->original);
            Cache::forever($this->cacheExpiryFingerprint, time() + $this->requestCacheTtl);
            $this->requestCacheExpiry = (int) Cache::get($this->cacheExpiryFingerprint);
        }
        $meta = $this->generateMeta($request);
        $cache = Cache::get($this->fingerprint);
        $cacheMutable = json_decode($cache, true);
        $cacheMutable = $this->cacheMutation($cacheMutable);
        $response = array_merge($meta, $cacheMutable);
        $headers = ['X-Request-Hash' => $this->fingerprint, 'X-Request-Cached' => $this->requestCached, 'X-Request-Cache-Ttl' => (int) $this->requestCacheExpiry - time()];
        return response()->json($response)->setEtag(md5($cache))->withHeaders($headers)->setExpires((new \DateTime())->setTimestamp($this->requestCacheExpiry));
    }
    private function generateMeta(Request $request) : array {
        return ['request_hash' => $this->fingerprint, 'request_cached' => $this->requestCached, 'request_cache_expiry' => (int) $this->requestCacheExpiry - time()];
    }
    private function cacheMutation(array $data) : array {
        if (!($this->requestType === 'anime' || $this->requestType === 'manga')) return $data;
        if (isset($data['related']) && \count($data['related']) === 0) $data['related'] = new \stdClass();
        return $data;
    }
}
JREOF
echo "Created JikanResponseHandler.php"

# 9. Update Throttle - use Cache facade
cat > app/Http/Middleware/Throttle.php << 'THROTEOF'
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
class Throttle
{
    public $maxAttemptsPerDecayMinutes = 30;
    public $maxAttemptsPerConcurrency = 2;
    public $decayMinutes = 1;
    public function handle(Request $request, Closure $next)
    {
        if (!env('THROTTLE', false)) return $next($request);
        if ($request->is('/')) return $next($request);
        $this->decayMinutes = (int) env('THROTTLE_DECAY_MINUTES', 1);
        $this->maxAttemptsPerDecayMinutes = (int) env('THROTTLE_MAX_REQUESTS_PER_DECAY_MINUTES', 60);
        $this->maxAttemptsPerConcurrency = (int) env('THROTTLE_MAX_REQUESTS_PER_SECOND', 2);
        $signature = sha1($request->getHost() . '|' . $request->ip());
        $key = "throttle:user:{$signature}:" . time();
        if (!Cache::has($key)) { Cache::put($key, 0, $this->decayMinutes * 60); }
        Cache::increment($key);
        $total = 0;
        $now = time();
        for ($i = 0; $i < $this->decayMinutes * 60; $i++) {
            $v = Cache::get("throttle:user:{$signature}:" . ($now - $i));
            if ($v !== null) $total += (int) $v;
        }
        if ($total > $this->maxAttemptsPerDecayMinutes) return response()->json(['error' => 'Rate limited'], 429);
        if ((int) Cache::get($key) > $this->maxAttemptsPerConcurrency) return response()->json(['error' => 'Rate limited'], 429);
        return $next($request);
    }
}
THROTEOF
echo "Created Throttle.php"

# 10. Update MicroCaching - use Cache facade
cat > app/Http/Middleware/MicroCaching.php << 'MICROEOF'
<?php
namespace App\Http\Middleware;
use App\Http\HttpHelper;
use Closure;
use Illuminate\Support\Facades\Cache;
class MicroCaching
{
    public function handle($request, Closure $next)
    {
        if ($request->header('auth') === env('APP_KEY')) return $next($request);
        if (!env('MICROCACHING', false)) return $next($request);
        $fingerprint = "microcache:".HttpHelper::resolveRequestFingerprint($request);
        $cached = Cache::get($fingerprint);
        if ($cached !== null) return response()->json(json_decode($cached, true));
        return $next($request);
    }
    public static function setMicroCache($fingerprint, $cache) {
        Cache::put("microcache:".$fingerprint, json_encode($cache), env('MICROCACHING_EXPIRE', 5));
    }
}
MICROEOF
echo "Created MicroCaching.php"

# 11. Add full() method to AnimeController
python3 << 'PYEOF'
with open('app/Http/Controllers/V3/AnimeController.php', 'r') as f:
    content = f.read()

if 'AnimePicturesRequest' not in content:
    content = content.replace(
        'use Jikan\\Request\\Anime\\AnimeRequest;',
        'use Jikan\\Request\\Anime\\AnimeRequest;\nuse Jikan\\Request\\Anime\\AnimePicturesRequest;\nuse Jikan\\Request\\Anime\\AnimeStatsRequest;'
    )

full_method = '''
    public function full(int $id)
    {
        $mainAnime = $this->jikan->getAnime(new AnimeRequest($id));
        $mainData = json_decode($this->serializer->serialize($mainAnime, 'json'), true);
        $picturesResult = $this->jikan->getAnimePictures(new AnimePicturesRequest($id));
        $picturesData = json_decode($this->serializer->serialize(['pictures' => $picturesResult], 'json'), true);
        $charactersStaffResult = $this->jikan->getAnimeCharactersAndStaff(new AnimeCharactersAndStaffRequest($id));
        $charactersStaffData = json_decode($this->serializer->serialize($charactersStaffResult, 'json'), true);
        $statsResult = $this->jikan->getAnimeStats(new AnimeStatsRequest($id));
        $statsData = json_decode($this->serializer->serialize($statsResult, 'json'), true);
        $combined = $mainData;
        if (isset($picturesData['pictures'])) $combined['pictures'] = $picturesData['pictures'];
        if (isset($charactersStaffData['characters'])) $combined['characters'] = $charactersStaffData['characters'];
        if (isset($charactersStaffData['staff'])) $combined['staff'] = $charactersStaffData['staff'];
        foreach ($statsData as $key => $value) {
            if (!isset($combined[$key])) $combined[$key] = $value;
        }
        return response(json_encode($combined));
    }

'''

content = content.replace('    public function characters_staff(', full_method + '    public function characters_staff(')
with open('app/Http/Controllers/V3/AnimeController.php', 'w') as f:
    f.write(content)
print("AnimeController updated with full()")
PYEOF

# 12. Add full() method to MangaController
python3 << 'PYEOF'
with open('app/Http/Controllers/V3/MangaController.php', 'r') as f:
    content = f.read()

if 'MangaPicturesRequest' not in content:
    content = content.replace(
        'use Jikan\\Request\\Manga\\MangaRequest;',
        'use Jikan\\Request\\Manga\\MangaRequest;\nuse Jikan\\Request\\Manga\\MangaPicturesRequest;\nuse Jikan\\Request\\Manga\\MangaStatsRequest;'
    )

full_method = '''
    public function full(int $id)
    {
        $mainManga = $this->jikan->getManga(new MangaRequest($id));
        $mainData = json_decode($this->serializer->serialize($mainManga, 'json'), true);
        $picturesResult = $this->jikan->getMangaPictures(new MangaPicturesRequest($id));
        $picturesData = json_decode($this->serializer->serialize(['pictures' => $picturesResult], 'json'), true);
        $charactersResult = $this->jikan->getMangaCharacters(new MangaCharactersRequest($id));
        $charactersData = json_decode($this->serializer->serialize(['characters' => $charactersResult], 'json'), true);
        $statsResult = $this->jikan->getMangaStats(new MangaStatsRequest($id));
        $statsData = json_decode($this->serializer->serialize($statsResult, 'json'), true);
        $combined = $mainData;
        if (isset($picturesData['pictures'])) $combined['pictures'] = $picturesData['pictures'];
        if (isset($charactersData['characters'])) $combined['characters'] = $charactersData['characters'];
        foreach ($statsData as $key => $value) {
            if (!isset($combined[$key])) $combined[$key] = $value;
        }
        return response(json_encode($combined));
    }

'''

content = content.replace('    public function characters(', full_method + '    public function characters(')
with open('app/Http/Controllers/V3/MangaController.php', 'w') as f:
    f.write(content)
print("MangaController updated with full()")
PYEOF

# 13. Add /full routes
python3 << 'PYEOF'
with open('routes/web.v3.php', 'r') as f:
    content = f.read()

# Add anime /full route after AnimeController@main
content = content.replace(
    "            'uses' => 'AnimeController@main'\n        ]);\n\n        $router->get('/characters_staff'",
    "            'uses' => 'AnimeController@main'\n        ]);\n\n        $router->get('/full', [\n            'uses' => 'AnimeController@full'\n        ]);\n\n        $router->get('/characters_staff'"
)

# Add manga /full route after MangaController@main
content = content.replace(
    "            'uses' => 'MangaController@main'\n        ]);\n\n        $router->get('/characters'",
    "            'uses' => 'MangaController@main'\n        ]);\n\n        $router->get('/full', [\n            'uses' => 'MangaController@full'\n        ]);\n\n        $router->get('/characters'"
)

with open('routes/web.v3.php', 'w') as f:
    f.write(content)
print("Routes updated with /full endpoints")
PYEOF

echo "=== All modifications applied successfully ==="