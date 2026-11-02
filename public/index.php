<?php
declare(strict_types=1);

use MailMcp\App;

require dirname(__DIR__) . '/vendor/autoload.php';

App::fromEnvironment(dirname(__DIR__))->handle();
