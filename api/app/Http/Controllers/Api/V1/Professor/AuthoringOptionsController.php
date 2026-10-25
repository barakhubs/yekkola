<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Professor;

use App\Domain\Authoring\Enums\Audience;
use App\Domain\Authoring\Enums\CourseLevel;
use App\Domain\Platform\Settings\CatalogSettings;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Shared\Enums\Currency;
use App\Http\Controllers\Controller;

/**
 * The choices and limits the studio offers (PRD-03 FR-04, FR-06, FR-08, FR-09), read from platform settings so
 * no client hard-codes them. Every write is still validated by the API.
 */
final class AuthoringOptionsController extends Controller
{
    /**
     * @return array{data: array{currencies: list<array{code: string, min_minor: int|null, max_minor: int|null, step_minor: int}>, default_currency: string, max_preview_lessons: int, upload_limits_mb: array{video: int, audio: int, document: int}, languages: list<string>, levels: list<string>, audiences: list<string>}}
     */
    public function __invoke(CommerceSettings $commerce, CatalogSettings $catalog): array
    {
        $currencies = collect($commerce->enabled_currencies)->map(fn (string $code) => [
            'code' => $code,
            'min_minor' => $commerce->price_limits_minor[$code]['min'] ?? null,
            'max_minor' => $commerce->price_limits_minor[$code]['max'] ?? null,
            // Prices must be a multiple of this (mobile money can't collect smaller amounts in this currency).
            'step_minor' => Currency::from($code)->collectionStepMinor(),
        ])->values()->all();

        return ['data' => [
            'currencies' => $currencies,
            'default_currency' => $commerce->default_currency,
            'max_preview_lessons' => $catalog->max_preview_lessons,
            'upload_limits_mb' => [
                'video' => $catalog->max_video_upload_mb,
                'audio' => $catalog->max_audio_upload_mb,
                'document' => $catalog->max_document_upload_mb,
            ],
            'languages' => ['fr', 'en'],
            'levels' => array_map(fn (CourseLevel $l) => $l->value, CourseLevel::cases()),
            'audiences' => array_map(fn (Audience $a) => $a->value, Audience::cases()),
        ]];
    }
}
