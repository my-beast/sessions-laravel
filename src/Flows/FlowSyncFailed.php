<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Flows;

use RuntimeException;

/** The service did not take the key flows (down, refused the secret or the body): the ones it had stay. */
final class FlowSyncFailed extends RuntimeException {}
