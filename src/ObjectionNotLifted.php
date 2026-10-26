<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel;

use RuntimeException;

/**
 * The service did not confirm that a person's objection to the journey was lifted (down, refused the secret): their
 * steps are still dropped there. The host should try again - a queued job does.
 */
final class ObjectionNotLifted extends RuntimeException {}
