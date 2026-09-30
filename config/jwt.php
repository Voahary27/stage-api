<?php
require_once __DIR__ . '/env.php';

define('JWT_SECRET',env('JWT_SECRET'));
define ('JWT_ALGO', 'HS256');
define('JWT_EXPIRATION', 28800); 