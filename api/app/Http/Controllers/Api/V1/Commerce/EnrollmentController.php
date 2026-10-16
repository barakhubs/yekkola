<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\EnrollInFreeCourse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Learning\EnrollmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentController extends Controller
{
    /**
     * One-tap enrollment in a free course (PRD-05 FR-01). Enrolling again changes nothing.
     */
    public function store(Request $request, string $course, EnrollInFreeCourse $enroll): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $enrollment = $enroll->handle($user, $course);

        return (new EnrollmentResource($enrollment))->response();
    }
}
