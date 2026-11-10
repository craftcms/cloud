<?php

declare(strict_types=1);

// The orb portal proxies HTTPS to the dev server, so trust its X-Forwarded-* headers.
return [
    'proxies' => '*',
];
