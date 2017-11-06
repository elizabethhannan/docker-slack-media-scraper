<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

require __DIR__ . '/vendor/autoload.php';

// Init logger
$logger = new \Monolog\Logger("log");
$logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());

// Default filesystem root
$filesystemRoot = '/data';
// Default from time, 1 month
$fromTime = time() - 30 * 24 * 60 * 60;

// Check required input
$params = [];
for ($i=1; $i<count($argv); ++$i) {
    $parts = explode("=", $argv[$i]);
    $params[$parts[0]] = $parts[1];
}
if (empty($params['token'])) {
    $logger->addError('Slack API access token param named `token` is required, ie. token=abc123'); 
    exit;
}
if (empty($params['channel'])) {
    $logger->addError('Slack channel to scrape param named `channel` is required, ie. channel=petri');     
    exit;
}
if (!empty($params['filesystem'])) {
    $filesystemRoot = $params['filesystem'];
}
if (!empty($params['fromtime'])) {
    $fromTime = $params['fromtime'];
}

// Init filesystem
$adapter = new \League\Flysystem\Adapter\Local($filesystemRoot);
$filesystem = new \League\Flysystem\Filesystem($adapter);
$filesystem->addPlugin(new \Emgag\Flysystem\Hash\HashPlugin);

// Run scraper
$slackMediaScraper = new \App\SlackMediaScraper($params['token'], $params['channel'], $logger, $filesystem);
$slackMediaScraper->run($fromTime);
