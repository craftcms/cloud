<?php

namespace craft\cloud;

use Craft;
use League\Uri\Components\HierarchicalPath;
use League\Uri\Components\Query;
use League\Uri\Components\UserInfo;
use League\Uri\Uri;
use yii\base\Exception;
use yii\redis\Connection;

class Redis extends Connection
{
    protected ?string $url = null;

    public function setUrl(string $value): static
    {
        $this->url = $value;

        Craft::configure(
            $this,
            $this->parseUrlToConfig($this->url),
        );

        return $this;
    }

    /**
     * Parse a redis url scheme to config array based on RFC 3986
     * @see https://www.iana.org/assignments/uri-schemes/prov/redis
     * @see https://www.iana.org/assignments/uri-schemes/prov/rediss
     */
    protected function parseUrlToConfig(string $url): array
    {
        $uri = Uri::new($url);
        $query = Query::fromUri($uri);
        $userInfo = UserInfo::fromUri($uri);
        $path = HierarchicalPath::fromUri($uri);
        $dbFromPath = $path->get(0) ?: null;

        if (!in_array($uri->getScheme(), ['redis', 'rediss'])) {
            throw new Exception('Invalid scheme for Redis URL');
        }

        return [
            'useSSL' => $uri->getScheme() === 'rediss',
            'hostname' => $uri->getHost() ?? 'localhost',
            'port' => $uri->getPort() ?? 6379,
            'username' => $userInfo->getUser(),
            'password' => $userInfo->getPass() ?? $query->get('password'),
            'database' => $dbFromPath ?? $query->get('db') ?? 0,
        ];
    }
}
