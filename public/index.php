<?php

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'landing.php';
$app = new PbbLanding_App($config);
$response = $app->handle(PbbLanding_Request::fromGlobals());
$response->send();
