<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAnalyticsWebhookEvent;
use App\Services\Analytics\Exceptions\UnknownWebhookProviderException;
use App\Services\Analytics\WebhookHandlerRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AnalyticsWebhookController extends Controller
{
    public function __construct(private readonly WebhookHandlerRegistry $registry) {}

    public function receive(Request $request, string $provider): JsonResponse
    {
        try {
            $handler = $this->registry->for($provider);
        } catch (UnknownWebhookProviderException) {
            return response()->json(['error' => 'Unknown provider.'], 422);
        }

        try {
            $handler->verify($request);
        } catch (HttpException $e) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        $events = $handler->parse($request);

        foreach ($events as $event) {
            ProcessAnalyticsWebhookEvent::dispatch($event);
        }

        return response()->json(['accepted' => count($events)]);
    }
}
