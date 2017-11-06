<?php

namespace App;

use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use wrapi\slack\slack;

class SlackMediaScraper {
    /**
     * Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Filesystem
     *
     * @var \League\Flysystem\Filesystem
     */
    private $filesystem;

    /**
     * Slack access token
     *
     * @var string
     */
    private $slackAccessToken;

    /**
     * Slack channel id
     *
     * @var string
     */
    private $slackChannelId;

    /**
     * Slack client
     *
     * @var \wrapi\slack\slack
     */
    private $slackClient;

    /**
     * Total messages
     *
     * @var integer
     */
    private $totalMessages = 0;

    /**
     * Total media downloaded
     *
     * @var integer
     */
    private $totalMedia = 0;

    /**
     * Has more messages
     *
     * @var boolean
     */
    private $hasMoreMessages = true;

    /**
     * Timestamp to continue fetching messages from
     *
     * @var int
     */
    private $nextFromTime;
    
    /**
     * Constructor
     *
     * @param string $slackAccessToken
     * @param string $slackChannel
     * @param LoggerInterface $logger
     * @param Filesystem $filesystem
     */
    public function __construct($slackAccessToken, $slackChannel, LoggerInterface $logger, Filesystem $filesystem) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->slackAccessToken = $slackAccessToken;
        $this->slackClient = new slack($slackAccessToken);
        $this->slackChannelId = $this->getChannelId($slackChannel);
    }

    /**
     * Run scraper
     *
     * @param int $fromTime
     * @return void
     */
    public function run(int $fromTime) {
        $this->nextFromTime = $fromTime;
        while ($this->hasMoreMessages) {
            $messages = $this->getNextMessages();
            foreach ($messages as $message) {
                if ($message['type'] != 'message') {
                    continue;
                }
                $filename = date('Y-m-d--H-i-s', $message['ts']);
                if (!empty($message['attachments'])) {
                    foreach ($message['attachments'] as $attachment) {
                        if (!empty($attachment['image_url'])) {
                            $this->downloadFile($attachment['image_url'], $filename);
                        }
                    }
                }
                elseif (!empty($message['subtype']) && $message['subtype'] == 'file_share') {
                    $this->downloadFile($message['file']['url_private'], $filename, true);
                }
            }
            $this->logger->addInfo(sprintf('Messages parsed: %s Media files downloaded: %s', $this->totalMessages, $this->totalMedia));
        }
    }

    /**
     * Get next messages
     *
     * @return array
     */
    private function getNextMessages() {
        $this->hasMoreMessages = false;
        $requestOptions = [
            'channel' => $this->slackChannelId,
            'count' => 100,
            'oldest' => $this->nextFromTime,
        ];
        $result = $this->slackClient->channels->history($requestOptions);
        if (empty($result['messages'])) {
            return false;
        }
        if (!empty($result['has_more'])) {
            $this->hasMoreMessages = true;
            $this->nextFromTime = $result['messages'][0]['ts'];
        }
        $this->totalMessages += count($result['messages']);
        return $result['messages'];
    }

    /**
     * Resolve channel id from name
     *
     * @param string $channel
     * @return string
     */
    private function getChannelId($channel) {
        $hasMore = true;
        $defaultOptions = [
            'limit' => 100,
        ];
        $cursor = false;
        while ($hasMore) {
            $extraOptions = [];
            // Pagination option
            if ($cursor) {
                $extraOptions['cursor'] = $cursor;
            }
            $result = $this->slackClient->channels->list($defaultOptions + $extraOptions);
            if (empty($result['channels'])) {
                return false;
            }
            foreach ($result['channels'] as $item) {
                // Return found id
                if ($item['name'] == $channel) {
                    return $item['id'];
                }
            }
            $hasMore = $result['response_metadata']['next_cursor'];
            if ($hasMore) {
                $cursor = $result['response_metadata']['next_cursor'];
            }
        }
        return false;
    }

    /**
     * Download file
     *
     * @param string $url
     * @param boolean $requiresAccessToken
     * @return bool
     */
    private function downloadFile($url, $filename, $requiresAccessToken = false) {
        $tmpFilepath = 'tmp';
        if($this->filesystem->has($tmpFilepath)) {
            $this->filesystem->delete($tmpFilepath);
        }
        $headers = [];
        if ($requiresAccessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->slackAccessToken;
        }
        $handle = tmpfile();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FILE, $handle);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_exec($ch);
        curl_close($ch);
        $this->filesystem->writeStream($tmpFilepath, $handle);
        if (is_resource($handle)) {
            fclose($handle);
        }
        $mimetype = $this->filesystem->getMimetype($tmpFilepath);
        if (!$mimetype) {
            $this->logger->addWarning('Unable to resolve file mimetype', [$url]);
            return false;
        }
        $extension = $this->getExtension($mimetype);
        if (!$extension) {
            $this->logger->addWarning('Unable to resolve file extension for mimemetype', [$url, $mimetype]);
            return false;
        }
        $filepath = 'media/' . $filename  . '.' . $extension;
        if($this->filesystem->has($filepath)) {
            $this->logger->addInfo('File already exists', [$url, $filepath]);
            return true;
        }
        $hash = $this->filesystem->hash($tmpFilepath, 'md5');
        $hashFilepath = 'hash/' . $hash;
        if($this->filesystem->has($hashFilepath)) {
            $this->logger->addInfo('File with same hash already exists', [$url, $filepath, $hash]);
            return true;
        }
        $result = $this->filesystem->rename($tmpFilepath, $filepath);
        $this->filesystem->write($hashFilepath, $filepath);
        $this->totalMedia++;
        return $result;
    }

    /**
     * Get extension for mimetype
     * 
     * Stolen from interwebses
     *
     * @param string $mime
     * @return string
     */
    private function getExtension($mime)
    {
        $all_mimes = [
            'png' => [
                'image/png',
                'image/x-png',
            ],
            'bmp' => [
                'image/bmp',
                'image/x-bmp',
                'image/x-bitmap',
                'image/x-xbitmap',
                'image/x-win-bitmap',
                'image/x-windows-bmp',
                'image/ms-bmp',
                'image/x-ms-bmp',
                'application/bmp',
                'application/x-bmp',
                'application/x-win-bitmap',
            ],
            'gif' => [
                'image/gif',
            ],
            'jpeg' => [
                'image/jpeg',
                'image/pjpeg',
            ],
            'wmv' => [
                'video/x-ms-wmv',
                'video/x-ms-asf',
            ],
            'ac3' => [
                'audio/ac3',
            ],
            'flac' => [
                'audio/x-flac',
            ],
            'ogg' => [
                'audio/ogg',
                'video/ogg',
                'application/ogg',
            ],
            'svg' => [
                'image/svg+xml',
            ],
            '3g2' => [
                'video/3gpp2',
            ],
            '3gp' => [
                'video/3gp',
                'video/3gpp',
            ],
            'mp4' => [
                'video/mp4',
            ],
            'm4a' => [
                'audio/x-m4a',
            ],
            'f4v' => [
                'video/x-f4v',
            ],
            'flv' => [
                'video/x-flv',
            ],
            'webm' => [
                'video/webm',
            ],
            'aac' => [
                'audio/x-acc',
            ],
            'mpeg' => [
                'video/mpeg',
            ],
            'mov' => [
                'video/quicktime',
            ],
            'avi' => [
                'video/x-msvideo',
                'video/msvideo',
                'video/avi',
                'application/x-troff-msvideo',
            ],
            'mp3' => [
                'audio/mpeg',
                'audio/mpg',
                'audio/mpeg3',
                'audio/mp3',
            ],
            'swf' => [
                'application/x-shockwave-flash',
            ],
            'mid' => [
                'audio/midi',
            ],
            'aif' => [
                'audio/x-aiff',
                'audio/aiff',
            ],
            'tiff' => [
                'image/tiff',
            ],
        ];
        foreach ($all_mimes as $key => $value) {
          if (array_search($mime,$value) !== false) return $key;
        }
        return false;
    }
}
