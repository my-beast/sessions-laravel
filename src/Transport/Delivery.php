<?php

declare(strict_types=1);

namespace MyBeast\Sessions\Laravel\Transport;

/** What became of a batch of steps sent to the service - and so of their files in the spool. */
enum Delivery
{
    /** The service has them (or refused them for good): the files go. */
    case Taken;

    /** 413: the same steps again, in half the batch. */
    case TooBig;

    /** No answer, or the service is busy or down: the files wait for the next try. */
    case Later;
}
