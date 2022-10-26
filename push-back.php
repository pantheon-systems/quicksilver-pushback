<?php

include __DIR__ . '/http-build-url.php';

include __DIR__ . '/Pushback.php';
include __DIR__ . '/SecretsInterface.php';
include __DIR__ . '/SecretsBase.php';
include __DIR__ . '/SecretsLegacy.php';


$pushback = new Pantheon\QuicksilverPushback\Pushback();
$pushback->pushback('legacy');
