<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel;

use RuntimeException;

/**
 * The service did not hand a test person's recordings over whole (down, refused the secret, answered something else,
 * or its caps cut the export): nothing is returned - a masking canary must never pass on part of them.
 */
final class ExportFailed extends RuntimeException {}
