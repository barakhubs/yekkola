<?php

declare(strict_types=1);

return [
    'coupon' => [
        'ends_before_start' => 'The end must come after the code’s start.',
    ],
    'bundle' => [
        'invalid_courses' => 'A bundle holds at least two paid courses in the bundle currency (for a professor, only their own courses).',
        'invalid_price' => 'The bundle price must be below the courses’ total and payable by mobile money.',
    ],
];
