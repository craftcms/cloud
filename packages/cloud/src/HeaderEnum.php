<?php

namespace craft\cloud;

enum HeaderEnum: string
{
    case CACHE_TAG = 'Cache-Tag';
    case CACHE_PURGE_TAG = 'Cache-Purge-Tag';
    case CACHE_PURGE_PREFIX = 'Cache-Purge-Prefix';
    case CACHE_CONTROL = 'Cache-Control';
    case CDN_CACHE_CONTROL = 'CDN-Cache-Control';
    case SURROGATE_CONTROL = 'Surrogate-Control';
    case AUTHORIZATION = 'Authorization';
    case DEV_MODE = 'Dev-Mode';
    case REQUEST_TYPE = 'Request-Type';
    case SET_COOKIE = 'Set-Cookie';

    public function matches(string $name): bool
    {
        return strcasecmp($this->value, $name) === 0;
    }
}
