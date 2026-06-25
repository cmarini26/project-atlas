<?php

namespace App\Services\Analyst\Contracts;

/**
 * Marker interface for all Atlas AI analyst services.
 *
 * Only classes implementing this interface may call AiProvider::complete().
 * Concrete analysts define typed analyze() methods — the signature varies
 * by analysis task, so no common method is declared here.
 */
interface Analyst {}
