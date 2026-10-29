<?php

namespace App\Feeds;

/**
 * What one fetch attempt amounted to.
 *
 * `Unchanged` covers both a 304 and a 200 whose body hashes to what is already
 * stored. Both are successful check-ins; neither is worth telling anyone about.
 */
enum FeedFetchResult: string
{
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case Failed = 'failed';

    public function succeeded(): bool
    {
        return $this !== self::Failed;
    }
}
