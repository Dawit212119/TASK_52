<?php

/**
 * PHPUnit loads this before the test suite. Docker Compose sets DB_* and CACHE_* on the
 * host/container; apply testing defaults here so Laravel boots with sqlite + in-memory cache.
 */
putenv('APP_ENV=testing');
$testAppKey = 'base64:kVk7fJWDDICHzbjS8E30xFDB3bBHtJlXIpLdJ0jasfY=';
putenv('APP_KEY='.$testAppKey);
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_URL=');
putenv('CACHE_STORE=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');

$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_KEY'] = $testAppKey;
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_ENV['DB_URL'] = '';
$_ENV['CACHE_STORE'] = 'array';
$_ENV['SESSION_DRIVER'] = 'array';
$_ENV['QUEUE_CONNECTION'] = 'sync';

$_SERVER['APP_ENV'] = 'testing';
$_SERVER['APP_KEY'] = $testAppKey;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';
$_SERVER['DB_URL'] = '';
$_SERVER['CACHE_STORE'] = 'array';
$_SERVER['SESSION_DRIVER'] = 'array';
$_SERVER['QUEUE_CONNECTION'] = 'sync';

// Docker mounts bootstrap/cache; config.php from `php artisan config:cache` ignores PHPUnit env and breaks tests (401 logins, wrong DB).
$bootstrapCache = dirname(__DIR__).'/bootstrap/cache';
if (is_file($bootstrapCache.'/config.php')) {
    @unlink($bootstrapCache.'/config.php');
}

require dirname(__DIR__).'/vendor/autoload.php';
