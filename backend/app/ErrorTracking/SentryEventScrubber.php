<?php

namespace App\ErrorTracking;

use Sentry\Event;

class SentryEventScrubber
{
    public static function scrub(Event $event): Event
    {
        $request = $event->getRequest();
        unset(
            $request['cookies'],
            $request['data'],
            $request['env'],
            $request['headers'],
            $request['query_string'],
        );
        $event->setRequest($request);
        $event->setUser(null);

        return $event;
    }
}
