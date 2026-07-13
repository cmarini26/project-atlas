<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * The action the business most wants customers to take — collected in
 * onboarding's Marketing Preferences step (Milestone 15 Phase 1).
 */
enum PrimaryCallToAction: string
{
    use EnumValues;

    case Call = 'call';
    case FillOutForm = 'fill_out_form';
    case Book = 'book';
    case VisitLocation = 'visit_location';
    case BuyOnline = 'buy_online';
    case AttendEvent = 'attend_event';
    case RequestQuote = 'request_quote';
}
