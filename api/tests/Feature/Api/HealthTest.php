<?php

declare(strict_types=1);

it('exposes the health check', function () {
    $this->get('/up')->assertOk();
});

it('answers ping under /api/v1', function () {
    $this->getJson('/api/v1/ping')->assertOk()->assertExactJson(['status' => 'ok']);
});
