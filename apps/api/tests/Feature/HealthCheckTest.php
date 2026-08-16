<?php

it('returns 200 with status ok from the health endpoint', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => 'MarQira Pulse API',
        ])
        ->assertJsonStructure(['status', 'service', 'timestamp']);
});
