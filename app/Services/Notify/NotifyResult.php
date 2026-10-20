<?php

namespace App\Services\Notify;

/** What happened to one call to Dot.Notify, and what the caller should do about it. */
final class NotifyResult
{
    /** Accepted (any 2xx). */
    public const DONE = 'done';

    /** Notify was busy, down or slow: try again later. */
    public const RETRY = 'retry';

    /** Notify refused this event and retrying the same payload cannot change that. */
    public const GIVE_UP = 'give_up';

    /** Our signature was refused: a configuration problem that affects every event, not this one. */
    public const UNAUTHORISED = 'unauthorised';

    public function __construct(
        public readonly string $outcome,
        /** What Notify said it did with an accepted event (routed, no_rule, ignored ...). */
        public readonly ?string $notifyStatus = null,
        /** A short code for a log or a status column; never a response body. */
        public readonly ?string $error = null,
    ) {}

    public function delivered(): bool
    {
        return $this->outcome === self::DONE;
    }
}
