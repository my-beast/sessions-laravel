<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Transport;

use RuntimeException;

/** The service could not be reached: no answer at all, so nothing was taken. */
final class TransportFailed extends RuntimeException {}
