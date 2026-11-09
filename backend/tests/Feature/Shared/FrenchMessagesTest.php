<?php

declare(strict_types=1);

it('renvoie les erreurs de validation en français', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.message', 'Le champ adresse e-mail est obligatoire.');
});
