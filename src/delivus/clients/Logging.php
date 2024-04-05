<?php namespace delivus\clients\logging;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;

class Logging
{
    private static array $loggers = [];
    public static function getLogger(string $name): Logger
    {
        if (!isset(static::$loggers[$name])) {
            static::$loggers[$name] = new Logger($name);
            $handler = new StreamHandler(getStdout());
            $handler->setFormatter(new ConsoleFormatter());
            static::$loggers[$name]->pushHandler($handler);
        }
        return static::$loggers[$name];
    }
}