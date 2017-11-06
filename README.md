# Slack media scraper

Scrapes all media from given slack channel.

## Requirements

  - [Install Docker](https://docs.docker.com/engine/installation/)

## Options

  - token _(required)_
    - Slack API access token https://api.slack.com/custom-integrations/legacy-tokens
  - channel _(required)_
    - Name of slack channel (no leading #)
  - filesystem
    - Default: /data
    - Where to write files
  - fromtime
    - Default: 1 month ago

## Usage

### Building image
  - Run in the repository dir
```
docker build -t jussil/slack-media-scraper .
```

### Scrape
```
docker run --rm \
  -v "$PWD"/data:/data \
  jussil/slack-media-scraper \
  token=abc123 \
  channel=general
```

## Author
Jussi Lindfors