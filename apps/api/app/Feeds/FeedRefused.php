<?php

namespace App\Feeds;

use RuntimeException;

/**
 * A URL this server will not fetch. The message is shown to the person who
 * typed it, so it says what is wrong rather than which check failed.
 */
final class FeedRefused extends RuntimeException {}
